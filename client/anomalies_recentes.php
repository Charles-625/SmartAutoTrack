<?php
require_once '../config/config.php';
require_once '../config/database.php';

requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Récupérer les anomalies des 30 derniers jours (statut/niveau reconvertis vers
// la convention historique minuscule pour laisser le gabarit inchangé)
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description, a.dateDetection AS date_detection,
           COALESCE(a.type, 'Anomalie') AS type, LOWER(a.niveau) AS niveau,
           CASE a.statut WHEN 'NOUVELLE' THEN 'detectee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'resolue' END AS statut,
           v.marque, v.modele, v.immatriculation
    FROM anomalie a
    JOIN vehicule v ON a.idVehicule = v.idVehicule
    WHERE v.idClient = ?
    AND a.dateDetection >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY a.dateDetection DESC
");
$stmt->execute([$_SESSION['user_id']]);
$anomalies_recentes = $stmt->fetchAll();

// Statistiques pour les graphiques
$stats = [];

// Répartition par niveau
$stats['par_niveau'] = [
    'faible' => count(array_filter($anomalies_recentes, fn($a) => $a['niveau'] === 'faible')),
    'moyen' => count(array_filter($anomalies_recentes, fn($a) => $a['niveau'] === 'moyen')),
    'critique' => count(array_filter($anomalies_recentes, fn($a) => $a['niveau'] === 'critique'))
];

// Répartition par statut
$stats['par_statut'] = [
    'detectee' => count(array_filter($anomalies_recentes, fn($a) => $a['statut'] === 'detectee')),
    'en_cours' => count(array_filter($anomalies_recentes, fn($a) => $a['statut'] === 'en_cours')),
    'resolue' => count(array_filter($anomalies_recentes, fn($a) => $a['statut'] === 'resolue'))
];

// Répartition par véhicule
$vehicules_stats = [];
foreach ($anomalies_recentes as $anomalie) {
    $key = $anomalie['immatriculation'];
    if (!isset($vehicules_stats[$key])) {
        $vehicules_stats[$key] = [
            'immatriculation' => $anomalie['immatriculation'],
            'marque' => $anomalie['marque'],
            'modele' => $anomalie['modele'],
            'count' => 0
        ];
    }
    $vehicules_stats[$key]['count']++;
}
$stats['par_vehicule'] = array_values($vehicules_stats);

// Évolution dans le temps (7 derniers jours)
$evolution = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = count(array_filter($anomalies_recentes, function($a) use ($date) {
        return date('Y-m-d', strtotime($a['date_detection'])) === $date;
    }));
    $evolution[] = [
        'date' => $date,
        'count' => $count,
        'label' => date('d/m', strtotime($date))
    ];
}
$stats['evolution'] = $evolution;

// Types d'anomalies les plus fréquents
$types_stats = [];
foreach ($anomalies_recentes as $anomalie) {
    $type = $anomalie['type'];
    if (!isset($types_stats[$type])) {
        $types_stats[$type] = 0;
    }
    $types_stats[$type]++;
}
arsort($types_stats);
$stats['types_frequents'] = array_slice($types_stats, 0, 5, true);

$pageTitle = 'Anomalies récentes';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-content">
        <h1><i class="fas fa-chart-line"></i> Anomalies récentes</h1>
        <p>Analyse des anomalies détectées sur vos véhicules (30 derniers jours)</p>
    </div>
    <div class="page-header-actions">
        <a href="anomalies.php" class="btn btn-outline">
            <i class="fas fa-list"></i> Voir toutes
        </a>
    </div>
</div>

<!-- Statistiques générales -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-value"><?php echo count($anomalies_recentes); ?></div>
        <div class="stat-label">Total anomalies</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon danger">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="stat-value"><?php echo $stats['par_niveau']['critique']; ?></div>
        <div class="stat-label">Critiques</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-value"><?php echo $stats['par_statut']['en_cours']; ?></div>
        <div class="stat-label">En cours</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-check"></i>
        </div>
        <div class="stat-value"><?php echo $stats['par_statut']['resolue']; ?></div>
        <div class="stat-label">Résolues</div>
    </div>
</div>

