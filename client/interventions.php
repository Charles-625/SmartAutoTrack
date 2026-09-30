<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Interventions du client : liste, filtres et demande d'une nouvelle intervention.
 *
 * Accès : rôle client uniquement.
 * POST form=request_intervention (jeton CSRF requis) : crée une intervention
 *   pour un véhicule du client auprès d'un garage VALIDE, journalise
 *   l'événement et notifie les administrateurs et le garage choisi.
 * GET : status ('demandee' | 'planifiee' | 'en_cours' | 'terminee' | 'annulee'),
 *       vehicle (id d'un véhicule du client), success (message après redirection).
 * Tables lues : intervention, vehicule, garage, utilisateur.
 * Tables écrites : intervention, notifications, journal d'activité (log_activity()).
 * Fichiers liés : client/includes/helpers.php (v2_recommend_garages, v2_relative),
 * admin/interventions.php (supervision et réaffectation).
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

// ============================================================
// Traitement du formulaire "Demander une intervention"
// Le client décrit le véhicule et le problème/motif, ET choisit désormais
// lui-même un garage partenaire (la jonction Client → Garage se fait ici,
// à la création, plutôt que par une affectation manuelle a posteriori par
// l'administrateur). L'admin conserve la supervision et peut réaffecter
// (cf. admin/interventions.php, admin/intervention_detail.php).
// ⚠️ Le garage choisi n'est JAMAIS pris tel quel depuis le navigateur : il
// est revérifié serveur (existe, VALIDE) avant tout INSERT.
// ============================================================
$requestErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'request_intervention') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $requestErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $reqVehiculeId = filter_var($_POST['vehicule_id'] ?? null, FILTER_VALIDATE_INT);
        $reqType = trim((string)($_POST['type'] ?? ''));
        $reqDescription = trim((string)($_POST['description'] ?? ''));
        $reqGarageId = filter_var($_POST['garage_id'] ?? null, FILTER_VALIDATE_INT);
        $allowedTypes = ['Diagnostic général', 'Panne / dysfonctionnement', 'Entretien courant'];

        if (!$reqVehiculeId) $requestErrors[] = 'Merci de choisir un véhicule.';
        if (!in_array($reqType, $allowedTypes, true)) $requestErrors[] = 'Motif de demande invalide.';
        if ($reqDescription === '') $requestErrors[] = 'Merci de décrire le problème ou la demande.';
        elseif (mb_strlen($reqDescription) > 2000) $requestErrors[] = 'Description trop longue (2000 caractères maximum).';
        if (!$reqGarageId) $requestErrors[] = 'Merci de choisir un garage partenaire.';

        // Contrôle de propriété : le véhicule doit appartenir au client connecté.
        if (empty($requestErrors)) {
            $stmt = $conn->prepare("SELECT idVehicule FROM vehicule WHERE idVehicule = ? AND idClient = ?");
            $stmt->execute([$reqVehiculeId, $_SESSION['user_id']]);
            if (!$stmt->fetch()) {
                $requestErrors[] = 'Ce véhicule ne vous appartient pas.';
            }
        }

        // Le garage choisi doit réellement exister et être VALIDE — jamais de
        // confiance dans la valeur brute envoyée par le formulaire.
        $chosenGarage = null;
        if (empty($requestErrors)) {
            $stmt = $conn->prepare("SELECT idGarage, nomGarage, idUtilisateur FROM garage WHERE idGarage = ? AND statutGarage = 'VALIDE'");
            $stmt->execute([$reqGarageId]);
            $chosenGarage = $stmt->fetch();
            if (!$chosenGarage) {
                $requestErrors[] = 'Ce garage n\'est plus disponible. Merci d\'en choisir un autre dans la liste.';
            }
        }

        // Création de la demande ; statut et date prennent les valeurs par défaut de la table.
        if (empty($requestErrors)) {
            $stmt = $conn->prepare("INSERT INTO intervention (idClient, idVehicule, idGarage, type, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $reqVehiculeId, $chosenGarage['idGarage'], $reqType, $reqDescription]);
            $newInterventionId = (int)$conn->lastInsertId();

            log_activity($conn, 'Nouvelle demande d\'intervention', [
                'idUtilisateur' => $_SESSION['user_id'],
                'idIntervention' => $newInterventionId,
                'description' => $reqType,
                'categorie' => 'intervention',
            ]);

            // Recommandation SmartAutoTrack (logique simple, cf.
            // v2_recommend_garages) : journalisée séparément de la sélection
            // du client, sans idGarage réel (idGarage => false) pour ne
            // jamais fuiter dans le journal d'un garage qui n'a pas été
            // choisi — seuls l'admin et le client (via idClient) la voient.
            $recommendations = v2_recommend_garages($conn, $profile['adresse'] ?? null);
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

            log_activity($conn, 'Client a sélectionné un garage pour sa demande', [
                'idUtilisateur' => $_SESSION['user_id'],
                'idIntervention' => $newInterventionId,
                'idGarage' => (int)$chosenGarage['idGarage'],
                'description' => trim($_SESSION['prenom'] . ' ' . $_SESSION['nom']) . ' a sélectionné ' . $chosenGarage['nomGarage'] . ' pour cette demande.',
                'categorie' => 'intervention',
            ]);

            // Notifier les administrateurs (supervision) et le garage choisi
            // (c'est désormais lui qui doit traiter la demande).
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, titre, message)
                SELECT idAdministrateur, 'intervention', 'Nouvelle demande d''intervention',
                       CONCAT(?, ' a demandé une intervention (', ?, ') auprès de ', ?, '.')
                FROM administrateur
            ");
            $stmt->execute([trim($_SESSION['prenom'] . ' ' . $_SESSION['nom']), $reqType, $chosenGarage['nomGarage']]);

            if ($chosenGarage['idUtilisateur']) {
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Nouvelle demande d\'intervention', ?)")
                    ->execute([$chosenGarage['idUtilisateur'], trim($_SESSION['prenom'] . ' ' . $_SESSION['nom']) . ' vous a choisi pour une intervention (' . $reqType . ').']);
            }

            // Redirection après POST : un rechargement ne renvoie pas la demande.
            header('Location: interventions.php?success=intervention_requested');
            exit;
        }
    }
}

