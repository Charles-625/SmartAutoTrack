<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$etatFilter = $_GET['etat'] ?? '';

$where = [];
$params = [];
if ($search) {
    $where[] = "(v.marque LIKE ? OR v.modele LIKE ? OR v.immatriculation LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}
if ($typeFilter === 'particulier') { $where[] = "c.typeClient = 'PARTICULIER'"; }
elseif ($typeFilter === 'entreprise') { $where[] = "c.typeClient = 'ENTREPRISE'"; }
if ($etatFilter) { $where[] = 'v.etat = ?'; $params[] = $etatFilter; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $conn->prepare("
    SELECT v.idVehicule AS id, v.marque, v.modele, v.immatriculation, v.kilometrage, v.annee, v.etat,
           u.nom AS proprietaire_nom, u.prenom AS proprietaire_prenom, c.typeClient,
           (SELECT COUNT(*) FROM anomalie a WHERE a.idVehicule = v.idVehicule AND a.statut IN ('NOUVELLE','EN_COURS')) AS anomalies_actives,
           (SELECT COUNT(*) FROM intervention i WHERE i.idVehicule = v.idVehicule) AS interventions_count
    FROM vehicule v
    JOIN utilisateur u ON u.idUtilisateur = v.idClient
    JOIN client c ON c.idClient = v.idClient
    WHERE $whereSql
    ORDER BY v.idVehicule DESC
    LIMIT 200
");
$stmt->execute($params);
$vehicules = $stmt->fetchAll();

$totalVehicules = (int)$conn->query("SELECT COUNT(*) FROM vehicule")->fetchColumn();
$vehiculesActifs = (int)$conn->query("SELECT COUNT(*) FROM vehicule WHERE etat = 'BON' OR etat IS NULL")->fetchColumn();
$vehiculesEnPanne = (int)$conn->query("SELECT COUNT(*) FROM vehicule WHERE etat = 'EN_PANNE'")->fetchColumn();

// `vehicule.etat` est un varchar libre (pas un ENUM) : ces libellés couvrent
// les valeurs réellement utilisées par l'application (BON/EN_ENTRETIEN/
// EN_PANNE/HORS_SERVICE) ; toute autre valeur retombe sur son texte brut.
$etatLabels = ['BON' => 'Bon état', 'EN_ENTRETIEN' => 'En entretien', 'EN_PANNE' => 'En panne', 'HORS_SERVICE' => 'Hors service'];
$etatBadge = ['BON' => 'ok', 'EN_ENTRETIEN' => 'info', 'EN_PANNE' => 'bad', 'HORS_SERVICE' => 'neutral'];

$pageTitle = 'Véhicules';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'vehicules'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Véhicules</h1>
                <p class="av2-sub">Tous les véhicules enregistrés sur la plateforme, tous propriétaires confondus.</p>
            </div>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$totalVehicules; ?></div><div class="av2-stat-label">Total véhicules</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$vehiculesActifs; ?></div><div class="av2-stat-label">Actifs</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FDEDEE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$vehiculesEnPanne; ?></div><div class="av2-stat-label">En panne</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <div class="av2-filterbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#8B90B3" stroke-width="1.8"/><path d="M21 21L16.5 16.5" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="text" name="search" placeholder="Marque, modèle, immatriculation, propriétaire..." value="<?php echo h($search); ?>">
            </div>
            <select name="type" onchange="this.form.submit()">
                <option value="">Tous les types de client</option>
                <option value="particulier" <?php echo $typeFilter === 'particulier' ? 'selected' : ''; ?>>Particulier</option>
                <option value="entreprise" <?php echo $typeFilter === 'entreprise' ? 'selected' : ''; ?>>Entreprise</option>
            </select>
            <select name="etat" onchange="this.form.submit()">
                <option value="">Tous les états</option>
                <?php foreach ($etatLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $etatFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="av2-btn-primary">Filtrer</button>
            <?php if ($search || $typeFilter || $etatFilter): ?><a href="vehicules.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($vehicules)): ?>
                <div class="av2-empty">Aucun véhicule ne correspond aux critères.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Propriétaire</th><th>Type</th><th>Kilométrage</th><th>État</th><th>Anomalies actives</th><th>Interventions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicules as $v): $isEnt = $v['typeClient'] === 'ENTREPRISE'; $etat = $v['etat'] ?: 'BON'; ?>
                                <tr>
                                    <td>
                                        <div class="av2-table-entity">
                                            <div class="av2-table-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#1E7DBF" stroke-width="1.6"/></svg></div>
                                            <?php echo h($v['marque'] . ' ' . $v['modele']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($v['immatriculation']); ?><?php echo h($v['annee'] ? ' · ' . $v['annee'] : ''); ?></div>
                                    </td>
                                    <td><?php echo h($v['proprietaire_prenom'] . ' ' . $v['proprietaire_nom']); ?></td>
                                    <td><span class="av2-badge <?php echo $isEnt ? 'info' : 'neutral'; ?>"><?php echo $isEnt ? 'Entreprise' : 'Particulier'; ?></span></td>
                                    <td><?php echo number_format((float)$v['kilometrage'], 0, ',', ' '); ?> km</td>
                                    <td><span class="av2-badge <?php echo h($etatBadge[$etat] ?? 'neutral'); ?>"><?php echo h($etatLabels[$etat] ?? $etat); ?></span></td>
                                    <td><span class="av2-badge <?php echo $v['anomalies_actives'] > 0 ? 'bad' : 'ok'; ?>"><?php echo (int)$v['anomalies_actives']; ?></span></td>
                                    <td><span class="av2-badge neutral"><?php echo (int)$v['interventions_count']; ?></span></td>
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
