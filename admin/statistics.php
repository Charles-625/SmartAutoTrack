<?php
require_once '../config/config.php';
require_once '../config/database.php';

/**
 * Statistiques globales de la plateforme (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Lecture seule. Calcule les totaux (utilisateurs, véhicules, anomalies,
 * interventions, réparations), les évolutions sur 12 mois, le top 5 des
 * techniciens et les anomalies par type ; les graphiques sont dessinés
 * côté navigateur à partir de ces données.
 *
 * Tables lues : utilisateur, client, technicien, administrateur, vehicule,
 * anomalie, intervention, reparation.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Période de filtrage
$period = $_GET['period'] ?? '30'; // 7, 30, 90, 365 jours
// Remarque : $start_date et $end_date ne sont utilisés par aucune requête
// ci-dessous ; le paramètre `period` ne sert qu'à présélectionner la liste
// déroulante (les évolutions portent toujours sur 12 mois).
$start_date = date('Y-m-d', strtotime("-$period days"));
$end_date = date('Y-m-d');

// Statistiques générales
$stats = [];

// Total des utilisateurs (le rôle se déduit de la table où l'id apparaît)
$stmt = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM utilisateur) as total_users,
        (SELECT COUNT(*) FROM client) as clients,
        (SELECT COUNT(*) FROM technicien) as techniciens,
        (SELECT COUNT(*) FROM administrateur) as admins
");
$user_stats = $stmt->fetch();
$stats = array_merge($stats, $user_stats);

// Total des véhicules
$stmt = $conn->query("SELECT COUNT(*) as total_vehicles FROM vehicule");
$stats['total_vehicles'] = $stmt->fetchColumn();

// Anomalies
$stmt = $conn->query("
    SELECT
        COUNT(*) as total_anomalies,
        SUM(CASE WHEN niveau = 'CRITIQUE' THEN 1 ELSE 0 END) as anomalies_critiques,
        SUM(CASE WHEN niveau = 'MOYEN' THEN 1 ELSE 0 END) as anomalies_moyennes,
        SUM(CASE WHEN niveau = 'FAIBLE' THEN 1 ELSE 0 END) as anomalies_faibles,
        SUM(CASE WHEN statut = 'TRAITEE' THEN 1 ELSE 0 END) as anomalies_resolues
    FROM anomalie
");
$anomaly_stats = $stmt->fetch();
$stats = array_merge($stats, $anomaly_stats);

// Interventions
$stmt = $conn->query("
    SELECT
        COUNT(*) as total_interventions,
        SUM(CASE WHEN statut = 'PLANIFIEE' THEN 1 ELSE 0 END) as interventions_planifiees,
        SUM(CASE WHEN statut = 'EN_COURS' THEN 1 ELSE 0 END) as interventions_en_cours,
        SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) as interventions_terminees
    FROM intervention
");
$intervention_stats = $stmt->fetch();
$stats = array_merge($stats, $intervention_stats);

// Réparations
$stmt = $conn->query("
    SELECT
        COUNT(*) as total_reparations,
        SUM(cout) as total_cout_reparations,
        AVG(cout) as cout_moyen_reparations
    FROM reparation
");
$reparation_stats = $stmt->fetch();
$stats = array_merge($stats, $reparation_stats);

// Évolution des inscriptions (derniers 12 mois)
$stmt = $conn->prepare("
    SELECT
        DATE_FORMAT(dateCreation, '%Y-%m') as month,
        COUNT(*) as count
    FROM utilisateur
    WHERE dateCreation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(dateCreation, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute();
$inscriptions_evolution = $stmt->fetchAll();

// Top techniciens par interventions (la clé étrangère garantit que idTechnicien
// désigne toujours un technicien : pas besoin de filtrer par rôle)
$stmt = $conn->query("
    SELECT
        u.nom, u.prenom,
        COUNT(i.idIntervention) as interventions_count,
        COUNT(CASE WHEN i.statut = 'TERMINEE' THEN 1 END) as interventions_terminees
    FROM utilisateur u
    JOIN intervention i ON u.idUtilisateur = i.idTechnicien
    GROUP BY u.idUtilisateur
    ORDER BY interventions_count DESC
    LIMIT 5
");
$top_techniciens = $stmt->fetchAll();

// Anomalies par type (sévérité moyenne : 1 = FAIBLE, 2 = MOYEN, 3 = CRITIQUE)
$stmt = $conn->query("
    SELECT
        COALESCE(type, 'Non renseigné') AS type,
        COUNT(*) as count,
        AVG(CASE WHEN niveau = 'CRITIQUE' THEN 3 WHEN niveau = 'MOYEN' THEN 2 ELSE 1 END) as severity_avg
    FROM anomalie
    GROUP BY type
    ORDER BY count DESC
    LIMIT 10
");
$anomalies_by_type = $stmt->fetchAll();

// Interventions par mois (derniers 12 mois)
$stmt = $conn->query("
    SELECT
        DATE_FORMAT(dateIntervention, '%Y-%m') as month,
        COUNT(*) as count
    FROM intervention
    WHERE dateIntervention >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(dateIntervention, '%Y-%m')
    ORDER BY month ASC
");
$interventions_evolution = $stmt->fetchAll();

require_once 'includes/helpers.php';

$pageTitle = 'Statistiques';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'statistiques'; include 'includes/sidebar.php'; ?>
    <main class="av2-main">

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Statistiques de la Plateforme</h1>
    <div class="header-actions">
        <select id="periodSelect" class="form-control" style="width: auto;">
            <option value="7" <?php echo $period === '7' ? 'selected' : ''; ?>>7 derniers jours</option>
            <option value="30" <?php echo $period === '30' ? 'selected' : ''; ?>>30 derniers jours</option>
            <option value="90" <?php echo $period === '90' ? 'selected' : ''; ?>>90 derniers jours</option>
            <option value="365" <?php echo $period === '365' ? 'selected' : ''; ?>>365 derniers jours</option>
        </select>
    </div>
</div>

<!-- Statistiques principales -->
<div class="stats-grid-large">
    <div class="stat-card-large primary">
        <div class="stat-icon-large">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value-large"><?php echo $stats['total_users']; ?></div>
            <div class="stat-label-large">Utilisateurs totaux</div>
            <div class="stat-breakdown">
                <span class="breakdown-item"><?php echo $stats['clients']; ?> clients</span>
                <span class="breakdown-item"><?php echo $stats['techniciens']; ?> techniciens</span>
            </div>
        </div>
    </div>

    <div class="stat-card-large success">
        <div class="stat-icon-large">
            <i class="fas fa-car"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value-large"><?php echo $stats['total_vehicles']; ?></div>
            <div class="stat-label-large">Véhicules suivis</div>
            <div class="stat-breakdown">
                <span class="breakdown-item">Tous actifs</span>
            </div>
        </div>
    </div>

    <div class="stat-card-large warning">
        <div class="stat-icon-large">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value-large"><?php echo $stats['total_anomalies']; ?></div>
            <div class="stat-label-large">Anomalies détectées</div>
            <div class="stat-breakdown">
                <span class="breakdown-item"><?php echo $stats['anomalies_critiques']; ?> critiques</span>
                <span class="breakdown-item"><?php echo $stats['anomalies_resolues']; ?> résolues</span>
            </div>
        </div>
    </div>

    <div class="stat-card-large info">
        <div class="stat-icon-large">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value-large"><?php echo number_format($stats['total_cout_reparations'], 0, ',', ' '); ?> XAF</div>
            <div class="stat-label-large">Coût total réparations</div>
            <div class="stat-breakdown">
                <span class="breakdown-item">Moyenne: <?php echo number_format($stats['cout_moyen_reparations'], 0, ',', ' '); ?> XAF</span>
            </div>
        </div>
    </div>
</div>

<!-- Graphiques -->
<div class="charts-grid">
    <!-- Évolution des inscriptions -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-line"></i> Évolution des inscriptions</h3>
            <div class="chart-period">12 derniers mois</div>
        </div>
        <div class="chart-container">
            <canvas id="inscriptionsChart"></canvas>
        </div>
    </div>
    
    <!-- Anomalies par type -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-pie"></i> Anomalies par type</h3>
            <div class="chart-period">Top 10</div>
        </div>
        <div class="chart-container">
            <canvas id="anomaliesChart"></canvas>
        </div>
    </div>
    
    <!-- Interventions par mois -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-area"></i> Interventions par mois</h3>
            <div class="chart-period">12 derniers mois</div>
        </div>
        <div class="chart-container">
            <canvas id="interventionsChart"></canvas>
        </div>
    </div>
    
    <!-- Top techniciens -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-trophy"></i> Top techniciens</h3>
            <div class="chart-period">Par nombre d'interventions</div>
        </div>
        <div class="chart-container">
            <canvas id="techniciensChart"></canvas>
        </div>
    </div>
</div>

<!-- Tableaux détaillés -->
<div class="tables-grid">
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Anomalies par type</h3>
        </div>
        <div class="table-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Nombre</th>
                            <th>Sévérité moyenne</th>
                            <th>Progression</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($anomalies_by_type as $anomaly): ?>
                            <tr>
                                <td><?php echo h($anomaly['type']); ?></td>
                                <td><?php echo $anomaly['count']; ?></td>
                                <td>
                                    <div class="severity-bar">
                                        <div class="severity-fill" style="width: <?php echo ($anomaly['severity_avg'] / 3) * 100; ?>%"></div>
                                        <span class="severity-text"><?php echo round($anomaly['severity_avg'], 1); ?>/3</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo h(($anomaly['count'] / max(array_column($anomalies_by_type, 'count'))) * 100); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-medal"></i> Top techniciens</h3>
        </div>
        <div class="table-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Technicien</th>
                            <th>Interventions</th>
                            <th>Terminées</th>
                            <th>Taux de réussite</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_techniciens as $index => $tech): ?>
                            <tr>
                                <td>
                                    <div class="technician-rank">
                                        <span class="rank-badge rank-<?php echo $index + 1; ?>"><?php echo $index + 1; ?></span>
                                        <?php echo h($tech['prenom'] . ' ' . $tech['nom']); ?>
                                    </div>
                                </td>
                                <td><?php echo $tech['interventions_count']; ?></td>
                                <td><?php echo h($tech['interventions_terminees']); ?></td>
                                <td>
                                    <?php 
                                    $success_rate = $tech['interventions_count'] > 0 ? ($tech['interventions_terminees'] / $tech['interventions_count']) * 100 : 0;
                                    ?>
                                    <div class="success-rate">
                                        <div class="rate-bar">
                                            <div class="rate-fill" style="width: <?php echo $success_rate; ?>%"></div>
                                            <span class="rate-text"><?php echo round($success_rate, 1); ?>%</span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Données pour les graphiques -->
<script>
const chartData = {
    inscriptions: <?php echo json_encode($inscriptions_evolution, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    anomalies: <?php echo json_encode($anomalies_by_type, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    interventions: <?php echo json_encode($interventions_evolution, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    techniciens: <?php echo json_encode($top_techniciens, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
};
</script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* Statistiques principales */
.stats-grid-large {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
    margin-bottom: 3rem;
}

