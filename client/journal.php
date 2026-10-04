<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/subscription.php';

/**
 * Journal d'activité du client.
 *
 * Accès : rôle client uniquement.
 * GET : date_from, date_to (AAAA-MM-JJ, optionnels) pour borner la période.
 * Lecture seule. Les événements viennent de activity_log_fetch()
 * (includes/activity_log.php), qui limite le résultat aux véhicules,
 * interventions, réparations et anomalies du client.
 * Formule gratuite : seuls les SUB_FREE_HISTORY_MONTHS derniers mois sont
 * affichés (subscriptionHistorySince()), sauf les événements d'une
 * intervention encore en cours ou d'une anomalie encore active, toujours
 * visibles ; les données plus anciennes restent en base.
 * Le rapport de fin d'intervention rempli par le technicien ou le garage
 * (description multi-lignes, activity_log_is_report()) s'ouvre dans la
 * fenêtre commune « Voir le rapport » (bouton report-modal-trigger, données
 * de activity_log_report_json(), fenêtre construite par assets/js/main.js),
 * avec un lien « Télécharger en PDF » vers ajax/download_report.php ; les
 * descriptions des autres événements restent masquées, comme avant.
 * Un événement d'intervention sans garage (demande adressée à SmartAutoTrack,
 * technicien SmartAutoTrack sans garage en appui) est signé « SmartAutoTrack ».
 * Tables lues : journal d'activité (via activity_log_fetch), intervention
 * (badge, interventions actives), anomalie (anomalies actives), abonnement.
 * Ouverture de la page : activity_log_mark_seen(..., 'journal') remet à zéro
 * la pastille rouge de nouveautés de cet onglet dans la sidebar (table
 * onglet_vu, includes/activity_log.php).
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Onglet « Journal d'activité » ouvert : sa pastille rouge de nouveautés
// disparaît, avant le rendu de la sidebar (sans effet tant que la
// migration onglet_vu n'est pas appliquée).
activity_log_mark_seen($conn, (int)$_SESSION['user_id'], 'journal');

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// Uniquement les événements liés à MES véhicules / interventions / réparations
// / anomalies — jamais le journal interne du garage ou du technicien qui est
// intervenu. Le cloisonnement (i.idClient = mon idUtilisateur) est appliqué
// une seule fois dans activity_log_fetch() (includes/activity_log.php).
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$journal = activity_log_fetch($conn, 'client', (int)$_SESSION['user_id'], [], [
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
]);

// Historique limité de la formule gratuite (null : aucun filtre). Le filtre se
// fait ici plutôt que dans activity_log_fetch() pour garder visibles, quelle
// que soit leur date, les événements des dossiers encore ouverts.
$historySince = subscriptionHistorySince($conn, (int)$_SESSION['user_id']);
if ($historySince !== null && $journal) {
    $stmt = $conn->prepare("SELECT idIntervention FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
    $stmt->execute([$_SESSION['user_id']]);
    $openInterventions = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    $stmt = $conn->prepare("
        SELECT a.idAnomalie FROM anomalie a
        JOIN vehicule v ON v.idVehicule = a.idVehicule
        WHERE v.idClient = ? AND a.statut IN ('NOUVELLE', 'EN_COURS')
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $openAnomalies = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $journal = array_values(array_filter($journal, function ($j) use ($historySince, $openInterventions, $openAnomalies) {
        return $j['dateHeure'] >= $historySince
            || isset($openInterventions[(int)$j['idIntervention']])
            || isset($openAnomalies[(int)$j['idAnomalie']]);
    }));
}

// Badge de la sidebar : interventions actives (planifiées ou en cours) du client.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmt->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmt->fetchColumn();

// Couleurs de l'icône de chaque événement, selon sa catégorie.
$icons = [
    'intervention' => ['bg' => '#E9EAFB', 'color' => '#4B4FCE'],
    'reparation' => ['bg' => '#E4F7EE', 'color' => '#1E8A5A'],
    'anomalie' => ['bg' => '#FDEDEE', 'color' => '#E5484D'],
];

$pageTitle = "Journal d'activité";
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'journal'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">
        <div class="v2-page-head">
            <div>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1">Journal d'activité</h1>
                <p class="v2-sub"><?php echo $isEntreprise ? 'Les événements concernant votre entreprise et votre parc automobile.' : 'Les événements concernant vos véhicules et vos interventions.'; ?></p>
            </div>
        </div>

        <?php if ($historySince !== null): ?>
            <div class="v2-alert premium">Historique limité aux <?php echo (int)SUB_FREE_HISTORY_MONTHS; ?> derniers mois — <a href="abonnement.php">Premium : historique complet</a></div>
        <?php endif; ?>

        <form method="GET" class="v2-filterbar">
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()">
            <?php if ($dateFrom || $dateTo): ?>
                <a href="journal.php" class="v2-table-link" style="text-decoration:none;">Réinitialiser</a>
            <?php endif; ?>
        </form>

        <div class="v2-card v2-panel">
            <?php if (empty($journal)): ?>
                <div class="v2-empty">Aucune activité pour le moment. Cet historique se remplit automatiquement au fil de vos demandes d'intervention, réparations et anomalies constatées sur vos véhicules.</div>
            <?php else: ?>
                <div class="v2-timeline">
                    <?php foreach ($journal as $j): $icon = $icons[$j['categorie']] ?? ['bg' => '#EEF0F7', 'color' => '#5A5E7A']; ?>
                        <div class="v2-timeline-item">
                            <div class="v2-timeline-dot" style="background:<?php echo h($icon['bg']); ?>;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" fill="<?php echo h($icon['color']); ?>"/></svg>
                            </div>
                            <div>
                                <div class="v2-timeline-title"><?php echo h($j['nomActivite']); ?></div>
                                <div class="v2-row-meta">
                                    <?php if ($j['idIntervention']): ?>
                                        <?php echo h($j['intervention_type'] ?: 'Intervention'); ?><?php echo h($j['marque'] ? ' · ' . $j['marque'] . ' ' . $j['modele'] . ' (' . $j['immatriculation'] . ')' : ''); ?>
                                    <?php endif; ?>
                                    <?php if ($j['nomGarage']): ?> · <?php echo h($j['nomGarage']); ?><?php elseif ($j['idIntervention']): ?> · SmartAutoTrack<?php endif; ?>
                                </div>
                                <div class="v2-timeline-time"><?php echo h(v2_relative($j['dateHeure'], $dbNow)); ?> · <?php echo h(date('d/m/Y H:i', strtotime($j['dateHeure']))); ?></div>
                                <?php if (activity_log_is_report($j)): ?>
                                    <button type="button" class="report-modal-trigger" data-report="<?php echo h(activity_log_report_json($j)); ?>">Voir le rapport</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
