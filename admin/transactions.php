<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/payments.php';

/**
 * Supervision des paiements clients (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Lecture seule. Filtre GET `statut` (PAYE, EN_ATTENTE, ECHOUE, ANNULE) ;
 * au plus 200 transactions affichées.
 *
 * Tables lues : paiement, utilisateur, intervention.
 * Liens : includes/payments.php (paymentsReady), webhooks/campay.php.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();
// Vrai si la migration CamPay est appliquée : les colonnes CamPay ne sont
// sélectionnées que si elles existent, pour que la page marche aussi sur un
// schéma plus ancien.
$campayColumns = paymentsReady($conn);

// La table `paiement` existe déjà dans le schéma : supervision réelle,
// aucune transaction fictive créée pour peupler cette page.
$statutFilter = $_GET['statut'] ?? '';
$where = [];
$params = [];
if ($statutFilter) { $where[] = 'p.statut = ?'; $params[] = $statutFilter; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $conn->prepare("
    SELECT p.idPaiement AS id, p.montant, p.datePaiement, p.typePaiement, p.statut,
           u.nom, u.prenom, i.type AS intervention_type
           " . ($campayColumns ? ", p.referenceCampay, p.operateur, p.telephone, p.messageErreur" : "") . "
    FROM paiement p
    JOIN utilisateur u ON u.idUtilisateur = p.idClient
    LEFT JOIN intervention i ON i.idIntervention = p.idIntervention
    WHERE $whereSql
    ORDER BY p.datePaiement DESC
    LIMIT 200
");
$stmt->execute($params);
$paiements = $stmt->fetchAll();

$stats = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN statut = 'PAYE' THEN 1 ELSE 0 END) AS reussies,
           SUM(CASE WHEN statut = 'EN_ATTENTE' THEN 1 ELSE 0 END) AS en_attente,
           SUM(CASE WHEN statut = 'PAYE' THEN montant ELSE 0 END) AS total_encaisse
    FROM paiement
")->fetch();

$statutLabels = ['PAYE' => 'Réussie', 'EN_ATTENTE' => 'En attente', 'ECHOUE' => 'Échouée', 'ANNULE' => 'Annulée'];
$statutBadge = ['PAYE' => 'ok', 'EN_ATTENTE' => 'warn', 'ECHOUE' => 'bad', 'ANNULE' => 'neutral'];

$pageTitle = 'Transactions';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'transactions'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Transactions</h1>
                <p class="av2-sub">Supervision des paiements enregistrés sur la plateforme.</p>
            </div>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M7 8H21M7 8L10 5M7 8L10 11" stroke="#6D74A0" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total transactions</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['reussies']; ?></div><div class="av2-stat-label">Réussies</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo number_format((float)($stats['total_encaisse'] ?? 0), 0, ',', ' '); ?> XAF</div><div class="av2-stat-label">Total encaissé</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <?php foreach ($statutLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $statutFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statutFilter): ?><a href="transactions.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($paiements)): ?>
                <div class="av2-empty">Aucune transaction enregistrée pour le moment.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Référence</th><th>Client</th><th>Intervention</th><th>Montant</th><th>Date</th><th>Moyen</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paiements as $p): ?>
                                <tr>
                                    <td>#<?php echo (int)$p['id']; ?><?php if (!empty($p['referenceCampay'])): ?><br><small title="Référence CamPay"><?php echo h($p['referenceCampay']); ?></small><?php endif; ?></td>
                                    <td><?php echo h($p['prenom'] . ' ' . $p['nom']); ?></td>
                                    <td><?php echo h($p['intervention_type'] ?: '—'); ?></td>
                                    <td><?php echo number_format((float)$p['montant'], 0, ',', ' '); ?> XAF</td>
                                    <td><?php echo h($p['datePaiement'] ? date('d/m/Y', strtotime($p['datePaiement'])) : '—'); ?></td>
                                    <td><?php echo h(($p['typePaiement'] ?: '—') . (!empty($p['operateur']) ? ' · ' . $p['operateur'] : '')); ?><?php if (!empty($p['telephone'])): ?><br><small><?php echo h($p['telephone']); ?></small><?php endif; ?></td>
                                    <td><span class="av2-badge <?php echo h($statutBadge[$p['statut']] ?? 'neutral'); ?>" title="<?php echo h($p['messageErreur'] ?? ''); ?>"><?php echo h($statutLabels[$p['statut']] ?? $p['statut']); ?></span></td>
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
