<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

requireRole('client');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

$stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmtBadge->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmtBadge->fetchColumn();

// Véhicules du client, pour le filtre
$stmt = $conn->prepare("SELECT idVehicule AS id, marque, modele, immatriculation FROM vehicule WHERE idClient = ? ORDER BY marque, modele");
$stmt->execute([$_SESSION['user_id']]);
$vehicules = $stmt->fetchAll();

// Filtres (lecture seule — aucune action de création pour le client : les
// anomalies viennent uniquement des constats technicien/garage lors d'une
// intervention ou d'un diagnostic).
$statutFilter = $_GET['statut'] ?? '';
$niveauFilter = $_GET['niveau'] ?? '';
$vehicleFilter = filter_var($_GET['vehicle'] ?? null, FILTER_VALIDATE_INT);

$where = ['v.idClient = ?'];
$params = [$_SESSION['user_id']];

if ($statutFilter === 'active') {
    $where[] = "a.statut IN ('NOUVELLE', 'EN_COURS')";
} elseif ($statutFilter === 'resolue') {
    $where[] = "a.statut IN ('TRAITEE', 'IGNOREE')";
}
if (in_array($niveauFilter, ['FAIBLE', 'MOYEN', 'CRITIQUE'], true)) {
    $where[] = 'a.niveau = ?';
    $params[] = $niveauFilter;
}
if ($vehicleFilter) {
    $where[] = 'a.idVehicule = ?';
    $params[] = $vehicleFilter;
}
$whereSql = implode(' AND ', $where);

// Anomalies du parc/véhicule(s) du client, avec l'intervention la plus proche
// (même véhicule, à ou avant la date de détection) comme provenance affichée —
// et le garage associé à cette intervention, si connu.
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description, a.dateDetection, a.dateResolution, a.niveau, a.statut,
           v.marque, v.modele, v.immatriculation,
           (SELECT i.idIntervention FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.dateIntervention <= a.dateDetection ORDER BY i.dateIntervention DESC LIMIT 1) AS intervention_id,
           (SELECT i.type FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.dateIntervention <= a.dateDetection ORDER BY i.dateIntervention DESC LIMIT 1) AS intervention_type,
           (SELECT i.dateIntervention FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.dateIntervention <= a.dateDetection ORDER BY i.dateIntervention DESC LIMIT 1) AS intervention_date,
           (SELECT g.nomGarage FROM intervention i LEFT JOIN garage g ON g.idGarage = i.idGarage WHERE i.idVehicule = a.idVehicule AND i.dateIntervention <= a.dateDetection ORDER BY i.dateIntervention DESC LIMIT 1) AS nomGarage
    FROM anomalie a
    JOIN vehicule v ON a.idVehicule = v.idVehicule
    WHERE $whereSql
    ORDER BY a.dateDetection DESC
");
$stmt->execute($params);
$anomalies = $stmt->fetchAll();

// Compteurs globaux (non filtrés) pour les cartes stats
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN a.statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS actives,
        SUM(CASE WHEN a.statut = 'NOUVELLE' AND a.niveau = 'CRITIQUE' OR a.statut = 'EN_COURS' AND a.niveau = 'CRITIQUE' THEN 1 ELSE 0 END) AS critiques,
        SUM(CASE WHEN a.statut IN ('TRAITEE','IGNOREE') THEN 1 ELSE 0 END) AS resolues,
        COUNT(*) AS total
    FROM anomalie a
    JOIN vehicule v ON a.idVehicule = v.idVehicule
    WHERE v.idClient = ?
");
$stmt->execute([$_SESSION['user_id']]);
$counts = $stmt->fetch();

