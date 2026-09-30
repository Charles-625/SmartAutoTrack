<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Réparations réalisées par le garage.
 *
 * Accès : rôle « garage ».
 * Actions :
 *   - POST form=new_reparation : renseigner la réparation d'une intervention
 *     EN_COURS du garage. Dans une même transaction : création de la
 *     réparation (TERMINEE), clôture de l'intervention, résolution de ses
 *     anomalies ouvertes, journalisation et notification du client.
 *   - GET action=new&intervention_id=… : ouvre directement le formulaire.
 * Tables : reparation, intervention, anomalie, notifications (écriture),
 *          vehicule, utilisateur (lecture), journalactivites (via garage_log()).
 */

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
// Renseigner une réparation (clôture l'intervention en cours)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'new_reparation') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        $titre = sanitize($_POST['titre'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $diagnostic = sanitize($_POST['diagnostic'] ?? '');
        $travaux = sanitize($_POST['travaux_effectues'] ?? '');
        $pieces = sanitize($_POST['pieces_utilisees'] ?? '');
        $duree = (float)($_POST['duree_intervention'] ?? 0);
        $cout = (float)($_POST['cout'] ?? 0);
        $recommandations = sanitize($_POST['recommandations'] ?? '');

        if (!$interventionId) $formErrors[] = 'Intervention requise.';
        if (empty($titre)) $formErrors[] = 'Titre du rapport requis.';
        if (empty($description)) $formErrors[] = 'Description requise.';
        if (empty($diagnostic)) $formErrors[] = 'Diagnostic requis.';
        if (empty($travaux)) $formErrors[] = 'Travaux effectués requis.';
        if ($duree <= 0) $formErrors[] = 'Durée d\'intervention requise.';
        if ($cout < 0) $formErrors[] = 'Coût invalide.';

        if (empty($formErrors)) {
            // Contrôle de propriété et d'état : intervention EN_COURS de CE garage.
            $stmt = $conn->prepare("SELECT idClient, idTechnicien, type FROM intervention WHERE idIntervention = ? AND idGarage = ? AND statut = 'EN_COURS'");
            $stmt->execute([$interventionId, $garageId]);
            $iv = $stmt->fetch();
            if (!$iv) {
                $formErrors[] = 'Cette intervention n\'est pas (ou plus) en cours pour votre garage.';
            } else {
                // Transaction : réparation, clôture de l'intervention et résolution des anomalies sont validées ensemble.
                $conn->beginTransaction();
                $stmt = $conn->prepare("
                    INSERT INTO reparation (idIntervention, idTechnicien, titre, description, diagnostic, travauxEffectues, piecesUtilisees, dureeIntervention, cout, recommandations, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TERMINEE')
                ");
                $stmt->execute([$interventionId, $iv['idTechnicien'], $titre, $description, $diagnostic, $travaux, $pieces, $duree, $cout, $recommandations]);
                $reparationId = (int)$conn->lastInsertId();

                $conn->prepare("UPDATE intervention SET statut = 'TERMINEE' WHERE idIntervention = ? AND idGarage = ?")->execute([$interventionId, $garageId]);

                // La réparation règle les anomalies constatées sur cette intervention
                $conn->prepare("UPDATE anomalie SET statut = 'TRAITEE', dateResolution = NOW() WHERE idIntervention = ? AND statut IN ('NOUVELLE', 'EN_COURS')")->execute([$interventionId]);

                garage_log($conn, $interventionId, 'Réparation renseignée et intervention clôturée', $titre, $iv['idTechnicien'], ['idReparation' => $reparationId, 'categorie' => 'reparation']);

                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'rapport', 'Rapport de réparation disponible', ?)")
                    ->execute([$iv['idClient'], 'Le rapport de réparation pour votre véhicule est disponible (' . $garageNom . ').']);

                $conn->commit();
                header('Location: reparations.php?success=created');
                exit;
            }
        }
    }
}

// Interventions EN_COURS de ce garage, disponibles pour créer un rapport
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, v.marque, v.modele, v.immatriculation, u.nom AS client_nom, u.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE i.idGarage = ? AND i.statut = 'EN_COURS'
    ORDER BY i.dateIntervention ASC
");
$stmt->execute([$garageId]);
$interventionsDisponibles = $stmt->fetchAll();

// Réparations déjà réalisées par ce garage
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre, r.statut, r.cout, r.dateReparation, r.dureeIntervention,
           v.marque, v.modele, v.immatriculation,
           uc.nom AS client_nom, uc.prenom AS client_prenom,
           ut.nom AS technicien_nom, ut.prenom AS technicien_prenom
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur uc ON i.idClient = uc.idUtilisateur
    LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
    WHERE i.idGarage = ?
    ORDER BY r.dateReparation DESC
