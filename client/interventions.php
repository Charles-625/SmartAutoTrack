<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/subscription.php';
require_once '../includes/anomaly_types.php';

/**
 * Interventions du client : liste, filtres et demande d'une nouvelle intervention.
 *
 * Accès : rôle client uniquement.
 * POST form=request_intervention (jeton CSRF requis) : crée une intervention
 *   pour un véhicule du client, adressée selon garage_id :
 *   - CLIENT_REQUEST_SAT ('sat', choix par défaut) : à SmartAutoTrack, qui
 *     affecte un de ses techniciens internes (avec si besoin un garage
 *     partenaire en appui) ; intervention créée sans garage ni technicien
 *     (« à affecter »), seuls les administrateurs sont notifiés
 *     (« Nouvelle demande à affecter ») ;
 *   - id d'un garage VALIDE : à ce garage partenaire, notifié avec les
 *     administrateurs (« Nouvelle demande d'intervention »).
 *   Dans les deux cas l'événement est journalisé.
 *   Motif « Anomalie constatée » : anomalie_type et anomalie_niveau (listes
 *   fermées de includes/anomaly_types.php) sont obligatoires, et pour le type
 *   « Autre » la précision anomalie_autre saisie par le client ; l'anomalie
 *   (statut NOUVELLE) est créée avec l'intervention dans la même transaction,
 *   journalisée (ANOMALY_CLIENT_LOG_NAME) et annoncée dans les notifications,
 *   dont le titre est préfixé par « URGENT » si la gravité est CRITIQUE.
 * GET : status ('demandee' | 'planifiee' | 'en_cours' | 'terminee' | 'annulee'),
 *       vehicle (id d'un véhicule du client), success (message après redirection).
 * Formule gratuite : la liste et les compteurs se limitent aux
 * SUB_FREE_HISTORY_MONTHS derniers mois (subscriptionHistorySince()), sauf les
 * interventions encore ouvertes (PLANIFIEE / EN_COURS), celles qui ont une
 * anomalie active ou une réparation terminée non payée : toujours visibles.
 * Tables lues : intervention, vehicule, garage, utilisateur ; anomalie,
 * reparation, paiement et abonnement pour l'historique limité.
 * Tables écrites : intervention, anomalie, notifications, journal d'activité (log_activity()).
 * Affichage « qui s'occupe » : v2_intervention_handler() (SmartAutoTrack et son
 * technicien interne, garage partenaire en appui, ou garage seul).
 * Fichiers liés : client/includes/helpers.php (CLIENT_REQUEST_SAT,
 * v2_recommend_garages, v2_intervention_handler, v2_relative),
 * admin/interventions.php (supervision, affectation et réaffectation),
 * includes/anomaly_types.php (types et gravités d'anomalie).
 * Ouverture de la page : activity_log_mark_seen(..., 'interventions') remet à zéro
 * la pastille rouge de nouveautés de cet onglet dans la sidebar (table
 * onglet_vu, includes/activity_log.php).
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Onglet « Interventions » ouvert : sa pastille rouge de nouveautés
// disparaît, avant le rendu de la sidebar (sans effet tant que la
// migration onglet_vu n'est pas appliquée).
activity_log_mark_seen($conn, (int)$_SESSION['user_id'], 'interventions');

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

// ============================================================
// Traitement du formulaire "Demander une intervention"
// Le client décrit le véhicule et le problème/motif, et choisit qui doit s'en
// occuper : SmartAutoTrack (par défaut — l'admin affecte ensuite un
// technicien interne, avec si besoin un garage partenaire en appui) ou
// directement un garage partenaire. L'admin conserve la supervision et peut
// réaffecter (cf. admin/interventions.php, admin/intervention_detail.php).
// ⚠️ Le destinataire n'est JAMAIS pris tel quel depuis le navigateur : la
// valeur spéciale CLIENT_REQUEST_SAT est comparée en liste blanche, tout
// autre choix doit être l'id d'un garage revérifié serveur (existe, VALIDE)
// avant tout INSERT.
// ============================================================
$requestErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'request_intervention') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $requestErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $reqVehiculeId = filter_var($_POST['vehicule_id'] ?? null, FILTER_VALIDATE_INT);
        $reqType = trim((string)($_POST['type'] ?? ''));
        $reqDescription = trim((string)($_POST['description'] ?? ''));
        // Destinataire : SmartAutoTrack (valeur spéciale, liste blanche) ou id de garage.
        $reqGarageRaw = (string)($_POST['garage_id'] ?? '');
        $reqToSat = $reqGarageRaw === CLIENT_REQUEST_SAT;
        $reqGarageId = $reqToSat ? null : filter_var($reqGarageRaw, FILTER_VALIDATE_INT);
        $allowedTypes = ['Diagnostic général', 'Panne / dysfonctionnement', 'Entretien courant', ANOMALY_REQUEST_MOTIF];
        // Type et gravité de l'anomalie : lus et validés (listes blanches de
        // includes/anomaly_types.php) uniquement pour le motif « Anomalie constatée ».
        $isAnomalyRequest = $reqType === ANOMALY_REQUEST_MOTIF;
        $reqAnomalyChoice = $isAnomalyRequest ? trim((string)($_POST['anomalie_type'] ?? '')) : '';
        $reqAnomalyNiveau = $isAnomalyRequest ? (string)($_POST['anomalie_niveau'] ?? '') : '';
        // Type enregistré : celui de la liste, ou pour « Autre » la précision
        // saisie par le client (anomalie_autre), validée par anomaly_resolve_type().
        $reqAnomalyType = $isAnomalyRequest ? anomaly_resolve_type($reqAnomalyChoice, (string)($_POST['anomalie_autre'] ?? '')) : '';

        if (!$reqVehiculeId) $requestErrors[] = 'Merci de choisir un véhicule.';
        if (!in_array($reqType, $allowedTypes, true)) $requestErrors[] = 'Motif de demande invalide.';
        if ($isAnomalyRequest && !anomaly_is_valid_type($reqAnomalyChoice)) $requestErrors[] = 'Merci de choisir le type d\'anomalie.';
        elseif ($isAnomalyRequest && $reqAnomalyType === null) $requestErrors[] = 'Merci de préciser ce que vous constatez sur le véhicule (' . ANOMALY_CUSTOM_TYPE_MIN . ' à ' . ANOMALY_CUSTOM_TYPE_MAX . ' caractères : lettres, chiffres et ponctuation simple).';
        if ($isAnomalyRequest && !anomaly_is_valid_niveau($reqAnomalyNiveau)) $requestErrors[] = 'Merci d\'indiquer la gravité de l\'anomalie.';
        if ($reqDescription === '') $requestErrors[] = 'Merci de décrire le problème ou la demande.';
        elseif (mb_strlen($reqDescription) > 2000) $requestErrors[] = 'Description trop longue (2000 caractères maximum).';
        if (!$reqToSat && !$reqGarageId) $requestErrors[] = 'Merci de choisir SmartAutoTrack ou un garage partenaire.';

        // Contrôle de propriété : le véhicule doit appartenir au client connecté.
        if (empty($requestErrors)) {
            $stmt = $conn->prepare("SELECT idVehicule FROM vehicule WHERE idVehicule = ? AND idClient = ?");
            $stmt->execute([$reqVehiculeId, $_SESSION['user_id']]);
            if (!$stmt->fetch()) {
                $requestErrors[] = 'Ce véhicule ne vous appartient pas.';
            }
        }

        // Le garage choisi doit réellement exister et être VALIDE — jamais de
        // confiance dans la valeur brute envoyée par le formulaire. Demande à
        // SmartAutoTrack : aucun garage ($chosenGarage reste null).
        $chosenGarage = null;
        if (empty($requestErrors) && !$reqToSat) {
            $stmt = $conn->prepare("SELECT idGarage, nomGarage, idUtilisateur FROM garage WHERE idGarage = ? AND statutGarage = 'VALIDE'");
            $stmt->execute([$reqGarageId]);
            $chosenGarage = $stmt->fetch();
            if (!$chosenGarage) {
                $requestErrors[] = 'Ce garage n\'est plus disponible. Merci d\'en choisir un autre dans la liste.';
            }
        }

        // Création de la demande ; statut et date prennent les valeurs par
        // défaut de la table. Intervention, anomalie déclarée, journal et
        // notifications sont écrits dans UNE transaction : une demande ne peut
        // pas exister sans son anomalie (ni l'inverse) si une écriture échoue.
        if (empty($requestErrors)) {
            $clientName = trim($_SESSION['prenom'] . ' ' . $_SESSION['nom']);
            $isCritical = $isAnomalyRequest && $reqAnomalyNiveau === 'CRITIQUE';
            // Motif détaillé (type et gravité de l'anomalie) repris dans les notifications.
            $motifDetail = $isAnomalyRequest
                ? $reqType . ' — ' . $reqAnomalyType . ', gravité : ' . anomaly_severity_label($reqAnomalyNiveau)
                : $reqType;
            $notifType = $isAnomalyRequest ? 'anomalie' : 'intervention';
            $notifTitle = ($isCritical ? 'URGENT — ' : '') . ($reqToSat ? 'Nouvelle demande à affecter' : 'Nouvelle demande d\'intervention');
            // Garage enregistré : NULL pour SmartAutoTrack (demande « à affecter »).
            $newGarageId = $chosenGarage ? (int)$chosenGarage['idGarage'] : null;

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("INSERT INTO intervention (idClient, idVehicule, idGarage, type, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $reqVehiculeId, $newGarageId, $reqType, $reqDescription]);
                $newInterventionId = (int)$conn->lastInsertId();

                // Anomalie déclarée par le client : rattachée au véhicule ET à
                // l'intervention, elle apparaît aussitôt dans les onglets
                // Anomalies du client, du garage choisi (le cas échéant), de l'admin, puis du
                // technicien dès son affectation ; la clôture de la réparation
                // la passera à TRAITEE comme les constats des professionnels.
                $newAnomalieId = null;
                if ($isAnomalyRequest) {
                    $stmt = $conn->prepare("INSERT INTO anomalie (idVehicule, idIntervention, description, type, niveau, statut) VALUES (?, ?, ?, ?, ?, 'NOUVELLE')");
                    $stmt->execute([$reqVehiculeId, $newInterventionId, $reqDescription, $reqAnomalyType, $reqAnomalyNiveau]);
                    $newAnomalieId = (int)$conn->lastInsertId();
                }

                log_activity($conn, 'Nouvelle demande d\'intervention', [
                    'idUtilisateur' => $_SESSION['user_id'],
                    'idIntervention' => $newInterventionId,
                    'description' => $reqType,
                    'categorie' => 'intervention',
                ]);

                if ($newAnomalieId) {
                    log_activity($conn, ANOMALY_CLIENT_LOG_NAME, [
                        'idUtilisateur' => $_SESSION['user_id'],
                        'idIntervention' => $newInterventionId,
                        'idAnomalie' => $newAnomalieId,
                        'description' => $reqAnomalyType . ' — gravité : ' . anomaly_severity_label($reqAnomalyNiveau),
                        'categorie' => 'anomalie',
                    ]);
                }

                // Recommandation SmartAutoTrack (logique simple, cf.
                // v2_recommend_garages) : journalisée séparément de la sélection
                // du client, sans idGarage réel (idGarage => false) pour ne
                // jamais fuiter dans le journal d'un garage qui n'a pas été
                // choisi — seuls l'admin et le client (via idClient) la voient.
                // Utile seulement quand le client a choisi lui-même un garage.
                $recommendations = $reqToSat ? [] : v2_recommend_garages($conn, $profile['adresse'] ?? null);
                $recommendedGarage = $recommendations[0] ?? null;
                if ($recommendedGarage) {
                    log_activity($conn, 'SmartAutoTrack a recommandé un garage pour cette demande', [
                        'idUtilisateur' => null,
                        'idIntervention' => $newInterventionId,
                        'idGarage' => false,
                        'description' => 'Garage recommandé : ' . $recommendedGarage['nomGarage'],
                        'categorie' => 'intervention',
                    ]);
                }

                if ($reqToSat) {
                    log_activity($conn, 'Client a adressé sa demande à SmartAutoTrack', [
                        'idUtilisateur' => $_SESSION['user_id'],
                        'idIntervention' => $newInterventionId,
                        'description' => $clientName . ' a adressé cette demande à SmartAutoTrack : un technicien est à affecter.',
                        'categorie' => 'intervention',
                    ]);
                } else {
                    log_activity($conn, 'Client a sélectionné un garage pour sa demande', [
                        'idUtilisateur' => $_SESSION['user_id'],
                        'idIntervention' => $newInterventionId,
                        'idGarage' => (int)$chosenGarage['idGarage'],
                        'description' => $clientName . ' a sélectionné ' . $chosenGarage['nomGarage'] . ' pour cette demande.',
                        'categorie' => 'intervention',
                    ]);
                }

                // Notifier les administrateurs : supervision d'une demande
                // adressée à un garage, ou affectation d'un technicien pour une
                // demande adressée à SmartAutoTrack. Le garage choisi est en plus
                // notifié (c'est lui qui traite sa demande). Une anomalie
                // CRITIQUE préfixe le titre par « URGENT ».
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, titre, message)
                    SELECT idAdministrateur, ?, ?, CONCAT(?, ' a demandé une intervention (', ?, ') ', ?, '.')
                    FROM administrateur
                ");
                $stmt->execute([$notifType, $notifTitle, $clientName, $motifDetail,
                    $reqToSat ? 'à SmartAutoTrack : un technicien est à affecter' : 'auprès de ' . $chosenGarage['nomGarage']]);

                if ($chosenGarage && $chosenGarage['idUtilisateur']) {
                    $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, ?, ?, ?)")
                        ->execute([$chosenGarage['idUtilisateur'], $notifType, $notifTitle, $clientName . ' vous a choisi pour une intervention (' . $motifDetail . ').']);
                }

                $conn->commit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $requestErrors[] = 'Erreur lors de l\'envoi de la demande. Merci de réessayer.';
            }
        }

        if (empty($requestErrors)) {
            // Redirection après POST : un rechargement ne renvoie pas la demande.
            header('Location: interventions.php?success=' . ($reqToSat ? 'intervention_requested_sat' : 'intervention_requested'));
            exit;
        }
    }
}

// Garages partenaires proposés dans le formulaire de demande, après l'option
// SmartAutoTrack : uniquement validés/actifs (jamais en attente, rejetés ou
// suspendus). Une liste vide n'empêche pas de demander à SmartAutoTrack.
$garageRecommendations = v2_recommend_garages($conn, $profile['adresse'] ?? null);

$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// Véhicules du client (pour le formulaire de demande)
$stmt = $conn->prepare("SELECT idVehicule AS id, marque, modele, immatriculation FROM vehicule WHERE idClient = ? ORDER BY marque, modele");
$stmt->execute([$_SESSION['user_id']]);
$vehicules = $stmt->fetchAll();

// Filtres : statut, + véhicule (utile dès que le client suit plusieurs
// véhicules — surtout pour un parc entreprise, mais fonctionne pour tous).
$statusFilter = $_GET['status'] ?? '';
$vehicleFilter = filter_var($_GET['vehicle'] ?? null, FILTER_VALIDATE_INT);
// Chaque filtre de statut correspond à une condition SQL fixe (liste blanche) ;
// 'demandee' et 'planifiee' se distinguent par l'affectation d'un technicien :
// la demande reste « envoyée » tant qu'aucun technicien n'est affecté, par le
// garage choisi ou, pour une demande adressée à SmartAutoTrack, par l'admin.
$statusConditions = [
    'demandee'  => "i.statut = 'PLANIFIEE' AND i.idTechnicien IS NULL",
    'planifiee' => "i.statut = 'PLANIFIEE' AND i.idTechnicien IS NOT NULL",
    'en_cours'  => "i.statut = 'EN_COURS'",
    'terminee'  => "i.statut = 'TERMINEE'",
    'annulee'   => "i.statut = 'ANNULEE'",
];
$where = "i.idClient = ?";
$queryParams = [$_SESSION['user_id']];
if (isset($statusConditions[$statusFilter])) {
    $where .= " AND " . $statusConditions[$statusFilter];
}
if ($vehicleFilter) {
    $where .= " AND i.idVehicule = ?";
    $queryParams[] = $vehicleFilter;
}

// Historique limité de la formule gratuite (null : aucun filtre). Seules les
// interventions closes et anciennes sont masquées : une intervention ouverte,
// liée à une anomalie active ou à une réparation terminée encore à payer
// reste visible quelle que soit sa date.
$historySince = subscriptionHistorySince($conn, (int)$_SESSION['user_id']);
$historySql = '';
$historyParams = [];
if ($historySince !== null) {
    // Sans les colonnes CamPay, aucun paiement n'est rattachable à une
    // réparation : toute réparation terminée au coût non nul compte comme à payer.
    $paidSql = paymentsReady($conn)
        ? "AND NOT EXISTS (SELECT 1 FROM paiement p WHERE p.idReparation = r.idReparation AND p.statut = 'PAYE')"
        : '';
    $historySql = " AND (i.dateIntervention >= ? OR i.statut IN ('PLANIFIEE', 'EN_COURS')
        OR EXISTS (SELECT 1 FROM anomalie a WHERE a.idIntervention = i.idIntervention AND a.statut IN ('NOUVELLE', 'EN_COURS'))
        OR EXISTS (SELECT 1 FROM reparation r WHERE r.idIntervention = i.idIntervention AND r.statut = 'TERMINEE' AND r.cout > 0 $paidSql))";
    $historyParams = [$historySince];
    $where .= $historySql;
    $queryParams = array_merge($queryParams, $historyParams);
}

// Toutes les interventions du client (tous statuts), avec véhicule/garage/technicien
// (et son type INTERNE/GARAGE, lu par v2_intervention_handler())
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut,
           i.idTechnicien, i.idGarage, v.marque, v.modele, v.immatriculation,
           g.nomGarage, ut.nom AS technicien_nom, ut.prenom AS technicien_prenom,
           t.typeTechnicien AS technicien_type
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    LEFT JOIN garage g ON i.idGarage = g.idGarage
    LEFT JOIN utilisateur ut ON i.idTechnicien = ut.idUtilisateur
    LEFT JOIN technicien t ON i.idTechnicien = t.idTechnicien
    WHERE $where
    ORDER BY i.dateIntervention DESC
");
$stmt->execute($queryParams);
$interventions = $stmt->fetchAll();
foreach ($interventions as &$iv) {
    if ($iv['statut'] === 'EN_COURS') {
        $iv['display'] = 'en_cours';
    } elseif ($iv['statut'] === 'TERMINEE') {
        $iv['display'] = 'terminee';
    } elseif ($iv['statut'] === 'ANNULEE') {
        $iv['display'] = 'annulee';
    } elseif ($iv['idTechnicien'] === null) {
        $iv['display'] = 'demande_envoyee';
    } else {
        $iv['display'] = 'planifiee';
    }
}
unset($iv);

// Compteurs (tous statuts confondus, indépendants du filtre courant ; seul
// l'historique limité de la formule gratuite s'applique, comme à la liste)
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN i.statut = 'PLANIFIEE' AND i.idTechnicien IS NULL THEN 1 ELSE 0 END) AS demandee,
        SUM(CASE WHEN i.statut = 'PLANIFIEE' AND i.idTechnicien IS NOT NULL THEN 1 ELSE 0 END) AS planifiee,
        SUM(CASE WHEN i.statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN i.statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminee
    FROM intervention i WHERE i.idClient = ?$historySql
");
$stmt->execute(array_merge([$_SESSION['user_id']], $historyParams));
$counts = $stmt->fetch();
$interventionsActivesCount = (int)($counts['demandee'] ?? 0) + (int)($counts['planifiee'] ?? 0) + (int)($counts['en_cours'] ?? 0);

/**
 * Ligne secondaire d'une intervention dans la liste (qui, quand, état).
 * « Qui » : v2_intervention_handler() (SmartAutoTrack, garage partenaire ou garage).
 *
 * @param array $iv Intervention enrichie de sa clé 'display'.
 * @return string   Texte brut, à échapper à l'affichage.
 */
