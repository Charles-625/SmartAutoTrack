<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Journal d'activité du garage.
 *
 * Accès : rôle « garage ».
 * Page en lecture seule. Filtres GET : categorie, date_from, date_to.
 * Un rapport de fin d'intervention (description multi-lignes, voir
 * activity_log_is_report()) n'est pas collé à la ligne de contexte : il
 * s'ouvre dans la fenêtre commune « Voir le rapport » (bouton
 * report-modal-trigger, données de activity_log_report_json(), fenêtre
 * construite par assets/js/main.js), avec un lien « Télécharger en PDF »
 * vers ajax/download_report.php.
 * Table lue : journalactivites, uniquement via activity_log_fetch()
 * (includes/activity_log.php), qui applique le cloisonnement par garage.
 * Ouverture de la page : activity_log_mark_seen(..., 'journal') remet à zéro
 * la pastille rouge de nouveautés de cet onglet dans la sidebar (table
 * onglet_vu, includes/activity_log.php).
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

// Onglet « Journal d'activité » ouvert : sa pastille rouge de nouveautés
// disparaît, avant le rendu de la sidebar (sans effet tant que la
// migration onglet_vu n'est pas appliquée).
activity_log_mark_seen($conn, (int)$_SESSION['user_id'], 'journal');

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);

// Heure MySQL : les durées relatives (v2_relative) sont calculées sur la même horloge que les dates stockées.
$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// Journal d'activité de CE garage uniquement. La lecture passe par
// activity_log_fetch() (includes/activity_log.php), seul point d'accès en
// lecture à journalactivites : le cloisonnement par idGarage y est appliqué
// une fois pour toutes, jamais reconstruit ici — jamais le journal d'un
// autre garage, même par manipulation de l'URL.
$categorieFilter = $_GET['categorie'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$journal = activity_log_fetch($conn, 'garage', (int)$_SESSION['user_id'], ['idGarage' => $garageId], [
    'categorie' => $categorieFilter ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
]);

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

// Couleurs de l'icône et libellé affichés pour chaque catégorie du journal.
$icons = [
    'intervention' => ['bg' => '#E7F3FC', 'color' => '#1E7DBF'],
    'reparation' => ['bg' => '#E9F6EE', 'color' => '#1E8A4C'],
    'anomalie' => ['bg' => '#FDEDEE', 'color' => '#E5484D'],
    'technicien' => ['bg' => '#E7F3FC', 'color' => '#1E7DBF'],
    'garage' => ['bg' => '#F3F5FE', 'color' => '#3956E8'],
    'compte' => ['bg' => '#EFF0F6', 'color' => '#6D74A0'],
];
$categorieLabels = [
    'intervention' => 'Intervention', 'reparation' => 'Réparation', 'anomalie' => 'Anomalie',
    'technicien' => 'Technicien', 'garage' => 'Garage', 'compte' => 'Compte',
];

$pageTitle = "Journal d'activité";
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'journal'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">
        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Journal d'activité</h1>
                <p class="gv2-sub">Les actions importantes réalisées dans votre garage — visible uniquement par vous.</p>
            </div>
        </div>

        <form method="GET" class="gv2-filterbar">
            <select name="categorie" onchange="this.form.submit()">
                <option value="">Toutes les catégories</option>
                <?php foreach ($categorieLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $categorieFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()">
            <?php if ($categorieFilter || $dateFrom || $dateTo): ?>
                <a href="journal.php" class="gv2-btn-outline gv2-btn-xs" style="text-decoration:none;">Réinitialiser</a>
            <?php endif; ?>
        </form>

        <div class="gv2-card gv2-panel">
            <?php if (empty($journal)): ?>
                <div class="gv2-empty">Aucune activité pour ce filtre. Le journal se remplit automatiquement au fil des actions de votre garage (acceptation, affectation, démarrage, clôture, anomalie constatée...).</div>
            <?php else: ?>
                <div class="gv2-timeline">
                    <?php foreach ($journal as $j): $icon = $icons[$j['categorie']] ?? ['bg' => '#EFF0F6', 'color' => '#6D74A0']; ?>
                        <div class="gv2-timeline-item">
                            <div class="gv2-timeline-dot" style="background:<?php echo h($icon['bg']); ?>;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" fill="<?php echo h($icon['color']); ?>"/></svg>
                            </div>
                            <div>
                                <div class="gv2-timeline-title">
                                    <?php echo h($j['nomActivite']); ?>
                                    <?php if ($j['technicien_nom']): ?> — <?php echo h($j['technicien_prenom'] . ' ' . $j['technicien_nom']); ?><?php endif; ?>
                                </div>
                                <div class="gv2-row-meta">
                                    <?php if ($j['idIntervention']): ?>
                                        <?php echo h($j['intervention_type'] ?: 'Intervention'); ?><?php echo h($j['marque'] ? ' · ' . $j['marque'] . ' ' . $j['modele'] . ' (' . $j['immatriculation'] . ')' : ''); ?>
                                    <?php else: ?>
                                        <?php echo h($categorieLabels[$j['categorie']] ?? 'Activité'); ?>
                                    <?php endif; ?>
                                    <?php if (!activity_log_is_report($j)): ?><?php echo h($j['description'] ? ' — ' . $j['description'] : ''); ?><?php endif; ?>
                                </div>
                                <div class="gv2-timeline-time">
                                    <?php echo h(v2_relative($j['dateHeure'], $dbNow)); ?> · <?php echo h(date('d/m/Y H:i', strtotime($j['dateHeure']))); ?>
                                    <?php if ($j['acteur_nom']): ?> · par <?php echo h($j['acteur_prenom'] . ' ' . $j['acteur_nom']); ?><?php endif; ?>
                                </div>
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