");
$stmt->execute([$garageId]);
$reparations = $stmt->fetchAll();

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$pageTitle = 'Réparations';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'reparations'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="gv2-alert success">Réparation enregistrée et intervention clôturée.</div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="gv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Réparations</h1>
                <p class="gv2-sub">Réparations réalisées par votre garage — renseignez le rapport dès qu'une intervention est terminée.</p>
            </div>
            <?php if (!empty($interventionsDisponibles)): ?>
                <button type="button" class="gv2-btn-primary" id="openReparationModal">+ Renseigner une réparation</button>
            <?php endif; ?>
        </div>

        <?php if (empty($reparations)): ?>
            <div class="gv2-card" style="padding:24px;"><div class="gv2-empty">Aucune réparation enregistrée pour le moment.</div></div>
        <?php else: ?>
            <div class="gv2-card" style="padding:8px;">
                <div class="gv2-table-wrap">
                    <table class="gv2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Technicien</th><th>Titre</th><th>Date</th><th>Durée</th><th>Coût</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reparations as $r): ?>
                                <tr>
                                    <td><?php echo h($r['marque'] . ' ' . $r['modele']); ?><div style="font-size:11.5px; color:#8AA0A3;"><?php echo h($r['immatriculation']); ?></div></td>
                                    <td><?php echo h($r['client_prenom'] . ' ' . $r['client_nom']); ?></td>
                                    <td><?php echo h($r['technicien_nom'] ? $r['technicien_prenom'] . ' ' . $r['technicien_nom'] : '—'); ?></td>
                                    <td><?php echo h($r['titre'] ?: '—'); ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($r['dateReparation']))); ?></td>
                                    <td><?php echo h($r['dureeIntervention']); ?> h</td>
                                    <td><?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</td>
                                    <td><span class="gv2-badge <?php echo $r['statut'] === 'TERMINEE' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(strtolower($r['statut']))); ?></span></td>
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
<div class="gv2-modal-overlay" id="reparationModalOverlay">
    <div class="gv2-modal">
        <h3>Renseigner une réparation</h3>
        <p class="gv2-modal-sub">Cette action clôture l'intervention et rend le rapport disponible au client.</p>
        <form method="POST">
            <input type="hidden" name="form" value="new_reparation">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="gv2-form-group">
                <label for="repIntervention">Intervention</label>
                <select name="intervention_id" id="repIntervention" required>
                    <option value="">Sélectionner une intervention en cours</option>
                    <?php foreach ($interventionsDisponibles as $iv): ?>
                        <option value="<?php echo (int)$iv['id']; ?>" <?php echo $preselectIntervention === (int)$iv['id'] ? 'selected' : ''; ?>>
                            <?php echo h(($iv['type'] ?: 'Intervention') . ' — ' . $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ') — ' . $iv['client_prenom'] . ' ' . $iv['client_nom']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gv2-form-group"><label for="repTitre">Titre du rapport</label><input type="text" name="titre" id="repTitre" required placeholder="Ex. Remplacement des plaquettes de frein"></div>
            <div class="gv2-form-group"><label for="repDescription">Description générale</label><textarea name="description" id="repDescription" required placeholder="Ex. Bruit métallique au freinage à l'avant…"></textarea></div>
            <div class="gv2-form-group"><label for="repDiagnostic">Diagnostic</label><textarea name="diagnostic" id="repDiagnostic" required placeholder="Ex. Plaquettes avant usées à 90 %…"></textarea></div>
            <div class="gv2-form-group"><label for="repTravaux">Travaux effectués</label><textarea name="travaux_effectues" id="repTravaux" required placeholder="Ex. Remplacement des plaquettes avant, purge du circuit…"></textarea></div>
            <div class="gv2-form-group"><label for="repPieces">Pièces utilisées</label><textarea name="pieces_utilisees" id="repPieces" placeholder="Ex. 2 plaquettes avant Bosch, liquide de frein DOT4"></textarea></div>
            <div class="gv2-form-row">
                <div class="gv2-form-group"><label for="repDuree">Durée (heures)</label><input type="number" name="duree_intervention" id="repDuree" required min="0" step="0.5" placeholder="Ex. 1.5"></div>
                <div class="gv2-form-group"><label for="repCout">Coût (XAF)</label><input type="number" name="cout" data-only="digits" inputmode="numeric" id="repCout" required min="0" step="1" placeholder="Ex. 25000"></div>
            </div>
            <div class="gv2-form-group"><label for="repRecommandations">Recommandations</label><textarea name="recommandations" id="repRecommandations" placeholder="Ex. Contrôler les disques dans 5 000 km"></textarea></div>
            <div class="gv2-modal-actions">
                <button type="button" class="gv2-btn-outline" id="closeReparationModal">Annuler</button>
                <button type="submit" class="gv2-btn-primary">Enregistrer et clôturer</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('reparationModalOverlay');
    var openBtn = document.getElementById('openReparationModal');
    if (openBtn) openBtn.addEventListener('click', function () { overlay.classList.add('show'); });
    document.getElementById('closeReparationModal').addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });
    <?php if ($action === 'new' || !empty($formErrors)): ?>
    overlay.classList.add('show');
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
