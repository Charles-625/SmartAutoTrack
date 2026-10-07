<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/repair_report.php';
require_once '../includes/payments.php';

/**
 * Espace garage — Réparations réalisées par le garage.
 *
 * Accès : rôle « garage ».
 * Actions :
 *   - POST form=new_reparation : renseigner la réparation (rapport de fin
 *     d'intervention) d'une intervention EN_COURS du garage, kilométrage
 *     relevé et état du véhicule compris. Traitement commun avec le
 *     technicien : repairReportParse() puis repairReportClose()
 *     (includes/repair_report.php), bornés ici à idGarage = mon garage. Dans
 *     une même transaction : réparation (TERMINEE), clôture de l'intervention,
 *     kilométrage et état du véhicule, résolution de ses anomalies ouvertes,
 *     rapport complet dans le journal et notification du client.
 *   - GET action=new&intervention_id=… : ouvre directement le formulaire.
 * Paiement : colonne « Paiement » (seulement si paymentsReady()) pour chaque
 * réparation TERMINEE au coût non nul : « Payé » avec la date du paiement,
 * « Paiement en cours » (tentative EN_ATTENTE récente) ou « En attente de
 * paiement » (paymentStatesForRepairs(), includes/payments.php). Le garage
 * et le technicien sont aussi notifiés au paiement (paymentApplyCampayStatus()).
 * Tables : reparation, intervention, vehicule, anomalie, notifications
 *          (écriture, via repairReportClose()), utilisateur, paiement
 *          (lecture), journalactivites (via log_activity()).
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);
$garageNom = $profile['nomGarage'] ?? 'Garage';

$formErrors = [];
$action = $_GET['action'] ?? '';
$preselectIntervention = filter_var($_GET['intervention_id'] ?? null, FILTER_VALIDATE_INT);
$old = [];

// ============================================================
// Renseigner une réparation (clôture l'intervention en cours)
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
            // Contrôle de propriété et d'état (dans la transaction) : intervention EN_COURS de CE garage.
            // Erreur SQL au milieu : repairReportClose() a déjà annulé la
            // transaction ; on l'affiche au lieu d'une page d'erreur.
            try {
                $result = $garageId > 0
                    ? repairReportClose($conn, ['idGarage' => $garageId], $parsed['data'], (int)$_SESSION['user_id'], $garageNom)
                    : ['ok' => false, 'error' => 'not_found', 'kmActuel' => null];
            } catch (Exception $e) {
                $result = ['ok' => false, 'error' => 'db_error', 'kmActuel' => null];
            }
            if ($result['ok']) {
                header('Location: reparations.php?success=created');
                exit;
            }
            if ($result['error'] === 'km_too_low') {
                $formErrors[] = repairReportKmError((int)$parsed['data']['kilometrage'], (int)$result['kmActuel']);
            } elseif ($result['error'] === 'db_error') {
                $formErrors[] = 'Erreur lors de l\'enregistrement du rapport. Rien n\'a été modifié, merci de réessayer.';
            } else {
                $formErrors[] = 'Cette intervention n\'est pas (ou plus) en cours pour votre garage.';
            }
        }
    }
}

// Interventions EN_COURS de ce garage, disponibles pour créer un rapport
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, v.marque, v.modele, v.immatriculation, v.kilometrage,
           u.nom AS client_nom, u.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE i.idGarage = ? AND i.statut = 'EN_COURS'
    ORDER BY i.dateIntervention ASC
");
$stmt->execute([$garageId]);
$interventionsDisponibles = $stmt->fetchAll();

// Kilométrage actuel de l'intervention présélectionnée (aide du champ kilométrage).
$kmPreselect = null;
foreach ($interventionsDisponibles as $iv) {
    if ($preselectIntervention === (int)$iv['id']) $kmPreselect = (int)$iv['kilometrage'];
}

// Réparations déjà réalisées par ce garage
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre, r.statut, r.cout, r.dateReparation, r.dureeIntervention,
           v.marque, v.modele, v.immatriculation,
           uc.nom AS client_nom, uc.prenom AS client_prenom,
           ut.nom AS technicien_nom, ut.prenom AS technicien_prenom
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur uc ON i.idClient = uc.idUtilisateur
    LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
    WHERE i.idGarage = ?
    ORDER BY r.dateReparation DESC
");
$stmt->execute([$garageId]);
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

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$pageTitle = 'Réparations';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'reparations'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="gv2-alert success">Réparation enregistrée et intervention clôturée.</div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="gv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Réparations</h1>
                <p class="gv2-sub">Réparations réalisées par votre garage — renseignez le rapport dès qu'une intervention est terminée.</p>
            </div>
            <?php if (!empty($interventionsDisponibles)): ?>
                <button type="button" class="gv2-btn-primary" id="openReparationModal">+ Renseigner une réparation</button>
            <?php endif; ?>
        </div>

        <?php if (empty($reparations)): ?>
            <div class="gv2-card" style="padding:24px;"><div class="gv2-empty">Aucune réparation enregistrée pour le moment.</div></div>
        <?php else: ?>
            <div class="gv2-card" style="padding:8px;">
                <div class="gv2-table-wrap">
                    <table class="gv2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Technicien</th><th>Titre</th><th>Date</th><th>Durée</th><th>Coût</th><th>Statut</th><?php if ($paymentsEnabled): ?><th>Paiement</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reparations as $r): ?>
                                <tr>
                                    <td><?php echo h($r['marque'] . ' ' . $r['modele']); ?><div style="font-size:11.5px; color:#8B90B3;"><?php echo h($r['immatriculation']); ?></div></td>
                                    <td><?php echo h($r['client_prenom'] . ' ' . $r['client_nom']); ?></td>
                                    <td><?php echo h($r['technicien_nom'] ? $r['technicien_prenom'] . ' ' . $r['technicien_nom'] : '—'); ?></td>
                                    <td><?php echo h($r['titre'] ?: '—'); ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($r['dateReparation']))); ?></td>
                                    <td><?php echo h($r['dureeIntervention']); ?> h</td>
                                    <td><?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</td>
                                    <td><span class="gv2-badge <?php echo $r['statut'] === 'TERMINEE' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(strtolower($r['statut']))); ?></span></td>
                                    <?php if ($paymentsEnabled): ?>
                                        <td>
                                            <?php if ($r['statut'] === 'TERMINEE' && (float)$r['cout'] > 0): $payState = $paymentStates[(int)$r['id']] ?? null; ?>
                                                <?php if ($payState === 'PAYE'): ?>
                                                    <span class="gv2-badge ok">Payé</span><?php if (!empty($paidDates[(int)$r['id']])): ?><div style="font-size:11.5px; color:#8B90B3;">le <?php echo h(date('d/m/Y', strtotime($paidDates[(int)$r['id']]))); ?></div><?php endif; ?>
                                                <?php elseif ($payState === 'EN_ATTENTE'): ?>
                                                    <span class="gv2-badge warn">Paiement en cours</span>
                                                <?php else: ?>
                                                    <span class="gv2-badge warn">En attente de paiement</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#8B90B3;">—</span>
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
<div class="gv2-modal-overlay" id="reparationModalOverlay">
    <div class="gv2-modal">
        <h3>Renseigner une réparation</h3>
        <p class="gv2-modal-sub">Cette action clôture l'intervention et rend le rapport disponible au client.</p>
        <form method="POST">
            <input type="hidden" name="form" value="new_reparation">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="gv2-form-group">
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
            <?php repairReportFormFields('gv2', $old, $kmPreselect); ?>
            <div class="gv2-modal-actions">
                <button type="button" class="gv2-btn-outline" id="closeReparationModal">Annuler</button>
                <button type="submit" class="gv2-btn-primary">Enregistrer et clôturer</button>
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
