<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Supervision des réparations de toute la plateforme (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Lecture seule. Filtres GET : `search` (marque, modèle, immatriculation,
 * titre), `garage`, `statut` ; au plus 200 réparations affichées.
 *
 * Tables lues : reparation, intervention, vehicule, utilisateur (client,
 * technicien), garage.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Supervision uniquement : l'admin consulte, il n'exécute pas les réparations.
$search = $_GET['search'] ?? '';
$garageFilter = filter_var($_GET['garage'] ?? null, FILTER_VALIDATE_INT);
$statutFilter = $_GET['statut'] ?? '';

$where = [];
$params = [];
if ($search) {
    $where[] = "(v.marque LIKE ? OR v.modele LIKE ? OR v.immatriculation LIKE ? OR r.titre LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}
if ($garageFilter) { $where[] = 'i.idGarage = ?'; $params[] = $garageFilter; }
if ($statutFilter) { $where[] = 'r.statut = ?'; $params[] = $statutFilter; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

// Le garage et le client sont retrouvés via l'intervention d'origine ; le
// technicien est celui de la réparation (LEFT JOIN : il peut être absent).
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre, r.diagnostic, r.travauxEffectues, r.piecesUtilisees, r.cout, r.statut, r.dateReparation, r.dureeIntervention,
           v.marque, v.modele, v.immatriculation,
           uc.prenom AS client_prenom, uc.nom AS client_nom,
           ut.prenom AS technicien_prenom, ut.nom AS technicien_nom,
           g.nomGarage
    FROM reparation r
    JOIN intervention i ON i.idIntervention = r.idIntervention
    JOIN vehicule v ON v.idVehicule = i.idVehicule
    JOIN utilisateur uc ON uc.idUtilisateur = i.idClient
    LEFT JOIN utilisateur ut ON ut.idUtilisateur = r.idTechnicien
    LEFT JOIN garage g ON g.idGarage = i.idGarage
    WHERE $whereSql
    ORDER BY r.dateReparation DESC
    LIMIT 200
");
$stmt->execute($params);
$reparations = $stmt->fetchAll();

$garagesList = $conn->query("SELECT idGarage, nomGarage FROM garage WHERE statutGarage = 'VALIDE' ORDER BY nomGarage")->fetchAll();

$stats = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN statut IN ('EN_ATTENTE','EN_COURS') THEN 1 ELSE 0 END) AS en_cours,
           SUM(CASE WHEN statut = 'TERMINEE' THEN 1 ELSE 0 END) AS terminees
    FROM reparation
")->fetch();

$statutLabels = ['EN_ATTENTE' => 'En attente', 'EN_COURS' => 'En cours', 'TERMINEE' => 'Terminée'];
$statutBadge = ['EN_ATTENTE' => 'warn', 'EN_COURS' => 'info', 'TERMINEE' => 'ok'];

$pageTitle = 'Réparations';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'reparations'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Réparations</h1>
                <p class="av2-sub">Supervision globale des réparations réalisées — l'exécution reste au garage/technicien.</p>
            </div>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total réparations</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['en_cours']; ?></div><div class="av2-stat-label">En cours</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['terminees']; ?></div><div class="av2-stat-label">Terminées</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <div class="av2-filterbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#8B90B3" stroke-width="1.8"/><path d="M21 21L16.5 16.5" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="text" name="search" placeholder="Rechercher par marque, modèle, immatriculation, titre…" value="<?php echo h($search); ?>">
            </div>
            <select name="garage" onchange="this.form.submit()">
                <option value="">Tous les garages</option>
                <?php foreach ($garagesList as $g): ?>
                    <option value="<?php echo (int)$g['idGarage']; ?>" <?php echo $garageFilter === (int)$g['idGarage'] ? 'selected' : ''; ?>><?php echo h($g['nomGarage']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="statut" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <?php foreach ($statutLabels as $key => $label): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $statutFilter === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="av2-btn-primary">Filtrer</button>
            <?php if ($search || $garageFilter || $statutFilter): ?><a href="reparations.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($reparations)): ?>
                <div class="av2-empty">Aucune réparation ne correspond aux critères.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Garage</th><th>Technicien</th><th>Titre</th><th>Date</th><th>Durée</th><th>Coût</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reparations as $r): ?>
                                <tr>
                                    <td><?php echo h($r['marque'] . ' ' . $r['modele']); ?><div style="font-size:11.5px; color:#8B90B3;"><?php echo h($r['immatriculation']); ?></div></td>
                                    <td><?php echo h($r['client_prenom'] . ' ' . $r['client_nom']); ?></td>
                                    <td><?php if ($r['nomGarage']): ?><?php echo h($r['nomGarage']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php if ($r['technicien_nom']): ?><?php echo h($r['technicien_prenom'] . ' ' . $r['technicien_nom']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php echo h($r['titre'] ?: '—'); ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($r['dateReparation']))); ?></td>
                                    <td><?php echo h($r['dureeIntervention']); ?> h</td>
                                    <td><?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</td>
                                    <td><span class="av2-badge <?php echo h($statutBadge[$r['statut']] ?? 'neutral'); ?>"><?php echo h($statutLabels[$r['statut']] ?? $r['statut']); ?></span></td>
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
