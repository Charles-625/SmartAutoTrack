<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/repair_report.php';
require_once '../includes/payments.php';

/**
 * Espace technicien — Mes réparations.
 *
 * Accès : rôle « technicien ».
 * Actions :
 *   - POST form=new_reparation : enregistrer la réparation (rapport de fin
 *     d'intervention) d'une de mes interventions EN_COURS, kilométrage relevé
 *     et état du véhicule compris. Traitement commun avec le garage :
 *     repairReportParse() puis repairReportClose() (includes/repair_report.php),
 *     bornés ici à idTechnicien = moi. Dans une même transaction : réparation
 *     (TERMINEE), clôture de l'intervention, kilométrage et état du véhicule,
 *     résolution de ses anomalies ouvertes, rapport complet dans le journal et
 *     notification du client.
 *     Champ facultatif return=taches|interventions (liste blanche,
 *     repairReportReturnKey()) : envoyé par la fenêtre « Marquer terminée »
 *     de taches.php / interventions.php, on y revient après succès
 *     (?success=repaired) ; sinon retour sur cette page (?success=created).
 *   - GET action=new&intervention_id=…[&return=…] : ouvre directement le formulaire.
 * Paiement : colonne « Paiement » (seulement si paymentsReady()) pour chaque
 * réparation TERMINEE au coût non nul : « Payé » avec la date du paiement,
 * « Paiement en cours » (tentative EN_ATTENTE récente) ou « En attente de
 * paiement » (paymentStatesForRepairs(), includes/payments.php). Le garage
 * et le technicien sont aussi notifiés au paiement (paymentApplyCampayStatus()).
 * Tables : reparation, intervention, vehicule, anomalie, notifications
 *          (écriture, via repairReportClose()), utilisateur, paiement
 *          (lecture), journalactivites (via log_activity()).
 */

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

$formErrors = [];
$action = $_GET['action'] ?? '';
$preselectIntervention = filter_var($_GET['intervention_id'] ?? null, FILTER_VALIDATE_INT);
$returnKey = repairReportReturnKey($_POST['return'] ?? $_GET['return'] ?? '');
$old = [];

// ============================================================
// Enregistrer une réparation (clôture l'intervention en cours). Il ne s'agit
// PAS d'un rapport rédigé librement : un formulaire structuré qui alimente
// directement la fiche réparation vue par le garage et le client, et
// journalise automatiquement le rapport complet (jamais un document à télécharger).
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'new_reparation') {
    // Valeurs réaffichées dans le formulaire en cas d'erreur.
    $old = $_POST;
    $preselectIntervention = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $parsed = repairReportParse($_POST);
        $formErrors = $parsed['errors'];

        if (empty($formErrors)) {
            // Contrôle de propriété et d'état (dans la transaction) : une de MES interventions, encore EN_COURS.
            // Erreur SQL au milieu : repairReportClose() a déjà annulé la
            // transaction ; on l'affiche au lieu d'une page d'erreur.
            try {
                $result = repairReportClose($conn, ['idTechnicien' => $selfId], $parsed['data'], $selfId);
            } catch (Exception $e) {
                $result = ['ok' => false, 'error' => 'db_error', 'kmActuel' => null];
            }
            if ($result['ok']) {
                $target = $returnKey !== '' ? $returnKey . '.php?success=repaired' : 'reparations.php?success=created';
                header('Location: ' . $target);
                exit;
            }
            if ($result['error'] === 'km_too_low') {
                $formErrors[] = repairReportKmError((int)$parsed['data']['kilometrage'], (int)$result['kmActuel']);
            } elseif ($result['error'] === 'db_error') {
                $formErrors[] = 'Erreur lors de l\'enregistrement du rapport. Rien n\'a été modifié, merci de réessayer.';
            } else {
                $formErrors[] = 'Cette intervention n\'est pas (ou plus) en cours pour vous.';
            }
        }
    }
}

// Interventions EN_COURS de ce technicien, disponibles pour enregistrer une réparation
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, v.marque, v.modele, v.immatriculation, v.kilometrage,
           u.nom AS client_nom, u.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE i.idTechnicien = ? AND i.statut = 'EN_COURS'
    ORDER BY i.dateIntervention ASC
");
$stmt->execute([$selfId]);
$interventionsDisponibles = $stmt->fetchAll();

// Kilométrage actuel de l'intervention présélectionnée (aide du champ kilométrage).
$kmPreselect = null;
foreach ($interventionsDisponibles as $iv) {
    if ($preselectIntervention === (int)$iv['id']) $kmPreselect = (int)$iv['kilometrage'];
}

// Réparations déjà enregistrées par ce technicien
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre, r.statut, r.cout, r.dateReparation, r.dureeIntervention,
           v.marque, v.modele, v.immatriculation,
           uc.nom AS client_nom, uc.prenom AS client_prenom
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur uc ON i.idClient = uc.idUtilisateur
    WHERE r.idTechnicien = ?
    ORDER BY r.dateReparation DESC
");
$stmt->execute([$selfId]);
$reparations = $stmt->fetchAll();

