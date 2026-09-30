<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Tableau de bord.
 *
 * Accès : rôle « garage ».
 * Page en lecture seule : cartes statistiques, donut des interventions par
 * statut, tendance sur 7 jours, demandes non affectées, charge de travail
 * des techniciens et nombre de messages non lus. Toutes les requêtes sont
 * bornées au garage connecté (idGarage issu de son profil).
 * Tables lues : intervention, reparation, technicien, utilisateur, messages.
 * Liés : garage/includes/helpers.php (gv2_donut_svg, gv2_trend_svg),
 *        garage/includes/sidebar.php.
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);
$garageNom = $profile['nomGarage'] ?? 'Garage';

// Heure MySQL : les durées relatives (v2_relative) sont calculées sur la même horloge que les dates stockées.
$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// ============================================================
// Cartes statistiques — uniquement des données réelles du garage connecté
// ============================================================
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NOT NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$interventionsPlanifiees = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND statut = 'EN_COURS'");
$stmt->execute([$garageId]);
$interventionsEnCours = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(*) FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    WHERE i.idGarage = ? AND r.statut = 'TERMINEE'
");
$stmt->execute([$garageId]);
$reparationsTerminees = (int)$stmt->fetchColumn();

// ============================================================
// Donut : répartition des interventions du garage par statut
// ============================================================
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN statut = 'PLANIFIEE' AND idTechnicien IS NULL THEN 1 ELSE 0 END) AS nouvelle,
        SUM(CASE WHEN statut = 'PLANIFIEE' AND idTechnicien IS NOT NULL THEN 1 ELSE 0 END) AS planifiee,
        SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminee
    FROM intervention WHERE idGarage = ?
");
$stmt->execute([$garageId]);
$repartition = $stmt->fetch();
$donutSegments = [
    ['label' => 'Nouvelles demandes', 'value' => (int)($repartition['nouvelle'] ?? 0), 'color' => '#1E7DBF'],
    ['label' => 'Planifiées', 'value' => (int)($repartition['planifiee'] ?? 0), 'color' => '#5C7276'],
    ['label' => 'En cours', 'value' => (int)($repartition['en_cours'] ?? 0), 'color' => '#C8871A'],
    ['label' => 'Terminées', 'value' => (int)($repartition['terminee'] ?? 0), 'color' => '#1E8A5A'],
];
$donutTotal = array_sum(array_column($donutSegments, 'value'));

// ============================================================
// Tendance : interventions du garage sur les 7 derniers jours
// ============================================================
$stmt = $conn->prepare("
    SELECT DATE(dateIntervention) AS jour, COUNT(*) AS total
    FROM intervention
    WHERE idGarage = ? AND dateIntervention >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(dateIntervention)
");
$stmt->execute([$garageId]);
$trendRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendValues = [];
$trendLabels = [];
// 7 points exactement : un jour sans intervention vaut 0.
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $trendValues[] = (int)($trendRows[$day] ?? 0);
    $trendLabels[] = date('d/m', strtotime($day));
}

// ============================================================
// Demandes récentes non affectées
// ============================================================
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, i.priorite,
           v.marque, v.modele, v.immatriculation,
           u.nom AS client_nom, u.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE i.idGarage = ? AND i.idTechnicien IS NULL AND i.statut = 'PLANIFIEE'
    ORDER BY i.dateIntervention ASC
    LIMIT 5
");
$stmt->execute([$garageId]);
$demandesRecentes = $stmt->fetchAll();

// ============================================================
// Charge de travail par technicien (interventions actives)
// ============================================================
$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom,
           SUM(CASE WHEN i.statut IN ('PLANIFIEE', 'EN_COURS') THEN 1 ELSE 0 END) AS actives
    FROM technicien t
    JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
    LEFT JOIN intervention i ON i.idTechnicien = t.idTechnicien AND i.idGarage = ?
    WHERE t.idGarage = ?
    GROUP BY u.idUtilisateur, u.nom, u.prenom
    ORDER BY actives DESC
    LIMIT 6
");
$stmt->execute([$garageId, $garageId]);
$technicienCharge = $stmt->fetchAll();
$maxCharge = max(1, ...array_map(fn($t) => (int)$t['actives'], $technicienCharge ?: [['actives' => 0]]));