$pageTitle = 'Anomalies';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'anomalies'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">

        <div class="v2-page-head">
            <div>
                <?php if ($isEntreprise): ?>
                    <div class="v2-entreprise-tag">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M3 21V7L12 3L21 7V21" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 21V13H15V21" stroke="currentColor" stroke-width="1.8"/></svg>
                        Supervision de flotte
                    </div>
                <?php endif; ?>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1">Anomalies</h1>
                <p class="v2-sub">Constatées par un technicien ou un garage lors d'une intervention ou d'un diagnostic — <?php echo $isEntreprise ? 'sur les véhicules de votre parc' : 'sur vos véhicules'; ?>.</p>
            </div>
        </div>

        <div class="v2-stats">
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#FDEDEE;"><i class="fas fa-exclamation-triangle" style="color:#E5484D;"></i></div>
                <div>
                    <div class="v2-stat-value" style="color:#E5484D;"><?php echo (int)($counts['actives'] ?? 0); ?></div>
                    <div class="v2-stat-label">Anomalie(s) active(s)</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#FFF4E2;"><i class="fas fa-fire" style="color:#C8871A;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['critiques'] ?? 0); ?></div>
                    <div class="v2-stat-label">Niveau critique</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#E9F6EE;"><i class="fas fa-check" style="color:#1E8A4C;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['resolues'] ?? 0); ?></div>
                    <div class="v2-stat-label">Résolue(s)</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#EAEFFC;"><i class="fas fa-list" style="color:#2540C4;"></i></div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)($counts['total'] ?? 0); ?></div>
                    <div class="v2-stat-label">Total constaté</div>
                </div>
            </div>
        </div>

        <form method="GET" class="v2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="active" <?php echo $statutFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="resolue" <?php echo $statutFilter === 'resolue' ? 'selected' : ''; ?>>Résolue</option>
            </select>
            <select name="niveau" onchange="this.form.submit()">
                <option value="">Tous les niveaux</option>
                <option value="FAIBLE" <?php echo $niveauFilter === 'FAIBLE' ? 'selected' : ''; ?>>Faible</option>
                <option value="MOYEN" <?php echo $niveauFilter === 'MOYEN' ? 'selected' : ''; ?>>Moyen</option>
                <option value="CRITIQUE" <?php echo $niveauFilter === 'CRITIQUE' ? 'selected' : ''; ?>>Critique</option>
            </select>
            <?php if (count($vehicules) > 1): ?>
                <select name="vehicle" onchange="this.form.submit()">
                    <option value="">Tous les véhicules</option>
                    <?php foreach ($vehicules as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" <?php echo $vehicleFilter === (int)$v['id'] ? 'selected' : ''; ?>><?php echo h($v['marque'] . ' ' . $v['modele'] . ' — ' . $v['immatriculation']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($statutFilter !== '' || $niveauFilter !== '' || $vehicleFilter): ?>
                <a href="anomalies.php" class="v2-btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="v2-card" style="padding:8px;">
            <?php if (empty($anomalies)): ?>
                <div class="v2-empty">Aucune anomalie ne correspond à ces critères.</div>
            <?php else: ?>
                <div class="v2-table-wrap">
                    <table class="v2-table">
                        <thead>
                            <tr>
                                <th>Véhicule</th>
                                <th>Anomalie constatée</th>
                                <th>Niveau</th>
                                <th>Date</th>
                                <th>Intervention concernée</th>
                                <th>Garage</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anomalies as $a):
                                $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true);
                                $niveauBadge = $a['niveau'] === 'CRITIQUE' ? 'bad' : ($a['niveau'] === 'MOYEN' ? 'warn' : 'neutral');
                                $statutLabels = ['NOUVELLE' => 'Nouvelle', 'EN_COURS' => 'En cours de traitement', 'TRAITEE' => 'Traitée', 'IGNOREE' => 'Résolue'];
                            ?>
                                <tr>
                                    <td>
                                        <div class="v2-table-vehicle">
                                            <div class="v2-table-vehicle-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#2540C4" stroke-width="1.6"/><circle cx="7.5" cy="18.5" r="1.5" stroke="#2540C4" stroke-width="1.6"/><circle cx="16.5" cy="18.5" r="1.5" stroke="#2540C4" stroke-width="1.6"/><path d="M5 10L7 5.5H17L19 10" stroke="#2540C4" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php echo h($a['marque'] . ' ' . $a['modele']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($a['immatriculation']); ?></div>
                                    </td>
                                    <td style="max-width:260px;"><?php echo h($a['description']); ?></td>
                                    <td><span class="v2-badge <?php echo h($niveauBadge); ?>"><?php echo h(ucfirst(strtolower($a['niveau']))); ?></span></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($a['dateDetection']))); ?></td>
                                    <td><?php echo h($a['intervention_type'] ? $a['intervention_type'] . ' du ' . date('d/m/Y', strtotime($a['intervention_date'])) : '—'); ?></td>
                                    <td><?php echo h($a['nomGarage'] ?: '—'); ?></td>
                                    <td><span class="v2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo h($statutLabels[$a['statut']] ?? ucfirst(strtolower($a['statut']))); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <p class="v2-note" style="margin-top:14px;">Les anomalies sont constatées et enregistrées par un technicien ou un garage à la suite d'une intervention ou d'un diagnostic — vous ne pouvez pas en créer directement.</p>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
