<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Supervision des abonnements clients (espace Administrateur).
 *
 * Accès : rôle admin uniquement (requireRole).
 * Lecture seule : aucune action POST. Filtres GET `search` (nom, prénom,
 * raison sociale) et `type` (particulier|entreprise).
 *
 * Tables lues : utilisateur, client, entreprise, vehicule (taille de flotte).
 * Liens : client/abonnement.php (seule formule existante, « Gratuit »),
 * admin/includes/sidebar.php.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Le modèle économique n'est pas encore finalisé : il n'existe aucune table
// d'abonnement, aucun prix ni palier définitif dans le projet. Cette page
// prépare l'espace de supervision (liste des clients + statut d'abonnement
// visible) sans inventer de tarification — tous les clients affichent
// aujourd'hui la seule formule réellement existante ("Gratuit", cf.
// client/abonnement.php). Quand le modèle économique sera fixé, cette
// requête n'aura qu'à joindre la future table d'abonnements.
$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';

// Construction dynamique du WHERE : seules des conditions fixes sont
// concaténées, les valeurs saisies passent toujours par des paramètres liés.
$where = [];
$params = [];
if ($search) {
    $where[] = "(u.nom LIKE ? OR u.prenom LIKE ? OR e.raisonSociale LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
}
if ($typeFilter === 'particulier') { $where[] = "c.typeClient = 'PARTICULIER'"; }
elseif ($typeFilter === 'entreprise') { $where[] = "c.typeClient = 'ENTREPRISE'"; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.dateCreation AS created_at,
           c.typeClient, e.raisonSociale,
           COUNT(v.idVehicule) AS flotte_taille
    FROM utilisateur u
    JOIN client c ON c.idClient = u.idUtilisateur
    LEFT JOIN entreprise e ON e.idClient = c.idClient
    LEFT JOIN vehicule v ON v.idClient = u.idUtilisateur
    WHERE $whereSql
    GROUP BY u.idUtilisateur
    ORDER BY u.dateCreation DESC
");
$stmt->execute($params);
$clients = $stmt->fetchAll();

$totalClients = count($clients);

$pageTitle = 'Abonnements';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'abonnements'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Abonnements</h1>
                <p class="av2-sub">Supervision des abonnements clients.</p>
            </div>
        </div>

        <div class="av2-card av2-panel" style="background:#E7F3FC; border-color:#C9E2F5;">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/><path d="M12 8V13M12 16V16.1" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                <div style="font-size:13.5px; color:#12417A; line-height:1.6;">
                    <strong>Modèle économique en cours de finalisation.</strong> Aucun tarif ni palier n'est encore défini — pour les entreprises, la formule sera calculée selon la taille de la flotte. Cet espace prépare la supervision ; il ne reflète aucune tarification définitive.
                </div>
            </div>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$totalClients; ?></div><div class="av2-stat-label">Clients abonnés (Gratuit)</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2.5" stroke="#1E8A4C" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value">0</div><div class="av2-stat-label">Formules payantes actives</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value">—</div><div class="av2-stat-label">Revenu mensuel (non défini)</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <div class="av2-filterbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#8B90B3" stroke-width="1.8"/><path d="M21 21L16.5 16.5" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="text" name="search" placeholder="Rechercher par nom, prénom, raison sociale…" value="<?php echo h($search); ?>">
            </div>
            <select name="type" onchange="this.form.submit()">
                <option value="">Tous les types</option>
                <option value="particulier" <?php echo $typeFilter === 'particulier' ? 'selected' : ''; ?>>Particulier</option>
                <option value="entreprise" <?php echo $typeFilter === 'entreprise' ? 'selected' : ''; ?>>Entreprise</option>
            </select>
            <button type="submit" class="av2-btn-primary">Filtrer</button>
            <?php if ($search || $typeFilter): ?><a href="abonnements.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($clients)): ?>
                <div class="av2-empty">Aucun client ne correspond aux critères.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Client</th><th>Type</th><th>Formule</th><th>Périodicité</th><th>Taille de flotte</th><th>Statut</th><th>Depuis</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $c): $isEnt = $c['typeClient'] === 'ENTREPRISE'; ?>
                                <tr>
                                    <td><?php echo h($isEnt ? ($c['raisonSociale'] ?: ($c['prenom'] . ' ' . $c['nom'])) : ($c['prenom'] . ' ' . $c['nom'])); ?></td>
                                    <td><span class="av2-badge <?php echo $isEnt ? 'info' : 'neutral'; ?>"><?php echo $isEnt ? 'Entreprise' : 'Particulier'; ?></span></td>
                                    <td><span class="av2-badge neutral">Gratuit</span></td>
                                    <td>—</td>
                                    <td><?php echo h($isEnt ? ((int)$c['flotte_taille'] . ' véhicule(s)') : '—'); ?></td>
                                    <td><span class="av2-badge ok">Actif</span></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($c['created_at']))); ?></td>
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
