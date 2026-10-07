<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/repair_report.php';

/**
 * Espace technicien — Mes interventions (liste complète, tous statuts).
 *
 * Accès : rôle « technicien ».
 * Actions :
 *   - POST form=start : démarrer une de mes interventions PLANIFIEE (passage
 *     à EN_COURS) et la journaliser.
 *   - « Marquer terminée » (intervention EN_COURS) : fenêtre sur la page
 *     même (repairReportFinishModal(), includes/repair_report.php) avec le
 *     formulaire complet du rapport de fin d'intervention, envoyé en POST au
 *     traitement de reparations.php (return=interventions) ; retour ici avec
 *     ?success=repaired.
 *   - GET statut=a_demarrer|en_cours|terminee|annulee, date, vehicule : filtres.
 * Tables : intervention (écriture), vehicule, utilisateur (lecture),
 *          journalactivites (via technicien_log()).
 * Liés : technicien/taches.php (même action « démarrer », vue réduite).
 * Ouverture de la page : activity_log_mark_seen(..., 'interventions') remet à zéro
 * la pastille rouge de nouveautés de cet onglet dans la sidebar (table
 * onglet_vu, includes/activity_log.php).
 */

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();

// Onglet « Mes interventions » ouvert : sa pastille rouge de nouveautés
// disparaît, avant le rendu de la sidebar (sans effet tant que la
// migration onglet_vu n'est pas appliquée).
activity_log_mark_seen($conn, (int)$_SESSION['user_id'], 'interventions');

$selfId = (int)$_SESSION['user_id'];

// ============================================================
// Démarrer une intervention (PLANIFIEE → EN_COURS), disponible aussi depuis
// cette vue complète (même logique que "Mes tâches").
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'start') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        // Seule une de MES interventions encore PLANIFIEE peut démarrer.
        $stmt = $conn->prepare("SELECT idIntervention FROM intervention WHERE idIntervention = ? AND idTechnicien = ? AND statut = 'PLANIFIEE'");
        $stmt->execute([$interventionId, $selfId]);
        if ($stmt->fetch()) {
            $conn->prepare("UPDATE intervention SET statut = 'EN_COURS' WHERE idIntervention = ? AND idTechnicien = ?")->execute([$interventionId, $selfId]);
            technicien_log($conn, 'Intervention démarrée', ['idIntervention' => (int)$interventionId, 'categorie' => 'intervention']);
            header('Location: interventions.php?success=started');
            exit;
        }
    }
    header('Location: interventions.php');
    exit;
}

