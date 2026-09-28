<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$client_id = $_GET['id'] ?? null;
$errors = [];

// Supprimer un client. Modifie des données : n'accepte plus que POST + un
// jeton CSRF valide (auparavant un simple lien GET, protégé uniquement par
// une boîte de confirmation JS — qui n'arrête pas une requête forgée depuis
// un site tiers, seulement un clic accidentel).
$postOk = $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '');
if ($action === 'delete' && $client_id && $postOk) {
    try {
        $stmt = $conn->prepare('SELECT nom, prenom, email FROM utilisateur WHERE idUtilisateur = ?');
        $stmt->execute([$client_id]);
        $deletedClient = $stmt->fetch();

        $conn->beginTransaction();
        // Le schéma protège paiements/interventions par des clés RESTRICT (pas
        // de cascade automatique) : suppression explicite dans l'ordre. Messages
        // et notifications, eux, sont en CASCADE depuis utilisateur.
        $conn->prepare("DELETE FROM paiement WHERE idClient = ?")->execute([$client_id]);
        $conn->prepare("DELETE FROM intervention WHERE idClient = ?")->execute([$client_id]);
        $conn->prepare("DELETE FROM vehicule WHERE idClient = ?")->execute([$client_id]);
        $conn->prepare("DELETE FROM utilisateur WHERE idUtilisateur = ?")->execute([$client_id]);
        $conn->commit();

        log_activity($conn, 'Compte client supprimé', [
            'idUtilisateur' => $_SESSION['user_id'] ?? null,
            'description' => $deletedClient
                ? ('Client supprimé : ' . $deletedClient['prenom'] . ' ' . $deletedClient['nom'] . ' (' . $deletedClient['email'] . ')')
                : ('Client supprimé (idUtilisateur ' . $client_id . ')'),
            'categorie' => 'compte',
        ]);

        header("Location: clients.php?success=deleted");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $errors[] = 'Erreur lors de la suppression du client.';
    }
}

// Filtres
$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';