.stat-card-large {
    background: var(--secondary-color);
    border-radius: var(--border-radius);
    padding: 2rem;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: var(--transition);
    border-left: 5px solid;
}

.stat-card-large:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
}

.stat-card-large.primary { border-left-color: var(--primary-color); }
.stat-card-large.success { border-left-color: var(--success-color); }
.stat-card-large.warning { border-left-color: var(--warning-color); }
.stat-card-large.info { border-left-color: var(--info-color); }

.stat-icon-large {
    position: absolute;
    top: 2rem;
    right: 2rem;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    opacity: 0.1;
}

.stat-card-large.primary .stat-icon-large { background: var(--primary-color); color: white; }
.stat-card-large.success .stat-icon-large { background: var(--success-color); color: white; }
.stat-card-large.warning .stat-icon-large { background: var(--warning-color); color: white; }
.stat-card-large.info .stat-icon-large { background: var(--info-color); color: white; }

.stat-content {
    position: relative;
    z-index: 2;
}

.stat-value-large {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    background: linear-gradient(45deg, var(--primary-color), var(--primary-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    animation: countUp 2s ease-out;
}

.stat-label-large {
    font-size: 1.1rem;
    color: var(--text-color);
    margin-bottom: 1rem;
    font-weight: 600;
}

.stat-breakdown {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.breakdown-item {
    font-size: 0.9rem;
    color: var(--text-light);
    padding: 0.25rem 0.5rem;
    background: var(--background-color);
    border-radius: 12px;
    display: inline-block;
}

.stat-trend {
    position: absolute;
    top: 1rem;
    right: 1rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
}

.stat-trend.positive {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success-color);
}

.stat-trend.negative {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger-color);
}

/* Graphiques */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 2rem;
    margin-bottom: 3rem;
}