// ============================================================
// Filtres : statut, date, véhicule — toujours bornés à idTechnicien = moi
// ============================================================
$statutFilter = $_GET['statut'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$vehiculeFilter = filter_var($_GET['vehicule'] ?? null, FILTER_VALIDATE_INT);

$where = ['i.idTechnicien = ?'];
$params = [$selfId];
if ($statutFilter === 'a_demarrer') { $where[] = "i.statut = 'PLANIFIEE'"; }
elseif ($statutFilter === 'en_cours') { $where[] = "i.statut = 'EN_COURS'"; }
elseif ($statutFilter === 'terminee') { $where[] = "i.statut = 'TERMINEE'"; }
elseif ($statutFilter === 'annulee') { $where[] = "i.statut = 'ANNULEE'"; }
if ($dateFilter) { $where[] = 'DATE(i.dateIntervention) = ?'; $params[] = $dateFilter; }
if ($vehiculeFilter) { $where[] = 'i.idVehicule = ?'; $params[] = $vehiculeFilter; }
$whereSql = implode(' AND ', $where);

$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut, i.priorite,
           v.idVehicule AS vehicule_id, v.marque, v.modele, v.immatriculation, v.kilometrage,
           uc.nom AS client_nom, uc.prenom AS client_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur uc ON i.idClient = uc.idUtilisateur
    WHERE $whereSql
    ORDER BY i.dateIntervention DESC
");
$stmt->execute($params);
$interventions = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT DISTINCT v.idVehicule AS id, v.marque, v.modele, v.immatriculation FROM vehicule v JOIN intervention i ON i.idVehicule = v.idVehicule WHERE i.idTechnicien = ? ORDER BY v.marque");
$stmt->execute([$selfId]);
$vehiculesAssignes = $stmt->fetchAll();

// Compteur du badge « Mes tâches » de la sidebar (tâches à démarrer).
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

// Étape de la frise de progression (1 à 3) affichée pour chaque intervention.
$steps = ['PLANIFIEE' => 1, 'EN_COURS' => 2, 'TERMINEE' => 3];

$pageTitle = 'Mes interventions';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'interventions'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">

        <?php if (isset($_GET['success']) && $_GET['success'] === 'started'): ?>
            <div class="tv2-alert success">Intervention démarrée.</div>
        <?php elseif (($_GET['success'] ?? '') === 'repaired'): ?>
            <div class="tv2-alert success">Intervention terminée : le rapport de fin d'intervention est enregistré et ajouté au journal d'activité.</div>
        <?php endif; ?>

        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Mes interventions</h1>
                <p class="tv2-sub">Le suivi complet de vos interventions, de la planification à la clôture.</p>
            </div>
        </div>

        <form method="GET" class="tv2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="a_demarrer" <?php echo $statutFilter === 'a_demarrer' ? 'selected' : ''; ?>>À démarrer</option>
                <option value="en_cours" <?php echo $statutFilter === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                <option value="terminee" <?php echo $statutFilter === 'terminee' ? 'selected' : ''; ?>>Terminée</option>
                <option value="annulee" <?php echo $statutFilter === 'annulee' ? 'selected' : ''; ?>>Annulée</option>
            </select>
            <input type="date" name="date" value="<?php echo h($dateFilter); ?>" onchange="this.form.submit()">
            <?php if (count($vehiculesAssignes) > 1): ?>
                <select name="vehicule" onchange="this.form.submit()">
                    <option value="">Tous les véhicules</option>
                    <?php foreach ($vehiculesAssignes as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" <?php echo $vehiculeFilter === (int)$v['id'] ? 'selected' : ''; ?>><?php echo h($v['marque'] . ' ' . $v['modele'] . ' — ' . $v['immatriculation']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($statutFilter || $dateFilter || $vehiculeFilter): ?>
                <a href="interventions.php" class="tv2-btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">Effacer</a>
            <?php endif; ?>
        </form>

        <?php if (empty($interventions)): ?>
            <div class="tv2-card" style="padding:8px;"><div class="tv2-empty">Aucune intervention pour ce filtre.</div></div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php foreach ($interventions as $iv): $info = tv2_status_info($iv); $step = $steps[$iv['statut']] ?? 0; ?>
                    <div class="tv2-card tv2-panel">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                            <div>
                                <div class="tv2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?> (<?php echo h($iv['immatriculation']); ?>)</div>
                                <div class="tv2-row-meta">Client : <?php echo h($iv['client_prenom'] . ' ' . $iv['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?></div>
                            </div>
                            <span class="tv2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span>
                        </div>

                        <?php if ($iv['statut'] !== 'ANNULEE'): ?>
                        <div style="display:flex; align-items:center; gap:6px; margin:14px 0;">
                            <?php foreach (['Planifiée' => 1, 'En cours' => 2, 'Terminée' => 3] as $label => $n): ?>
                                <div style="display:flex; align-items:center; gap:6px; <?php echo $n < 3 ? 'flex-grow:1;' : ''; ?>">
                                    <div style="width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; flex-shrink:0; <?php echo $step >= $n ? 'background:#3956E8; color:#fff;' : 'background:#EFF0F6; color:#8B90B3;'; ?>"><?php echo (int)$n; ?></div>
                                    <span style="font-size:11.5px; color:<?php echo $step >= $n ? '#171B33' : '#8B90B3'; ?>; font-weight:<?php echo $step >= $n ? '700' : '500'; ?>;"><?php echo h($label); ?></span>
                                    <?php if ($n < 3): ?><div style="flex-grow:1; height:2px; background:<?php echo $step > $n ? '#3956E8' : '#EFF0F6'; ?>;"></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($iv['description']): ?>
                            <p style="margin:0 0 12px; font-size:13px; color:#666C8E; font-style:italic;"><?php echo h($iv['description']); ?></p>
                        <?php endif; ?>

                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <?php if ($info['key'] === 'planifiee'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="form" value="start">
                                    <input type="hidden" name="intervention_id" value="<?php echo (int)$iv['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <button type="submit" class="tv2-btn-primary tv2-btn-xs">Démarrer</button>
                                </form>
                            <?php elseif ($info['key'] === 'en_cours'): ?>
                                <?php repairReportFinishLink($iv, 'interventions', 'tv2-btn-primary tv2-btn-xs'); ?>
                                <a href="anomalies.php?action=new&intervention_id=<?php echo (int)$iv['id']; ?>" class="tv2-btn-outline tv2-btn-xs" style="text-decoration:none;">Constater une anomalie</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php repairReportFinishModal('interventions'); ?>

<?php include '../includes/footer.php'; ?>
