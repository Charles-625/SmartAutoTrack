<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('technicien');

// Compte en attente de validation : accès bloqué avant validation admin
// (même garde-fou que l'ancien dashboard.php, conservé à l'identique).
if (($_SESSION['statut'] ?? '') === 'pending') {
    $pageTitle = 'Compte en attente';
    include '../includes/header.php';
    ?>
    <div class="empty-state" style="padding: 4rem 2rem; text-align: center;">
        <i class="fas fa-hourglass-half" style="font-size: 3rem; color: var(--warning-color); margin-bottom: 1rem;"></i>
        <h2>Votre compte est en cours de validation</h2>
        <p style="color: var(--text-light); margin-top: 0.5rem;">Un administrateur doit valider votre compte technicien avant que vous puissiez accéder à votre espace de travail.</p>
    </div>
    <?php include '../includes/footer.php'; ?>
    <?php exit; ?>
<?php }

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// ============================================================
// Cartes statistiques — uniquement des données réelles de CE technicien
// ============================================================
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'EN_COURS'");
$stmt->execute([$selfId]);
$interventionsEnCours = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT COUNT(*) FROM reparation WHERE idTechnicien = ? AND MONTH(dateReparation) = MONTH(CURDATE()) AND YEAR(dateReparation) = YEAR(CURDATE())");
$stmt->execute([$selfId]);
$reparationsCeMois = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT a.idAnomalie) FROM anomalie a
    JOIN intervention i ON i.idIntervention = a.idIntervention
    WHERE i.idTechnicien = ?
");
$stmt->execute([$selfId]);
$anomaliesConstatees = (int)$stmt->fetchColumn();

// ============================================================
// Donut : répartition de MES interventions par statut
// ============================================================
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN statut = 'PLANIFIEE' THEN 1 ELSE 0 END) AS a_demarrer,
        SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminee,
        SUM(CASE WHEN statut = 'ANNULEE' THEN 1 ELSE 0 END) AS annulee
    FROM intervention WHERE idTechnicien = ?
");
$stmt->execute([$selfId]);
$repartition = $stmt->fetch();
$donutSegments = [
    ['label' => 'À démarrer', 'value' => (int)($repartition['a_demarrer'] ?? 0), 'color' => '#1E7DBF'],
    ['label' => 'En cours', 'value' => (int)($repartition['en_cours'] ?? 0), 'color' => '#C8871A'],
    ['label' => 'Terminées', 'value' => (int)($repartition['terminee'] ?? 0), 'color' => '#1E8A5A'],
    ['label' => 'Annulées', 'value' => (int)($repartition['annulee'] ?? 0), 'color' => '#A5977F'],
];
$donutTotal = array_sum(array_column($donutSegments, 'value'));

// ============================================================
// Tendance : mon activité sur les 7 derniers jours (interventions)
// ============================================================
$stmt = $conn->prepare("
    SELECT DATE(dateIntervention) AS jour, COUNT(*) AS total
    FROM intervention
    WHERE idTechnicien = ? AND dateIntervention >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(dateIntervention)
");
$stmt->execute([$selfId]);
$trendRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendValues = [];
$trendLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $trendValues[] = (int)($trendRows[$day] ?? 0);
    $trendLabels[] = date('d/m', strtotime($day));
}

// ============================================================
// Mes tâches à traiter (à démarrer ou en cours), les plus proches d'abord
// ============================================================
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, i.statut, i.priorite,
           v.marque, v.modele, v.immatriculation
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    WHERE i.idTechnicien = ? AND i.statut IN ('PLANIFIEE', 'EN_COURS')
    ORDER BY FIELD(i.statut, 'EN_COURS', 'PLANIFIEE'), i.dateIntervention ASC
    LIMIT 5
");
$stmt->execute([$selfId]);
$tachesRecentes = $stmt->fetchAll();

// Dernières activités (aperçu du journal, cloisonné à CE technicien)
$journalRecent = activity_log_fetch($conn, 'technicien', $selfId, [], ['limit' => 4]);

