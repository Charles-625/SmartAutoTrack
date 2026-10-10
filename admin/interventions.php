<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Liste et supervision de toutes les interventions (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Actions POST (jeton CSRF requis), passées par ?action=… :
 *   - assign : affecte ou réaffecte une intervention PLANIFIEE ou ANNULEE
 *     (refusée) via admin_assign() — technicien (SmartAutoTrack ou de
 *     garage) et garage facultatifs, au moins l'un des deux ;
 *   - new : crée une intervention assignée directement à un technicien ;
 *   - update_status (?id=N) : change le statut de l'intervention.
 * Filtres GET : `status`, `technicien`, `garage` (id ou « none » pour les
 * demandes non affectées), `date`.
 *
 * Demande « à affecter » : sans technicien, et sans garage (demande adressée
 * à SmartAutoTrack) ou refusée par un garage. Chaque intervention liée à une
 * anomalie ouverte affiche son type et sa gravité ; les demandes à affecter
 * avec une anomalie CRITIQUE ouverte passent en tête de liste.
 * Colonne Garage : « SmartAutoTrack » quand il n'y a aucun garage, ou garage
 * partenaire « en appui » quand un technicien interne mène l'intervention.
 * Bouton de ligne : « Affecter » (demande sans affectation active) ou
 * « Réaffecter » (PLANIFIEE déjà confiée à un technicien ou un garage), pour
 * assurer la disponibilité ; rien pour EN_COURS/TERMINEE. La fenêtre
 * affiche la charge (interventions planifiées et en cours) de chacun.
 *
 * Tables : intervention (lecture/écriture), vehicule, utilisateur,
 * technicien, garage, anomalie, notifications, journal d'activité.
 * Liens : intervention_detail.php, admin/includes/helpers.php
 * (admin_assign, admin_assign_choices, admin_assign_fields), client/interventions.php.
 * Ouverture de la page : activity_log_mark_seen(..., 'interventions') remet à zéro
 * la pastille rouge de nouveautés de cet onglet dans la sidebar (table
 * onglet_vu, includes/activity_log.php).
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Onglet « Interventions » ouvert : sa pastille rouge de nouveautés
// disparaît, avant le rendu de la sidebar (sans effet tant que la
// migration onglet_vu n'est pas appliquée).
activity_log_mark_seen($conn, (int)$_SESSION['user_id'], 'interventions');

$action = $_GET['action'] ?? '';
$intervention_id = $_GET['id'] ?? null;
$errors = [];

// Affecter / réaffecter une intervention : l'administrateur choisit librement
// un technicien (SmartAutoTrack ou de garage), un garage, ou les deux — au
// moins l'un des deux. Règles, verrou, journal et notifications :
// admin_assign() (admin/includes/helpers.php), partagée avec
// intervention_detail.php.
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $assignInterventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        // Deux choix facultatifs : valeur vide = aucun.
        $assignTechnicienId = admin_assign_parse_id($_POST['technicien_id'] ?? null);
        $assignGarageId = admin_assign_parse_id($_POST['garage_id'] ?? null);

        if (!$assignInterventionId) $errors[] = 'Intervention invalide.';
        if ($assignTechnicienId === false) $errors[] = 'Technicien invalide.';
        if ($assignGarageId === false) $errors[] = 'Garage invalide.';

        if (empty($errors)) {
            $assignError = admin_assign($conn, $assignInterventionId, $assignTechnicienId, $assignGarageId, (int)$_SESSION['user_id']);
            if ($assignError !== null) {
                $errors[] = $assignError;
            } else {
                header('Location: interventions.php?success=assigned');
                exit;
            }
        }
    }
}

