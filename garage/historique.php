<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Historique : interventions terminées ou annulées,
 * réparations et anomalies liées au garage.
 *
 * Accès : rôle « garage ».
 * Page en lecture seule. Filtre GET optionnel date_from / date_to (Y-m-d) ;
 * chaque liste est limitée aux 100 éléments les plus récents.
 * Tables lues : intervention, reparation, anomalie, vehicule, utilisateur.
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);

// Période optionnelle : une date mal formée est ignorée (pas de filtre).
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom) ?: null;
$dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo) ?: null;

// Interventions terminées ou annulées
$where = ["i.idGarage = ?", "i.statut IN ('TERMINEE', 'ANNULEE')"];
$params = [$garageId];
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

// Réparations
$where2 = ["i.idGarage = ?"];
$params2 = [$garageId];
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

// Anomalies constatées par ce garage
$where3 = ["EXISTS (SELECT 1 FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.idGarage = ?)"];
$params3 = [$garageId];
if ($dateFromObj) { $where3[] = 'a.dateDetection >= ?'; $params3[] = $dateFromObj->format('Y-m-d') . ' 00:00:00'; }
if ($dateToObj) { $where3[] = 'a.dateDetection <= ?'; $params3[] = $dateToObj->format('Y-m-d') . ' 23:59:59'; }
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description AS type, a.dateDetection AS date_event, a.statut, a.niveau,
           v.marque, v.modele, v.immatriculation, u.nom AS client_nom, u.prenom AS client_prenom
    FROM anomalie a
    JOIN vehicule v ON a.idVehicule = v.idVehicule
    JOIN utilisateur u ON v.idClient = u.idUtilisateur
    WHERE " . implode(' AND ', $where3) . "
    ORDER BY a.dateDetection DESC
    LIMIT 100
");
$stmt->execute($params3);
$anomaliesHisto = $stmt->fetchAll();

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$pageTitle = 'Historique';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'historique'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">
        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Historique</h1>
                <p class="gv2-sub">Interventions, réparations et anomalies passées de votre garage.</p>
            </div>
        </div>

        <form method="GET" class="gv2-filterbar">
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()" placeholder="Depuis">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()" placeholder="Jusqu'au">
            <?php if ($dateFrom || $dateTo): ?>
                <a href="historique.php" class="gv2-btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="gv2-body">
            <div class="gv2-col">
                <div class="gv2-card gv2-panel">
                    <div class="gv2-panel-head"><h2>Interventions (terminées / annulées)</h2></div>
                    <?php if (empty($interventionsHisto)): ?>
                        <div class="gv2-empty">Aucune intervention dans cette période.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($interventionsHisto as $iv): ?>
                                <div class="gv2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="gv2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?></div>
                                        <div class="gv2-row-meta"><?php echo h($iv['client_prenom'] . ' ' . $iv['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($iv['date_event']))); ?></div>
                                    </div>
                                    <span class="gv2-badge <?php echo $iv['statut'] === 'TERMINEE' ? 'ok' : 'bad'; ?>"><?php echo $iv['statut'] === 'TERMINEE' ? 'Terminée' : 'Annulée'; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="gv2-card gv2-panel">
                    <div class="gv2-panel-head"><h2>Réparations</h2></div>
                    <?php if (empty($reparationsHisto)): ?>
                        <div class="gv2-empty">Aucune réparation dans cette période.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($reparationsHisto as $r): ?>
                                <div class="gv2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="gv2-row-title"><?php echo h($r['type'] ?: 'Réparation'); ?> — <?php echo h($r['marque'] . ' ' . $r['modele']); ?></div>
                                        <div class="gv2-row-meta"><?php echo h($r['client_prenom'] . ' ' . $r['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($r['date_event']))); ?> · <?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</div>
                                    </div>
                                    <span class="gv2-badge ok">Terminée</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gv2-col">
                <div class="gv2-card gv2-panel-sm">
                    <div class="gv2-panel-head"><h2>Anomalies</h2></div>
                    <?php if (empty($anomaliesHisto)): ?>
                        <div class="gv2-empty">Aucune anomalie dans cette période.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($anomaliesHisto as $a): $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true); ?>
                                <div>
                                    <div style="font-size:13px; font-weight:600;"><?php echo h($a['marque'] . ' ' . $a['modele']); ?></div>
                                    <div style="font-size:12px; color:#5C7276; margin:2px 0;"><?php echo h($a['type']); ?></div>
                                    <div style="font-size:11.5px; color:#8AA0A3;"><?php echo h(date('d/m/Y', strtotime($a['date_event']))); ?> · <span class="gv2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo $isActive ? 'Active' : 'Résolue'; ?></span></div>
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