$where = [];
$params = [];
if ($search) {
    $where[] = "(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR e.raisonSociale LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}
if ($typeFilter === 'particulier') { $where[] = "c.typeClient = 'PARTICULIER'"; }
elseif ($typeFilter === 'entreprise') { $where[] = "c.typeClient = 'ENTREPRISE'"; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, u.telephone, u.dateCreation AS created_at,
           c.typeClient, e.raisonSociale,
           COUNT(DISTINCT v.idVehicule) AS vehicles_count,
           COUNT(DISTINCT CASE WHEN a.statut IN ('NOUVELLE', 'EN_COURS') THEN a.idAnomalie END) AS active_anomalies
    FROM utilisateur u
    JOIN client c ON c.idClient = u.idUtilisateur
    LEFT JOIN entreprise e ON e.idClient = c.idClient
    LEFT JOIN vehicule v ON u.idUtilisateur = v.idClient
    LEFT JOIN anomalie a ON v.idVehicule = a.idVehicule
    WHERE $whereSql
    GROUP BY u.idUtilisateur
    ORDER BY u.dateCreation DESC
");
$stmt->execute($params);
$clients = $stmt->fetchAll();

$stmt = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN typeClient = 'PARTICULIER' THEN 1 ELSE 0 END) AS particuliers,
           SUM(CASE WHEN typeClient = 'ENTREPRISE' THEN 1 ELSE 0 END) AS entreprises
    FROM client
");
$stats = $stmt->fetch();

$pageTitle = 'Clients';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'clients'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
            <div class="av2-alert success">Client supprimé avec succès.</div>
        <?php endif; ?>
        <?php foreach ($errors ?? [] as $err): ?>
            <div class="av2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Clients</h1>
                <p class="av2-sub">Supervision de tous les clients de la plateforme — particuliers et entreprises.</p>
            </div>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="#1E7DBF" stroke-width="1.8"/><path d="M3.5 20C4.6 16.3 6.9 15 9 15C11.1 15 13.4 16.3 14.5 20" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total clients</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.6" stroke="#6D74A0" stroke-width="1.8"/><path d="M4.5 20C5.8 16 8.6 14.5 12 14.5C15.4 14.5 18.2 16 19.5 20" stroke="#6D74A0" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)($stats['particuliers'] ?? 0); ?></div><div class="av2-stat-label">Particuliers</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 10L12 4L20 10V19C20 20.1 19.1 21 18 21H6C4.9 21 4 20.1 4 19V10Z" stroke="#1E8A4C" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)($stats['entreprises'] ?? 0); ?></div><div class="av2-stat-label">Entreprises</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <div class="av2-filterbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#8B90B3" stroke-width="1.8"/><path d="M21 21L16.5 16.5" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="text" name="search" placeholder="Nom, raison sociale, email..." value="<?php echo h($search); ?>">
            </div>
            <select name="type" onchange="this.form.submit()">
                <option value="">Tous les types</option>
                <option value="particulier" <?php echo $typeFilter === 'particulier' ? 'selected' : ''; ?>>Particulier</option>
                <option value="entreprise" <?php echo $typeFilter === 'entreprise' ? 'selected' : ''; ?>>Entreprise</option>
            </select>
            <button type="submit" class="av2-btn-primary">Rechercher</button>
            <?php if ($search || $typeFilter): ?>
                <a href="clients.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($clients)): ?>
                <div class="av2-empty">Aucun client ne correspond aux critères.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Client</th><th>Type</th><th>Contact</th><th>Véhicules</th><th>Anomalies actives</th><th>Abonnement</th><th>Inscription</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $c): $isEnt = $c['typeClient'] === 'ENTREPRISE'; ?>
                                <tr>
                                    <td>
                                        <div class="av2-table-entity">
                                            <div class="av2-table-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><?php if ($isEnt): ?><path d="M4 10L12 4L20 10V19C20 20.1 19.1 21 18 21H6C4.9 21 4 20.1 4 19V10Z" stroke="#1E7DBF" stroke-width="1.6" stroke-linejoin="round"/><?php else: ?><circle cx="12" cy="8" r="3" stroke="#1E7DBF" stroke-width="1.6"/><path d="M5 20C6 16.5 8.5 15 12 15C15.5 15 18 16.5 19 20" stroke="#1E7DBF" stroke-width="1.6" stroke-linecap="round"/><?php endif; ?></svg></div>
                                            <?php echo h($isEnt ? ($c['raisonSociale'] ?: ($c['prenom'] . ' ' . $c['nom'])) : ($c['prenom'] . ' ' . $c['nom'])); ?>
                                        </div>
                                    </td>
                                    <td><span class="av2-badge <?php echo $isEnt ? 'info' : 'neutral'; ?>"><?php echo $isEnt ? 'Entreprise' : 'Particulier'; ?></span></td>
                                    <td><?php echo h($c['email']); ?><div style="font-size:11.5px; color:#8B90B3;"><?php echo h($c['telephone']); ?></div></td>
                                    <td><span class="av2-badge neutral"><?php echo (int)$c['vehicles_count']; ?></span></td>
                                    <td><span class="av2-badge <?php echo $c['active_anomalies'] > 0 ? 'bad' : 'ok'; ?>"><?php echo (int)$c['active_anomalies']; ?></span></td>
                                    <td><span class="av2-badge neutral">Gratuit</span></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($c['created_at']))); ?></td>
                                    <td>
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <a href="client_detail.php?id=<?php echo (int)$c['id']; ?>" class="av2-table-link">Voir</a>
                                            <form method="POST" action="clients.php?action=delete&id=<?php echo (int)$c['id']; ?>" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Supprimer définitivement ce client ? Cette action est irréversible." style="color:#E5484D;">Supprimer</button>
                                            </form>
                                        </div>
                                    </td>
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
