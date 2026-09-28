<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);
$garageNom = $profile['nomGarage'] ?? 'Garage';

$formErrors = [];
$action = $_GET['action'] ?? '';
$preselectIntervention = filter_var($_GET['intervention_id'] ?? null, FILTER_VALIDATE_INT);

// ============================================================
// Constater et enregistrer une anomalie (capacité réservée au
// garage/technicien — jamais au client, cf. règle métier).
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'new_anomalie') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        $description = sanitize($_POST['description'] ?? '');
        $type = sanitize($_POST['type'] ?? '');
        $niveau = $_POST['niveau'] ?? 'MOYEN';
        if (!in_array($niveau, ['FAIBLE', 'MOYEN', 'CRITIQUE'], true)) $niveau = 'MOYEN';

        if (!$interventionId) $formErrors[] = 'Merci de choisir l\'intervention concernée.';
        if (empty($description)) $formErrors[] = 'Merci de décrire l\'anomalie constatée.';

        if (empty($formErrors)) {
            $stmt = $conn->prepare("SELECT idVehicule, idTechnicien FROM intervention WHERE idIntervention = ? AND idGarage = ?");
            $stmt->execute([$interventionId, $garageId]);
            $iv = $stmt->fetch();
            if (!$iv) {
                $formErrors[] = 'Intervention introuvable pour votre garage.';
            } else {
                $stmt = $conn->prepare("INSERT INTO anomalie (idVehicule, idIntervention, description, type, niveau, statut) VALUES (?, ?, ?, ?, ?, 'NOUVELLE')");
                $stmt->execute([$iv['idVehicule'], $interventionId, $description, $type ?: null, $niveau]);
                $anomalieId = (int)$conn->lastInsertId();

                garage_log($conn, $interventionId, 'Anomalie constatée', $description, $iv['idTechnicien'], ['idAnomalie' => $anomalieId, 'categorie' => 'anomalie']);

                header('Location: anomalies.php?success=created');
                exit;
            }
        }
    }
}

// ============================================================
// Interventions de ce garage, pour le formulaire (toutes, la plus récente
// d'abord — une anomalie peut être constatée après coup, pas seulement
// pendant une intervention EN_COURS).
// ============================================================
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, v.marque, v.modele, v.immatriculation
    FROM intervention i JOIN vehicule v ON i.idVehicule = v.idVehicule
    WHERE i.idGarage = ? AND i.statut IN ('EN_COURS', 'TERMINEE')
    ORDER BY i.dateIntervention DESC
    LIMIT 50
");
$stmt->execute([$garageId]);
$interventionsDisponibles = $stmt->fetchAll();

// ============================================================
// Filtres de la liste
// ============================================================
$statutFilter = $_GET['statut'] ?? '';
$niveauFilter = $_GET['niveau'] ?? '';
$where = ["EXISTS (SELECT 1 FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.idGarage = ?)"];
$params = [$garageId];
if ($statutFilter === 'active') { $where[] = "a.statut IN ('NOUVELLE', 'EN_COURS')"; }
elseif ($statutFilter === 'resolue') { $where[] = "a.statut IN ('TRAITEE', 'IGNOREE')"; }
if (in_array($niveauFilter, ['FAIBLE', 'MOYEN', 'CRITIQUE'], true)) { $where[] = 'a.niveau = ?'; $params[] = $niveauFilter; }
$whereSql = implode(' AND ', $where);

// L'intervention concernée est celle réellement choisie au constat
// (a.idIntervention, colonne dédiée) ; secours par proximité de date
// uniquement pour d'éventuelles anomalies plus anciennes sans ce lien.
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description, a.niveau, a.statut, a.dateDetection,
           v.marque, v.modele, v.immatriculation,
           u.nom AS client_nom, u.prenom AS client_prenom,
           COALESCE(
               ie.type,
               (SELECT i.type FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.idGarage = ? AND i.dateIntervention <= a.dateDetection ORDER BY i.dateIntervention DESC LIMIT 1)
           ) AS intervention_type,
           COALESCE(
               ie.dateIntervention,
               (SELECT i.dateIntervention FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.idGarage = ? AND i.dateIntervention <= a.dateDetection ORDER BY i.dateIntervention DESC LIMIT 1)
           ) AS intervention_date
    FROM anomalie a
    JOIN vehicule v ON a.idVehicule = v.idVehicule
    JOIN utilisateur u ON v.idClient = u.idUtilisateur
    LEFT JOIN intervention ie ON ie.idIntervention = a.idIntervention
    WHERE $whereSql
    ORDER BY a.dateDetection DESC