// Messages non lus
$stmt = $conn->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND lu = 'non'");
$stmt->execute([$selfId]);
$messagesNonLus = (int)$stmt->fetchColumn();

$pageTitle = 'Tableau de bord';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'dashboard'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">

        <div class="tv2-topbar">
            <div>
                <div class="tv2-kicker">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>
                    Espace technicien
                </div>
                <h1 class="tv2-h1">Bonjour, <?php echo h($_SESSION['prenom'] ?? ''); ?></h1>
                <p class="tv2-sub">Voici votre activité — <?php echo h(v2_today_fr()); ?></p>
            </div>
            <div class="tv2-topbar-actions">
                <div class="tv2-notif-wrap">
                    <button class="tv2-iconbtn" id="notificationBtn" aria-label="Notifications" type="button">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M6 10C6 6.7 8.7 4 12 4C15.3 4 18 6.7 18 10V14L20 17H4L6 14V10Z" stroke="#2B2116" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20C10.4 20.8 11.1 21.3 12 21.3C12.9 21.3 13.6 20.8 14 20" stroke="#2B2116" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <span class="tv2-notif-dot" id="notificationCounter"></span>
                    </button>
                    <div class="tv2-notif-panel" id="notificationPanel">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                            <button class="mark-all-read" id="markAllRead" type="button">Tout marquer comme lu</button>
                        </div>
                        <div class="notification-list" id="notificationList"></div>
                    </div>
                </div>
                <div class="tv2-topbar-avatar"><?php echo h(mb_strtoupper(mb_substr($_SESSION['prenom'] ?? '?', 0, 1))); ?></div>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="tv2-stats">
            <div class="tv2-card tv2-stat-card">
                <div class="tv2-stat-icon" style="background:#E7F3FC;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#1E7DBF" stroke-width="1.8"/><path d="M8.5 12L11 14.5L16 9" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="tv2-stat-value"><?php echo (int)$tachesADemarrer; ?></div>
                    <div class="tv2-stat-label">Tâche<?php echo $tachesADemarrer > 1 ? 's' : ''; ?> à démarrer</div>
                </div>
            </div>
            <div class="tv2-card tv2-stat-card">
                <div class="tv2-stat-icon" style="background:#FFF4E2;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="tv2-stat-value"><?php echo (int)$interventionsEnCours; ?></div>
                    <div class="tv2-stat-label">En cours</div>
                </div>
            </div>
            <div class="tv2-card tv2-stat-card">
                <div class="tv2-stat-icon" style="background:#E4F7EE;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#1E8A5A" stroke-width="1.7" stroke-linejoin="round"/><path d="M12.5 6.5L17.5 11.5" stroke="#1E8A5A" stroke-width="1.7"/></svg>
                </div>
                <div>
                    <div class="tv2-stat-value"><?php echo (int)$reparationsCeMois; ?></div>
                    <div class="tv2-stat-label">Réparations ce mois</div>
                </div>
            </div>
            <div class="tv2-card tv2-stat-card">
                <div class="tv2-stat-icon" style="background:#FDEDEE;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#E5484D" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#E5484D"/></svg>
                </div>
                <div>
                    <div class="tv2-stat-value"><?php echo (int)$anomaliesConstatees; ?></div>
                    <div class="tv2-stat-label">Anomalies constatées</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="tv2-chart-row">
            <div class="tv2-card tv2-panel">
                <div class="tv2-panel-head"><h2>Mon activité — 7 derniers jours</h2></div>
                <?php if (array_sum($trendValues) === 0): ?>
                    <div class="tv2-empty">Aucune intervention enregistrée récemment.</div>
                <?php else: ?>
                    <?php echo tv2_trend_svg($trendValues, 560, 110); ?>
                    <div style="display:flex; justify-content:space-between; margin-top:6px;">
                        <?php foreach ($trendLabels as $l): ?><span style="font-size:11px; color:#A5977F;"><?php echo h($l); ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="tv2-card tv2-panel">
                <div class="tv2-panel-head"><h2>Répartition de mes interventions</h2></div>
                <?php if ($donutTotal === 0): ?>
                    <div class="tv2-empty">Aucune donnée pour le moment.</div>
                <?php else: ?>
                    <div class="tv2-donut-wrap">
                        <div style="position:relative; flex-shrink:0;">
                            <?php echo tv2_donut_svg(array_map(fn($s) => ['value' => $s['value'], 'color' => $s['color']], $donutSegments), 130, 18); ?>
                            <div style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                <div class="tv2-donut-center-value"><?php echo (int)$donutTotal; ?></div>
                                <div class="tv2-donut-center-label">Total</div>
                            </div>
                        </div>
                        <div class="tv2-donut-legend">
                            <?php foreach ($donutSegments as $seg): if ($seg['value'] <= 0) continue; ?>
                                <div class="tv2-donut-legend-item">
                                    <span class="tv2-donut-legend-dot" style="background:<?php echo h($seg['color']); ?>;"></span>
                                    <span class="tv2-donut-legend-label"><?php echo h($seg['label']); ?></span>
                                    <span class="tv2-donut-legend-value"><?php echo (int)$seg['value']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tv2-body">
            <div class="tv2-col">
                <!-- Mes tâches -->
                <div class="tv2-card tv2-panel">
                    <div class="tv2-panel-head">
                        <h2>Mes tâches</h2>
                        <a href="taches.php" class="tv2-link">Voir toutes →</a>
                    </div>
                    <?php if (empty($tachesRecentes)): ?>
                        <div class="tv2-empty">Aucune tâche à traiter pour le moment.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($tachesRecentes as $t): $info = tv2_status_info($t); ?>
                                <div class="tv2-row <?php echo $info['key'] === 'planifiee' ? 'pending' : ''; ?>">
                                    <div class="tv2-row-icon" style="background:#E7F3FC;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#1E7DBF" stroke-width="1.8"/><path d="M8 8H16M8 12H16M8 16H12" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    </div>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="tv2-row-title"><?php echo h($t['type'] ?: 'Intervention'); ?> — <?php echo h($t['marque'] . ' ' . $t['modele']); ?></div>
                                        <div class="tv2-row-meta"><?php echo h($t['immatriculation']); ?> · <?php echo h(v2_relative($t['dateIntervention'], $dbNow)); ?></div>
                                    </div>
                                    <span class="tv2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tv2-col">
                <!-- Dernières activités -->
                <div class="tv2-card tv2-panel-sm">
                    <div class="tv2-panel-head">
                        <h2>Dernières activités</h2>
                        <a href="journal.php" class="tv2-link">Tout voir →</a>
                    </div>
                    <?php if (empty($journalRecent)): ?>
                        <div class="tv2-empty">Aucune activité récente.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($journalRecent as $j): ?>
                                <div>
                                    <div style="font-size:13.5px; font-weight:600;"><?php echo h(tv2_phrase($j, $selfId)); ?></div>
                                    <div style="font-size:11.5px; color:#A5977F; margin-top:2px;"><?php echo h(v2_relative($j['dateHeure'], $dbNow)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Messages -->
                <div class="tv2-card tv2-panel-sm">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#2B2116" stroke-width="1.8"/><path d="M3 6.5L12 13L21 6.5" stroke="#2B2116" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:15px; font-weight:700;">Messages</h2>
                    </div>
                    <p style="margin:0 0 14px; font-size:13px; line-height:1.5; color:#7A6A57;"><?php echo (int)$messagesNonLus; ?> message<?php echo $messagesNonLus > 1 ? 's' : ''; ?> non lu<?php echo $messagesNonLus > 1 ? 's' : ''; ?>.</p>
                    <a href="<?php echo SITE_URL; ?>messages/index.php" class="tv2-btn-dark" style="display:block; text-align:center; box-sizing:border-box; text-decoration:none;">Ouvrir la messagerie</a>
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