$displayMeta = function ($iv) use ($dbNow) {
    $qui = v2_intervention_handler($iv);
    switch ($iv['display']) {
        case 'demande_envoyee':
            return 'demande envoyée à ' . $qui . ' ' . v2_relative($iv['dateIntervention'], $dbNow) . ' — en attente d\'affectation d\'un technicien';
        case 'terminee':
            return ($qui ? $qui . ' · ' : '') . 'terminée le ' . date('d/m/Y', strtotime($iv['dateIntervention']));
        case 'annulee':
            return 'annulée';
        default:
            return ($qui ? $qui . ' · ' : '') . date('d/m/Y', strtotime($iv['dateIntervention']));
    }
};
// Icône, couleurs et libellé du badge pour chaque statut d'affichage.
$displayInfo = [
    'demande_envoyee' => ['icon' => 'send', 'bg' => '#EFF0F6', 'color' => '#6D74A0', 'badge' => 'neutral', 'label' => 'Demande envoyée', 'row' => 'pending'],
    'planifiee'        => ['icon' => 'clock', 'bg' => '#F3F5FE', 'color' => '#3956E8', 'badge' => 'ok', 'label' => 'Planifiée', 'row' => ''],
    'en_cours'         => ['icon' => 'clock', 'bg' => '#FFF4E2', 'color' => '#C8871A', 'badge' => 'warn', 'label' => 'En cours', 'row' => ''],
    'terminee'         => ['icon' => 'check', 'bg' => '#E9F6EE', 'color' => '#1E8A4C', 'badge' => 'ok', 'label' => 'Terminée', 'row' => ''],
    'annulee'          => ['icon' => 'close', 'bg' => '#FDEDEE', 'color' => '#E5484D', 'badge' => 'bad', 'label' => 'Annulée', 'row' => ''],
];