// État du paiement Mobile Money (includes/payments.php) des réparations
// TERMINEE au coût non nul : 'PAYE' (avec la date du paiement confirmé),
// 'EN_ATTENTE' (tentative récente) ou absent (à payer). Rien n'est affiché
// tant que les colonnes de paiement n'existent pas (paymentsReady()).
$paymentsEnabled = paymentsReady($conn);
$paymentStates = [];
$paidDates = [];
if ($paymentsEnabled) {
    $payableIds = [];
    foreach ($reparations as $r) {
        if ($r['statut'] === 'TERMINEE' && (float)$r['cout'] > 0) $payableIds[] = (int)$r['id'];
    }
    $paymentStates = paymentStatesForRepairs($conn, $payableIds);
    $paidIds = array_keys(array_filter($paymentStates, fn($s) => $s === 'PAYE'));
    if ($paidIds) {
        $in = implode(',', array_fill(0, count($paidIds), '?'));
        $stmt = $conn->prepare("SELECT idReparation, MAX(datePaiement) FROM paiement WHERE statut = 'PAYE' AND idReparation IN ($in) GROUP BY idReparation");
        $stmt->execute($paidIds);
        $paidDates = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

// Compteur du badge « Mes tâches » de la sidebar (tâches à démarrer).
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

$pageTitle = 'Mes réparations';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'reparations'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="tv2-alert success">Réparation enregistrée et intervention clôturée.</div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="tv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Mes réparations</h1>
                <p class="tv2-sub">Enregistrez une réparation dès qu'une intervention est terminée — elle est automatiquement journalisée et transmise au garage et au client.</p>
            </div>
            <?php if (!empty($interventionsDisponibles)): ?>
                <button type="button" class="tv2-btn-primary" id="openReparationModal">+ Enregistrer une réparation</button>
            <?php endif; ?>
        </div>

        <?php if (empty($reparations)): ?>
            <div class="tv2-card" style="padding:24px;"><div class="tv2-empty">Aucune réparation enregistrée pour le moment.</div></div>
        <?php else: ?>
            <div class="tv2-card" style="padding:8px;">
                <div class="tv2-table-wrap">
                    <table class="tv2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Titre</th><th>Date</th><th>Durée</th><th>Coût</th><th>Statut</th><?php if ($paymentsEnabled): ?><th>Paiement</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reparations as $r): ?>
                                <tr>
                                    <td><?php echo h($r['marque'] . ' ' . $r['modele']); ?><div style="font-size:11.5px; color:#A5977F;"><?php echo h($r['immatriculation']); ?></div></td>
                                    <td><?php echo h($r['client_prenom'] . ' ' . $r['client_nom']); ?></td>
                                    <td><?php echo h($r['titre'] ?: '—'); ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($r['dateReparation']))); ?></td>
                                    <td><?php echo h($r['dureeIntervention']); ?> h</td>
                                    <td><?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</td>
                                    <td><span class="tv2-badge <?php echo $r['statut'] === 'TERMINEE' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(strtolower($r['statut']))); ?></span></td>
                                    <?php if ($paymentsEnabled): ?>
                                        <td>
                                            <?php if ($r['statut'] === 'TERMINEE' && (float)$r['cout'] > 0): $payState = $paymentStates[(int)$r['id']] ?? null; ?>
                                                <?php if ($payState === 'PAYE'): ?>
                                                    <span class="tv2-badge ok">Payé</span><?php if (!empty($paidDates[(int)$r['id']])): ?><div style="font-size:11.5px; color:#A5977F;">le <?php echo h(date('d/m/Y', strtotime($paidDates[(int)$r['id']]))); ?></div><?php endif; ?>
                                                <?php elseif ($payState === 'EN_ATTENTE'): ?>
                                                    <span class="tv2-badge warn">Paiement en cours</span>
                                                <?php else: ?>
                                                    <span class="tv2-badge warn">En attente de paiement</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#A5977F;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if (!empty($interventionsDisponibles)): ?>
<div class="tv2-modal-overlay" id="reparationModalOverlay">
    <div class="tv2-modal">
        <h3>Enregistrer une réparation</h3>
        <p class="tv2-modal-sub">Cette action clôture l'intervention et rend la réparation visible au garage et au client.</p>
        <form method="POST">
            <input type="hidden" name="form" value="new_reparation">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="return" value="<?php echo h($returnKey); ?>">
            <div class="tv2-form-group">
                <label for="repIntervention">Intervention</label>
                <select name="intervention_id" id="repIntervention" required>
                    <option value="">Sélectionner une intervention en cours</option>
                    <?php foreach ($interventionsDisponibles as $iv): ?>
                        <option value="<?php echo (int)$iv['id']; ?>" data-km="<?php echo (int)$iv['kilometrage']; ?>" <?php echo $preselectIntervention === (int)$iv['id'] ? 'selected' : ''; ?>>
                            <?php echo h(($iv['type'] ?: 'Intervention') . ' — ' . $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ') — ' . $iv['client_prenom'] . ' ' . $iv['client_nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php repairReportFormFields('tv2', $old, $kmPreselect); ?>
            <div class="tv2-modal-actions">
                <button type="button" class="tv2-btn-outline" id="closeReparationModal">Annuler</button>
                <button type="submit" class="tv2-btn-primary">Enregistrer et clôturer</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('reparationModalOverlay');
    var openBtn = document.getElementById('openReparationModal');
    if (openBtn) openBtn.addEventListener('click', function () { overlay.classList.add('show'); });
    document.getElementById('closeReparationModal').addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });

    // Kilométrage actuel du véhicule de l'intervention choisie : aide et minimum du champ.
    var select = document.getElementById('repIntervention');
    var km = document.getElementById('repKilometrage');
    var kmHint = document.getElementById('repKmActuel');
    function syncKm() {
        var opt = select.options[select.selectedIndex];
        var value = opt && opt.dataset.km !== undefined ? parseInt(opt.dataset.km, 10) : NaN;
        km.min = isNaN(value) ? 0 : value;
        kmHint.textContent = isNaN(value) ? '' : 'Kilométrage actuel : ' + value.toLocaleString('fr-FR') + ' km';
    }
    select.addEventListener('change', syncKm);
    syncKm();
    <?php if ($action === 'new' || !empty($formErrors)): ?>
    overlay.classList.add('show');
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