// Garages partenaires proposés dans le formulaire de demande : uniquement
// validés/actifs (jamais en attente, rejetés ou suspendus).
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
// 'demandee' et 'planifiee' se distinguent par la présence d'une affectation.
$statusConditions = [
    'demandee'  => "i.statut = 'PLANIFIEE' AND i.idTechnicien IS NULL AND i.idGarage IS NULL",
    'planifiee' => "i.statut = 'PLANIFIEE' AND (i.idTechnicien IS NOT NULL OR i.idGarage IS NOT NULL)",
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

// Toutes les interventions du client (tous statuts), avec véhicule/garage/technicien
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut,
           i.idTechnicien, i.idGarage, v.marque, v.modele, v.immatriculation,
           g.nomGarage, ut.nom AS technicien_nom, ut.prenom AS technicien_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    LEFT JOIN garage g ON i.idGarage = g.idGarage
    LEFT JOIN utilisateur ut ON i.idTechnicien = ut.idUtilisateur
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
    } elseif ($iv['idTechnicien'] === null && $iv['idGarage'] === null) {
        $iv['display'] = 'demande_envoyee';
    } else {
        $iv['display'] = 'planifiee';
    }
}
unset($iv);

// Compteurs (tous statuts confondus, indépendants du filtre courant)
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN statut = 'PLANIFIEE' AND idTechnicien IS NULL AND idGarage IS NULL THEN 1 ELSE 0 END) AS demandee,
        SUM(CASE WHEN statut = 'PLANIFIEE' AND (idTechnicien IS NOT NULL OR idGarage IS NOT NULL) THEN 1 ELSE 0 END) AS planifiee,
        SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminee
    FROM intervention WHERE idClient = ?
");
$stmt->execute([$_SESSION['user_id']]);
$counts = $stmt->fetch();
$interventionsActivesCount = (int)($counts['demandee'] ?? 0) + (int)($counts['planifiee'] ?? 0) + (int)($counts['en_cours'] ?? 0);

/**
 * Ligne secondaire d'une intervention dans la liste (qui, quand, état).
 *
 * @param array $iv Intervention enrichie de sa clé 'display'.
 * @return string   Texte brut, à échapper à l'affichage.
 */
$displayMeta = function ($iv) use ($dbNow) {
    $qui = $iv['nomGarage'] ?: trim(($iv['technicien_prenom'] ?? '') . ' ' . ($iv['technicien_nom'] ?? ''));
    switch ($iv['display']) {
        case 'demande_envoyee':
            return 'demande envoyée ' . v2_relative($iv['dateIntervention'], $dbNow) . ' — en attente d\'affectation';
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
    'planifiee'        => ['icon' => 'clock', 'bg' => '#EEF1FF', 'color' => '#3956E8', 'badge' => 'ok', 'label' => 'Planifiée', 'row' => ''],
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
                <div class="v2-stat-icon" style="background:#EEF1FF;"><i class="fas fa-calendar-check" style="color:#3956E8;"></i></div>
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
        <p class="v2-modal-sub">Décrivez le véhicule, le problème rencontré, et choisissez le garage partenaire qui doit s'en occuper.</p>
        <?php if (!empty($requestErrors)): ?>
            <div class="v2-alert error"><?php foreach ($requestErrors as $err) echo h($err) . '<br>'; ?></div>
        <?php endif; ?>
        <?php if (empty($vehicules)): ?>
            <div class="v2-alert error">Vous devez d'abord <a href="vehicles.php">ajouter un véhicule</a> avant de pouvoir demander une intervention.</div>
        <?php elseif (empty($garageRecommendations)): ?>
            <div class="v2-alert error">Aucun garage partenaire n'est disponible pour le moment. Merci de réessayer plus tard.</div>
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
                </select>
            </div>
            <div class="v2-form-group">
                <label for="ivGarage">Choisir un garage</label>
                <select name="garage_id" id="ivGarage" required>
                    <?php foreach ($garageRecommendations as $g): ?>
                        <option value="<?php echo (int)$g['idGarage']; ?>" <?php echo $g['recommended'] ? 'selected' : ''; ?>>
                            <?php echo h(($g['recommended'] ? '★ Recommandé — ' : '') . $g['nomGarage'] . ' — ' . ($g['adresse'] ?: 'adresse non renseignée') . ' — ' . $g['charge'] . ' intervention(s) en cours'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php $ivRecommended = $garageRecommendations[0]; ?>
                <p style="font-size:12.5px; color:#6D74A0; margin:6px 0 0;">
                    💡 <strong>Garage recommandé</strong> : <?php echo h($ivRecommended['nomGarage']); ?> — sélection basée sur sa charge actuelle et, si connue, la proximité de votre adresse. Vous restez libre de choisir un autre garage partenaire dans la liste ci-dessus.
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

    <?php if (!empty($requestErrors)): ?>
    overlay.classList.add('show');
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
