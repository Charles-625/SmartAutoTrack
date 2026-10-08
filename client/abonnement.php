<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/subscription.php';
require_once '../includes/maintenance.php';

/**
 * Page "Abonnement" de l'espace client (particulier ou entreprise).
 *
 * Accès : rôle client uniquement (requireRole('client')).
 * Avant la migration (subscriptionsReady() faux) : page informative en
 * lecture seule, « formule gratuite, Premium bientôt », sans action.
 * Après la migration : formule actuelle (Gratuit ou Premium, fin de période),
 * véhicules utilisés / limite, messages IA restants aujourd'hui, comparatif
 * Gratuit / Premium selon le type de client, essai et souscription.
 * Ligne « Rappels d'entretien et d'échéances » du comparatif : affichée
 * seulement si maintenanceReady() (échéances visibles par tous, rappels
 * automatiques par notification et email réservés à Premium ; voir
 * includes/maintenance.php) ; sinon elle reste annoncée « Bientôt ».
 * POST form=start_trial (CSRF) : active l'essai Premium de SUB_TRIAL_DAYS
 *     jours (subscriptionStartTrial()), puis redirige vers ?success=trial.
 * Le paiement Mobile Money passe par des appels AJAX : ajax/subscription_collect.php
 * (lancement) puis ajax/campay_status.php (suivi jusqu'à PAYE / ECHOUE).
 * Le prix affiché vient de subscriptionPrice() ; pour une entreprise, il est
 * recalculé en JavaScript avec les mêmes paliers quand le nombre de
 * véhicules change (le serveur recalcule toujours le montant réel).
 * Tables lues : intervention (badge sidebar), vehicule, abonnement, ia_usage,
 * paiement (paiement d'abonnement en cours) ; écrite : abonnement (essai).
 * Fichiers liés : includes/subscription.php, client/includes/sidebar.php,
 * includes/header.php, assets/css/client_v2.css.
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();
$clientId = (int)$_SESSION['user_id'];

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, $clientId);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

// Badge de la sidebar : interventions actives (planifiées ou en cours) du client.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmt->execute([$clientId]);
$interventionsActivesCount = (int)$stmt->fetchColumn();

// Nombre de véhicules suivis par le client (taille du parc).
$fleetSize = subscriptionVehicleCount($conn, $clientId);

// Tant que la migration n'est pas appliquée, la page garde son affichage
// informatif d'origine : aucune lecture des tables d'abonnement.
$subscriptionsEnabled = subscriptionsReady($conn);
$errors = [];

// Essai Premium : un seul par client, sans paiement.
if ($subscriptionsEnabled && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'start_trial') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        try {
            subscriptionStartTrial($conn, $clientId);
            header('Location: abonnement.php?success=trial');
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[SmartAutoTrack] essai Premium : ' . $e->getMessage());
            $errors[] = 'Erreur lors de l\'activation de l\'essai Premium.';
        }
    }
}

if ($subscriptionsEnabled) {
    $clientType = subscriptionClientType($conn, $clientId);
    $activeSub = subscriptionActive($conn, $clientId);
    $isPremium = $activeSub !== null;
    $vehicleLimit = subscriptionVehicleLimit($clientType, $activeSub);
    $aiRemaining = subscriptionAiRemaining($conn, $clientId);
    $trialAvailable = !$isPremium && subscriptionTrialAvailable($conn, $clientId);
    // Fin de la couverture Premium : dateFin de l'abonnement actif le plus
    // tardif, y compris une période déjà payée qui commencera plus tard.
    $premiumUntil = $isPremium ? subscriptionExtensionStart($conn, $clientId, time()) : null;
    // Échéances et rappels d'entretien disponibles (migration section 8).
    $maintenanceEnabled = maintenanceReady($conn);
    $periodLabels = ['ESSAI' => 'Essai gratuit', 'OFFERT' => 'Mois offert', 'MENSUEL' => 'Mensuel', 'ANNUEL' => 'Annuel'];

    // Paiement en ligne : CamPay configuré et tables de paiement présentes.
    $paymentsEnabled = campayIsConfigured() && paymentsReady($conn);
    // Entreprise : au moins SUB_ENTREPRISE_MIN_VEHICLES et tous les véhicules
    // déjà enregistrés ; au-delà de SUB_ENTREPRISE_MAX_ONLINE, sur devis.
    $minVehicles = $clientType === 'ENTREPRISE' ? max(SUB_ENTREPRISE_MIN_VEHICLES, $fleetSize) : 0;
    $onlinePayable = $clientType !== 'ENTREPRISE' || $minVehicles <= SUB_ENTREPRISE_MAX_ONLINE;
    // Valeur proposée : la taille de l'abonnement actif s'il couvre plus de véhicules.
    $defaultVehicles = $clientType === 'ENTREPRISE'
        ? min(max($minVehicles, (int)($activeSub['nbVehicules'] ?? 0)), max($minVehicles, SUB_ENTREPRISE_MAX_ONLINE))
        : 0;
    $priceMensuel = $onlinePayable ? subscriptionPrice($clientType, 'MENSUEL', $defaultVehicles) : null;
    $priceAnnuel = $onlinePayable ? subscriptionPrice($clientType, 'ANNUEL', $defaultVehicles) : null;

    // Paiement d'abonnement encore en attente de confirmation sur le téléphone.
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM paiement
        WHERE idClient = ? AND idAbonnement IS NOT NULL AND statut = 'EN_ATTENTE' AND datePaiement > ?
    ");
    $stmt->execute([$clientId, date('Y-m-d H:i:s', time() - PAYMENT_PENDING_LOCK_SECONDS)]);
    $pendingPayment = (int)$stmt->fetchColumn() > 0;
}

/** Montant en FCFA avec séparateur de milliers (ex. 10 000). */
function subscriptionPageAmount(int $amount): string {
    return number_format($amount, 0, ',', ' ');
}

