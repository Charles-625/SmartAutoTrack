<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/subscription.php';
require_once 'includes/helpers.php';

/**
 * Supervision des abonnements clients (espace Administrateur).
 *
 * Accès : rôle admin uniquement (requireRole).
 * Actions :
 *   - POST action=grant + client_id (jeton CSRF requis) : offre 1 mois
 *     Premium au client (subscriptionGrantFreeMonth, qui prolonge un
 *     abonnement actif, notifie le client et écrit le journal d'activité),
 *     puis redirige (PRG) pour qu'un rafraîchissement ne l'offre pas deux fois ;
 *   - GET `search` (nom, prénom, raison sociale), `type`
 *     (particulier|entreprise) et `formule` (gratuit|premium, essais compris).
 *
 * Tant que scripts/migrate_structure.php n'est pas appliqué
 * (subscriptionsReady() faux) : page en lecture seule comme avant, tous les
 * clients en « Gratuit », avec une note invitant à appliquer la migration.
 *
 * Tables : utilisateur, client, entreprise, vehicule (taille de flotte),
 * abonnement (formule, fin de période, statistiques), paiement (revenus des
 * abonnements du mois : PAYE avec idAbonnement).
 * Liens : includes/subscription.php (règles et tarifs), client/abonnement.php,
 * admin/includes/sidebar.php.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();
$subsReady = subscriptionsReady($conn);
$errors = [];

$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$formuleFilter = $subsReady && in_array($_GET['formule'] ?? '', ['gratuit', 'premium'], true) ? $_GET['formule'] : '';
// Filtres courants, repris par le formulaire « Offrir 1 mois » et sa redirection.
$filterQuery = http_build_query(array_filter(['search' => $search, 'type' => $typeFilter, 'formule' => $formuleFilter], fn($v) => $v !== ''));

// Offrir 1 mois Premium. Modifie des données : POST + jeton CSRF valide.
// Les règles (prolongation, véhicules couverts, notification, journal) sont
// toutes dans subscriptionGrantFreeMonth() ; la page ne fait que l'appeler.
if ($subsReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grant') {
    $grantClientId = (int)($_POST['client_id'] ?? 0);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, veuillez réessayer.';
    } elseif ($grantClientId <= 0) {
        $errors[] = 'Client introuvable.';
    } else {
        try {
            subscriptionGrantFreeMonth($conn, $grantClientId, (int)$_SESSION['user_id']);
            header('Location: abonnements.php?' . ($filterQuery ? $filterQuery . '&' : '') . 'success=granted&id=' . $grantClientId);
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[SmartAutoTrack] mois offert : ' . $e->getMessage());
            $errors[] = 'Erreur lors de l\'attribution du mois offert.';
        }
    }
}

// Instant de référence calculé en PHP (fuseau différent de MySQL sur ce
// serveur : pas de NOW() SQL), passé en paramètre à toutes les requêtes.
$nowTs = time();
$nowSql = date('Y-m-d H:i:s', $nowTs);

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
// Premium = un abonnement ACTIF couvre l'instant présent (même règle que subscriptionActive()).
if ($formuleFilter !== '') {
    $where[] = ($formuleFilter === 'premium' ? '' : 'NOT ') . "EXISTS (
        SELECT 1 FROM abonnement a
        WHERE a.idClient = c.idClient AND a.statut = 'ACTIF' AND a.dateDebut <= ? AND a.dateFin > ?
    )";
    array_push($params, $nowSql, $nowSql);
}
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

$subStats = null;
$activeByClient = [];
$premiumEndByClient = [];
$successMessage = null;
if ($subsReady) {
    // Abonnements actifs : celui qui couvre maintenant et finit le plus tard
    // (comme subscriptionActive(), mais en une requête pour toute la liste)...
    $stmt = $conn->prepare("
        SELECT * FROM abonnement
        WHERE statut = 'ACTIF' AND dateDebut <= ? AND dateFin > ?
        ORDER BY dateFin ASC
    ");
    $stmt->execute([$nowSql, $nowSql]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activeByClient[(int)$row['idClient']] = $row; // tri croissant : la dernière ligne gagne
    }
    // ... et fin réelle du Premium, prolongations déjà payées ou offertes comprises.
    $stmt = $conn->prepare("SELECT idClient, MAX(dateFin) AS fin FROM abonnement WHERE statut = 'ACTIF' AND dateFin > ? GROUP BY idClient");
    $stmt->execute([$nowSql]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $premiumEndByClient[(int)$row['idClient']] = $row['fin'];
    }

    // Statistiques sur l'ensemble des clients (indépendantes des filtres).
    $allClients = (int)$conn->query('SELECT COUNT(*) FROM client')->fetchColumn();
    $premiumCount = count($activeByClient);
    $trialCount = count(array_filter($activeByClient, fn(array $a) => $a['periodicite'] === 'ESSAI'));
    // Bornes du mois en cours calculées en PHP : [1er du mois, 1er du mois suivant[.
    $monthStart = date('Y-m-01 00:00:00', $nowTs);
    $nextMonthStart = date('Y-m-d H:i:s', strtotime('first day of next month 00:00:00', $nowTs));
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(montant), 0) FROM paiement
        WHERE statut = 'PAYE' AND idAbonnement IS NOT NULL AND datePaiement >= ? AND datePaiement < ?
    ");
    $stmt->execute([$monthStart, $nextMonthStart]);
    $subStats = [
        'premium' => $premiumCount,
        'gratuit' => max(0, $allClients - $premiumCount),
        'essais' => $trialCount,
        'revenus' => (int)round((float)$stmt->fetchColumn()),
    ];

    // Message de succès relu en base (et non passé dans l'URL) : le nom et la
    // date affichés sont toujours les vrais.
    if (($_GET['success'] ?? '') === 'granted' && (int)($_GET['id'] ?? 0) > 0) {
        $grantedId = (int)$_GET['id'];
        $stmt = $conn->prepare("
            SELECT u.nom, u.prenom, c.typeClient, e.raisonSociale
            FROM utilisateur u JOIN client c ON c.idClient = u.idUtilisateur
            LEFT JOIN entreprise e ON e.idClient = c.idClient
            WHERE u.idUtilisateur = ?
        ");
        $stmt->execute([$grantedId]);
        $granted = $stmt->fetch();
        if ($granted && isset($premiumEndByClient[$grantedId])) {
            $grantedName = $granted['typeClient'] === 'ENTREPRISE' && $granted['raisonSociale'] ? $granted['raisonSociale'] : ($granted['prenom'] . ' ' . $granted['nom']);
            $successMessage = '1 mois Premium offert à ' . $grantedName . ' : Premium jusqu\'au ' . date('d/m/Y', strtotime($premiumEndByClient[$grantedId])) . '.';
        }
    }
}

// Libellé affiché de la périodicité d'un abonnement actif.
$periodiciteLabels = ['ESSAI' => 'Essai ' . SUB_TRIAL_DAYS . ' jours', 'OFFERT' => 'Mois offert', 'MENSUEL' => 'Mensuel', 'ANNUEL' => 'Annuel'];

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

        <?php if ($successMessage): ?>
            <div class="av2-alert success"><?php echo h($successMessage); ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="av2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Abonnements</h1>
                <p class="av2-sub">Supervision des abonnements clients.</p>
            </div>
        </div>

        <?php if (!$subsReady): ?>
            <div class="av2-card av2-panel" style="background:#E7F3FC; border-color:#C9E2F5;">
                <div style="display:flex; gap:12px; align-items:flex-start;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/><path d="M12 8V13M12 16V16.1" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <div style="font-size:13.5px; color:#12417A; line-height:1.6;">
                        <strong>Abonnements Premium pas encore activés.</strong> Les tables d'abonnement n'existent pas encore dans la base : tous les clients sont en formule Gratuit. Appliquez la migration : <code>php scripts/migrate_structure.php --apply</code>
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
        <?php else: ?>
            <div class="av2-card av2-panel" style="background:#E7F3FC; border-color:#C9E2F5;">
                <div style="display:flex; gap:12px; align-items:flex-start;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/><path d="M12 8V13M12 16V16.1" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <div style="font-size:13.5px; color:#12417A; line-height:1.6;">
                        <strong>Tarifs Premium.</strong>
                        Particulier : <?php echo number_format(SUB_PRICE_PARTICULIER_MENSUEL, 0, ',', ' '); ?> FCFA/mois ou <?php echo number_format(SUB_PRICE_PARTICULIER_ANNUEL, 0, ',', ' '); ?> FCFA/an, jusqu'à <?php echo (int)SUB_PREMIUM_VEHICLES_PARTICULIER; ?> véhicules (Gratuit : <?php echo (int)SUB_FREE_VEHICLES_PARTICULIER; ?>).
                        Entreprise : prix par véhicule et par mois selon la taille de la flotte, 2 000 FCFA (1 à 5), 1 500 FCFA (6 à 20), 1 200 FCFA (21 à <?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?>), au moins <?php echo (int)SUB_ENTREPRISE_MIN_VEHICLES; ?> véhicules ; au-delà de <?php echo (int)SUB_ENTREPRISE_MAX_ONLINE; ?>, sur devis. Annuel = 10 mois (2 mois offerts). Gratuit : <?php echo (int)SUB_FREE_VEHICLES_ENTREPRISE; ?> véhicules.
                        Essai Premium de <?php echo (int)SUB_TRIAL_DAYS; ?> jours, une seule fois par client.
                    </div>
                </div>
            </div>

            <div class="av2-stats">
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L14.6 8.6L20.5 9.3L16.1 13.3L17.3 19.2L12 16.2L6.7 19.2L7.9 13.3L3.5 9.3L9.4 8.6L12 3Z" stroke="#1E8A4C" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$subStats['premium']; ?></div><div class="av2-stat-label">Clients Premium actifs</div></div>
                </div>
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$subStats['gratuit']; ?></div><div class="av2-stat-label">Clients Gratuit</div></div>
                </div>
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/><path d="M12 7V12L15 14" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$subStats['essais']; ?></div><div class="av2-stat-label">Essais en cours</div></div>
                </div>
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2.5" stroke="#C8871A" stroke-width="1.8"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo h(number_format($subStats['revenus'], 0, ',', ' ')); ?> <span style="font-size:13px;">FCFA</span></div><div class="av2-stat-label">Revenus abonnements (mois en cours)</div></div>
                </div>
            </div>
        <?php endif; ?>

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
            <?php if ($subsReady): ?>
                <select name="formule" onchange="this.form.submit()">
                    <option value="">Toutes les formules</option>
                    <option value="gratuit" <?php echo $formuleFilter === 'gratuit' ? 'selected' : ''; ?>>Gratuit</option>
                    <option value="premium" <?php echo $formuleFilter === 'premium' ? 'selected' : ''; ?>>Premium (essais compris)</option>
                </select>
            <?php endif; ?>
            <button type="submit" class="av2-btn-primary">Filtrer</button>
            <?php if ($search || $typeFilter || $formuleFilter): ?><a href="abonnements.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($clients)): ?>
                <div class="av2-empty">Aucun client ne correspond aux critères.</div>
            <?php elseif (!$subsReady): ?>
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
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Client</th><th>Type</th><th>Formule</th><th>Périodicité</th><th>Véhicules</th><th>Fin du Premium</th><th>Inscription</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $c):
                                $isEnt = $c['typeClient'] === 'ENTREPRISE';
                                $clientId = (int)$c['id'];
                                $active = $activeByClient[$clientId] ?? null;
                                $isTrial = $active && $active['periodicite'] === 'ESSAI';
                                $limit = subscriptionVehicleLimit($isEnt ? 'ENTREPRISE' : 'PARTICULIER', $active);
                                $count = (int)$c['flotte_taille'];
                                $clientName = $isEnt ? ($c['raisonSociale'] ?: ($c['prenom'] . ' ' . $c['nom'])) : ($c['prenom'] . ' ' . $c['nom']);
                            ?>
                                <tr>
                                    <td><?php echo h($clientName); ?></td>
                                    <td><span class="av2-badge <?php echo $isEnt ? 'info' : 'neutral'; ?>"><?php echo $isEnt ? 'Entreprise' : 'Particulier'; ?></span></td>
                                    <td>
                                        <?php if (!$active): ?><span class="av2-badge neutral">Gratuit</span>
                                        <?php elseif ($isTrial): ?><span class="av2-badge warn">Essai</span>
                                        <?php else: ?><span class="av2-badge ok">Premium</span><?php endif; ?>
                                    </td>
                                    <td><?php echo h($active ? ($periodiciteLabels[$active['periodicite']] ?? $active['periodicite']) : '—'); ?></td>
                                    <td>
                                        <?php echo (int)$count; ?> / <?php echo (int)$limit; ?>
                                        <?php if ($count > $limit): ?><div style="font-size:11.5px; color:#8B90B3;">au-delà de la limite (véhicules conservés)</div><?php endif; ?>
                                    </td>
                                    <td><?php echo h(isset($premiumEndByClient[$clientId]) ? date('d/m/Y', strtotime($premiumEndByClient[$clientId])) : '—'); ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($c['created_at']))); ?></td>
                                    <td>
                                        <form method="POST" action="<?php echo h('abonnements.php' . ($filterQuery ? '?' . $filterQuery : '')); ?>" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="grant">
                                            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                                            <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="<?php echo h('Offrir 1 mois Premium (' . SUB_TRIAL_DAYS . ' jours) à ' . $clientName . ' ? Un abonnement actif sera prolongé.'); ?>">Offrir 1 mois</button>
                                        </form>
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
