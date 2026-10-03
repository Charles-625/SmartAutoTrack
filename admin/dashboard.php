<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Tableau de bord de l'Administrateur (page d'accueil de l'espace admin).
 *
 * Accès : rôle admin uniquement.
 * Lecture seule : aucune action POST. Affiche les compteurs globaux, la
 * répartition des interventions (donut), la tendance sur 7 jours, les
 * garages à valider, les demandes à affecter (adressées à SmartAutoTrack ou
 * refusées par un garage), la charge des garages et
 * les dernières activités du journal.
 *
 * Tables lues : client, garage, technicien, vehicule, intervention,
 * anomalie, reparation, utilisateur, journal d'activité (activity_log_fetch).
 * Liens : admin/includes/helpers.php (av2_donut_svg, av2_trend_svg).
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Heure du serveur MySQL, utilisée pour les dates relatives (évite un
// décalage avec l'horloge PHP).
$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// ============================================================
// Cartes statistiques — uniquement des données réelles de la plateforme.
// Un sous-ensemble représentatif (pas les 11 possibles) pour ne pas
// surcharger l'écran d'accueil ; le détail complet est dans chaque rubrique.
// ============================================================
$stmt = $conn->query("
    SELECT
        SUM(CASE WHEN c.typeClient = 'PARTICULIER' THEN 1 ELSE 0 END) AS particuliers,
        SUM(CASE WHEN c.typeClient = 'ENTREPRISE' THEN 1 ELSE 0 END) AS entreprises
    FROM client c
");
$clientsRepartition = $stmt->fetch();
$totalClients = (int)($clientsRepartition['particuliers'] ?? 0) + (int)($clientsRepartition['entreprises'] ?? 0);

$totalGarages = (int)$conn->query("SELECT COUNT(*) FROM garage")->fetchColumn();
$garagesEnAttente = (int)$conn->query("SELECT COUNT(*) FROM garage WHERE statutGarage = 'EN_ATTENTE'")->fetchColumn();
$totalTechniciens = (int)$conn->query("SELECT COUNT(*) FROM technicien")->fetchColumn();
$totalVehicules = (int)$conn->query("SELECT COUNT(*) FROM vehicule")->fetchColumn();
$interventionsEnCours = (int)$conn->query("SELECT COUNT(*) FROM intervention WHERE statut = 'EN_COURS'")->fetchColumn();
$interventionsTerminees = (int)$conn->query("SELECT COUNT(*) FROM intervention WHERE statut = 'TERMINEE'")->fetchColumn();
$anomaliesActives = (int)$conn->query("SELECT COUNT(*) FROM anomalie WHERE statut IN ('NOUVELLE', 'EN_COURS')")->fetchColumn();
$reparationsEnCours = (int)$conn->query("SELECT COUNT(*) FROM reparation WHERE statut IN ('EN_ATTENTE', 'EN_COURS')")->fetchColumn();

// ============================================================
// Donut : répartition des interventions de toute la plateforme par statut
// ============================================================
$stmt = $conn->query("
    SELECT
        SUM(CASE WHEN statut = 'PLANIFIEE' THEN 1 ELSE 0 END) AS planifiee,
        SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminee,
        SUM(CASE WHEN statut = 'ANNULEE' THEN 1 ELSE 0 END) AS annulee
    FROM intervention
");
$repartition = $stmt->fetch();
$donutSegments = [
    ['label' => 'Planifiées', 'value' => (int)($repartition['planifiee'] ?? 0), 'color' => '#1E7DBF'],
    ['label' => 'En cours', 'value' => (int)($repartition['en_cours'] ?? 0), 'color' => '#C8871A'],
    ['label' => 'Terminées', 'value' => (int)($repartition['terminee'] ?? 0), 'color' => '#1E8A4C'],
    ['label' => 'Annulées', 'value' => (int)($repartition['annulee'] ?? 0), 'color' => '#8B90B3'],
];
$donutTotal = array_sum(array_column($donutSegments, 'value'));

// ============================================================
// Tendance : interventions créées sur la plateforme, 7 derniers jours
// ============================================================
$stmt = $conn->query("
    SELECT DATE(dateIntervention) AS jour, COUNT(*) AS total
    FROM intervention
    WHERE dateIntervention >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(dateIntervention)
");
$trendRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
// Les jours sans intervention sont absents du GROUP BY : on complète à 0
// pour toujours avoir 7 points sur la courbe.
$trendValues = [];
$trendLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $trendValues[] = (int)($trendRows[$day] ?? 0);
    $trendLabels[] = date('d/m', strtotime($day));
}

// ============================================================
// Garages en attente de validation (actionnable directement)
// ============================================================
$stmt = $conn->query("
    SELECT g.idGarage, g.nomGarage, g.adresse, u.dateCreation
    FROM garage g LEFT JOIN utilisateur u ON u.idUtilisateur = g.idUtilisateur
    WHERE g.statutGarage = 'EN_ATTENTE'
    ORDER BY u.dateCreation ASC
    LIMIT 5
");
$garagesAValider = $stmt->fetchAll();

// ============================================================
// Demandes en attente d'affectation : adressées à SmartAutoTrack (ni garage
// ni technicien) ou refusées par un garage et à réaffecter. À confier à un
// technicien SmartAutoTrack (garage partenaire en appui facultatif) ou à
// un garage, depuis interventions.php.
// ============================================================
$stmt = $conn->query("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, i.statut,
           v.marque, v.modele, v.immatriculation, uc.prenom AS client_prenom, uc.nom AS client_nom
    FROM intervention i
    JOIN vehicule v ON v.idVehicule = i.idVehicule
    JOIN utilisateur uc ON uc.idUtilisateur = i.idClient
    WHERE i.idTechnicien IS NULL AND (i.idGarage IS NULL OR i.statut = 'ANNULEE')
    ORDER BY i.dateIntervention ASC
    LIMIT 5
");
$demandesNonAffectees = $stmt->fetchAll();
$demandesNonAffecteesCount = (int)$conn->query("
    SELECT COUNT(*) FROM intervention WHERE idTechnicien IS NULL AND (idGarage IS NULL OR statut = 'ANNULEE')
")->fetchColumn();

// Répartition des garages validés par charge (interventions actives)
$stmt = $conn->query("
    SELECT g.nomGarage,
           SUM(CASE WHEN i.statut IN ('PLANIFIEE', 'EN_COURS') THEN 1 ELSE 0 END) AS actives
    FROM garage g
    LEFT JOIN intervention i ON i.idGarage = g.idGarage
    WHERE g.statutGarage = 'VALIDE'
    GROUP BY g.idGarage, g.nomGarage
    ORDER BY actives DESC
    LIMIT 6
");
$garageCharge = $stmt->fetchAll();
// max(1, ...) évite une division par zéro dans le calcul des barres de
// charge quand aucun garage n'a d'intervention active.
$maxCharge = max(1, ...array_map(fn($g) => (int)$g['actives'], $garageCharge ?: [['actives' => 0]]));

// Activités récentes (vision globale, sans restriction pour l'admin)
$journalRecent = activity_log_fetch($conn, 'admin', (int)$_SESSION['user_id'], [], ['limit' => 6]);
$categorieLabels = ['intervention' => 'Intervention', 'reparation' => 'Réparation', 'anomalie' => 'Anomalie', 'technicien' => 'Technicien', 'garage' => 'Garage', 'compte' => 'Compte'];

$pageTitle = 'Tableau de bord';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'dashboard'; $garagesEnAttenteBadge = $garagesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <div class="av2-topbar">
            <div>
                <div class="av2-kicker">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.9"/><path d="M12 8V12L14.5 13.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                    Centre de contrôle SmartAutoTrack
                </div>
                <h1 class="av2-h1">Bonjour, <?php echo h($_SESSION['prenom'] ?? ''); ?></h1>
                <p class="av2-sub">Vue d'ensemble de la plateforme — <?php echo h(v2_today_fr()); ?></p>
            </div>
            <div class="av2-topbar-actions">
                <div class="av2-notif-wrap">
                    <button class="av2-iconbtn" id="notificationBtn" aria-label="Notifications" type="button">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M6 10C6 6.7 8.7 4 12 4C15.3 4 18 6.7 18 10V14L20 17H4L6 14V10Z" stroke="#171B33" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20C10.4 20.8 11.1 21.3 12 21.3C12.9 21.3 13.6 20.8 14 20" stroke="#171B33" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <span class="av2-notif-dot" id="notificationCounter"></span>
                    </button>
                    <div class="av2-notif-panel" id="notificationPanel">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                            <button class="mark-all-read" id="markAllRead" type="button">Tout marquer comme lu</button>
                        </div>
                        <div class="notification-list" id="notificationList"></div>
                    </div>
                </div>
                <div class="av2-topbar-avatar"><?php echo h(mb_strtoupper(mb_substr($_SESSION['prenom'] ?? '?', 0, 1))); ?></div>
            </div>
        </div>

        <div class="av2-group-title">Comptes de la plateforme</div>
        <div class="av2-stats">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="#1E7DBF" stroke-width="1.8"/><path d="M3.5 20C4.6 16.3 6.9 15 9 15C11.1 15 13.4 16.3 14.5 20" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/><circle cx="17" cy="9" r="2.4" stroke="#1E7DBF" stroke-width="1.6"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$totalClients; ?></div>
                    <div class="av2-stat-label">Clients (<?php echo (int)($clientsRepartition['particuliers'] ?? 0); ?> part. · <?php echo (int)($clientsRepartition['entreprises'] ?? 0); ?> entr.)</div>
                </div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 10L12 4L20 10V19C20 20.1 19.1 21 18 21H6C4.9 21 4 20.1 4 19V10Z" stroke="#1E8A4C" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$totalGarages; ?></div>
                    <div class="av2-stat-label">Garages<?php echo h($garagesEnAttente > 0 ? ' (' . (int)$garagesEnAttente . ' à valider)' : ''); ?></div>
                </div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#C8871A" stroke-width="1.7" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$totalTechniciens; ?></div>
                    <div class="av2-stat-label">Techniciens</div>
                </div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#6D74A0" stroke-width="1.8"/><circle cx="7.5" cy="18.5" r="1.6" stroke="#6D74A0" stroke-width="1.8"/><circle cx="16.5" cy="18.5" r="1.6" stroke="#6D74A0" stroke-width="1.8"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$totalVehicules; ?></div>
                    <div class="av2-stat-label">Véhicules suivis</div>
                </div>
            </div>
        </div>

        <div class="av2-group-title">Activité en cours</div>
        <div class="av2-stats">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$interventionsEnCours; ?></div>
                    <div class="av2-stat-label">Interventions en cours</div>
                </div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#1E8A4C" stroke-width="1.8"/><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$interventionsTerminees; ?></div>
                    <div class="av2-stat-label">Interventions terminées</div>
                </div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FDEDEE;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#E5484D" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#E5484D"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$anomaliesActives; ?></div>
                    <div class="av2-stat-label">Anomalies actives</div>
                </div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#1E7DBF" stroke-width="1.7" stroke-linejoin="round"/><path d="M12.5 6.5L17.5 11.5" stroke="#1E7DBF" stroke-width="1.7"/></svg>
                </div>
                <div>
                    <div class="av2-stat-value"><?php echo (int)$reparationsEnCours; ?></div>
                    <div class="av2-stat-label">Réparations en cours</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="av2-chart-row">
            <div class="av2-card av2-panel">
                <div class="av2-panel-head"><h2>Activité plateforme — 7 derniers jours</h2></div>
                <?php if (array_sum($trendValues) === 0): ?>
                    <div class="av2-empty">Aucune intervention créée récemment.</div>
                <?php else: ?>
                    <?php echo av2_trend_svg($trendValues, 560, 110); ?>
                    <div style="display:flex; justify-content:space-between; margin-top:6px;">
                        <?php foreach ($trendLabels as $l): ?><span style="font-size:11px; color:#8B90B3;"><?php echo h($l); ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="av2-card av2-panel">
                <div class="av2-panel-head"><h2>Interventions — toute la plateforme</h2></div>
                <?php if ($donutTotal === 0): ?>
                    <div class="av2-empty">Aucune donnée pour le moment.</div>
                <?php else: ?>
                    <div class="av2-donut-wrap">
                        <div style="position:relative; flex-shrink:0;">
                            <?php echo av2_donut_svg(array_map(fn($s) => ['value' => $s['value'], 'color' => $s['color']], $donutSegments), 130, 18); ?>
                            <div style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                <div class="av2-donut-center-value"><?php echo (int)$donutTotal; ?></div>
                                <div class="av2-donut-center-label">Total</div>
                            </div>
                        </div>
                        <div class="av2-donut-legend">
                            <?php foreach ($donutSegments as $seg): if ($seg['value'] <= 0) continue; ?>
                                <div class="av2-donut-legend-item">
                                    <span class="av2-donut-legend-dot" style="background:<?php echo h($seg['color']); ?>;"></span>
                                    <span class="av2-donut-legend-label"><?php echo h($seg['label']); ?></span>
                                    <span class="av2-donut-legend-value"><?php echo (int)$seg['value']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="av2-body">
            <div class="av2-col">
                <!-- Demandes à affecter (technicien SmartAutoTrack ou garage) -->
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head">
                        <h2>Demandes à affecter</h2>
                        <a href="interventions.php?garage=none" class="av2-link">Voir toutes (<?php echo (int)$demandesNonAffecteesCount; ?>) →</a>
                    </div>
                    <?php if (empty($demandesNonAffectees)): ?>
                        <div class="av2-empty">Aucune demande en attente d'affectation.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($demandesNonAffectees as $d): $refused = $d['statut'] === 'ANNULEE'; ?>
                                <div class="av2-row pending">
                                    <div class="av2-row-icon" style="background:#FFF4E2;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#C8871A" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#C8871A"/></svg>
                                    </div>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="av2-row-title"><?php echo h($d['type'] ?: 'Intervention'); ?> — <?php echo h($d['marque'] . ' ' . $d['modele']); ?></div>
                                        <div class="av2-row-meta"><?php echo h($d['client_prenom'] . ' ' . $d['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($d['dateIntervention']))); ?><?php echo h($refused ? ' · refusée, à réaffecter' : ' · demande à SmartAutoTrack'); ?></div>
                                    </div>
                                    <a href="interventions.php?garage=none" class="av2-btn-primary av2-btn-xs" style="text-decoration:none;">Affecter</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Garages à valider -->
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head">
                        <h2>Garages à valider</h2>
                        <a href="garages.php?status=pending" class="av2-link">Voir tous →</a>
                    </div>
                    <?php if (empty($garagesAValider)): ?>
                        <div class="av2-empty">Aucun garage en attente de validation.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($garagesAValider as $g): ?>
                                <div class="av2-row">
                                    <div class="av2-row-icon" style="background:#FFF4E2;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 10L12 4L20 10V19C20 20.1 19.1 21 18 21H6C4.9 21 4 20.1 4 19V10Z" stroke="#C8871A" stroke-width="1.8" stroke-linejoin="round"/></svg>
                                    </div>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="av2-row-title"><?php echo h($g['nomGarage']); ?></div>
                                        <div class="av2-row-meta"><?php echo h($g['adresse'] ?: 'Adresse non renseignée'); ?></div>
                                    </div>
                                    <form method="POST" action="garages.php?action=validate&id=<?php echo (int)$g['idGarage']; ?>" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <button type="submit" class="av2-btn-primary av2-btn-xs">Valider</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Activités récentes -->
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head">
                        <h2>Activités récentes</h2>
                        <a href="journal.php" class="av2-link">Tout voir →</a>
                    </div>
                    <?php if (empty($journalRecent)): ?>
                        <div class="av2-empty">Aucune activité récente.</div>
                    <?php else: ?>
                        <div class="av2-timeline">
                            <?php foreach ($journalRecent as $j): ?>
                                <div class="av2-timeline-item">
                                    <div class="av2-timeline-dot">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" fill="#3956E8"/></svg>
                                    </div>
                                    <div>
                                        <div class="av2-timeline-title">
                                            <?php echo h($j['nomActivite']); ?>
                                            <?php if ($j['acteur_nom']): ?> — <?php echo h($j['acteur_prenom'] . ' ' . $j['acteur_nom']); ?><?php endif; ?>
                                            <?php if ($j['nomGarage']): ?> · <?php echo h($j['nomGarage']); ?><?php endif; ?>
                                        </div>
                                        <div class="av2-timeline-time"><?php echo h(v2_relative($j['dateHeure'], $dbNow)); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="av2-col">
                <!-- Charge des garages -->
                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head">
                        <h2>Charge des garages</h2>
                        <a href="garages.php" class="av2-link">Voir tous →</a>
                    </div>
                    <?php if (empty($garageCharge)): ?>
                        <div class="av2-empty">Aucun garage validé pour le moment.</div>
                    <?php else: ?>
                        <div class="av2-bars">
                            <?php foreach ($garageCharge as $g): $actives = (int)$g['actives']; ?>
                                <div class="av2-bar-row">
                                    <div class="av2-bar-label"><?php echo h($g['nomGarage']); ?></div>
                                    <div class="av2-bar-track"><div class="av2-bar-fill" style="width:<?php echo round(($actives / $maxCharge) * 100); ?>%;"></div></div>
                                    <div class="av2-bar-value"><?php echo (int)$actives; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Accès rapide -->
                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Accès rapide</h2></div>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <a href="techniciens.php?status=pending" class="av2-btn-outline" style="text-decoration:none; text-align:left;">Techniciens à valider</a>
                        <a href="anomalies.php?statut=active" class="av2-btn-outline" style="text-decoration:none; text-align:left;">Anomalies actives</a>
                        <a href="<?php echo SITE_URL; ?>messages/index.php" class="av2-btn-dark" style="text-decoration:none; text-align:center;">Ouvrir la messagerie</a>
                    </div>
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