");
$stmt->execute(array_merge([$garageId, $garageId], $params));
$anomalies = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$statutLabels = ['NOUVELLE' => 'Nouvelle', 'EN_COURS' => 'En cours de traitement', 'TRAITEE' => 'Traitée', 'IGNOREE' => 'Résolue'];

$pageTitle = 'Anomalies';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'anomalies'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="gv2-alert success">Anomalie constatée et enregistrée.</div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="gv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Anomalies</h1>
                <p class="gv2-sub">Constatez et enregistrez les anomalies découvertes lors de vos interventions et diagnostics.</p>
            </div>
            <?php if (!empty($interventionsDisponibles)): ?>
                <button type="button" class="gv2-btn-primary" id="openAnomalieModal">+ Constater une anomalie</button>
            <?php endif; ?>
        </div>

        <form method="GET" class="gv2-filterbar">
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
        </form>

        <?php if (empty($anomalies)): ?>
            <div class="gv2-card" style="padding:24px;"><div class="gv2-empty">Aucune anomalie pour ce filtre.</div></div>
        <?php else: ?>
            <div class="gv2-card" style="padding:8px;">
                <div class="gv2-table-wrap">
                    <table class="gv2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Anomalie constatée</th><th>Niveau</th><th>Date</th><th>Intervention concernée</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anomalies as $a): $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true); $niveauBadge = $a['niveau'] === 'CRITIQUE' ? 'bad' : ($a['niveau'] === 'MOYEN' ? 'warn' : 'neutral'); ?>
                                <tr>
                                    <td><?php echo h($a['marque'] . ' ' . $a['modele']); ?><div style="font-size:11.5px; color:#8AA0A3;"><?php echo h($a['immatriculation']); ?></div></td>
                                    <td><?php echo h($a['client_prenom'] . ' ' . $a['client_nom']); ?></td>
                                    <td style="max-width:240px;"><?php echo h($a['description']); ?></td>
                                    <td><span class="gv2-badge <?php echo h($niveauBadge); ?>"><?php echo h(ucfirst(strtolower($a['niveau']))); ?></span></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($a['dateDetection']))); ?></td>
                                    <td><?php echo h($a['intervention_type'] ? $a['intervention_type'] . ' du ' . date('d/m/Y', strtotime($a['intervention_date'])) : '—'); ?></td>
                                    <td><span class="gv2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo h($statutLabels[$a['statut']] ?? ucfirst(strtolower($a['statut']))); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if (!empty($interventionsDisponibles)): ?>
<div class="gv2-modal-overlay" id="anomalieModalOverlay">
    <div class="gv2-modal">
        <h3>Constater une anomalie</h3>
        <p class="gv2-modal-sub">Rattachez l'anomalie au véhicule et à l'intervention concernée. Le client pourra la consulter, jamais la modifier.</p>
        <form method="POST">
            <input type="hidden" name="form" value="new_anomalie">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="gv2-form-group">
                <label for="anIntervention">Intervention concernée</label>
                <select name="intervention_id" id="anIntervention" required>
                    <option value="">Sélectionner une intervention</option>
                    <?php foreach ($interventionsDisponibles as $iv): ?>
                        <option value="<?php echo (int)$iv['id']; ?>" <?php echo $preselectIntervention === (int)$iv['id'] ? 'selected' : ''; ?>>
                            <?php echo h(($iv['type'] ?: 'Intervention') . ' — ' . $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ') — ' . date('d/m/Y', strtotime($iv['dateIntervention']))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gv2-form-group">
                <label for="anType">Type d'anomalie</label>
                <input type="text" name="type" id="anType" placeholder="Ex. : Freinage, Moteur, Pneumatiques...">
            </div>
            <div class="gv2-form-group">
                <label for="anNiveau">Niveau</label>
                <select name="niveau" id="anNiveau">
                    <option value="FAIBLE">Faible</option>
                    <option value="MOYEN" selected>Moyen</option>
                    <option value="CRITIQUE">Critique</option>
                </select>
            </div>
            <div class="gv2-form-group">
                <label for="anDescription">Anomalie constatée</label>
                <textarea name="description" id="anDescription" required placeholder="Ex. : Usure importante des plaquettes de frein avant"></textarea>
            </div>
            <div class="gv2-modal-actions">
                <button type="button" class="gv2-btn-outline" id="closeAnomalieModal">Annuler</button>
                <button type="submit" class="gv2-btn-primary">Enregistrer l'anomalie</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('anomalieModalOverlay');
    var openBtn = document.getElementById('openAnomalieModal');
    if (openBtn) openBtn.addEventListener('click', function () { overlay.classList.add('show'); });
    document.getElementById('closeAnomalieModal').addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });
    <?php if ($action === 'new' || !empty($formErrors)): ?>
    overlay.classList.add('show');
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
