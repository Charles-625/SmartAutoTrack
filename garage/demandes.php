<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/anomaly_types.php';

/**
 * Espace garage — Demandes d'intervention reçues des clients.
 *
 * Accès : rôle « garage ».
 * Une « nouvelle demande » est une intervention PLANIFIEE sans technicien
 * (libellé calculé, cf. garage_status_info()).
 * Actions :
 *   - POST form=assign : accepter la demande, l'affecter à un technicien
 *     validé du garage, fixer la date prévue et la priorité ; notifie le
 *     technicien et le client.
 *   - POST form=refuse : refuser une demande encore non affectée (statut
 *     ANNULEE) ; le client est notifié.
 *   - GET statut=nouvelle|planifiee|en_cours|terminee|refusee|toutes : filtre.
 * Les interventions où le garage est seulement en appui d'un technicien
 * SmartAutoTrack (affectées par l'admin) n'y figurent pas : elles sont
 * listées dans garage/interventions.php.
 * Motif « Anomalie constatée » : la liste affiche le type et la gravité de
 * l'anomalie déclarée par le client (la première rattachée à l'intervention) ;
 * les nouvelles demandes portant une anomalie CRITIQUE passent en tête, le
 * reste garde l'ordre chronologique.
 * Tables : intervention (écriture), technicien, vehicule, utilisateur, anomalie
 *          (lecture), notifications (écriture), journalactivites (via garage_log()).
 * Liés : includes/anomaly_types.php (motif, libellés de gravité).
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);
$garageNom = $profile['nomGarage'] ?? 'Garage';

// ============================================================
// Techniciens du garage (validés), pour le formulaire d'affectation.
// Un garage ne doit jamais pouvoir choisir un technicien d'un autre garage :
// la requête est bornée à t.idGarage = $garageId.
// ============================================================
$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, t.specialite
    FROM technicien t JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
    WHERE t.idGarage = ? AND t.statutValidation = 'VALIDE'
    ORDER BY u.nom, u.prenom
");
$stmt->execute([$garageId]);
$techniciens = $stmt->fetchAll();

$formErrors = [];

// ============================================================
// Accepter + affecter une demande à un technicien du garage
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'assign') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        $technicienId = filter_var($_POST['technicien_id'] ?? null, FILTER_VALIDATE_INT);
        $datePrevue = $_POST['date_prevue'] ?? '';
        $priorite = $_POST['priorite'] ?? 'MOYENNE';

        if (!$interventionId) $formErrors[] = 'Demande invalide.';
        if (!$technicienId) $formErrors[] = 'Merci de choisir un technicien.';
        if (!in_array($priorite, ['BASSE', 'MOYENNE', 'HAUTE'], true)) $priorite = 'MOYENNE';
        $dateObj = DateTime::createFromFormat('Y-m-d', $datePrevue);
        if (!$dateObj) $formErrors[] = 'Date prévue invalide.';

        // Le technicien doit appartenir à CE garage (jamais à un autre)
        if (empty($formErrors)) {
            $stmt = $conn->prepare("SELECT 1 FROM technicien WHERE idTechnicien = ? AND idGarage = ? AND statutValidation = 'VALIDE'");
            $stmt->execute([$technicienId, $garageId]);
            if (!$stmt->fetch()) {
                $formErrors[] = 'Ce technicien n\'appartient pas à votre garage.';
            }
        }

        if (empty($formErrors)) {
            $stmt = $conn->prepare("SELECT idClient, type FROM intervention WHERE idIntervention = ? AND idGarage = ? AND idTechnicien IS NULL");
            $stmt->execute([$interventionId, $garageId]);
            $iv = $stmt->fetch();
            if (!$iv) {
                $formErrors[] = 'Cette demande ne peut plus être affectée (déjà traitée ou hors de votre garage).';
            } else {
                // Le formulaire ne saisit qu'un jour : l'heure prévue est fixée à 8 h.
                $stmt = $conn->prepare("UPDATE intervention SET idTechnicien = ?, dateIntervention = ?, priorite = ? WHERE idIntervention = ? AND idGarage = ?");
                $stmt->execute([$technicienId, $dateObj->format('Y-m-d') . ' 08:00:00', $priorite, $interventionId, $garageId]);

                garage_log($conn, $interventionId, 'Demande acceptée et planifiée', 'Le garage a affecté cette intervention à un technicien.', null);
                garage_log($conn, $interventionId, 'Intervention assignée à un technicien', null, $technicienId);

                // Notifications : le technicien affecté et le client.
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Nouvelle intervention assignée', ?)")
                    ->execute([$technicienId, 'Vous avez été assigné à une intervention : ' . $iv['type']]);
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Intervention planifiée', ?)")
                    ->execute([$iv['idClient'], 'Votre demande d\'intervention a été planifiée par ' . $garageNom . '.']);

                header('Location: demandes.php?success=assigned');
                exit;
            }
        }
    }
}

// ============================================================
// Refuser une demande non encore affectée
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'refuse') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("SELECT idClient, type FROM intervention WHERE idIntervention = ? AND idGarage = ? AND idTechnicien IS NULL");
        $stmt->execute([$interventionId, $garageId]);
        $iv = $stmt->fetch();
        if ($iv) {
            $conn->prepare("UPDATE intervention SET statut = 'ANNULEE' WHERE idIntervention = ? AND idGarage = ?")->execute([$interventionId, $garageId]);
            garage_log($conn, $interventionId, 'Demande refusée', 'Le garage a refusé cette demande d\'intervention.', null);
            $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Demande refusée', ?)")
                ->execute([$iv['idClient'], $garageNom . ' n\'a pas pu prendre en charge votre demande (' . $iv['type'] . '). Un administrateur va la réaffecter.']);
            header('Location: demandes.php?success=refused');
            exit;
        }
        $formErrors[] = 'Cette demande ne peut plus être refusée.';
    }
}

// ============================================================
// Liste des demandes du garage
// ============================================================
$statutFilter = $_GET['statut'] ?? 'nouvelle';
// Les interventions où le garage est en appui d'un technicien SmartAutoTrack
// (INTERNE, affecté par l'admin) ne sont pas des demandes adressées au
// garage : elles restent dans interventions.php, jamais ici.
$where = "i.idGarage = ? AND NOT EXISTS (SELECT 1 FROM technicien ti WHERE ti.idTechnicien = i.idTechnicien AND ti.typeTechnicien = 'INTERNE')";
$params = [$garageId];
if ($statutFilter === 'nouvelle') {
    $where .= " AND i.idTechnicien IS NULL AND i.statut = 'PLANIFIEE'";
} elseif ($statutFilter === 'planifiee') {
    $where .= " AND i.idTechnicien IS NOT NULL AND i.statut = 'PLANIFIEE'";
} elseif ($statutFilter === 'en_cours') {
    $where .= " AND i.statut = 'EN_COURS'";
} elseif ($statutFilter === 'terminee') {
    $where .= " AND i.statut = 'TERMINEE'";
} elseif ($statutFilter === 'refusee') {
    $where .= " AND i.statut = 'ANNULEE'";
}
// 'toutes' : pas de filtre supplémentaire

// Anomalie déclarée à la demande = la première rattachée à l'intervention
// (les constats des professionnels n'arrivent qu'une fois l'intervention
// démarrée). Tri : nouvelles demandes avec une anomalie CRITIQUE d'abord,
// puis ordre chronologique comme avant.
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut, i.priorite, i.idTechnicien,
           v.marque, v.modele, v.immatriculation,
           u.nom AS client_nom, u.prenom AS client_prenom,
           (SELECT a.type FROM anomalie a WHERE a.idIntervention = i.idIntervention ORDER BY a.idAnomalie ASC LIMIT 1) AS anomalie_type,
           (SELECT a.niveau FROM anomalie a WHERE a.idIntervention = i.idIntervention ORDER BY a.idAnomalie ASC LIMIT 1) AS anomalie_niveau
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE $where
    ORDER BY CASE WHEN i.idTechnicien IS NULL AND i.statut = 'PLANIFIEE'
                   AND EXISTS (SELECT 1 FROM anomalie ac WHERE ac.idIntervention = i.idIntervention AND ac.niveau = 'CRITIQUE')
              THEN 0 ELSE 1 END,
             i.dateIntervention ASC
");
$stmt->execute($params);
$demandes = $stmt->fetchAll();

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$pageTitle = "Demandes d'intervention";
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'demandes'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="gv2-alert success">
                <?php echo $_GET['success'] === 'assigned' ? 'Demande acceptée et affectée avec succès.' : 'Demande refusée.'; ?>
            </div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="gv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Demandes d'intervention</h1>
                <p class="gv2-sub">Les demandes adressées à votre garage, du dépôt jusqu'à l'affectation à l'un de vos techniciens.</p>
            </div>
        </div>

        <form method="GET" class="gv2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="nouvelle" <?php echo $statutFilter === 'nouvelle' ? 'selected' : ''; ?>>Nouvelles demandes</option>
                <option value="planifiee" <?php echo $statutFilter === 'planifiee' ? 'selected' : ''; ?>>Planifiées</option>
                <option value="en_cours" <?php echo $statutFilter === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                <option value="terminee" <?php echo $statutFilter === 'terminee' ? 'selected' : ''; ?>>Terminées</option>
                <option value="refusee" <?php echo $statutFilter === 'refusee' ? 'selected' : ''; ?>>Refusées / Annulées</option>
                <option value="toutes" <?php echo $statutFilter === 'toutes' ? 'selected' : ''; ?>>Toutes</option>
            </select>
        </form>

        <div class="gv2-card" style="padding:8px;">
            <?php if (empty($demandes)): ?>
                <div class="gv2-empty">Aucune demande pour ce filtre.</div>
            <?php else: ?>
                <div class="gv2-table-wrap">
                    <table class="gv2-table">
                        <thead>
                            <tr>
                                <th>Client</th><th>Véhicule</th><th>Type d'intervention</th><th>Date de demande</th><th>Priorité</th><th>Statut</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demandes as $d): $info = garage_status_info($d); ?>
                                <tr>
                                    <td><?php echo h($d['client_prenom'] . ' ' . $d['client_nom']); ?></td>
                                    <td>
                                        <div class="gv2-table-entity">
                                            <div class="gv2-table-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#1E7DBF" stroke-width="1.6"/><circle cx="7.5" cy="18.5" r="1.5" stroke="#1E7DBF" stroke-width="1.6"/><circle cx="16.5" cy="18.5" r="1.5" stroke="#1E7DBF" stroke-width="1.6"/><path d="M5 10L7 5.5H17L19 10" stroke="#1E7DBF" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php echo h($d['marque'] . ' ' . $d['modele']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($d['immatriculation']); ?></div>
                                    </td>
                                    <td>
                                        <?php echo h($d['type'] ?: '—'); ?>
                                        <?php if ($d['type'] === ANOMALY_REQUEST_MOTIF && $d['anomalie_niveau']): $anomalieBadge = $d['anomalie_niveau'] === 'CRITIQUE' ? 'bad' : ($d['anomalie_niveau'] === 'MOYEN' ? 'warn' : 'neutral'); ?>
                                            <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($d['anomalie_type'] ?: 'Type non précisé'); ?></div>
                                            <span class="gv2-badge <?php echo h($anomalieBadge); ?>" title="<?php echo h(anomaly_severity_label($d['anomalie_niveau'])); ?>" style="display:inline-block; margin-top:4px;"><?php echo h('Gravité : ' . ucfirst(strtolower($d['anomalie_niveau']))); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h(date('d/m/Y', strtotime($d['dateIntervention']))); ?></td>
                                    <td><span class="gv2-badge <?php echo h($d['priorite'] === 'HAUTE' ? 'bad' : ($d['priorite'] === 'BASSE' ? 'neutral' : 'warn')); ?>"><?php echo h(ucfirst(strtolower($d['priorite']))); ?></span></td>
                                    <td><span class="gv2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span></td>
                                    <td>
                                        <?php if ($info['key'] === 'nouvelle'): ?>
                                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                                <button type="button" class="gv2-btn-primary gv2-btn-xs" onclick="openAssign(<?php echo (int)$d['id']; ?>, '<?php echo h(addslashes($d['type'] . ' — ' . $d['marque'] . ' ' . $d['modele'])); ?>')">Traiter</button>
                                                <button type="button" class="gv2-btn-danger gv2-btn-xs" onclick="openRefuse(<?php echo (int)$d['id']; ?>)">Refuser</button>
                                            </div>
                                        <?php else: ?>
                                            <a href="interventions.php?id=<?php echo (int)$d['id']; ?>" class="gv2-table-link">Voir détails</a>
                                        <?php endif; ?>
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

<!-- Modal : accepter + affecter -->
<div class="gv2-modal-overlay" id="assignModalOverlay">
    <div class="gv2-modal">
        <h3>Affecter la demande</h3>
        <p class="gv2-modal-sub" id="assignModalSub"></p>
        <?php if (!empty($formErrors)): ?><div class="gv2-alert error"><?php foreach ($formErrors as $e) echo h($e) . '<br>'; ?></div><?php endif; ?>
        <?php if (empty($techniciens)): ?>
            <div class="gv2-alert error">Vous n'avez aucun technicien validé. Ajoutez-en un depuis <a href="techniciens.php">Mes techniciens</a> avant de pouvoir affecter une demande.</div>
        <?php else: ?>
        <form method="POST" action="demandes.php<?php echo h($statutFilter !== 'nouvelle' ? '?statut=' . urlencode($statutFilter) : ''); ?>">
            <input type="hidden" name="form" value="assign">
            <input type="hidden" name="intervention_id" id="assignInterventionId">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="gv2-form-group">
                <label for="assignTechnicien">Technicien</label>
                <select name="technicien_id" id="assignTechnicien" required>
                    <option value="">Sélectionner un technicien</option>
                    <?php foreach ($techniciens as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['prenom'] . ' ' . $t['nom'] . ($t['specialite'] ? ' — ' . $t['specialite'] : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gv2-form-row">
                <div class="gv2-form-group">
                    <label for="assignDate">Date prévue</label>
                    <input type="date" name="date_prevue" id="assignDate" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="gv2-form-group">
                    <label for="assignPriorite">Priorité</label>
                    <select name="priorite" id="assignPriorite">
                        <option value="BASSE">Basse</option>
                        <option value="MOYENNE" selected>Normale</option>
                        <option value="HAUTE">Haute</option>
                    </select>
                </div>
            </div>
            <div class="gv2-modal-actions">
                <button type="button" class="gv2-btn-outline" id="closeAssignModal">Annuler</button>
                <button type="submit" class="gv2-btn-primary">Enregistrer l'affectation</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Modal : refuser -->
<div class="gv2-modal-overlay" id="refuseModalOverlay">
    <div class="gv2-modal">
        <h3>Refuser cette demande ?</h3>
        <p class="gv2-modal-sub">Le client sera notifié et un administrateur pourra réaffecter la demande à un autre garage.</p>
        <form method="POST" action="demandes.php<?php echo h($statutFilter !== 'nouvelle' ? '?statut=' . urlencode($statutFilter) : ''); ?>">
            <input type="hidden" name="form" value="refuse">
            <input type="hidden" name="intervention_id" id="refuseInterventionId">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="gv2-modal-actions">
                <button type="button" class="gv2-btn-outline" id="closeRefuseModal">Annuler</button>
                <button type="submit" class="gv2-btn-danger">Confirmer le refus</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var assignOverlay = document.getElementById('assignModalOverlay');
    var refuseOverlay = document.getElementById('refuseModalOverlay');

    window.openAssign = function (id, label) {
        document.getElementById('assignInterventionId').value = id;
        document.getElementById('assignModalSub').textContent = label;
        assignOverlay.classList.add('show');
    };
    window.openRefuse = function (id) {
        document.getElementById('refuseInterventionId').value = id;
        refuseOverlay.classList.add('show');
    };
    document.getElementById('closeAssignModal').addEventListener('click', function () { assignOverlay.classList.remove('show'); });
    document.getElementById('closeRefuseModal').addEventListener('click', function () { refuseOverlay.classList.remove('show'); });
    assignOverlay.addEventListener('click', function (e) { if (e.target === assignOverlay) assignOverlay.classList.remove('show'); });
    refuseOverlay.addEventListener('click', function (e) { if (e.target === refuseOverlay) refuseOverlay.classList.remove('show'); });

    <?php if (!empty($formErrors)): ?>
    assignOverlay.classList.add('show');
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
