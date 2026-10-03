<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/repair_report.php';

/**
 * Espace technicien — Mes tâches : interventions à démarrer ou en cours.
 *
 * Accès : rôle « technicien ».
 * Actions :
 *   - POST form=start : démarrer une tâche PLANIFIEE (passage à EN_COURS).
 *   - « Marquer terminée » (tâche EN_COURS) : fenêtre sur la page même
 *     (repairReportFinishModal(), includes/repair_report.php) avec le
 *     formulaire complet du rapport de fin d'intervention, envoyé en POST au
 *     traitement de reparations.php (return=taches) ; retour ici avec
 *     ?success=repaired.
 *   - GET statut=toutes|a_demarrer|en_cours : filtre.
 * Tables : intervention (écriture), vehicule, utilisateur (lecture),
 *          journalactivites (via technicien_log()).
 */

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

// ============================================================
// Démarrer une tâche (PLANIFIEE → EN_COURS). Toujours bornée à
// idTechnicien = moi-même : jamais la tâche d'un autre technicien, même par
// manipulation du formulaire.
// ============================================================
$formErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'start') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("SELECT idIntervention FROM intervention WHERE idIntervention = ? AND idTechnicien = ? AND statut = 'PLANIFIEE'");
        $stmt->execute([$interventionId, $selfId]);
        if ($stmt->fetch()) {
            $conn->prepare("UPDATE intervention SET statut = 'EN_COURS' WHERE idIntervention = ? AND idTechnicien = ?")->execute([$interventionId, $selfId]);
            technicien_log($conn, 'Intervention démarrée', ['idIntervention' => (int)$interventionId, 'categorie' => 'intervention']);
            header('Location: taches.php?success=started');
            exit;
        }
        $formErrors[] = 'Cette tâche ne peut plus être démarrée.';
    }
}

// ============================================================
// Mes tâches à traiter : à démarrer ou en cours uniquement — la liste
// complète (avec les terminées/annulées) est dans "Mes interventions".
// ============================================================
$statutFilter = $_GET['statut'] ?? 'toutes';
$where = ["idTechnicien = ?", "statut IN ('PLANIFIEE', 'EN_COURS')"];
$params = [$selfId];
if ($statutFilter === 'a_demarrer') { $where[] = "statut = 'PLANIFIEE'"; }
elseif ($statutFilter === 'en_cours') { $where[] = "statut = 'EN_COURS'"; }

$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut, i.priorite,
           v.marque, v.modele, v.immatriculation, v.kilometrage,
           u.nom AS client_nom, u.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur u ON i.idClient = u.idUtilisateur
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(i.statut, 'EN_COURS', 'PLANIFIEE'), i.dateIntervention ASC
");
$stmt->execute($params);
$taches = $stmt->fetchAll();

// Compteur du badge « Mes tâches » de la sidebar (tâches à démarrer).
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

$pageTitle = 'Mes tâches';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'taches'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">

        <?php if (($_GET['success'] ?? '') === 'repaired'): ?>
            <div class="tv2-alert success">Intervention terminée : le rapport de fin d'intervention est enregistré et ajouté au journal d'activité.</div>
        <?php elseif (isset($_GET['success'])): ?>
            <div class="tv2-alert success">Intervention démarrée.</div>
        <?php endif; ?>
        <?php foreach ($formErrors as $err): ?>
            <div class="tv2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Mes tâches</h1>
                <p class="tv2-sub">Les interventions qui vous sont affectées et qui restent à traiter.</p>
            </div>
        </div>

        <form method="GET" class="tv2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="toutes" <?php echo $statutFilter === 'toutes' ? 'selected' : ''; ?>>Toutes</option>
                <option value="a_demarrer" <?php echo $statutFilter === 'a_demarrer' ? 'selected' : ''; ?>>À démarrer</option>
                <option value="en_cours" <?php echo $statutFilter === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
            </select>
        </form>

        <div class="tv2-card" style="padding:8px;">
            <?php if (empty($taches)): ?>
                <div class="tv2-empty">Aucune tâche pour ce filtre.</div>
            <?php else: ?>
                <div class="tv2-table-wrap">
                    <table class="tv2-table">
                        <thead>
                            <tr><th>Client</th><th>Véhicule</th><th>Type</th><th>Date</th><th>Priorité</th><th>Statut</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taches as $t): $info = tv2_status_info($t); ?>
                                <tr>
                                    <td><?php echo h($t['client_prenom'] . ' ' . $t['client_nom']); ?></td>
                                    <td>
                                        <div class="tv2-table-entity">
                                            <div class="tv2-table-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#1E7DBF" stroke-width="1.6"/><circle cx="7.5" cy="18.5" r="1.5" stroke="#1E7DBF" stroke-width="1.6"/><circle cx="16.5" cy="18.5" r="1.5" stroke="#1E7DBF" stroke-width="1.6"/><path d="M5 10L7 5.5H17L19 10" stroke="#1E7DBF" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php echo h($t['marque'] . ' ' . $t['modele']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#A5977F; margin-top:2px;"><?php echo h($t['immatriculation']); ?></div>
                                    </td>
                                    <td><?php echo h($t['type'] ?: '—'); ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($t['dateIntervention']))); ?></td>
                                    <td><span class="tv2-badge <?php echo h($t['priorite'] === 'HAUTE' ? 'bad' : ($t['priorite'] === 'BASSE' ? 'neutral' : 'warn')); ?>"><?php echo h(ucfirst(strtolower($t['priorite']))); ?></span></td>
                                    <td><span class="tv2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span></td>
                                    <td>
                                        <?php if ($info['key'] === 'planifiee'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="form" value="start">
                                                <input type="hidden" name="intervention_id" value="<?php echo (int)$t['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <button type="submit" class="tv2-btn-primary tv2-btn-xs">Démarrer</button>
                                            </form>
                                        <?php else: ?>
                                            <?php repairReportFinishLink($t, 'taches', 'tv2-btn-primary tv2-btn-xs'); ?>
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

<?php repairReportFinishModal('taches'); ?>

<?php include '../includes/footer.php'; ?>
