<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace technicien — Mon historique : interventions terminées ou annulées,
 * réparations enregistrées et anomalies constatées.
 *
 * Accès : rôle « technicien ».
 * Page en lecture seule. Filtre GET optionnel date_from / date_to (Y-m-d) ;
 * chaque liste est limitée aux 100 éléments les plus récents.
 * Tables lues : intervention, reparation, anomalie, vehicule, utilisateur.
 */

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

// Période optionnelle : une date mal formée est ignorée (pas de filtre).
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom) ?: null;
$dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo) ?: null;

// Interventions terminées ou annulées qui m'étaient assignées
$where = ["i.idTechnicien = ?", "i.statut IN ('TERMINEE', 'ANNULEE')"];
$params = [$selfId];
if ($dateFromObj) { $where[] = 'i.dateIntervention >= ?'; $params[] = $dateFromObj->format('Y-m-d') . ' 00:00:00'; }
if ($dateToObj) { $where[] = 'i.dateIntervention <= ?'; $params[] = $dateToObj->format('Y-m-d') . ' 23:59:59'; }
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention AS date_event, i.statut,
           v.marque, v.modele, v.immatriculation, u.nom AS client_nom, u.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.dateIntervention DESC
    LIMIT 100
");
$stmt->execute($params);
$interventionsHisto = $stmt->fetchAll();

// Réparations que j'ai enregistrées
$where2 = ["r.idTechnicien = ?"];
$params2 = [$selfId];
if ($dateFromObj) { $where2[] = 'r.dateReparation >= ?'; $params2[] = $dateFromObj->format('Y-m-d') . ' 00:00:00'; }
if ($dateToObj) { $where2[] = 'r.dateReparation <= ?'; $params2[] = $dateToObj->format('Y-m-d') . ' 23:59:59'; }
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre AS type, r.dateReparation AS date_event, r.statut, r.cout,
           v.marque, v.modele, v.immatriculation, u.nom AS client_nom, u.prenom AS client_prenom
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE " . implode(' AND ', $where2) . "
    ORDER BY r.dateReparation DESC
    LIMIT 100
");
$stmt->execute($params2);
$reparationsHisto = $stmt->fetchAll();

// Anomalies que j'ai constatées (via mes interventions)
$where3 = ["EXISTS (SELECT 1 FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.idTechnicien = ?)"];
$params3 = [$selfId];
if ($dateFromObj) { $where3[] = 'a.dateDetection >= ?'; $params3[] = $dateFromObj->format('Y-m-d') . ' 00:00:00'; }
if ($dateToObj) { $where3[] = 'a.dateDetection <= ?'; $params3[] = $dateToObj->format('Y-m-d') . ' 23:59:59'; }
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description AS type, a.dateDetection AS date_event, a.statut, a.niveau,
           v.marque, v.modele, v.immatriculation
    FROM anomalie a
    JOIN vehicule v ON a.idVehicule = v.idVehicule
    WHERE " . implode(' AND ', $where3) . "
    ORDER BY a.dateDetection DESC
    LIMIT 100
");
$stmt->execute($params3);
$anomaliesHisto = $stmt->fetchAll();

// Compteur du badge « Mes tâches » de la sidebar (tâches à démarrer).
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

$pageTitle = 'Mon historique';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'historique'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">
        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Mon historique</h1>
                <p class="tv2-sub">Vos interventions, réparations et anomalies passées.</p>
            </div>
        </div>

        <form method="GET" class="tv2-filterbar">
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()">
            <?php if ($dateFrom || $dateTo): ?>
                <a href="historique.php" class="tv2-btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="tv2-body">
            <div class="tv2-col">
                <div class="tv2-card tv2-panel">
                    <div class="tv2-panel-head"><h2>Interventions (terminées / annulées)</h2></div>
                    <?php if (empty($interventionsHisto)): ?>
                        <div class="tv2-empty">Aucune intervention dans cette période.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($interventionsHisto as $iv): ?>
                                <div class="tv2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="tv2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?></div>
                                        <div class="tv2-row-meta"><?php echo h($iv['client_prenom'] . ' ' . $iv['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($iv['date_event']))); ?></div>
                                    </div>
                                    <span class="tv2-badge <?php echo $iv['statut'] === 'TERMINEE' ? 'ok' : 'bad'; ?>"><?php echo $iv['statut'] === 'TERMINEE' ? 'Terminée' : 'Annulée'; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tv2-card tv2-panel">
                    <div class="tv2-panel-head"><h2>Réparations</h2></div>
                    <?php if (empty($reparationsHisto)): ?>
                        <div class="tv2-empty">Aucune réparation dans cette période.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($reparationsHisto as $r): ?>
                                <div class="tv2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="tv2-row-title"><?php echo h($r['type'] ?: 'Réparation'); ?> — <?php echo h($r['marque'] . ' ' . $r['modele']); ?></div>
                                        <div class="tv2-row-meta"><?php echo h($r['client_prenom'] . ' ' . $r['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($r['date_event']))); ?> · <?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</div>
                                    </div>
                                    <span class="tv2-badge ok">Terminée</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tv2-col">
                <div class="tv2-card tv2-panel-sm">
                    <div class="tv2-panel-head"><h2>Anomalies</h2></div>
                    <?php if (empty($anomaliesHisto)): ?>
                        <div class="tv2-empty">Aucune anomalie dans cette période.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($anomaliesHisto as $a): $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true); ?>
                                <div>
                                    <div style="font-size:13px; font-weight:600;"><?php echo h($a['marque'] . ' ' . $a['modele']); ?></div>
                                    <div style="font-size:12px; color:#7A6A57; margin:2px 0;"><?php echo h($a['type']); ?></div>
                                    <div style="font-size:11.5px; color:#A5977F;"><?php echo h(date('d/m/Y', strtotime($a['date_event']))); ?> · <span class="tv2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo $isActive ? 'Active' : 'Résolue'; ?></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
