<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

$clientId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$clientId) { header('Location: clients.php'); exit; }

$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, u.telephone, u.dateCreation AS created_at,
           c.typeClient, e.raisonSociale, e.adresse AS entreprise_adresse, p.adresse AS particulier_adresse
    FROM utilisateur u
    JOIN client c ON c.idClient = u.idUtilisateur
    LEFT JOIN entreprise e ON e.idClient = c.idClient
    LEFT JOIN particulier p ON p.idClient = c.idClient
    WHERE u.idUtilisateur = ?
");
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) { header('Location: clients.php'); exit; }
$isEnt = $client['typeClient'] === 'ENTREPRISE';

$stmt = $conn->prepare("
    SELECT idVehicule AS id, marque, modele, immatriculation, kilometrage, annee,
           CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
    FROM vehicule WHERE idClient = ? ORDER BY marque
");
$stmt->execute([$clientId]);
$vehicules = $stmt->fetchAll();

$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, i.statut, v.marque, v.modele, v.immatriculation, g.nomGarage
    FROM intervention i
    JOIN vehicule v ON v.idVehicule = i.idVehicule
    LEFT JOIN garage g ON g.idGarage = i.idGarage
    WHERE i.idClient = ?
    ORDER BY i.dateIntervention DESC
    LIMIT 10
");
$stmt->execute([$clientId]);
$interventions = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT COUNT(*) FROM anomalie a JOIN vehicule v ON v.idVehicule = a.idVehicule WHERE v.idClient = ? AND a.statut IN ('NOUVELLE','EN_COURS')");
$stmt->execute([$clientId]);
$anomaliesActives = (int)$stmt->fetchColumn();

$pageTitle = ($isEnt ? ($client['raisonSociale'] ?: 'Entreprise') : ($client['prenom'] . ' ' . $client['nom']));
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'clients'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <div class="av2-kicker"><?php echo $isEnt ? 'Client entreprise' : 'Client particulier'; ?></div>
                <h1 class="av2-h1"><?php echo h($isEnt ? ($client['raisonSociale'] ?: 'Entreprise') : ($client['prenom'] . ' ' . $client['nom'])); ?></h1>
                <p class="av2-sub">Fiche client — supervision en lecture, aucune modification des données historiques.</p>
            </div>
            <a href="clients.php" class="av2-btn-outline" style="text-decoration:none;">← Retour aux clients</a>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo count($vehicules); ?></div><div class="av2-stat-label"><?php echo $isEnt ? 'Taille de la flotte' : 'Véhicules'; ?></div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FDEDEE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$anomaliesActives; ?></div><div class="av2-stat-label">Anomalies actives</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2.5" stroke="#1E8A4C" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value">Gratuit</div><div class="av2-stat-label">Abonnement</div></div>
            </div>
        </div>

        <div class="av2-body">
            <div class="av2-col">
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head"><h2><?php echo $isEnt ? 'Parc automobile' : 'Véhicules'; ?></h2></div>
                    <?php if (empty($vehicules)): ?>
                        <div class="av2-empty">Aucun véhicule enregistré.</div>
                    <?php else: ?>
                        <div class="av2-table-wrap">
                            <table class="av2-table">
                                <thead><tr><th>Véhicule</th><th>Immatriculation</th><th>Année</th><th>Kilométrage</th><th>État</th></tr></thead>
                                <tbody>
                                    <?php foreach ($vehicules as $v): ?>
                                        <tr>
                                            <td><?php echo h($v['marque'] . ' ' . $v['modele']); ?></td>
                                            <td><?php echo h($v['immatriculation']); ?></td>
                                            <td><?php echo h($v['annee'] ?: '—'); ?></td>
                                            <td><?php echo number_format((float)$v['kilometrage'], 0, ',', ' '); ?> km</td>
                                            <td><span class="av2-badge <?php echo $v['statut'] === 'actif' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(str_replace('_', ' ', $v['statut']))); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="av2-card av2-panel">
                    <div class="av2-panel-head"><h2>Interventions récentes</h2></div>
                    <?php if (empty($interventions)): ?>
                        <div class="av2-empty">Aucune intervention enregistrée.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($interventions as $iv): $ivBadge = $iv['statut'] === 'TERMINEE' ? 'ok' : ($iv['statut'] === 'ANNULEE' ? 'bad' : ($iv['statut'] === 'EN_COURS' ? 'warn' : 'info')); ?>
                                <div class="av2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="av2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?></div>
                                        <div class="av2-row-meta"><?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?><?php echo h($iv['nomGarage'] ? ' · ' . $iv['nomGarage'] : ''); ?></div>
                                    </div>
                                    <span class="av2-badge <?php echo h($ivBadge); ?>"><?php echo h(ucfirst(strtolower($iv['statut']))); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="av2-col">
                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2><?php echo $isEnt ? 'Entreprise' : 'Coordonnées'; ?></h2></div>
                    <div style="display:flex; flex-direction:column; gap:10px; font-size:13.5px;">
                        <?php if ($isEnt): ?>
                            <div><strong>Raison sociale</strong><br><?php echo h($client['raisonSociale'] ?: '—'); ?></div>
                            <div><strong>Adresse</strong><br><?php echo h($client['entreprise_adresse'] ?: 'Non renseignée'); ?></div>
                            <div><strong>Contact</strong><br><?php echo h($client['prenom'] . ' ' . $client['nom']); ?></div>
                        <?php else: ?>
                            <div><strong>Adresse</strong><br><?php echo h($client['particulier_adresse'] ?: 'Non renseignée'); ?></div>
                        <?php endif; ?>
                        <div><strong>Email</strong><br><?php echo h($client['email']); ?></div>
                        <div><strong>Téléphone</strong><br><?php echo h($client['telephone']); ?></div>
                        <div><strong>Client depuis</strong><br><?php echo h(date('d/m/Y', strtotime($client['created_at']))); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
