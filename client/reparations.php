<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/payments.php';

requireRole('client');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');
$stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmtBadge->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmtBadge->fetchColumn();

// Récupérer les filtres
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$vehicle_filter = $_GET['vehicle'] ?? '';

// Construire la clause WHERE (la réparation n'a plus de vehicle_id direct :
// on passe par son intervention, qui porte idVehicule et idClient)
$where_conditions = ['i.idClient = ?'];
$params = [$_SESSION['user_id']];

// Le nouveau statut de réparation n'a que 3 valeurs (EN_ATTENTE/EN_COURS/TERMINEE),
// sans étape de validation distincte comme avant : "terminée" et "validée" pointent
// donc toutes les deux vers TERMINEE, pour que le téléchargement du rapport
// (déclenché sur l'ancien statut 'validee') continue de fonctionner une fois la
// réparation achevée.
$statusFilterMap = ['planifiee' => ['EN_ATTENTE'], 'en_cours' => ['EN_COURS'], 'terminee' => ['TERMINEE'], 'validee' => ['TERMINEE']];
if ($status_filter && isset($statusFilterMap[$status_filter])) {
    $placeholders = implode(',', array_fill(0, count($statusFilterMap[$status_filter]), '?'));
    $where_conditions[] = "r.statut IN ($placeholders)";
    array_push($params, ...$statusFilterMap[$status_filter]);
}

if ($date_from) {
    $where_conditions[] = 'r.dateReparation >= ?';
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = 'r.dateReparation <= ?';
    $params[] = $date_to . ' 23:59:59';
}

