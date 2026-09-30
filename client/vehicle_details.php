<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

/**
 * Fiche détaillée d'un véhicule du client.
 *
 * Accès : rôle client uniquement, et seulement pour ses propres véhicules :
 * un id absent ou appartenant à un autre client renvoie vers vehicles.php.
 * GET : id (identifiant du véhicule, obligatoire).
 * Lecture seule : les détails d'une anomalie ou d'une réparation sont chargés
 * en AJAX (ajax/get_anomaly_details.php, ajax/get_reparation_details.php).
 * Tables lues : vehicule, anomalie, reparation, intervention, utilisateur.
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');
// Badge de la sidebar : interventions actives (planifiées ou en cours) du client.
$stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmtBadge->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmtBadge->fetchColumn();

// Récupérer l'ID du véhicule
$vehicle_id = $_GET['id'] ?? null;

if (!$vehicle_id) {
    header('Location: vehicles.php');
    exit;
}

// Vérifier que le véhicule appartient au client (etat reconverti en statut
// historique minuscule pour laisser le gabarit d'affichage inchangé)
$stmt = $conn->prepare("
    SELECT idVehicule AS id, marque, modele, immatriculation, annee, couleur, kilometrage, dateCreation AS created_at,
           CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
    FROM vehicule WHERE idVehicule = ? AND idClient = ?
");
$stmt->execute([$vehicle_id, $_SESSION['user_id']]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    header('Location: vehicles.php');
    exit;
}

// La propriété du véhicule est vérifiée ci-dessus : les requêtes suivantes
// peuvent filtrer sur son seul identifiant.
// Récupérer les anomalies du véhicule
$stmt = $conn->prepare("
    SELECT idAnomalie AS id, description, dateDetection AS date_detection, dateResolution AS date_resolution,
           COALESCE(type, 'Anomalie') AS type, LOWER(niveau) AS niveau,
           CASE statut WHEN 'NOUVELLE' THEN 'detectee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'resolue' END AS statut
    FROM anomalie
    WHERE idVehicule = ?
    ORDER BY dateDetection DESC
");
$stmt->execute([$vehicle_id]);
$anomalies = $stmt->fetchAll();

// Récupérer les réparations du véhicule (via son intervention : plus de
// vehicle_id/anomalie_id directs sur reparation dans le nouveau schéma)
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.description, r.titre, r.diagnostic,
           r.travauxEffectues AS travaux_effectues, r.piecesUtilisees AS pieces_utilisees, r.recommandations,
           r.cout, r.dureeIntervention AS duree_intervention, r.idTechnicien AS technicien_id,
           r.dateReparation AS created_at,
           CASE r.statut WHEN 'EN_ATTENTE' THEN 'planifiee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'validee' END AS statut,
           ut.prenom as technicien_prenom, ut.nom as technicien_nom,
           NULL as anomalie_type
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
    WHERE i.idVehicule = ?
    ORDER BY r.dateReparation DESC
");
$stmt->execute([$vehicle_id]);
$reparations = $stmt->fetchAll();

// Récupérer les interventions du véhicule
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type AS type_intervention, i.description,
           i.dateIntervention AS date_planifiee, LOWER(i.statut) AS statut,
           u.prenom as technicien_prenom, u.nom as technicien_nom
    FROM intervention i
    LEFT JOIN utilisateur u ON i.idTechnicien = u.idUtilisateur
    WHERE i.idVehicule = ?
    ORDER BY i.dateIntervention DESC
");
$stmt->execute([$vehicle_id]);
$interventions = $stmt->fetchAll();

// Statistiques
$stats = [];
$stats['anomalies_total'] = count($anomalies);
$stats['anomalies_actives'] = count(array_filter($anomalies, fn($a) => $a['statut'] !== 'resolue'));
$stats['reparations_total'] = count($reparations);
$stats['reparations_en_cours'] = count(array_filter($reparations, fn($r) => in_array($r['statut'], ['planifiee', 'en_cours'])));
$stats['interventions_total'] = count($interventions);
$stats['interventions_planifiees'] = count(array_filter($interventions, fn($i) => $i['statut'] === 'planifiee'));

$pageTitle = 'Détails du véhicule - ' . $vehicle['marque'] . ' ' . $vehicle['modele'];
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'vehicules'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>
    <main class="v2-main">

<div class="v2-page-head">
    <div>
        <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
        <h1 class="v2-h1"><?php echo h($vehicle['marque'] . ' ' . $vehicle['modele']); ?></h1>
        <p class="v2-sub">Détails complets et historique du véhicule</p>
    </div>
    <a href="vehicles.php" class="v2-btn-outline" style="text-decoration:none; display:inline-block;">← Retour</a>
</div>

<!-- Informations du véhicule -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-info-circle"></i> Informations du véhicule</h3>
    </div>
    <div class="card-body">
        <div class="vehicle-info-grid">
            <div class="vehicle-info-item">
                <div class="info-label">Marque</div>
                <div class="info-value"><?php echo h($vehicle['marque']); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Modèle</div>
                <div class="info-value"><?php echo h($vehicle['modele']); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Immatriculation</div>
                <div class="info-value"><?php echo h($vehicle['immatriculation']); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Année</div>
                <div class="info-value"><?php echo h($vehicle['annee'] ?? 'N/A'); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Couleur</div>
                <div class="info-value"><?php echo h($vehicle['couleur'] ?? 'N/A'); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Kilométrage</div>
                <div class="info-value"><?php echo number_format($vehicle['kilometrage']); ?> km</div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Statut</div>
                <div class="info-value">
                    <span class="badge badge-<?php echo h($vehicle['statut'] === 'actif' ? 'success' : 
                            ($vehicle['statut'] === 'en_panne' ? 'danger' : 'warning')); ?>">
                        <?php echo h(ucfirst($vehicle['statut'])); ?>
                    </span>
                </div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Date d'enregistrement</div>
                <div class="info-value"><?php echo formatDate($vehicle['created_at']); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Statistiques -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon danger">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['anomalies_actives']; ?></div>
        <div class="stat-label">Anomalies actives</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-tools"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['reparations_en_cours']; ?></div>
        <div class="stat-label">Réparations en cours</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['interventions_planifiees']; ?></div>
        <div class="stat-label">Interventions planifiées</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-history"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['reparations_total']; ?></div>
        <div class="stat-label">Total réparations</div>
    </div>
</div>

<div class="row">
    <!-- Anomalies récentes -->
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Anomalies récentes</h3>
                <a href="anomalies.php?vehicle=<?php echo $vehicle_id; ?>" class="btn btn-outline btn-sm">Voir toutes</a>
            </div>
            <div class="card-body">
                <?php if (empty($anomalies)): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-check-circle"></i>
                        <p>Aucune anomalie détectée</p>
                    </div>
                <?php else: ?>
                    <div class="anomalies-list">
                        <?php foreach (array_slice($anomalies, 0, 5) as $anomalie): ?>
                            <div class="anomaly-item-small">
                                <div class="anomaly-icon-small">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="anomaly-content-small">
                                    <div class="anomaly-title"><?php echo h($anomalie['type']); ?></div>
                                    <div class="anomaly-meta">
                                        <span class="badge badge-<?php echo h($anomalie['niveau'] === 'critique' ? 'danger' : 
                                                ($anomalie['niveau'] === 'moyen' ? 'warning' : 'info')); ?>">
                                            <?php echo h(ucfirst($anomalie['niveau'])); ?>
                                        </span>
                                        <span class="date"><?php echo formatDate($anomalie['date_detection']); ?></span>
                                    </div>
                                </div>
                                <div class="anomaly-actions-small">
                                    <button class="btn btn-outline btn-xs" onclick="viewAnomalyDetails(<?php echo $anomalie['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Réparations récentes -->
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tools"></i> Réparations récentes</h3>
                <a href="reparations.php?vehicle=<?php echo $vehicle_id; ?>" class="btn btn-outline btn-sm">Voir toutes</a>
            </div>
            <div class="card-body">
                <?php if (empty($reparations)): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-tools"></i>
                        <p>Aucune réparation enregistrée</p>
                    </div>
                <?php else: ?>
                    <div class="reparations-list">
                        <?php foreach (array_slice($reparations, 0, 5) as $reparation): ?>
                            <div class="reparation-item-small">
                                <div class="reparation-content-small">
                                    <div class="reparation-title"><?php echo h($reparation['titre'] ?? 'Réparation #' . $reparation['id']); ?></div>
                                    <div class="reparation-meta">
                                        <span class="badge badge-<?php echo h($reparation['statut'] === 'validee' ? 'success' : 
                                                ($reparation['statut'] === 'terminee' ? 'info' : 
                                                ($reparation['statut'] === 'en_cours' ? 'warning' : 'primary'))); ?>">
                                            <?php echo h(ucfirst($reparation['statut'])); ?>
                                        </span>
                                        <span class="date"><?php echo formatDate($reparation['created_at']); ?></span>
                                        <span class="cost"><?php echo number_format($reparation['cout'], 0, ',', ' '); ?> XAF</span>
                                    </div>
                                </div>
                                <div class="reparation-actions-small">
                                    <button class="btn btn-outline btn-xs" onclick="viewReparationDetails(<?php echo $reparation['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Interventions planifiées -->
<?php if (!empty($interventions)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-check"></i> Interventions planifiées</h3>
    </div>
    <div class="card-body">
        <div class="interventions-list">
            <?php foreach ($interventions as $intervention): ?>
                <div class="intervention-item">
                    <div class="intervention-header">
                        <div class="intervention-info">
                            <h4><?php echo h($intervention['type_intervention']); ?></h4>
                            <div class="intervention-meta">
                                <span class="date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo formatDate($intervention['date_planifiee']); ?>
                                </span>
                                <span class="technicien">
                                    <i class="fas fa-user"></i>
                                    <?php echo h($intervention['technicien_prenom'] . ' ' . $intervention['technicien_nom']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="intervention-status">
                            <span class="badge badge-<?php echo h($intervention['statut'] === 'terminee' ? 'success' : 
                                    ($intervention['statut'] === 'en_cours' ? 'warning' : 'primary')); ?>">
                                <?php echo h(ucfirst($intervention['statut'])); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($intervention['description']): ?>
                        <div class="intervention-description">
                            <p><?php echo h($intervention['description']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modals -->
<div id="anomalyModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3>Détails de l'anomalie</h3>
            <button class="modal-close" id="closeAnomalyModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="anomalyDetails">
            <!-- Le contenu sera chargé via AJAX -->
        </div>
    </div>
</div>

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

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.page-header-content h1 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.page-header-content p {
    color: var(--text-light);
    margin: 0;
}

.page-header-actions {
    display: flex;
    gap: 1rem;
}

.vehicle-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}

.vehicle-info-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.info-label {
    font-weight: 600;
    color: var(--text-light);
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    color: var(--text-color);
    font-size: 1.1rem;
    font-weight: 500;
}

.anomalies-list, .reparations-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.anomaly-item-small, .reparation-item-small {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--background-color);
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
    transition: var(--transition);
}

.anomaly-item-small:hover, .reparation-item-small:hover {
    box-shadow: var(--shadow);
}

.anomaly-icon-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: rgba(220, 53, 69, 0.1);
    color: var(--danger-color);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.anomaly-content-small, .reparation-content-small {
    flex: 1;
}

.anomaly-title, .reparation-title {
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--text-color);
}

.anomaly-meta, .reparation-meta {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.anomaly-meta .date, .reparation-meta .date {
    font-size: 0.8rem;
    color: var(--text-light);
}

.reparation-meta .cost {
    font-size: 0.8rem;
    color: var(--primary-color);
    font-weight: 600;
}

.anomaly-actions-small, .reparation-actions-small {
    display: flex;
    gap: 0.5rem;
}

.btn-xs {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.interventions-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.intervention-item {
    background: var(--background-color);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 1.5rem;
}

.intervention-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.intervention-info h4 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.intervention-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.intervention-meta span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: var(--text-light);
}

.intervention-meta i {
    color: var(--primary-color);
}

.intervention-description {
    background: var(--secondary-color);
    padding: 1rem;
    border-radius: var(--border-radius);
}

.intervention-description p {
    margin: 0;
    color: var(--text-light);
    line-height: 1.6;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
    
    .vehicle-info-grid {
        grid-template-columns: 1fr;
    }
    
    .row {
        flex-direction: column;
    }
    
    .intervention-meta {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .intervention-header {
        flex-direction: column;
        gap: 1rem;
    }
}
</style>

<script>
$(document).ready(function() {
    // Fermer les modals
    $('#closeAnomalyModal, #closeReparationModal').click(function() {
        $('#anomalyModal, #reparationModal').fadeOut();
    });
    
    // Fermer les modals en cliquant à l'extérieur
    $('#anomalyModal, #reparationModal').click(function(e) {
        if (e.target === this) {
            $(this).fadeOut();
        }
    });
});

function viewAnomalyDetails(anomalyId) {
    $.ajax({
        url: SITE_URL + 'ajax/get_anomaly_details.php',
        method: 'GET',
        data: { id: anomalyId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayAnomalyDetails(response.anomaly);
                $('#anomalyModal').fadeIn();
            } else {
                showToast('Erreur lors du chargement des détails', 'error');
            }
        },
        error: function() {
            showToast('Erreur lors du chargement des détails', 'error');
        }
    });
}

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

function displayAnomalyDetails(anomaly) {
    const details = $(`
        <div class="anomaly-details-full">
            <div class="detail-section">
                <h4>Informations de l'anomalie</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Type :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(anomaly.type)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Niveau :</label>
                        <span class="badge badge-${anomaly.niveau === 'critique' ? 'danger' : (anomaly.niveau === 'moyen' ? 'warning' : 'info')}">${SmartAutoTrack.utils.escapeHtml(anomaly.niveau)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Statut :</label>
                        <span class="badge badge-${anomaly.statut === 'resolue' ? 'success' : (anomaly.statut === 'en_cours' ? 'warning' : 'primary')}">${SmartAutoTrack.utils.escapeHtml(anomaly.statut)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Date de détection :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(anomaly.date_detection)}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Description</h4>
                <p>${SmartAutoTrack.utils.escapeHtml(anomaly.description)}</p>
            </div>
        </div>
    `);
    
    $('#anomalyDetails').html(details);
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
                        <label>Date :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.created_at)}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Description</h4>
                <p>${SmartAutoTrack.utils.escapeHtml(reparation.description)}</p>
            </div>
        </div>
    `);
    
    $('#reparationDetails').html(details);
}
</script>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
