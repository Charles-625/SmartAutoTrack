<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace technicien — Mon journal d'activité.
 *
 * Accès : rôle « technicien ».
 * Page en lecture seule. Filtres GET : categorie, date_from, date_to.
 * Un rapport de fin d'intervention (description multi-lignes, voir
 * activity_log_is_report()) s'affiche replié sous « Voir le rapport ».
 * Table lue : journalactivites, uniquement via activity_log_fetch().
 */

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

// Heure MySQL : les durées relatives (v2_relative) sont calculées sur la même horloge que les dates stockées.
$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// Le technicien ne voit que ses propres activités : ses propres actions, ses
// propres interventions/réparations affectées, et les anomalies constatées
// sur une intervention où il est intervenu — jamais le journal d'un autre
// technicien. Le cloisonnement est appliqué une seule fois, dans
// activity_log_fetch() (includes/activity_log.php).
//
// Traçabilité automatique des actions métier : chaque ligne est générée
// depuis l'action réellement effectuée (démarrage, clôture, anomalie
// constatée, réparation enregistrée...) via
// includes/activity_log.php::log_activity(), et mise en phrase à la 2e
// personne par tv2_phrase() (technicien/includes/helpers.php). Seule
// exception au texte libre : le rapport de fin d'intervention, rempli dans le
// formulaire structuré de clôture (includes/repair_report.php).
$categorieFilter = $_GET['categorie'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$journal = activity_log_fetch($conn, 'technicien', $selfId, [], [
    'categorie' => $categorieFilter ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
]);

// Compteur du badge « Mes tâches » de la sidebar (tâches à démarrer).
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

// Couleurs de l'icône et libellé affichés pour chaque catégorie du journal.
$icons = [
    'intervention' => ['bg' => '#E7F3FC', 'color' => '#1E7DBF'],
    'reparation' => ['bg' => '#E4F7EE', 'color' => '#1E8A5A'],
    'anomalie' => ['bg' => '#FDEDEE', 'color' => '#E5484D'],
];
$categorieLabels = ['intervention' => 'Intervention', 'reparation' => 'Réparation', 'anomalie' => 'Anomalie'];

$pageTitle = "Journal d'activité";
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'journal'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">
        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Journal d'activité</h1>
                <p class="tv2-sub">La trace automatique de vos actions — pas un rapport à rédiger, uniquement ce que vous avez réellement fait.</p>
            </div>
        </div>

        <form method="GET" class="tv2-filterbar">
            <select name="categorie" onchange="this.form.submit()">
                <option value="">Toutes les catégories</option>
                <?php foreach ($categorieLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $categorieFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()">
            <?php if ($categorieFilter || $dateFrom || $dateTo): ?>
                <a href="journal.php" class="tv2-btn-outline tv2-btn-xs" style="text-decoration:none;">Réinitialiser</a>
            <?php endif; ?>
        </form>

        <div class="tv2-card tv2-panel">
            <?php if (empty($journal)): ?>
                <div class="tv2-empty">Aucune activité pour le moment. Cet historique se remplit automatiquement dès que vous démarrez une intervention, enregistrez une réparation ou constatez une anomalie.</div>
            <?php else: ?>
                <div class="tv2-timeline">
                    <?php foreach ($journal as $j): $icon = $icons[$j['categorie']] ?? ['bg' => '#F2EEE7', 'color' => '#7A6A57']; ?>
                        <div class="tv2-timeline-item">
                            <div class="tv2-timeline-dot" style="background:<?php echo h($icon['bg']); ?>;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" fill="<?php echo h($icon['color']); ?>"/></svg>
                            </div>
                            <div>
                                <div class="tv2-timeline-title"><?php echo h(tv2_phrase($j, $selfId)); ?></div>
                                <div class="tv2-timeline-time"><?php echo h(v2_relative($j['dateHeure'], $dbNow)); ?> · <?php echo h(date('d/m/Y H:i', strtotime($j['dateHeure']))); ?></div>
                                <?php if (activity_log_is_report($j)): ?>
                                    <details style="margin-top:6px;"><summary style="cursor:pointer; font-size:12.5px; font-weight:700; color:#D97706;">Voir le rapport</summary><div style="white-space:pre-line; font-size:13px; line-height:1.55; margin-top:6px; padding:10px 12px; border-radius:10px; background:#F7F3EC;"><?php echo h($j['description']); ?></div></details>
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