<!-- Graphiques -->
<div class="row">
    <!-- Évolution dans le temps -->
    <div class="col-8">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Évolution des anomalies (7 derniers jours)</h3>
            </div>
            <div class="card-body">
                <canvas id="evolutionChart" height="100"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Répartition par niveau -->
    <div class="col-4">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Répartition par niveau</h3>
            </div>
            <div class="card-body">
                <canvas id="niveauChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Types d'anomalies fréquents -->
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar"></i> Types d'anomalies les plus fréquents</h3>
            </div>
            <div class="card-body">
                <canvas id="typesChart" height="200"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Répartition par véhicule -->
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-car"></i> Anomalies par véhicule</h3>
            </div>
            <div class="card-body">
                <?php if (empty($stats['par_vehicule'])): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-car"></i>
                        <p>Aucune anomalie récente</p>
                    </div>
                <?php else: ?>
                    <div class="vehicles-stats">
                        <?php foreach ($stats['par_vehicule'] as $vehicule): ?>
                            <div class="vehicle-stat-item">
                                <div class="vehicle-info">
                                    <h4><?php echo h($vehicule['marque'] . ' ' . $vehicule['modele']); ?></h4>
                                    <p><?php echo h($vehicule['immatriculation']); ?></p>
                                </div>
                                <div class="vehicle-count">
                                    <span class="count-number"><?php echo $vehicule['count']; ?></span>
                                    <span class="count-label">anomalie(s)</span>
                                </div>
                                <div class="vehicle-percentage">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo h(($vehicule['count'] / count($anomalies_recentes)) * 100); ?>%"></div>
                                    </div>
                                    <span class="percentage"><?php echo round(($vehicule['count'] / count($anomalies_recentes)) * 100, 1); ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Liste des anomalies récentes -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Détail des anomalies récentes</h3>
    </div>
    <div class="card-body">
        <?php if (empty($anomalies_recentes)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>Aucune anomalie récente</h3>
                <p>Aucune anomalie n'a été détectée sur vos véhicules ces 30 derniers jours.</p>
            </div>
        <?php else: ?>
            <div class="anomalies-timeline">
                <?php foreach ($anomalies_recentes as $anomalie): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <h4><?php echo h($anomalie['type']); ?></h4>
                                <span class="timeline-date"><?php echo formatDate($anomalie['date_detection']); ?></span>
                            </div>
                            <div class="timeline-meta">
                                <span class="vehicle-info">
                                    <i class="fas fa-car"></i>
                                    <?php echo h($anomalie['marque'] . ' ' . $anomalie['modele'] . ' (' . $anomalie['immatriculation'] . ')'); ?>
                                </span>
                                <span class="badge badge-<?php echo h($anomalie['niveau'] === 'critique' ? 'danger' : 
                                        ($anomalie['niveau'] === 'moyen' ? 'warning' : 'info')); ?>">
                                    <?php echo h(ucfirst($anomalie['niveau'])); ?>
                                </span>
                                <span class="badge badge-<?php echo h($anomalie['statut'] === 'resolue' ? 'success' : 
                                        ($anomalie['statut'] === 'en_cours' ? 'warning' : 'primary')); ?>">
                                    <?php echo h(ucfirst($anomalie['statut'])); ?>
                                </span>
                            </div>
                            <div class="timeline-description">
                                <p><?php echo h($anomalie['description']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    // Données pour les graphiques
    const evolutionData = <?php echo json_encode($stats['evolution'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const niveauData = <?php echo json_encode($stats['par_niveau'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const typesData = <?php echo json_encode($stats['types_frequents'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    // Graphique d'évolution
    const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
    new Chart(evolutionCtx, {
        type: 'line',
        data: {
            labels: evolutionData.map(item => item.label),
            datasets: [{
                label: 'Anomalies détectées',
                data: evolutionData.map(item => item.count),
                borderColor: '#1E90FF',
                backgroundColor: 'rgba(30, 144, 255, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#1E90FF',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            },
            elements: {
                point: {
                    hoverRadius: 8
                }
            }
        }
    });
    
    // Graphique par niveau
    const niveauCtx = document.getElementById('niveauChart').getContext('2d');
    new Chart(niveauCtx, {
        type: 'doughnut',
        data: {
            labels: ['Faible', 'Moyen', 'Critique'],
            datasets: [{
                data: [niveauData.faible, niveauData.moyen, niveauData.critique],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
    
    // Graphique des types
    const typesCtx = document.getElementById('typesChart').getContext('2d');
    new Chart(typesCtx, {
        type: 'bar',
        data: {
            labels: Object.keys(typesData),
            datasets: [{
                label: 'Nombre d\'anomalies',
                data: Object.values(typesData),
                backgroundColor: '#1E90FF',
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
});
</script>

<style>
.vehicles-stats {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.vehicle-stat-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--background-color);
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
}

.vehicle-info {
    flex: 1;
}

.vehicle-info h4 {
    margin-bottom: 0.25rem;
    color: var(--text-color);
    font-size: 1rem;
}

.vehicle-info p {
    margin: 0;
    color: var(--text-light);
    font-size: 0.9rem;
}

.vehicle-count {
    text-align: center;
    min-width: 60px;
}

.count-number {
    display: block;
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--primary-color);
}

.count-label {
    font-size: 0.8rem;
    color: var(--text-light);
}

.vehicle-percentage {
    min-width: 120px;
    text-align: right;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: var(--border-color);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 0.25rem;
}

.progress-fill {
    height: 100%;
    background: var(--primary-color);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.percentage {
    font-size: 0.8rem;
    color: var(--text-light);
    font-weight: 600;
}

.anomalies-timeline {
    position: relative;
    padding-left: 2rem;
}

.anomalies-timeline::before {
    content: '';
    position: absolute;
    left: 1rem;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border-color);
}

.timeline-item {
    position: relative;
    margin-bottom: 2rem;
}

.timeline-marker {
    position: absolute;
    left: -2rem;
    top: 0;
    width: 2rem;
    height: 2rem;
    background: var(--primary-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.8rem;
    border: 3px solid var(--secondary-color);
}

.timeline-content {
    background: var(--background-color);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-left: 1rem;
}

.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.timeline-header h4 {
    margin: 0;
    color: var(--text-color);
}

.timeline-date {
    color: var(--text-light);
    font-size: 0.9rem;
}

.timeline-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 1rem;
}

.timeline-meta .vehicle-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: var(--text-light);
}

.timeline-meta .vehicle-info i {
    color: var(--primary-color);
}

.timeline-description p {
    margin: 0;
    color: var(--text-light);
    line-height: 1.6;
}

@media (max-width: 768px) {
    .row {
        flex-direction: column;
    }
    
    .vehicle-stat-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .vehicle-percentage {
        width: 100%;
        text-align: left;
    }
    
    .timeline-header {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .timeline-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}
</style>

<?php include '../includes/footer.php'; ?>