// Messages non lus
$stmt = $conn->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND lu = 'non'");
$stmt->execute([$_SESSION['user_id']]);
$messagesNonLus = (int)$stmt->fetchColumn();

$pageTitle = 'Tableau de bord';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'dashboard'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">

        <div class="gv2-topbar">
            <div>
                <div class="gv2-kicker">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>
                    Centre de gestion des interventions
                </div>
                <h1 class="gv2-h1">Bonjour, Garage <?php echo h($garageNom); ?></h1>
                <p class="gv2-sub">Voici l'activité de votre garage — <?php echo h(v2_today_fr()); ?></p>
            </div>
            <div class="gv2-topbar-actions">
                <div class="gv2-notif-wrap">
                    <button class="gv2-iconbtn" id="notificationBtn" aria-label="Notifications" type="button">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M6 10C6 6.7 8.7 4 12 4C15.3 4 18 6.7 18 10V14L20 17H4L6 14V10Z" stroke="#12262A" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20C10.4 20.8 11.1 21.3 12 21.3C12.9 21.3 13.6 20.8 14 20" stroke="#12262A" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <span class="gv2-notif-dot" id="notificationCounter"></span>
                    </button>
                    <div class="gv2-notif-panel" id="notificationPanel">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                            <button class="mark-all-read" id="markAllRead" type="button">Tout marquer comme lu</button>
                        </div>
                        <div class="notification-list" id="notificationList"></div>
                    </div>
                </div>
                <div class="gv2-topbar-avatar"><?php echo h(mb_strtoupper(mb_substr($garageNom, 0, 1))); ?></div>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="gv2-stats">
            <div class="gv2-card gv2-stat-card">
                <div class="gv2-stat-icon" style="background:#E7F3FC;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L8 11M12 15L16 11" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V17" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="gv2-stat-value"><?php echo (int)$demandesEnAttente; ?></div>
                    <div class="gv2-stat-label">Demande<?php echo $demandesEnAttente > 1 ? 's' : ''; ?> en attente</div>
                </div>
            </div>
            <div class="gv2-card gv2-stat-card">
                <div class="gv2-stat-icon" style="background:#EEF2F2;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#5C7276" stroke-width="1.8"/><path d="M8 8H16M8 12H16M8 16H12" stroke="#5C7276" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="gv2-stat-value"><?php echo (int)$interventionsPlanifiees; ?></div>
                    <div class="gv2-stat-label">Planifiée<?php echo $interventionsPlanifiees > 1 ? 's' : ''; ?></div>
                </div>
            </div>
            <div class="gv2-card gv2-stat-card">
                <div class="gv2-stat-icon" style="background:#FFF4E2;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="gv2-stat-value"><?php echo (int)$interventionsEnCours; ?></div>
                    <div class="gv2-stat-label">En cours</div>
                </div>
            </div>
            <div class="gv2-card gv2-stat-card">
                <div class="gv2-stat-icon" style="background:#E4F7EE;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#1E8A5A" stroke-width="1.8"/><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A5A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="gv2-stat-value"><?php echo (int)$reparationsTerminees; ?></div>
                    <div class="gv2-stat-label">Réparations terminées</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="gv2-chart-row">
            <div class="gv2-card gv2-panel">
                <div class="gv2-panel-head"><h2>Activité — 7 derniers jours</h2></div>
                <?php if ($donutTotal === 0): ?>
                    <div class="gv2-empty">Aucune intervention enregistrée pour le moment.</div>
                <?php else: ?>
                    <?php echo gv2_trend_svg($trendValues, 560, 110); ?>
                    <div style="display:flex; justify-content:space-between; margin-top:6px;">
                        <?php foreach ($trendLabels as $l): ?><span style="font-size:11px; color:#8AA0A3;"><?php echo h($l); ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="gv2-card gv2-panel">
                <div class="gv2-panel-head"><h2>Répartition des interventions</h2></div>
                <?php if ($donutTotal === 0): ?>
                    <div class="gv2-empty">Aucune donnée pour le moment.</div>
                <?php else: ?>
                    <div class="gv2-donut-wrap">
                        <div style="position:relative; flex-shrink:0;">
                            <?php echo gv2_donut_svg(array_map(fn($s) => ['value' => $s['value'], 'color' => $s['color']], $donutSegments), 130, 18); ?>
                            <div style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                <div class="gv2-donut-center-value"><?php echo (int)$donutTotal; ?></div>
                                <div class="gv2-donut-center-label">Total</div>
                            </div>
                        </div>
                        <div class="gv2-donut-legend">
                            <?php foreach ($donutSegments as $seg): if ($seg['value'] <= 0) continue; ?>
                                <div class="gv2-donut-legend-item">
                                    <span class="gv2-donut-legend-dot" style="background:<?php echo h($seg['color']); ?>;"></span>
                                    <span class="gv2-donut-legend-label"><?php echo h($seg['label']); ?></span>
                                    <span class="gv2-donut-legend-value"><?php echo (int)$seg['value']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="gv2-body">
            <div class="gv2-col">
                <!-- Demandes récentes -->
                <div class="gv2-card gv2-panel">
                    <div class="gv2-panel-head">
                        <h2>Demandes d'intervention récentes</h2>
                        <a href="demandes.php" class="gv2-link">Voir toutes →</a>
                    </div>
                    <?php if (empty($demandesRecentes)): ?>
                        <div class="gv2-empty">Aucune demande en attente pour le moment.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($demandesRecentes as $d): ?>
                                <div class="gv2-row pending">
                                    <div class="gv2-row-icon" style="background:#E7F3FC;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L8 11M12 15L16 11" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V17" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    </div>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="gv2-row-title"><?php echo h($d['type'] ?: 'Intervention'); ?> — <?php echo h($d['marque'] . ' ' . $d['modele']); ?></div>
                                        <div class="gv2-row-meta"><?php echo h($d['client_prenom'] . ' ' . $d['client_nom']); ?> · <?php echo h($d['immatriculation']); ?> · demandée <?php echo h(v2_relative($d['dateIntervention'], $dbNow)); ?></div>
                                    </div>
                                    <a href="demandes.php" class="gv2-btn-primary gv2-btn-xs" style="text-decoration:none;">Traiter</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gv2-col">
                <!-- Charge des techniciens -->
                <div class="gv2-card gv2-panel-sm">
                    <div class="gv2-panel-head">
                        <h2>Charge des techniciens</h2>
                        <a href="techniciens.php" class="gv2-link">Voir tous →</a>
                    </div>
                    <?php if (empty($technicienCharge)): ?>
                        <div class="gv2-empty">Aucun technicien enregistré pour ce garage.</div>
                    <?php else: ?>
                        <div class="gv2-bars">
                            <?php foreach ($technicienCharge as $t): $actives = (int)$t['actives']; ?>
                                <div class="gv2-bar-row">
                                    <div class="gv2-bar-label"><?php echo h($t['prenom'] . ' ' . $t['nom']); ?></div>
                                    <div class="gv2-bar-track"><div class="gv2-bar-fill" style="width:<?php echo round(($actives / $maxCharge) * 100); ?>%;"></div></div>
                                    <div class="gv2-bar-value"><?php echo (int)$actives; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Messages -->
                <div class="gv2-card gv2-panel-sm">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#12262A" stroke-width="1.8"/><path d="M3 6.5L12 13L21 6.5" stroke="#12262A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:15px; font-weight:700;">Messages</h2>
                    </div>
                    <p style="margin:0 0 14px; font-size:13px; line-height:1.5; color:#5C7276;"><?php echo (int)$messagesNonLus; ?> message<?php echo $messagesNonLus > 1 ? 's' : ''; ?> non lu<?php echo $messagesNonLus > 1 ? 's' : ''; ?> de vos clients.</p>
                    <a href="<?php echo SITE_URL; ?>messages/index.php" class="gv2-btn-dark" style="display:block; text-align:center; box-sizing:border-box; text-decoration:none;">Ouvrir la messagerie</a>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var notifBtn = document.getElementById('notificationBtn');
    var notifPanel = document.getElementById('notificationPanel');
    if (notifBtn) {
        notifBtn.addEventListener('click', function (e) { e.stopPropagation(); notifPanel.style.display = (notifPanel.style.display === 'block') ? 'none' : 'block'; });
        document.addEventListener('click', function () { notifPanel.style.display = 'none'; });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