$pageTitle = 'Abonnement';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'abonnement'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">
        <div>
            <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
            <h1 class="v2-h1">Abonnement</h1>
            <p class="v2-sub">Votre formule actuelle et les prochaines offres SmartAutoTrack.</p>
        </div>

        <?php if (!$subscriptionsEnabled): ?>
        <div class="v2-card v2-panel" style="max-width: 560px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:17px;">Formule actuelle</h2>
                <span style="font-size:11px; font-weight:700; color:#6D74A0; background:#EFF0F6; padding:4px 10px; border-radius:20px;">GRATUIT</span>
            </div>
            <?php if ($isEntreprise): ?>
                <p style="color:#666C8E; font-size:14px; line-height:1.6;">
                    Vous utilisez actuellement la formule gratuite de SmartAutoTrack pour votre parc de
                    <?php echo (int)$fleetSize; ?> véhicule<?php echo $fleetSize > 1 ? 's' : ''; ?> : supervision de flotte,
                    demandes d'intervention, historique de réparations, consultation des anomalies, messagerie et notifications.
                </p>
                <div class="v2-alert" style="background:#F3F5FE; color:#2540C4; margin-top:16px;">
                    Les formules Premium entreprise seront proposées par <strong>paliers selon la taille de votre parc</strong>
                    (nombre de véhicules suivis), avec suivi prioritaire et délais d'intervention réduits.
                    Les tarifs et paliers n'ont pas encore été activés — revenez bientôt.
                </div>
            <?php else: ?>
                <p style="color:#666C8E; font-size:14px; line-height:1.6;">
                    Vous utilisez actuellement la formule gratuite de SmartAutoTrack : suivi de vos véhicules,
                    demandes d'intervention, historique de réparations, messagerie et notifications.
                </p>
                <div class="v2-alert" style="background:#F3F5FE; color:#3956E8; margin-top:16px;">
                    Les formules Premium (suivi prioritaire, délais d'intervention réduits, options supplémentaires)
                    sont en cours de définition. Les tarifs et paliers n'ont pas encore été activés — revenez bientôt.
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <?php if (($_GET['success'] ?? '') === 'trial'): ?>
            <div class="v2-alert success">Votre essai Premium de <?php echo (int)SUB_TRIAL_DAYS; ?> jours est activé. Profitez-en !</div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="v2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>
        <?php if ($pendingPayment): ?>
            <div class="v2-alert" style="background:var(--v2-warning-bg); color:var(--v2-warning);">
                Un paiement d'abonnement est en attente de confirmation sur votre téléphone. Votre abonnement sera activé dès sa validation.
            </div>
        <?php endif; ?>

        <div class="v2-body">
            <div class="v2-col">
                <!-- Formule actuelle -->
                <div class="v2-card v2-panel">
                    <div class="v2-panel-head">
                        <h2>Formule actuelle</h2>
                        <?php if ($isPremium): ?>
                            <span class="v2-badge ok">PREMIUM</span>
                        <?php else: ?>
                            <span class="v2-badge neutral">GRATUIT</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isPremium): ?>
                        <p style="color:#666C8E; font-size:14px; line-height:1.6; margin:0 0 14px;">
                            Formule Premium (<?php echo h($periodLabels[$activeSub['periodicite']] ?? $activeSub['periodicite']); ?>),
                            active jusqu'au <strong><?php echo h($premiumUntil->format('d/m/Y')); ?></strong>.
                        </p>
                    <?php else: ?>
                        <p style="color:#666C8E; font-size:14px; line-height:1.6; margin:0 0 14px;">
                            Vous utilisez la formule gratuite de SmartAutoTrack.
                        </p>
                    <?php endif; ?>
                    <div class="v2-stats" style="grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px;">
                        <div class="v2-card v2-stat-card">
                            <div>
                                <div class="v2-stat-value"><?php echo (int)$fleetSize; ?> / <?php echo (int)$vehicleLimit; ?></div>
                                <div class="v2-stat-label">Véhicules utilisés / autorisés</div>
                            </div>
                        </div>
                        <div class="v2-card v2-stat-card">
                            <div>
                                <div class="v2-stat-value"><?php echo h($aiRemaining === null ? 'Illimité' : $aiRemaining . ' / ' . SUB_FREE_AI_PER_DAY); ?></div>
                                <div class="v2-stat-label"><?php echo h($aiRemaining === null ? 'Messages à l\'assistant IA' : 'Messages à l\'assistant IA restants aujourd\'hui'); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php if ($fleetSize > $vehicleLimit): ?>
                        <p class="v2-note">
                            Vous conservez vos <?php echo (int)$fleetSize; ?> véhicules déjà enregistrés ; l'ajout de nouveaux véhicules
                            est bloqué tant que vous dépassez la limite de votre formule.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Comparatif Gratuit / Premium (uniquement ce qui est en place) -->
                <div class="v2-card v2-panel">
                    <div class="v2-panel-head"><h2>Gratuit ou Premium ?</h2></div>
                    <table class="v2-mini-table">
                        <thead>
                            <tr><th></th><th>Gratuit</th><th>Premium</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Véhicules</strong></td>
                                <?php if ($clientType === 'ENTREPRISE'): ?>
                                    <td><?php echo (int)SUB_FREE_VEHICLES_ENTREPRISE; ?> véhicules</td>
                                    <td>Le nombre choisi (<?php echo (int)SUB_ENTREPRISE_MIN_VEHICLES; ?> à <?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?>, au-delà sur devis)</td>
                                <?php else: ?>
                                    <td><?php echo (int)SUB_FREE_VEHICLES_PARTICULIER; ?> véhicule</td>
                                    <td>Jusqu'à <?php echo (int)SUB_PREMIUM_VEHICLES_PARTICULIER; ?> véhicules</td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td><strong>Assistant IA</strong></td>
                                <td><?php echo (int)SUB_FREE_AI_PER_DAY; ?> messages par jour</td>
                                <td>Illimité</td>
                            </tr>
                            <tr>
                                <td><strong>Historique consultable</strong></td>
                                <td><?php echo (int)SUB_FREE_HISTORY_MONTHS; ?> derniers mois</td>
                                <td>Complet</td>
                            </tr>
                            <tr>
                                <td><strong>Interventions, suivi, rapports, paiement des réparations, messagerie, notifications, alertes</strong></td>
                                <td>Inclus</td>
                                <td>Inclus</td>
                            </tr>
                            <?php if ($maintenanceEnabled): ?>
                            <tr>
                                <td><strong>Rappels d'entretien et d'échéances</strong><br><span class="v2-note" style="margin:0;">Assurance, visite technique, vidange, freins, pneus</span></td>
                                <td>Échéances affichées sur le tableau de bord et la fiche du véhicule</td>
                                <td>Échéances affichées et rappels automatiques : notification 30 jours, 7 jours et le jour même, email 7 jours avant et en cas de retard</td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td><strong>Prix</strong></td>
                                <td>Gratuit</td>
                                <?php if ($clientType === 'ENTREPRISE'): ?>
                                    <td>2 000, 1 500 ou 1 200 FCFA par véhicule et par mois selon la taille du parc (1–5, 6–20, 21–50) ; annuel = 10 mois</td>
                                <?php else: ?>
                                    <td><?php echo h(subscriptionPageAmount(SUB_PRICE_PARTICULIER_MENSUEL)); ?> FCFA / mois ou <?php echo h(subscriptionPageAmount(SUB_PRICE_PARTICULIER_ANNUEL)); ?> FCFA / an</td>
                                <?php endif; ?>
                            </tr>
                        </tbody>
                    </table>
                    <p class="v2-note"><?php echo h($maintenanceEnabled ? 'Bientôt : demandes prioritaires…' : "Bientôt : rappels d'entretien, carnet d'entretien, demandes prioritaires…"); ?></p>
                </div>
            </div>

            <div class="v2-col">
                <?php if ($trialAvailable): ?>
                <!-- Essai Premium : une seule fois par client -->
                <div class="v2-card v2-panel-sm">
                    <div class="v2-panel-head"><h2>Premier mois offert</h2></div>
                    <p style="color:#666C8E; font-size:13.5px; line-height:1.6; margin:0 0 14px;">
                        Essayez Premium gratuitement pendant <?php echo (int)SUB_TRIAL_DAYS; ?> jours, sans paiement.
                        Cet essai n'est proposé qu'une seule fois.
                    </p>
                    <form method="POST" action="abonnement.php">
                        <input type="hidden" name="form" value="start_trial">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="v2-btn-accent">Essayer 1 mois gratuitement</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Souscription / prolongation -->
                <div class="v2-card v2-panel-sm">
                    <div class="v2-panel-head"><h2><?php echo $isPremium ? 'Prolonger Premium' : 'Passer à Premium'; ?></h2></div>
                    <?php if ($isPremium): ?>
                        <p class="v2-note" style="margin:0 0 12px;">La nouvelle période commencera à la fin de votre abonnement actuel (<?php echo h($premiumUntil->format('d/m/Y')); ?>).</p>
                    <?php endif; ?>

                    <?php if (!$onlinePayable): ?>
                        <div class="v2-alert" style="background:#F3F5FE; color:#2540C4;">
                            Au-delà de <?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?> véhicules, l'abonnement Premium se fait sur devis :
                            <a href="sav.php">contactez-nous</a>.
                        </div>
                    <?php elseif (!$paymentsEnabled): ?>
                        <div class="v2-alert" style="background:#F3F5FE; color:#3956E8;">
                            Le paiement en ligne n'est pas encore disponible. Revenez bientôt.
                        </div>
                    <?php else: ?>
                        <?php if ($clientType === 'ENTREPRISE'): ?>
                            <div class="v2-form-group">
                                <label for="subVehicles">Nombre de véhicules couverts</label>
                                <input type="number" id="subVehicles" data-only="digits" inputmode="numeric"
                                       min="<?php echo (int)$minVehicles; ?>" max="<?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?>" value="<?php echo (int)$defaultVehicles; ?>"
                                       style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
                                <p class="v2-note">Au moins <?php echo (int)$minVehicles; ?> (vos véhicules actuels, minimum <?php echo (int)SUB_ENTREPRISE_MIN_VEHICLES; ?>).</p>
                            </div>
                            <div class="v2-alert" id="subQuote" style="display:none; background:#F3F5FE; color:#2540C4;">
                                Au-delà de <?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?> véhicules, l'abonnement se fait sur devis :
                                <a href="sav.php">contactez-nous</a>.
                            </div>
                        <?php endif; ?>
                        <div id="subOffer">
                            <div class="v2-form-group">
                                <label style="display:flex; align-items:center; gap:8px; font-weight:600; font-size:13.5px; cursor:pointer;">
                                    <input type="radio" name="subPeriod" value="MENSUEL" checked>
                                    Mensuel — <span id="subPriceMensuel"><?php echo h(subscriptionPageAmount((int)$priceMensuel)); ?></span> FCFA
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; font-weight:600; font-size:13.5px; cursor:pointer; margin-top:8px;">
                                    <input type="radio" name="subPeriod" value="ANNUEL">
                                    Annuel — <span id="subPriceAnnuel"><?php echo h(subscriptionPageAmount((int)$priceAnnuel)); ?></span> FCFA (2 mois offerts)
                                </label>
                            </div>
                            <button type="button" class="v2-btn-dark" id="subPayBtn">Payer par Mobile Money</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($subscriptionsEnabled && $paymentsEnabled && $onlinePayable): ?>
