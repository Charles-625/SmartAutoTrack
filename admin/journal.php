<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Journal d'activité global de la plateforme (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Lecture seule. Filtres GET : `categorie`, `garage`, `role_acteur`,
 * `date_from`, `date_to` ; au plus 300 entrées affichées.
 * Colonne « Contexte » : un rapport de fin d'intervention (description
 * multi-lignes, activity_log_is_report()) n'est plus déplié dans la colonne
 * étroite : le bouton « Voir le rapport » (report-modal-trigger, données de
 * activity_log_report_json()) l'ouvre dans la fenêtre commune construite par
 * assets/js/main.js, avec un lien « Télécharger en PDF » vers
 * ajax/download_report.php.
 *
 * Tables lues : journal d'activité (via activity_log_fetch), garage.
 * Liens : includes/activity_log.php.
 * Ouverture de la page : activity_log_mark_seen(..., 'journal') remet à zéro
 * la pastille rouge de nouveautés de cet onglet dans la sidebar (table
 * onglet_vu, includes/activity_log.php).
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Onglet « Journal d'activité » ouvert : sa pastille rouge de nouveautés
// disparaît, avant le rendu de la sidebar (sans effet tant que la
// migration onglet_vu n'est pas appliquée).
activity_log_mark_seen($conn, (int)$_SESSION['user_id'], 'journal');

// Vision globale : activity_log_fetch() n'applique aucune restriction pour
// le rôle admin (voir includes/activity_log.php). Les filtres ci-dessous ne
// sont que du confort d'affichage, jamais une condition de sécurité.
$categorieFilter = $_GET['categorie'] ?? '';
$garageFilter = filter_var($_GET['garage'] ?? null, FILTER_VALIDATE_INT);
$roleFilter = $_GET['role_acteur'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$journal = activity_log_fetch($conn, 'admin', (int)$_SESSION['user_id'], [], [
    'categorie' => $categorieFilter ?: null,
    'idGarage' => $garageFilter ?: null,
    'role_acteur' => $roleFilter ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
    'limit' => 300,
]);

$garagesList = $conn->query("SELECT idGarage, nomGarage FROM garage ORDER BY nomGarage")->fetchAll();

$categorieLabels = [
    'intervention' => 'Intervention', 'reparation' => 'Réparation', 'anomalie' => 'Anomalie',
    'technicien' => 'Technicien', 'garage' => 'Garage', 'compte' => 'Compte',
];
$categorieBadge = [
    'intervention' => 'info', 'reparation' => 'ok', 'anomalie' => 'bad',
    'technicien' => 'info', 'garage' => 'warn', 'compte' => 'neutral',
];
$roleLabels = ['admin' => 'Administrateur', 'garage' => 'Garage', 'technicien' => 'Technicien', 'client' => 'Client'];

$pageTitle = "Journal d'activité";
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'journal'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Journal d'activité</h1>
                <p class="av2-sub">Vision globale des actions métier importantes de la plateforme — jamais les simples consultations de page. <?php echo count($journal); ?> événement(s).</p>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <select name="categorie" onchange="this.form.submit()">
                <option value="">Toutes les catégories</option>
                <?php foreach ($categorieLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $categorieFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="role_acteur" onchange="this.form.submit()">
                <option value="">Tous les rôles</option>
                <?php foreach ($roleLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $roleFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="garage" onchange="this.form.submit()">
                <option value="">Tous les garages</option>
                <?php foreach ($garagesList as $g): ?>
                    <option value="<?php echo (int)$g['idGarage']; ?>" <?php echo $garageFilter === (int)$g['idGarage'] ? 'selected' : ''; ?>><?php echo h($g['nomGarage']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()">
            <?php if ($categorieFilter || $roleFilter || $garageFilter || $dateFrom || $dateTo): ?>
                <a href="journal.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($journal)): ?>
                <div class="av2-empty">Aucun événement ne correspond aux critères sélectionnés.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Date / Heure</th><th>Catégorie</th><th>Périmètre</th><th>Action</th><th>Acteur</th><th>Rôle</th><th>Garage</th><th>Élément concerné</th><th>Contexte</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($journal as $j): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo h(date('d/m/Y H:i', strtotime($j['dateHeure']))); ?></td>
                                    <td><span class="av2-badge <?php echo h($categorieBadge[$j['categorie']] ?? 'neutral'); ?>"><?php echo h($categorieLabels[$j['categorie']] ?? $j['categorie']); ?></span></td>
                                    <td><?php echo h(activity_log_perimetre_label($j['acteur_role'])); ?></td>
                                    <td><?php echo h($j['nomActivite']); ?></td>
                                    <td><?php if ($j['acteur_nom']): ?><?php echo h($j['acteur_prenom'] . ' ' . $j['acteur_nom']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php if ($j['acteur_role']): ?><span class="av2-badge neutral"><?php echo h($roleLabels[$j['acteur_role']] ?? $j['acteur_role']); ?></span><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php if ($j['nomGarage']): ?><?php echo h($j['nomGarage']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td>
                                        <?php if ($j['idReparation']): ?>
                                            Réparation<?php echo h($j['reparation_titre'] ? ' — ' . $j['reparation_titre'] : ''); ?>
                                        <?php elseif ($j['idAnomalie']): ?>
                                            Anomalie<?php echo h($j['anomalie_description'] ? ' — ' . mb_substr($j['anomalie_description'], 0, 60) : ''); ?>
                                        <?php elseif ($j['idIntervention']): ?>
                                            <?php echo h($j['intervention_type'] ?: 'Intervention'); ?><?php echo h($j['marque'] ? ' — ' . $j['marque'] . ' ' . $j['modele'] . ' (' . $j['immatriculation'] . ')' : ''); ?>
                                        <?php elseif ($j['technicien_nom']): ?>
                                            Technicien — <?php echo h($j['technicien_prenom'] . ' ' . $j['technicien_nom']); ?>
                                        <?php else: ?>
                                            <span style="color:#8B90B3;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:260px;"><?php if (activity_log_is_report($j)): ?><button type="button" class="report-modal-trigger" data-report="<?php echo h(activity_log_report_json($j)); ?>">Voir le rapport</button><?php elseif ($j['description']): ?><?php echo h($j['description']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