.chart-card {
    background: var(--secondary-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    transition: var(--transition);
}

.chart-card:hover {
    box-shadow: var(--shadow-hover);
}

.chart-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--background-color);
}

.chart-header h3 {
    margin: 0;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.chart-header h3 i {
    color: var(--primary-color);
}

.chart-period {
    font-size: 0.9rem;
    color: var(--text-light);
    background: var(--secondary-color);
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
}

.chart-container {
    padding: 1.5rem;
    height: 300px;
    position: relative;
}

/* Tableaux */
.tables-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 2rem;
    margin-bottom: 2rem;
}

.table-card {
    background: var(--secondary-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.table-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    background: var(--background-color);
}

.table-header h3 {
    margin: 0;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.table-header h3 i {
    color: var(--primary-color);
}

.table-body {
    padding: 0;
}

/* Barres de progression */
.severity-bar, .progress-bar, .rate-bar {
    position: relative;
    height: 20px;
    background: var(--background-color);
    border-radius: 10px;
    overflow: hidden;
}

.severity-fill, .progress-fill, .rate-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
    border-radius: 10px;
    transition: width 2s ease-out;
    animation: fillBar 2s ease-out;
}

.severity-text, .rate-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-color);
}

/* Rangs des techniciens */
.technician-rank {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.rank-badge {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: white;
}

.rank-1 { background: #FFD700; }
.rank-2 { background: #C0C0C0; }
.rank-3 { background: #CD7F32; }
.rank-4, .rank-5 { background: var(--text-light); }

.success-rate {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.rate-bar {
    flex: 1;
}

/* Animations */
@keyframes countUp {
    from { transform: scale(0.8); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

@keyframes fillBar {
    from { width: 0; }
}

@keyframes slideInUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.chart-card, .table-card {
    animation: slideInUp 0.6s ease-out;
}

.chart-card:nth-child(1) { animation-delay: 0.1s; }
.chart-card:nth-child(2) { animation-delay: 0.2s; }
.chart-card:nth-child(3) { animation-delay: 0.3s; }
.chart-card:nth-child(4) { animation-delay: 0.4s; }

/* Responsive */
@media (max-width: 768px) {
    .stats-grid-large {
        grid-template-columns: 1fr;
    }
    
    .charts-grid {
        grid-template-columns: 1fr;
    }
    
    .tables-grid {
        grid-template-columns: 1fr;
    }
    
    .chart-container {
        height: 250px;
    }
    
    .stat-value-large {
        font-size: 2rem;
    }
}
</style>

<script>
$(document).ready(function() {
    // Changement de période
    $('#periodSelect').change(function() {
        const period = $(this).val();
        window.location.href = `statistics.php?period=${period}`;
    });
    
    // Initialiser les graphiques
    initializeCharts();
    
    // Animation des barres de progression
    animateProgressBars();
});

function initializeCharts() {
    // Graphique des inscriptions
    const inscriptionsCtx = document.getElementById('inscriptionsChart').getContext('2d');
    new Chart(inscriptionsCtx, {
        type: 'line',
        data: {
            labels: chartData.inscriptions.map(item => {
                const date = new Date(item.month + '-01');
                return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
            }),
            datasets: [{
                label: 'Nouvelles inscriptions',
                data: chartData.inscriptions.map(item => item.count),
                borderColor: 'rgb(30, 144, 255)',
                backgroundColor: 'rgba(30, 144, 255, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgb(30, 144, 255)',
                pointBorderColor: '#fff',
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
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            animation: {
                duration: 2000,
                easing: 'easeOutQuart'
            }
        }
    });
    
    // Graphique des anomalies
    const anomaliesCtx = document.getElementById('anomaliesChart').getContext('2d');
    new Chart(anomaliesCtx, {
        type: 'doughnut',
        data: {
            labels: chartData.anomalies.map(item => item.type),
            datasets: [{
                data: chartData.anomalies.map(item => item.count),
                backgroundColor: [
                    'rgba(30, 144, 255, 0.8)',
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(255, 193, 7, 0.8)',
                    'rgba(220, 53, 69, 0.8)',
                    'rgba(23, 162, 184, 0.8)',
                    'rgba(108, 117, 125, 0.8)',
                    'rgba(111, 66, 193, 0.8)',
                    'rgba(253, 126, 20, 0.8)',
                    'rgba(32, 201, 151, 0.8)',
                    'rgba(233, 84, 87, 0.8)'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                }
            },
            animation: {
                animateRotate: true,
                duration: 2000
            }
        }
    });
    
    // Graphique des interventions
    const interventionsCtx = document.getElementById('interventionsChart').getContext('2d');
    new Chart(interventionsCtx, {
        type: 'bar',
        data: {
            labels: chartData.interventions.map(item => {
                const date = new Date(item.month + '-01');
                return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
            }),
            datasets: [{
                label: 'Interventions',
                data: chartData.interventions.map(item => item.count),
                backgroundColor: 'rgba(40, 167, 69, 0.8)',
                borderColor: 'rgb(40, 167, 69)',
                borderWidth: 1,
                borderRadius: 8,
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
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            animation: {
                duration: 2000,
                easing: 'easeOutBounce'
            }
        }
    });
    
    // Graphique des techniciens
    const techniciensCtx = document.getElementById('techniciensChart').getContext('2d');
    new Chart(techniciensCtx, {
        type: 'horizontalBar',
        data: {
            labels: chartData.techniciens.map(item => item.prenom + ' ' + item.nom),
            datasets: [{
                label: 'Interventions',
                data: chartData.techniciens.map(item => item.interventions_count),
                backgroundColor: [
                    'rgba(255, 215, 0, 0.8)',
                    'rgba(192, 192, 192, 0.8)',
                    'rgba(205, 127, 50, 0.8)',
                    'rgba(108, 117, 125, 0.8)',
                    'rgba(108, 117, 125, 0.8)'
                ],
                borderColor: [
                    'rgb(255, 215, 0)',
                    'rgb(192, 192, 192)',
                    'rgb(205, 127, 50)',
                    'rgb(108, 117, 125)',
                    'rgb(108, 117, 125)'
                ],
                borderWidth: 2,
                borderRadius: 4
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
                x: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    }
                },
                y: {
                    grid: {
                        display: false
                    }
                }
            },
            animation: {
                duration: 2000,
                easing: 'easeOutQuart'
            }
        }
    });
}

function animateProgressBars() {
    $('.severity-fill, .progress-fill, .rate-fill').each(function() {
        const width = $(this).css('width');
        $(this).css('width', '0');
        setTimeout(() => {
            $(this).css('width', width);
        }, 500);
    });
}
</script>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