if ($vehicle_filter) {
    $where_conditions[] = 'i.idVehicule = ?';
    $params[] = $vehicle_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Récupérer les réparations du client. Il n'y a plus de lien direct vers une
// anomalie précise dans le nouveau schéma (anomalie_type reste donc vide).
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.description, r.titre, r.diagnostic,
           r.travauxEffectues AS travaux_effectues, r.piecesUtilisees AS pieces_utilisees, r.recommandations,
           r.cout, r.dureeIntervention AS duree_intervention, r.idTechnicien AS technicien_id,
           r.dateReparation AS created_at,
           CASE r.statut WHEN 'EN_ATTENTE' THEN 'planifiee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'validee' END AS statut,
           v.marque, v.modele, v.immatriculation,
           ut.prenom as technicien_prenom, ut.nom as technicien_nom,
           NULL as anomalie_type
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
    WHERE $where_clause
    ORDER BY r.dateReparation DESC
");
$stmt->execute($params);
$reparations = $stmt->fetchAll();

$paymentsEnabled = campayIsConfigured() && paymentsReady($conn);
$paymentStates = $paymentsEnabled ? paymentStatesForRepairs($conn, array_column($reparations, 'id')) : [];

// Récupérer les véhicules du client pour le filtre
$stmt = $conn->prepare("SELECT idVehicule AS id, marque, modele, immatriculation FROM vehicule WHERE idClient = ? ORDER BY marque, modele");
$stmt->execute([$_SESSION['user_id']]);
$vehicules = $stmt->fetchAll();

// Statistiques
$stats = [];
$stats['total'] = count($reparations);
$stats['en_cours'] = count(array_filter($reparations, fn($r) => in_array($r['statut'], ['planifiee', 'en_cours'])));
$stats['terminees'] = count(array_filter($reparations, fn($r) => $r['statut'] === 'terminee'));
$stats['validees'] = count(array_filter($reparations, fn($r) => $r['statut'] === 'validee'));

$pageTitle = 'Mes Réparations';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'reparations'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>
    <main class="v2-main">

<div class="v2-page-head">
    <div>
        <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
        <h1 class="v2-h1">Réparations &amp; historique</h1>
        <p class="v2-sub">Historique complet de toutes vos réparations</p>
    </div>
</div>

<!-- Statistiques -->
<div class="v2-stats">
    <div class="v2-card v2-stat-card">
        <div class="v2-stat-icon" style="background:#EEF1FF;"><i class="fas fa-list" style="color:#3956E8;"></i></div>
        <div>
            <div class="v2-stat-value"><?php echo (int)$stats['total']; ?></div>
            <div class="v2-stat-label">Total réparations</div>
        </div>
    </div>
    <div class="v2-card v2-stat-card">
        <div class="v2-stat-icon" style="background:#FFF4E2;"><i class="fas fa-clock" style="color:#C8871A;"></i></div>
        <div>
            <div class="v2-stat-value"><?php echo (int)$stats['en_cours']; ?></div>
            <div class="v2-stat-label">En cours</div>
        </div>
    </div>
    <div class="v2-card v2-stat-card">
        <div class="v2-stat-icon" style="background:#E9F6EE;"><i class="fas fa-check" style="color:#1E8A4C;"></i></div>
        <div>
            <div class="v2-stat-value"><?php echo (int)$stats['terminees']; ?></div>
            <div class="v2-stat-label">Terminées</div>
        </div>
    </div>
    <div class="v2-card v2-stat-card">
        <div class="v2-stat-icon" style="background:#EEF1FF;"><i class="fas fa-check-double" style="color:#3956E8;"></i></div>
        <div>
            <div class="v2-stat-value"><?php echo (int)$stats['validees']; ?></div>
            <div class="v2-stat-label">Validées</div>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Filtres</h3>
    </div>
    <div class="card-body">
        <form method="GET" class="filters-form">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="status" class="form-control">
                        <option value="">Tous les statuts</option>
                        <option value="planifiee" <?php echo $status_filter === 'planifiee' ? 'selected' : ''; ?>>Planifiée</option>
                        <option value="en_cours" <?php echo $status_filter === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                        <option value="terminee" <?php echo $status_filter === 'terminee' ? 'selected' : ''; ?>>Terminée</option>
                        <option value="validee" <?php echo $status_filter === 'validee' ? 'selected' : ''; ?>>Validée</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Véhicule</label>
                    <select name="vehicle" class="form-control">
                        <option value="">Tous les véhicules</option>
                        <?php foreach ($vehicules as $vehicule): ?>
                            <option value="<?php echo $vehicule['id']; ?>" <?php echo $vehicle_filter == $vehicule['id'] ? 'selected' : ''; ?>>
                                <?php echo h($vehicule['marque'] . ' ' . $vehicule['modele'] . ' (' . $vehicule['immatriculation'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date de début</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo h($date_from); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date de fin</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo h($date_to); ?>">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filtrer
                </button>
                <a href="reparations.php" class="btn btn-outline">
                    <i class="fas fa-times"></i> Effacer
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Liste des réparations -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Historique des réparations</h3>
        <div class="card-header-actions">
            <span class="text-muted"><?php echo (int)$stats['total']; ?> réparation(s) trouvée(s)</span>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($reparations)): ?>
            <div class="empty-state">
                <i class="fas fa-tools"></i>
                <h3>Aucune réparation trouvée</h3>
                <p>Aucune réparation ne correspond à vos critères de recherche.</p>
                <a href="reparations.php" class="btn btn-primary">Voir toutes les réparations</a>
            </div>
        <?php else: ?>
            <div class="reparations-list">
                <?php foreach ($reparations as $reparation): ?>
                    <div class="reparation-item">
                        <div class="reparation-header">
                            <div class="reparation-info">
                                <h4><?php echo h($reparation['titre'] ?? 'Réparation #' . $reparation['id']); ?></h4>
                                <div class="reparation-meta">
                                    <span class="vehicle-info">
                                        <i class="fas fa-car"></i>
                                        <?php echo h($reparation['marque']) . ' ' . h($reparation['modele']) . ' (' . h($reparation['immatriculation']) . ')'; ?>
                                    </span>
                                    <span class="date-info">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo formatDate($reparation['created_at']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="reparation-status">
                                <span class="badge badge-<?php echo h($reparation['statut'] === 'validee' ? 'success' : 
                                        ($reparation['statut'] === 'terminee' ? 'info' : 
                                        ($reparation['statut'] === 'en_cours' ? 'warning' : 'primary'))); ?>">
                                    <?php echo h(ucfirst($reparation['statut'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="reparation-content">
                            <div class="reparation-description">
                                <p><?php echo h(substr($reparation['description'], 0, 150)) . (strlen($reparation['description']) > 150 ? '...' : ''); ?></p>
                            </div>
                            
                            <div class="reparation-details">
                                <div class="detail-item">
                                    <i class="fas fa-coins"></i>
                                    <span><?php echo number_format($reparation['cout'], 0, ',', ' '); ?> XAF</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo h($reparation['duree_intervention'] ?? 'N/A'); ?>h</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo h($reparation['technicien_prenom']) . ' ' . h($reparation['technicien_nom']); ?></span>
                                </div>
                                <?php if ($reparation['anomalie_type']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span><?php echo h($reparation['anomalie_type']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="reparation-actions">
                            <button class="btn btn-outline btn-sm" onclick="viewReparationDetails(<?php echo $reparation['id']; ?>)">
                                <i class="fas fa-eye"></i> Détails
                            </button>
                            <?php if ($reparation['statut'] === 'validee'): ?>
                                <button class="btn btn-primary btn-sm" onclick="downloadReport(<?php echo $reparation['id']; ?>)">
                                    <i class="fas fa-download"></i> Rapport
                                </button>
                            <?php endif; ?>
                            <?php if ($paymentsEnabled && $reparation['statut'] === 'validee' && (float)$reparation['cout'] > 0): $payState = $paymentStates[(int)$reparation['id']] ?? null; ?>
                                <?php if ($payState === 'PAYE'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Payée</span>
                                <?php elseif ($payState === 'EN_ATTENTE'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> Paiement en cours</span>
                                <?php else: ?>
                                    <button class="btn btn-primary btn-sm js-pay-repair"
                                            data-reparation-id="<?php echo (int)$reparation['id']; ?>"
                                            data-montant="<?php echo (int)round((float)$reparation['cout']); ?>"
                                            data-titre="<?php echo h($reparation['titre'] ?? 'Réparation #' . $reparation['id']); ?>">
                                        <i class="fas fa-mobile-alt"></i> Payer
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <button class="btn btn-outline btn-sm" onclick="sendMessage(<?php echo $reparation['technicien_id']; ?>)">
                                <i class="fas fa-envelope"></i> Contacter
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal détails réparation -->
<div id="reparationModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3>Détails de la réparation</h3>
            <button class="modal-close" id="closeReparationModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="reparationDetails">
            <!-- Le contenu sera chargé via AJAX -->
        </div>
    </div>
</div>

<?php if ($paymentsEnabled): ?>
<!-- Modal paiement Mobile Money -->
<div id="paymentModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Payer par Mobile Money</h3>
            <button class="modal-close" id="closePaymentModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div id="paymentStepForm">
                <p><strong id="paymentTitre"></strong></p>
                <p>Montant à payer : <strong><span id="paymentMontant"></span> XAF</strong></p>
                <div class="form-group">
                    <label class="form-label" for="paymentPhone">Numéro MTN Mobile Money ou Orange Money</label>
                    <input type="tel" id="paymentPhone" class="form-control" placeholder="6XX XX XX XX" inputmode="numeric" autocomplete="tel" maxlength="16">
                </div>
                <p class="text-muted" style="font-size: 0.85rem;">Une demande de confirmation sera envoyée sur ce téléphone.</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="paymentSubmit">
                        <i class="fas fa-mobile-alt"></i> Payer
                    </button>
                </div>
            </div>
            <div id="paymentStepWait" style="display: none; text-align: center; padding: 1rem 0;">
                <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--primary-color);"></i>
                <p id="paymentWaitText" style="margin-top: 1rem;"></p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.filters-form {
    margin-bottom: 0;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.reparations-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.reparation-item {
    background: var(--background-color);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    transition: var(--transition);
}

.reparation-item:hover {
    box-shadow: var(--shadow);
    transform: translateY(-2px);
}

.reparation-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.reparation-info h4 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.reparation-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.reparation-meta span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: var(--text-light);
}

.reparation-meta i {
    color: var(--primary-color);
}

.reparation-content {
    margin-bottom: 1.5rem;
}

.reparation-description {
    margin-bottom: 1rem;
}

.reparation-description p {
    color: var(--text-light);
    line-height: 1.6;
}

.reparation-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: var(--text-color);
}

.detail-item i {
    color: var(--primary-color);
    width: 16px;
}

.reparation-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.card-header-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .reparation-header {
        flex-direction: column;
        gap: 1rem;
    }
    
    .reparation-meta {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .reparation-details {
        grid-template-columns: 1fr;
    }
    
    .reparation-actions {
        flex-direction: column;
    }
    
    .form-actions {
        flex-direction: column;
    }
}
</style>

<script>
$(document).ready(function() {
    // Fermer le modal réparation
    $('#closeReparationModal').click(function() {
        $('#reparationModal').fadeOut();
    });
    
    // Fermer le modal en cliquant à l'extérieur
    $('#reparationModal').click(function(e) {
        if (e.target === this) {
            $(this).fadeOut();
        }
    });
});

function viewReparationDetails(reparationId) {
    $.ajax({
        url: SITE_URL + 'ajax/get_reparation_details.php',
        method: 'GET',
        data: { id: reparationId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayReparationDetails(response.reparation);
                $('#reparationModal').fadeIn();
            } else {
                showToast('Erreur lors du chargement des détails', 'error');
            }
        },
        error: function() {
            showToast('Erreur lors du chargement des détails', 'error');
        }
    });
}

function displayReparationDetails(reparation) {
    const details = $(`
        <div class="reparation-details-full">
            <div class="detail-section">
                <h4>Informations de la réparation</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Titre :</label>
                        <span>${reparation.titre || 'Réparation #' + reparation.id}</span>
                    </div>
                    <div class="detail-item">
                        <label>Statut :</label>
                        <span class="badge badge-${reparation.statut === 'validee' ? 'success' : (reparation.statut === 'terminee' ? 'info' : (reparation.statut === 'en_cours' ? 'warning' : 'primary'))}">${SmartAutoTrack.utils.escapeHtml(reparation.statut)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Coût :</label>
                        <span>${Math.round(parseFloat(reparation.cout)).toLocaleString('fr-FR')} XAF</span>
                    </div>
                    <div class="detail-item">
                        <label>Durée :</label>
                        <span>${reparation.duree_intervention || 'N/A'} heures</span>
                    </div>
                    <div class="detail-item">
                        <label>Technicien :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.technicien_prenom)} ${SmartAutoTrack.utils.escapeHtml(reparation.technicien_nom)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Date :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.created_at)}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Véhicule</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Marque/Modèle :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.marque)} ${SmartAutoTrack.utils.escapeHtml(reparation.modele)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Immatriculation :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.immatriculation)}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Description</h4>
                <p>${SmartAutoTrack.utils.escapeHtml(reparation.description)}</p>
            </div>
            
            ${reparation.diagnostic ? `
                <div class="detail-section">
                    <h4>Diagnostic</h4>
                    <p>${SmartAutoTrack.utils.escapeHtml(reparation.diagnostic)}</p>
                </div>
            ` : ''}
            
            ${reparation.travaux_effectues ? `
                <div class="detail-section">
                    <h4>Travaux effectués</h4>
                    <p>${SmartAutoTrack.utils.escapeHtml(reparation.travaux_effectues)}</p>
                </div>
            ` : ''}
            
            ${reparation.pieces_utilisees ? `
                <div class="detail-section">
                    <h4>Pièces utilisées</h4>
                    <p>${SmartAutoTrack.utils.escapeHtml(reparation.pieces_utilisees)}</p>
                </div>
            ` : ''}
            
            ${reparation.recommandations ? `
                <div class="detail-section">
                    <h4>Recommandations</h4>
                    <p>${SmartAutoTrack.utils.escapeHtml(reparation.recommandations)}</p>
                </div>
            ` : ''}
        </div>
    `);
    
    $('#reparationDetails').html(details);
}

function downloadReport(reparationId) {
    window.open(`../ajax/download_report.php?id=${reparationId}`, '_blank');
}

function sendMessage(technicienId) {
    window.location.href = `../messages/index.php?contact=${technicienId}`;
}

let paymentRepairId = null;
let paymentPollTimer = null;
let paymentLaunched = false;

$(document).on('click', '.js-pay-repair', function() {
    const btn = $(this);
    paymentRepairId = btn.data('reparation-id');
    $('#paymentTitre').text(btn.data('titre'));
    $('#paymentMontant').text(Number(btn.data('montant')).toLocaleString('fr-FR'));
    $('#paymentStepWait').hide();
    $('#paymentStepForm').show();
    $('#paymentModal').fadeIn();
});

$('#closePaymentModal').click(function() {
    clearTimeout(paymentPollTimer);
    $('#paymentModal').fadeOut();
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
        url: SITE_URL + 'ajax/campay_collect.php',
        method: 'POST',
        dataType: 'json',
        data: { reparation_id: paymentRepairId, telephone: phone },
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
        $('#paymentWaitText').text('Paiement toujours en attente. Si vous l\'avez validé, il apparaîtra comme payé d\'ici quelques minutes.');
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
                    showToast('Paiement reçu, merci !', 'success');
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
</script>

    </main>
</div>

<?php include '../includes/footer.php'; ?>