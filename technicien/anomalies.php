<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/anomaly_types.php';

/**
 * Espace technicien — Mes anomalies.
 *
 * Accès : rôle « technicien ».
 * Actions :
 *   - POST form=new_anomalie : constater une anomalie sur une de MES
 *     interventions EN_COURS ou TERMINEE (statut initial NOUVELLE) et la journaliser.
 *   - GET action=new&intervention_id=… : ouvre directement le formulaire.
 * Le champ type reste libre ; les types de includes/anomaly_types.php sont
 * proposés en suggestions (<datalist>).
 * Liste : les anomalies rattachées à MES interventions (a.idIntervention), y
 * compris celles déclarées par le client dans sa demande, visibles dès que le
 * garage m'affecte l'intervention.
 * Tables : anomalie (écriture), intervention, vehicule (lecture),
 *          journalactivites (via technicien_log()).
 */

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

$formErrors = [];
$action = $_GET['action'] ?? '';
$preselectIntervention = filter_var($_GET['intervention_id'] ?? null, FILTER_VALIDATE_INT);

// ============================================================
// Constater et enregistrer une anomalie, uniquement sur une intervention qui
// m'est assignée (le client, lui, ne peut en déclarer qu'en demandant une
// intervention).
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
            // Contrôle de propriété : l'intervention doit m'être assignée.
            $stmt = $conn->prepare("SELECT idVehicule FROM intervention WHERE idIntervention = ? AND idTechnicien = ?");
            $stmt->execute([$interventionId, $selfId]);
            $iv = $stmt->fetch();
            if (!$iv) {
                $formErrors[] = 'Intervention introuvable parmi les vôtres.';
            } else {
                $stmt = $conn->prepare("INSERT INTO anomalie (idVehicule, idIntervention, description, type, niveau, statut) VALUES (?, ?, ?, ?, ?, 'NOUVELLE')");
                $stmt->execute([$iv['idVehicule'], $interventionId, $description, $type ?: null, $niveau]);
                $anomalieId = (int)$conn->lastInsertId();

                technicien_log($conn, 'Anomalie constatée', [
                    'idIntervention' => $interventionId, 'idAnomalie' => $anomalieId, 'description' => $description, 'categorie' => 'anomalie',
                ]);

                header('Location: anomalies.php?success=created');
                exit;
            }
        }
    }
}

// Interventions de ce technicien, pour le formulaire (toutes, la plus récente
// d'abord — une anomalie peut être constatée après coup).
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, v.marque, v.modele, v.immatriculation
    FROM intervention i JOIN vehicule v ON i.idVehicule = v.idVehicule
    WHERE i.idTechnicien = ? AND i.statut IN ('EN_COURS', 'TERMINEE')
    ORDER BY i.dateIntervention DESC
    LIMIT 50
");
$stmt->execute([$selfId]);
$interventionsDisponibles = $stmt->fetchAll();

// Anomalies de mes interventions : mes constats et ceux du garage, ainsi que
// l'anomalie déclarée par le client à sa demande.
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description, a.type, a.niveau, a.statut, a.dateDetection,
           v.marque, v.modele, v.immatriculation
    FROM anomalie a
    JOIN intervention i ON i.idIntervention = a.idIntervention
    JOIN vehicule v ON v.idVehicule = a.idVehicule
    WHERE i.idTechnicien = ?
    ORDER BY a.dateDetection DESC
");
$stmt->execute([$selfId]);
$anomalies = $stmt->fetchAll();

// Compteur du badge « Mes tâches » de la sidebar (tâches à démarrer).
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

$pageTitle = 'Mes anomalies';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'anomalies'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="tv2-alert success">Anomalie constatée et enregistrée.</div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="tv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Mes anomalies</h1>
                <p class="tv2-sub">Les anomalies relevées sur vos interventions, y compris celles signalées par le client dans sa demande.</p>
            </div>
            <?php if (!empty($interventionsDisponibles)): ?>
                <button type="button" class="tv2-btn-primary" id="openAnomalieModal">+ Constater une anomalie</button>
            <?php endif; ?>
        </div>

        <?php if (empty($anomalies)): ?>
            <div class="tv2-card" style="padding:24px;"><div class="tv2-empty">Aucune anomalie constatée pour le moment.</div></div>
        <?php else: ?>
            <div class="tv2-card" style="padding:8px;">
                <div class="tv2-table-wrap">
                    <table class="tv2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Description</th><th>Niveau</th><th>Date</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anomalies as $a): $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true); $niveauBadge = $a['niveau'] === 'CRITIQUE' ? 'bad' : ($a['niveau'] === 'FAIBLE' ? 'neutral' : 'warn'); ?>
                                <tr>
                                    <td><?php echo h($a['marque'] . ' ' . $a['modele']); ?><div style="font-size:11.5px; color:#8B90B3;"><?php echo h($a['immatriculation']); ?></div></td>
                                    <td><?php echo h($a['description']); ?></td>
                                    <td><span class="tv2-badge <?php echo h($niveauBadge); ?>"><?php echo h(ucfirst(strtolower($a['niveau']))); ?></span></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($a['dateDetection']))); ?></td>
                                    <td><span class="tv2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo $isActive ? 'Active' : 'Résolue'; ?></span></td>
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
<div class="tv2-modal-overlay" id="anomalieModalOverlay">
    <div class="tv2-modal">
        <h3>Constater une anomalie</h3>
        <p class="tv2-modal-sub">Décrivez l'anomalie constatée sur le véhicule — elle sera visible par le garage et le client.</p>
        <form method="POST">
            <input type="hidden" name="form" value="new_anomalie">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="tv2-form-group">
                <label for="anIntervention">Intervention concernée</label>
                <select name="intervention_id" id="anIntervention" required>
                    <option value="">Sélectionner une intervention</option>
                    <?php foreach ($interventionsDisponibles as $iv): ?>
                        <option value="<?php echo (int)$iv['id']; ?>" <?php echo $preselectIntervention === (int)$iv['id'] ? 'selected' : ''; ?>>
                            <?php echo h(($iv['type'] ?: 'Intervention') . ' — ' . $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tv2-form-group"><label for="anType">Type</label><input type="text" name="type" id="anType" list="anTypeSuggestions" maxlength="100" placeholder="Ex. Freinage">
                <datalist id="anTypeSuggestions">
                    <?php foreach (ANOMALY_TYPES as $anomalyType): ?>
                        <option value="<?php echo h($anomalyType); ?>">
                    <?php endforeach; ?>
                </datalist></div>
            <div class="tv2-form-group"><label for="anDescription">Description</label><textarea name="description" id="anDescription" required placeholder="Ex. Bruit métallique au freinage à l'avant…"></textarea></div>
            <div class="tv2-form-group">
                <label for="anNiveau">Niveau</label>
                <select name="niveau" id="anNiveau">
                    <option value="FAIBLE">Faible</option>
                    <option value="MOYEN" selected>Moyen</option>
                    <option value="CRITIQUE">Critique</option>
                </select>
            </div>
            <div class="tv2-modal-actions">
                <button type="button" class="tv2-btn-outline" id="closeAnomalieModal">Annuler</button>
                <button type="submit" class="tv2-btn-primary">Enregistrer</button>
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