$pageTitle = 'Interventions';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'interventions'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">

        <?php if (isset($_GET['success']) && $_GET['success'] === 'intervention_requested'): ?>
            <div class="v2-alert success">Votre demande a bien été envoyée au garage choisi. Un administrateur supervise également son affectation.</div>
        <?php elseif (isset($_GET['success']) && $_GET['success'] === 'intervention_requested_sat'): ?>
            <div class="v2-alert success">Votre demande a été transmise à SmartAutoTrack, un technicien va vous être affecté.</div>
        <?php endif; ?>

        <div class="v2-page-head">
            <div>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1">Interventions</h1>
                <p class="v2-sub">Vos demandes et interventions, du dépôt de la demande jusqu'à la fin des travaux.</p>
            </div>
            <button class="v2-btn-primary" id="openInterventionModal" type="button" style="padding:12px 20px;">+ Demander une intervention</button>
        </div>

        <div class="v2-stats">
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#EFF0F6;"><i class="fas fa-paper-plane" style="color:#6D74A0;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['demandee'] ?? 0); ?></div>
                    <div class="v2-stat-label">Demande(s) envoyée(s)</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#F3F5FE;"><i class="fas fa-calendar-check" style="color:#3956E8;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['planifiee'] ?? 0); ?></div>
                    <div class="v2-stat-label">Planifiée(s)</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#FFF4E2;"><i class="fas fa-wrench" style="color:#C8871A;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['en_cours'] ?? 0); ?></div>
                    <div class="v2-stat-label">En cours</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#E9F6EE;"><i class="fas fa-check" style="color:#1E8A4C;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['terminee'] ?? 0); ?></div>
                    <div class="v2-stat-label">Terminée(s)</div>
                </div>
            </div>
        </div>

        <div class="v2-card v2-panel" style="margin-top:22px;">
            <?php if ($historySince !== null): ?>
                <div class="v2-alert premium">Historique limité aux <?php echo (int)SUB_FREE_HISTORY_MONTHS; ?> derniers mois — <a href="abonnement.php">Premium : historique complet</a></div>
            <?php endif; ?>
            <div class="v2-panel-head">
                <h2>Historique</h2>
                <form method="GET" style="margin:0; display:flex; gap:8px; flex-wrap:wrap;">
                    <select name="status" onchange="this.form.submit()" style="border:1px solid #DDE0F0; border-radius:10px; padding:8px 12px; font-family:'Manrope', sans-serif; font-size:13px;">
                        <option value="">Tous les statuts</option>
                        <option value="demandee" <?php echo $statusFilter === 'demandee' ? 'selected' : ''; ?>>Demande envoyée</option>
                        <option value="planifiee" <?php echo $statusFilter === 'planifiee' ? 'selected' : ''; ?>>Planifiée</option>
                        <option value="en_cours" <?php echo $statusFilter === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                        <option value="terminee" <?php echo $statusFilter === 'terminee' ? 'selected' : ''; ?>>Terminée</option>
                        <option value="annulee" <?php echo $statusFilter === 'annulee' ? 'selected' : ''; ?>>Annulée</option>
                    </select>
                    <?php if (count($vehicules) > 1): ?>
                        <select name="vehicle" onchange="this.form.submit()" style="border:1px solid #DDE0F0; border-radius:10px; padding:8px 12px; font-family:'Manrope', sans-serif; font-size:13px;">
                            <option value="">Tous les véhicules</option>
                            <?php foreach ($vehicules as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>" <?php echo $vehicleFilter === (int)$v['id'] ? 'selected' : ''; ?>><?php echo h($v['marque'] . ' ' . $v['modele'] . ' — ' . $v['immatriculation']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($interventions)): ?>
                <div class="v2-empty">Aucune intervention pour ce filtre.</div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($interventions as $iv): $info = $displayInfo[$iv['display']]; ?>
                        <div class="v2-row <?php echo h($info['row']); ?>">
                            <div class="v2-row-icon" style="background:<?php echo h($info['bg']); ?>;">
                                <?php if ($info['icon'] === 'send'): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L8 11M12 15L16 11" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V17" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php elseif ($info['icon'] === 'check'): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8"/><path d="M8 12.5L10.5 15L16 9" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php elseif ($info['icon'] === 'close'): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8"/><path d="M9 9L15 15M15 9L9 15" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php else: ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="<?php echo h($info['color']); ?>" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php endif; ?>
                            </div>
                            <div style="flex-grow:1; min-width:0;">
                                <div class="v2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?></div>
                                <div class="v2-row-meta"><?php echo h($iv['immatriculation']); ?> · <?php echo h($displayMeta($iv)); ?></div>
                                <?php if ($iv['description']): ?>
                                    <div class="v2-row-meta" style="margin-top:4px; font-style:italic;"><?php echo h($iv['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="v2-row-status v2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal : demander une intervention -->
<div class="v2-modal-overlay" id="interventionModalOverlay">
    <div class="v2-modal">
        <h3>Demander une intervention</h3>
        <p class="v2-modal-sub">Décrivez le véhicule, le problème rencontré, et choisissez qui doit s'en occuper : SmartAutoTrack ou un garage partenaire.</p>
        <?php if (!empty($requestErrors)): ?>
            <div class="v2-alert error"><?php foreach ($requestErrors as $err) echo h($err) . '<br>'; ?></div>
        <?php endif; ?>
        <?php if (empty($vehicules)): ?>
            <div class="v2-alert error">Vous devez d'abord <a href="vehicles.php">ajouter un véhicule</a> avant de pouvoir demander une intervention.</div>
        <?php else: ?>
        <form method="POST" action="interventions.php">
            <input type="hidden" name="form" value="request_intervention">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="v2-form-group">
                <label for="ivVehicule">Véhicule concerné</label>
                <select name="vehicule_id" id="ivVehicule" required>
                    <option value="">Sélectionner un véhicule</option>
                    <?php foreach ($vehicules as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>"><?php echo h($v['marque'] . ' ' . $v['modele'] . ' — ' . $v['immatriculation']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="v2-form-group">
                <label for="ivType">Type de demande</label>
                <select name="type" id="ivType" required>
                    <option value="">Sélectionner un motif</option>
                    <option value="Diagnostic général">Diagnostic général</option>
                    <option value="Panne / dysfonctionnement">Panne / dysfonctionnement</option>
                    <option value="Entretien courant">Entretien courant</option>
                    <option value="<?php echo h(ANOMALY_REQUEST_MOTIF); ?>"><?php echo h(ANOMALY_REQUEST_MOTIF); ?></option>
                </select>
            </div>
            <!-- Affiché (et rendu obligatoire) seulement pour le motif « Anomalie constatée ». -->
            <div id="ivAnomalyFields" hidden>
                <div class="v2-form-group">
                    <label for="ivAnomalyType">Type d'anomalie</label>
                    <select name="anomalie_type" id="ivAnomalyType" disabled>
                        <option value="">Sélectionner un type</option>
                        <?php foreach (ANOMALY_TYPES as $anomalyType): ?>
                            <option value="<?php echo h($anomalyType); ?>"><?php echo h($anomalyType); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Affiché seulement pour le type « Autre » : le client décrit ce qu'il constate. -->
                <div class="v2-form-group" id="ivAnomalyOtherWrap" hidden>
                    <label for="ivAnomalyOther">Précisez ce que vous constatez</label>
                    <input type="text" name="anomalie_autre" id="ivAnomalyOther" disabled minlength="<?php echo ANOMALY_CUSTOM_TYPE_MIN; ?>" maxlength="<?php echo ANOMALY_CUSTOM_TYPE_MAX; ?>" placeholder="Ex. Fumée blanche à l'échappement">
                </div>
                <div class="v2-form-group">
                    <label for="ivAnomalyNiveau">Gravité</label>
                    <select name="anomalie_niveau" id="ivAnomalyNiveau" disabled>
                        <option value="">Comment se comporte le véhicule ?</option>
                        <?php foreach (ANOMALY_CLIENT_SEVERITIES as $niveauCode => $niveauLabel): ?>
                            <option value="<?php echo h($niveauCode); ?>"><?php echo h($niveauLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="v2-form-group">
                <label for="ivGarage">Qui doit s'occuper de votre véhicule ?</label>
                <!-- SmartAutoTrack en premier et sélectionné par défaut ; le garage
                     recommandé reste signalé mais n'est plus présélectionné. -->
                <select name="garage_id" id="ivGarage" required>
                    <option value="<?php echo h(CLIENT_REQUEST_SAT); ?>" selected>SmartAutoTrack — nos techniciens</option>
                    <?php if (!empty($garageRecommendations)): ?>
                        <optgroup label="Garages partenaires">
                            <?php foreach ($garageRecommendations as $g): ?>
                                <option value="<?php echo (int)$g['idGarage']; ?>">
                                    <?php echo h(($g['recommended'] ? '★ Recommandé — ' : '') . $g['nomGarage'] . ' — ' . ($g['adresse'] ?: 'adresse non renseignée') . ' — ' . $g['charge'] . ' intervention(s) en cours'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <p style="font-size:12.5px; color:#6D74A0; margin:6px 0 0;">
                    💡 <strong>SmartAutoTrack</strong> affecte un de ses techniciens à votre demande, avec si besoin un garage pour la réparation.
                    <?php if (!empty($garageRecommendations)): ?>
                        Vous pouvez aussi choisir directement un garage partenaire — <strong>garage recommandé</strong> : <?php echo h($garageRecommendations[0]['nomGarage']); ?>, selon sa charge actuelle et, si connue, la proximité de votre adresse.
                    <?php endif; ?>
                </p>
            </div>
            <div class="v2-form-group">
                <label for="ivDescription">Décrivez le problème ou la demande</label>
                <textarea name="description" id="ivDescription" required maxlength="2000" placeholder="Ex. Bruit métallique au freinage à l'avant…"></textarea>
            </div>
            <div class="v2-modal-actions">
                <button type="button" class="v2-btn-outline" id="closeInterventionModal">Annuler</button>
                <button type="submit" class="v2-btn-primary">Envoyer la demande</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('interventionModalOverlay');
    var openBtn = document.getElementById('openInterventionModal');
    var closeBtn = document.getElementById('closeInterventionModal');

    if (openBtn) openBtn.addEventListener('click', function () { overlay.classList.add('show'); });
    if (closeBtn) closeBtn.addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });

    // Motif « Anomalie constatée » : affiche le type et la gravité et les rend
    // obligatoires ; masqués, ils sont désactivés et donc jamais envoyés.
    // Le type « Autre » ajoute de la même façon le champ de précision libre.
    var typeSelect = document.getElementById('ivType');
    var anomalyFields = document.getElementById('ivAnomalyFields');
    var anomalyTypeSelect = document.getElementById('ivAnomalyType');
    var otherWrap = document.getElementById('ivAnomalyOtherWrap');
    var otherInput = document.getElementById('ivAnomalyOther');
    function toggleAnomalyFields() {
        var isAnomaly = typeSelect.value === <?php echo json_encode(ANOMALY_REQUEST_MOTIF); ?>;
        anomalyFields.hidden = !isAnomaly;
        anomalyFields.querySelectorAll('select').forEach(function (field) {
            field.disabled = !isAnomaly;
            field.required = isAnomaly;
        });
        var isOther = isAnomaly && anomalyTypeSelect.value === <?php echo json_encode(ANOMALY_TYPE_OTHER); ?>;
        otherWrap.hidden = !isOther;
        otherInput.disabled = !isOther;
        otherInput.required = isOther;
    }
    if (typeSelect && anomalyFields && anomalyTypeSelect && otherInput) {
        typeSelect.addEventListener('change', toggleAnomalyFields);
        anomalyTypeSelect.addEventListener('change', toggleAnomalyFields);
        toggleAnomalyFields();
    }

    <?php if (!empty($requestErrors)): ?>
    overlay.classList.add('show');
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