// Créer une nouvelle intervention (assignation directe à un technicien —
// capacité historique de l'admin, conservée telle quelle).
if ($action === 'new' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    }
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $technicien_id = (int)($_POST['technicien_id'] ?? 0);
    $type_intervention = sanitize($_POST['type_intervention'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $date_planifiee = $_POST['date_planifiee'] ?? '';

    if (!$vehicle_id) $errors[] = 'Véhicule requis.';
    if (!$technicien_id) $errors[] = 'Technicien requis.';
    if (empty($type_intervention)) $errors[] = 'Type d\'intervention requis.';
    if (empty($date_planifiee)) $errors[] = 'Date planifiée requise.';

    if (empty($errors)) {
        try {
            // Le client de l'intervention est déduit du véhicule choisi, jamais du
            // formulaire.
            $stmt = $conn->prepare("SELECT idClient FROM vehicule WHERE idVehicule = ?");
            $stmt->execute([$vehicle_id]);
            $vehicle = $stmt->fetch();

            if (!$vehicle) {
                $errors[] = 'Véhicule introuvable.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO intervention (idClient, idVehicule, idTechnicien, type, description, dateIntervention)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$vehicle['idClient'], $vehicle_id, $technicien_id, $type_intervention, $description, $date_planifiee]);
                $newInterventionId = (int)$conn->lastInsertId();

                log_activity($conn, 'Intervention créée et assignée par l\'administrateur', [
                    'idUtilisateur' => $_SESSION['user_id'] ?? null,
                    'idIntervention' => $newInterventionId,
                    'idTechnicien' => $technicien_id,
                    'description' => $type_intervention,
                    'categorie' => 'intervention',
                ]);

                // Deux notifications : le technicien assigné et le propriétaire du véhicule.
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Nouvelle intervention assignée', ?)")
                    ->execute([$technicien_id, 'Vous avez été assigné à une nouvelle intervention : ' . $type_intervention]);
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Intervention planifiée', ?)")
                    ->execute([$vehicle['idClient'], 'Une intervention a été planifiée pour votre véhicule : ' . $type_intervention]);

                header("Location: interventions.php?success=created");
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la création de l\'intervention.';
        }
    }
}

// Modifier le statut d'une intervention
if ($action === 'update_status' && $intervention_id && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $new_status = $_POST['status'] ?? '';
    // Liste blanche des statuts acceptés : toute autre valeur est ignorée.
    $statusToDb = ['planifiee' => 'PLANIFIEE', 'en_cours' => 'EN_COURS', 'terminee' => 'TERMINEE', 'annulee' => 'ANNULEE'];
    if (isset($statusToDb[$new_status])) {
        try {
            $conn->prepare("UPDATE intervention SET statut = ? WHERE idIntervention = ?")->execute([$statusToDb[$new_status], $intervention_id]);
            log_activity($conn, 'Statut d\'intervention modifié par l\'administrateur', [
                'idUtilisateur' => $_SESSION['user_id'] ?? null,
                'idIntervention' => (int)$intervention_id,
                'description' => 'Nouveau statut : ' . $statusToDb[$new_status],
                'categorie' => 'intervention',
            ]);
            header("Location: interventions.php?success=status_updated");
            exit;
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la mise à jour du statut.';
        }
    }
}

// Filtres
$status_filter = $_GET['status'] ?? '';
$technicien_filter = filter_var($_GET['technicien'] ?? null, FILTER_VALIDATE_INT);
$garage_filter_raw = $_GET['garage'] ?? '';
$garage_filter = ($garage_filter_raw !== 'none') ? filter_var($garage_filter_raw, FILTER_VALIDATE_INT) : null;
$date_filter = $_GET['date'] ?? '';

$statusToDbFilter = ['planifiee' => 'PLANIFIEE', 'en_cours' => 'EN_COURS', 'terminee' => 'TERMINEE', 'annulee' => 'ANNULEE'];
$where = ['1=1'];
$params = [];
if ($status_filter && isset($statusToDbFilter[$status_filter])) { $where[] = 'i.statut = ?'; $params[] = $statusToDbFilter[$status_filter]; }
if ($technicien_filter) { $where[] = 'i.idTechnicien = ?'; $params[] = $technicien_filter; }
// « none » = demandes jamais affectées ou refusées par un garage (même
// définition que le compteur non_affectees et que le tableau de bord).
if ($garage_filter_raw === 'none') { $where[] = 'i.idTechnicien IS NULL AND (i.idGarage IS NULL OR i.statut = \'ANNULEE\')'; }
elseif ($garage_filter) { $where[] = 'i.idGarage = ?'; $params[] = $garage_filter; }
if ($date_filter) { $where[] = 'DATE(i.dateIntervention) = ?'; $params[] = $date_filter; }
$whereSql = implode(' AND ', $where);

// Anomalie affichée : la plus grave des anomalies ouvertes (NOUVELLE ou
// EN_COURS) liées à l'intervention. Tri : demandes à affecter avec une
// anomalie CRITIQUE ouverte d'abord, puis la plus récente en premier comme avant.
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut, i.priorite,
           i.idTechnicien, i.idGarage,
           v.marque, v.modele, v.immatriculation,
           uc.prenom AS client_prenom, uc.nom AS client_nom,
           ut.prenom AS technicien_prenom, ut.nom AS technicien_nom, t.typeTechnicien,
           g.nomGarage,
           (SELECT a.type FROM anomalie a WHERE a.idIntervention = i.idIntervention AND a.statut IN ('NOUVELLE', 'EN_COURS')
            ORDER BY FIELD(a.niveau, 'CRITIQUE', 'MOYEN', 'FAIBLE'), a.idAnomalie ASC LIMIT 1) AS anomalie_type,
           (SELECT a.niveau FROM anomalie a WHERE a.idIntervention = i.idIntervention AND a.statut IN ('NOUVELLE', 'EN_COURS')
            ORDER BY FIELD(a.niveau, 'CRITIQUE', 'MOYEN', 'FAIBLE'), a.idAnomalie ASC LIMIT 1) AS anomalie_niveau
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur uc ON i.idClient = uc.idUtilisateur
    LEFT JOIN utilisateur ut ON i.idTechnicien = ut.idUtilisateur
    LEFT JOIN technicien t ON t.idTechnicien = i.idTechnicien
    LEFT JOIN garage g ON g.idGarage = i.idGarage
    WHERE $whereSql
    ORDER BY CASE WHEN i.idTechnicien IS NULL AND (i.idGarage IS NULL OR i.statut = 'ANNULEE')
                   AND EXISTS (SELECT 1 FROM anomalie ac WHERE ac.idIntervention = i.idIntervention
                               AND ac.niveau = 'CRITIQUE' AND ac.statut IN ('NOUVELLE', 'EN_COURS'))
              THEN 0 ELSE 1 END,
             i.dateIntervention DESC
    LIMIT 200
");
$stmt->execute($params);
$interventions = $stmt->fetchAll();

$techniciens = $conn->query("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom FROM utilisateur u
    JOIN technicien t ON t.idTechnicien = u.idUtilisateur WHERE t.statutValidation = 'VALIDE' ORDER BY u.nom
")->fetchAll();

// Choix de la fenêtre d'affectation, avec la charge de chacun (revérifiés
// côté serveur par admin_assign()).
$assignChoices = admin_assign_choices($conn);

$garagesList = $conn->query("SELECT idGarage, nomGarage FROM garage WHERE statutGarage = 'VALIDE' ORDER BY nomGarage")->fetchAll();

$vehicles = $conn->query("
    SELECT v.idVehicule AS id, v.marque, v.modele, v.immatriculation, u.prenom AS client_prenom, u.nom AS client_nom
    FROM vehicule v JOIN utilisateur u ON v.idClient = u.idUtilisateur
    ORDER BY v.marque, v.modele
")->fetchAll();

$stats = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN statut = 'PLANIFIEE' THEN 1 ELSE 0 END) AS planifiees,
           SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
           SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminees,
           SUM(CASE WHEN idTechnicien IS NULL AND (idGarage IS NULL OR statut = 'ANNULEE') THEN 1 ELSE 0 END) AS non_affectees
    FROM intervention
")->fetch();

$statutLabels = ['PLANIFIEE' => 'Planifiée', 'EN_COURS' => 'En cours', 'TERMINEE' => 'Terminée', 'ANNULEE' => 'Annulée'];
$statutBadge = ['PLANIFIEE' => 'info', 'EN_COURS' => 'warn', 'TERMINEE' => 'ok', 'ANNULEE' => 'bad'];
// Transitions de statut proposées dans la liste selon le statut actuel
// (aucune pour TERMINEE, qui est un état final).
$nextStatus = ['PLANIFIEE' => ['en_cours' => 'En cours', 'annulee' => 'Annulée'], 'EN_COURS' => ['terminee' => 'Terminée', 'annulee' => 'Annulée'], 'ANNULEE' => ['planifiee' => 'Planifiée']];

$pageTitle = 'Interventions';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'interventions'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="av2-alert success">
                <?php
                $successMsgs = ['created' => 'Intervention créée avec succès.', 'status_updated' => 'Statut mis à jour.', 'assigned' => 'Affectation enregistrée : les personnes concernées ont été notifiées.', 'garage_assigned' => 'Demande affectée au garage avec succès.', 'internal_assigned' => 'Demande affectée au technicien SmartAutoTrack avec succès.'];
                echo h($successMsgs[$_GET['success']] ?? 'Action effectuée.');
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="av2-alert error"><?php echo h($err); ?></div><?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Interventions</h1>
                <p class="av2-sub">Supervision de toutes les interventions de la plateforme, tous garages confondus.</p>
            </div>
            <button type="button" class="av2-btn-primary" id="openNewIntervention">+ Nouvelle intervention</button>
        </div>

        <?php if ((int)$stats['non_affectees'] > 0): ?>
            <div class="av2-card av2-panel" style="background:#FFF4E2; border-color:#F3DFB3;">
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0;"><path d="M12 3L22 20H2L12 3Z" stroke="#C8871A" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#C8871A"/></svg>
                    <div style="flex-grow:1; font-size:13.5px; color:#5C4009;">
                        <strong><?php echo (int)$stats['non_affectees']; ?> demande(s)</strong> en attente d'affectation — à confier à un technicien SmartAutoTrack (avec un garage si besoin) ou à un garage. Le client ne verra sa demande avancer qu'une fois affectée.
                    </div>
                    <a href="interventions.php?garage=none" class="av2-btn-primary av2-btn-xs" style="text-decoration:none;">Voir les demandes à affecter</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="av2-stats">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total interventions</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#C8871A" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['non_affectees']; ?></div><div class="av2-stat-label">Non affectées</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['en_cours']; ?></div><div class="av2-stat-label">En cours</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['terminees']; ?></div><div class="av2-stat-label">Terminées</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <select name="status" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <?php foreach ($statutLabels as $key => $label): ?>
                    <option value="<?php echo h(strtolower($key)); ?>" <?php echo $status_filter === strtolower($key) ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="garage" onchange="this.form.submit()">
                <option value="">Tous les garages</option>
                <option value="none" <?php echo $garage_filter_raw === 'none' ? 'selected' : ''; ?>>⚠ Non affectées</option>
                <?php foreach ($garagesList as $g): ?>
                    <option value="<?php echo (int)$g['idGarage']; ?>" <?php echo $garage_filter === (int)$g['idGarage'] ? 'selected' : ''; ?>><?php echo h($g['nomGarage']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="technicien" onchange="this.form.submit()">
                <option value="">Tous les techniciens</option>
                <?php foreach ($techniciens as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>" <?php echo $technicien_filter === (int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['prenom'] . ' ' . $t['nom']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?php echo h($date_filter); ?>" onchange="this.form.submit()">
            <?php if ($status_filter || $technicien_filter || $garage_filter_raw || $date_filter): ?>
                <a href="interventions.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($interventions)): ?>
                <div class="av2-empty">Aucune intervention ne correspond aux critères sélectionnés.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Garage</th><th>Technicien</th><th>Date</th><th>Priorité</th><th>Statut</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($interventions as $iv):
                                // Demande à affecter : même définition que le compteur non_affectees.
                                $needsGarage = !$iv['technicien_nom'] && (!$iv['nomGarage'] || $iv['statut'] === 'ANNULEE');
                                $isInternal = $iv['typeTechnicien'] === 'INTERNE';
                                // (Ré)affectable tant que l'intervention n'a pas démarré ;
                                // « Réaffecter » si elle est déjà confiée (PLANIFIEE avec
                                // technicien ou garage), sinon « Affecter ».
                                $canAssign = in_array($iv['statut'], ['PLANIFIEE', 'ANNULEE'], true);
                                $isAssigned = $iv['statut'] === 'PLANIFIEE' && ($iv['idTechnicien'] !== null || $iv['idGarage'] !== null);
                            ?>
                                <tr>
                                    <td>
                                        <div class="av2-table-entity">
                                            <div class="av2-table-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#1E7DBF" stroke-width="1.6"/></svg></div>
                                            <?php echo h($iv['type'] ?: 'Intervention'); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($iv['marque'] . ' ' . $iv['modele'] . ' · ' . $iv['immatriculation']); ?></div>
                                        <?php if ($iv['anomalie_niveau']): ?>
                                            <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;">Anomalie : <?php echo h($iv['anomalie_type'] ?: 'type non précisé'); ?></div>
                                            <span class="av2-badge <?php echo h(admin_anomaly_badge($iv['anomalie_niveau'])); ?>" style="display:inline-block; margin-top:4px;"><?php echo h('Gravité : ' . ucfirst(strtolower($iv['anomalie_niveau']))); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($iv['client_prenom'] . ' ' . $iv['client_nom']); ?></td>
                                    <td>
                                        <?php if ($iv['nomGarage']): ?>
                                            <?php echo h($iv['nomGarage']); ?>
                                            <?php if ($needsGarage): ?><div style="font-size:11px; color:#C8871A;">refusée — à réaffecter</div>
                                            <?php endif; ?>
                                        <?php elseif ($needsGarage): ?>
                                            <span class="av2-badge warn">SmartAutoTrack — à affecter</span>
                                        <?php else: ?>
                                            <span class="av2-badge info"><?php echo h($iv['technicien_prenom'] . ' ' . $iv['technicien_nom']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($iv['technicien_nom']): ?>
                                            <?php echo h($iv['technicien_prenom'] . ' ' . $iv['technicien_nom']); ?>
                                            <?php if ($isInternal): ?><div style="font-size:11px; color:#8B90B3;">technicien SmartAutoTrack</div><?php endif; ?>
                                        <?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?>
                                    </td>
                                    <td><?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?></td>
                                    <td><span class="av2-badge <?php echo h($iv['priorite'] === 'HAUTE' ? 'bad' : ($iv['priorite'] === 'BASSE' ? 'neutral' : 'warn')); ?>"><?php echo h(ucfirst(strtolower($iv['priorite']))); ?></span></td>
                                    <td><span class="av2-badge <?php echo h($statutBadge[$iv['statut']] ?? 'neutral'); ?>"><?php echo h($statutLabels[$iv['statut']] ?? $iv['statut']); ?></span></td>
                                    <td>
                                        <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                                            <a href="intervention_detail.php?id=<?php echo (int)$iv['id']; ?>" class="av2-table-link">Détails</a>
                                            <?php if ($canAssign): ?>
                                                <?php // La fenêtre « Affecter la demande » (plus bas) s'ouvre avec l'affectation actuelle présélectionnée,
                                                      // sauf pour une demande refusée (ANNULEE) : rien n'est présélectionné. ?>
                                                <button type="button" class="<?php echo $isAssigned ? 'av2-btn-outline' : 'av2-btn-primary'; ?> av2-btn-xs js-open-assign"
                                                        data-id="<?php echo (int)$iv['id']; ?>"
                                                        data-mode="<?php echo $isAssigned ? 'reassign' : 'assign'; ?>"
                                                        data-technicien="<?php echo h($isAssigned ? ($iv['idTechnicien'] ?? '') : ''); ?>"
                                                        data-garage="<?php echo h($isAssigned ? ($iv['idGarage'] ?? '') : ''); ?>"
                                                        data-summary="<?php echo h(($iv['type'] ?: 'Intervention') . ' — ' . $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ') — ' . $iv['client_prenom'] . ' ' . $iv['client_nom']); ?>"
                                                        data-urgent="<?php echo $iv['anomalie_niveau'] === 'CRITIQUE' ? '1' : '0'; ?>"><?php echo $isAssigned ? 'Réaffecter' : 'Affecter'; ?></button>
                                            <?php endif; ?>
                                            <?php if (!$needsGarage && !empty($nextStatus[$iv['statut']])): ?>
                                                <form method="POST" action="interventions.php?action=update_status&id=<?php echo (int)$iv['id']; ?>" style="display:flex; gap:6px;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <select name="status" style="font-size:12.5px; padding:5px 8px; border-radius:8px; border:1px solid #DDE0F0;">
                                                        <?php foreach ($nextStatus[$iv['statut']] as $key => $label): ?>
                                                            <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="av2-btn-outline av2-btn-xs">OK</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal nouvelle intervention -->
<div class="av2-modal-overlay" id="newInterventionOverlay">
    <div class="av2-modal">
        <h3>Nouvelle intervention</h3>
        <p class="av2-modal-sub">Création et assignation directe par l'administrateur.</p>
        <?php if (!empty($errors) && $action === 'new'): ?><div class="av2-alert error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div><?php endif; ?>
        <form method="POST" action="interventions.php?action=new">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="av2-form-group">
                <label>Véhicule</label>
                <select name="vehicle_id" required>
                    <option value="">Sélectionner un véhicule</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>"><?php echo h($v['marque'] . ' ' . $v['modele'] . ' (' . $v['immatriculation'] . ') — ' . $v['client_prenom'] . ' ' . $v['client_nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="av2-form-group">
                <label>Technicien</label>
                <select name="technicien_id" required>
                    <option value="">Sélectionner un technicien</option>
                    <?php foreach ($techniciens as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['prenom'] . ' ' . $t['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="av2-form-group"><label>Type d'intervention</label><input type="text" name="type_intervention" required placeholder="Ex. Diagnostic, entretien…"></div>
            <div class="av2-form-group"><label>Description</label><textarea name="description" placeholder="Ex. Bruit métallique au freinage à l'avant…"></textarea></div>
            <div class="av2-form-group"><label>Date planifiée</label><input type="datetime-local" name="date_planifiee" required></div>
            <div class="av2-modal-actions">
                <button type="button" class="av2-btn-outline" id="closeNewIntervention">Annuler</button>
                <button type="submit" class="av2-btn-primary">Créer l'intervention</button>
            </div>
        </form>
    </div>
</div>

<!-- Fenêtre « Affecter la demande » / « Réaffecter l'intervention » : deux
     listes facultatives (technicien, garage — au moins l'un des deux), avec
     la charge de chacun ; une seule action « assign », traitée en haut de
     page par admin_assign(). L'affectation actuelle est présélectionnée à
     l'ouverture (data-technicien / data-garage du bouton de la ligne), sauf
     pour une demande refusée (ANNULEE), ouverte sans présélection. -->
<div class="av2-modal-overlay" id="assignOverlay">
    <div class="av2-modal" role="dialog" aria-modal="true" aria-labelledby="assignTitle">
        <h3 id="assignTitle">Affecter la demande</h3>
        <p class="av2-modal-sub" id="assignSummary"></p>
        <p class="av2-alert error" id="assignUrgent" hidden style="margin-bottom:14px;">Urgent : anomalie critique, véhicule immobilisé ou dangereux.</p>
        <form method="POST" id="assignForm" action="interventions.php?action=assign" class="js-assign-form">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="intervention_id" id="assignInterventionId" value="">

            <?php admin_assign_fields($assignChoices, 'assign'); ?>

            <div class="av2-modal-actions">
                <button type="button" class="av2-btn-outline" id="closeAssign">Annuler</button>
                <button type="submit" class="av2-btn-primary" id="assignSubmit">Affecter</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Fenêtre « Affecter la demande » / « Réaffecter l'intervention »
    var assignOverlay = document.getElementById('assignOverlay');
    var assignForm = document.getElementById('assignForm');
    var techSelect = assignForm.querySelector('[data-assign-tech]');
    var garageSelect = assignForm.querySelector('[data-assign-garage]');
    var assignError = assignForm.querySelector('[data-assign-error]');
    // Un technicien de garage est affecté avec son garage : on le présélectionne.
    techSelect.addEventListener('change', function () {
        var option = techSelect.options[techSelect.selectedIndex];
        if (option && option.getAttribute('data-garage')) garageSelect.value = option.getAttribute('data-garage');
        assignError.hidden = true;
    });
    garageSelect.addEventListener('change', function () { assignError.hidden = true; });
    // Validation légère : au moins un choix (la vraie validation reste serveur).
    assignForm.addEventListener('submit', function (e) {
        if (!techSelect.value && !garageSelect.value) {
            e.preventDefault();
            assignError.hidden = false;
        }
    });
    document.querySelectorAll('.js-open-assign').forEach(function (button) {
        button.addEventListener('click', function () {
            var reassign = button.getAttribute('data-mode') === 'reassign';
            assignForm.reset();
            assignError.hidden = true;
            document.getElementById('assignInterventionId').value = button.getAttribute('data-id');
            document.getElementById('assignTitle').textContent = reassign ? 'Réaffecter l\'intervention' : 'Affecter la demande';
            document.getElementById('assignSubmit').textContent = reassign ? 'Réaffecter' : 'Affecter';
            document.getElementById('assignSummary').textContent = button.getAttribute('data-summary');
            document.getElementById('assignUrgent').hidden = button.getAttribute('data-urgent') !== '1';
            // Affectation actuelle présélectionnée (vide si absente des listes).
            techSelect.value = button.getAttribute('data-technicien') || '';
            garageSelect.value = button.getAttribute('data-garage') || '';
            if (techSelect.selectedIndex < 0) techSelect.value = '';
            if (garageSelect.selectedIndex < 0) garageSelect.value = '';
            assignOverlay.classList.add('show');
        });
    });
    document.getElementById('closeAssign').addEventListener('click', function () { assignOverlay.classList.remove('show'); });
    assignOverlay.addEventListener('click', function (e) { if (e.target === assignOverlay) assignOverlay.classList.remove('show'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') assignOverlay.classList.remove('show'); });

    var overlay = document.getElementById('newInterventionOverlay');
    document.getElementById('openNewIntervention').addEventListener('click', function () { overlay.classList.add('show'); });
    document.getElementById('closeNewIntervention').addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });
    // Modale rouverte seulement pour une erreur de création (pas d'affectation).
    <?php if (!empty($errors) && $action === 'new'): ?>overlay.classList.add('show');<?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