<!-- Modal paiement Mobile Money (même fonctionnement que client/reparations.php) -->
<div class="v2-modal-overlay" id="paymentModal">
    <div class="v2-modal">
        <h3>Payer par Mobile Money</h3>
        <div id="paymentStepForm">
            <p class="v2-modal-sub"><strong id="paymentTitre"></strong></p>
            <p style="font-size:14px;">Montant à payer : <strong><span id="paymentMontant"></span> FCFA</strong></p>
            <div class="v2-form-group">
                <label for="paymentPhone">Numéro MTN Mobile Money ou Orange Money</label>
                <input type="tel" id="paymentPhone" data-only="digits" placeholder="Ex. 677123456" inputmode="numeric" autocomplete="tel" maxlength="16"
                       style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <p class="v2-note">Une demande de confirmation sera envoyée sur ce téléphone.</p>
            <div class="v2-modal-actions">
                <button type="button" class="v2-btn-outline" id="closePaymentModal">Fermer</button>
                <button type="button" class="v2-btn-primary" id="paymentSubmit">Payer</button>
            </div>
        </div>
        <div id="paymentStepWait" style="display: none; text-align: center; padding: 1rem 0;">
            <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--v2-accent);"></i>
            <p id="paymentWaitText" style="margin-top: 1rem; font-size:13.5px;"></p>
            <div class="v2-modal-actions" style="justify-content:center;">
                <button type="button" class="v2-btn-outline" id="closePaymentWait">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {

// Prix Premium : mêmes règles que subscriptionPrice() (includes/subscription.php),
// pour l'affichage seulement ; le serveur recalcule le montant réel.
const SUB_TYPE = <?php echo json_encode($clientType); ?>;
const SUB_PRICE_MENSUEL = <?php echo (int)SUB_PRICE_PARTICULIER_MENSUEL; ?>;
const SUB_PRICE_ANNUEL = <?php echo (int)SUB_PRICE_PARTICULIER_ANNUEL; ?>;
const SUB_MIN_VEHICLES = <?php echo (int)$minVehicles; ?>;
const SUB_MAX_ONLINE = <?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?>;

function entrepriseUnitPrice(n) {
    if (n < 1 || n > SUB_MAX_ONLINE) return null;
    if (n <= 5) return 2000;
    return n <= 20 ? 1500 : 1200;
}

function subscriptionPrice(periodicite, n) {
    if (SUB_TYPE !== 'ENTREPRISE') {
        return periodicite === 'MENSUEL' ? SUB_PRICE_MENSUEL : SUB_PRICE_ANNUEL;
    }
    if (n < SUB_MIN_VEHICLES) return null;
    const unit = entrepriseUnitPrice(n);
    if (unit === null) return null;
    return periodicite === 'MENSUEL' ? n * unit : 10 * n * unit;
}

function selectedVehicles() {
    return SUB_TYPE === 'ENTREPRISE' ? parseInt($('#subVehicles').val(), 10) || 0 : 0;
}

function refreshPrices() {
    if (SUB_TYPE !== 'ENTREPRISE') return;
    const n = selectedVehicles();
    const tooMany = n > SUB_MAX_ONLINE;
    $('#subQuote').toggle(tooMany);
    $('#subOffer').toggle(!tooMany);
    const mensuel = subscriptionPrice('MENSUEL', n);
    const annuel = subscriptionPrice('ANNUEL', n);
    $('#subPriceMensuel').text(mensuel === null ? '—' : mensuel.toLocaleString('fr-FR'));
    $('#subPriceAnnuel').text(annuel === null ? '—' : annuel.toLocaleString('fr-FR'));
    $('#subPayBtn').prop('disabled', mensuel === null);
}

$('#subVehicles').on('input change', refreshPrices);

let paymentPeriod = null;
let paymentVehicles = 0;
let paymentPollTimer = null;
let paymentLaunched = false;

$('#subPayBtn').click(function() {
    paymentPeriod = $('input[name="subPeriod"]:checked').val();
    paymentVehicles = selectedVehicles();
    const montant = subscriptionPrice(paymentPeriod, paymentVehicles);
    if (montant === null) {
        showToast('Choisissez au moins ' + SUB_MIN_VEHICLES + ' véhicules', 'error');
        return;
    }
    $('#paymentTitre').text('Premium ' + (paymentPeriod === 'MENSUEL' ? 'mensuel' : 'annuel')
        + (SUB_TYPE === 'ENTREPRISE' ? ' — ' + paymentVehicles + ' véhicules' : ''));
    $('#paymentMontant').text(montant.toLocaleString('fr-FR'));
    $('#paymentStepWait').hide();
    $('#paymentStepForm').show();
    $('#paymentModal').addClass('show');
});

$('#closePaymentModal, #closePaymentWait').click(function() {
    clearTimeout(paymentPollTimer);
    $('#paymentModal').removeClass('show');
    if (paymentLaunched) {
        location.reload();
    }
});

$('#paymentSubmit').click(function() {
    const phone = $('#paymentPhone').val().trim();
    if (!phone) {
        showToast('Saisissez votre numéro Mobile Money', 'error');
        return;
    }
    const submit = $(this).prop('disabled', true);
    $.ajax({
        url: SITE_URL + 'ajax/subscription_collect.php',
        method: 'POST',
        dataType: 'json',
        data: { periodicite: paymentPeriod, nb_vehicules: paymentVehicles, telephone: phone },
        success: function(response) {
            paymentLaunched = true;
            $('#paymentStepForm').hide();
            $('#paymentStepWait').show();
            $('#paymentWaitText').text('Confirmez le paiement sur votre téléphone'
                + (response.ussd_code ? ' (ou composez ' + response.ussd_code + ')' : '')
                + '. Cette fenêtre se met à jour automatiquement.');
            pollPayment(response.paiement_id, 0);
        },
        error: function(xhr) {
            showToast((xhr.responseJSON && xhr.responseJSON.message) || 'Erreur lors du lancement du paiement', 'error');
        },
        complete: function() {
            submit.prop('disabled', false);
        }
    });
});

function pollPayment(paiementId, attempt) {
    if (attempt >= 36) {
        $('#paymentWaitText').text('Paiement toujours en attente. Si vous l\'avez validé, votre abonnement sera activé d\'ici quelques minutes.');
        return;
    }
    paymentPollTimer = setTimeout(function() {
        $.ajax({
            url: SITE_URL + 'ajax/campay_status.php',
            method: 'POST',
            dataType: 'json',
            data: { paiement_id: paiementId },
            success: function(response) {
                if (response.statut === 'PAYE') {
                    showToast('Paiement reçu, votre abonnement Premium est actif. Merci !', 'success');
                    setTimeout(function() { location.reload(); }, 1200);
                } else if (response.statut === 'ECHOUE') {
                    showToast('Le paiement a échoué ou a été refusé.', 'error');
                    paymentLaunched = false;
                    $('#paymentStepWait').hide();
                    $('#paymentStepForm').show();
                } else {
                    pollPayment(paiementId, attempt + 1);
                }
            },
            error: function() {
                pollPayment(paiementId, attempt + 1);
            }
        });
    }, 5000);
}

});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
