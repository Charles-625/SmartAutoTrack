<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Liste de toutes les interventions du garage.
 *
 * Accès : rôle « garage ».
 * Actions :
 *   - POST form=start : démarrer une intervention PLANIFIEE déjà affectée
 *     (passage à EN_COURS) et la journaliser.
 *   - GET statut, date, technicien, vehicule : filtres de la liste.
 * Tables : intervention (écriture), vehicule, utilisateur, technicien (lecture),
 *          journalactivites (via garage_log()).
 * Liés : garage/vehicule.php (fiche véhicule), garage/reparations.php (clôture).
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);

// ============================================================
// Démarrer une intervention (PLANIFIEE + technicien affecté → EN_COURS)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'start') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $interventionId = filter_var($_POST['intervention_id'] ?? null, FILTER_VALIDATE_INT);
        // Seule une intervention PLANIFIEE de CE garage, déjà affectée à un technicien, peut démarrer.
        $stmt = $conn->prepare("SELECT idTechnicien FROM intervention WHERE idIntervention = ? AND idGarage = ? AND statut = 'PLANIFIEE' AND idTechnicien IS NOT NULL");
        $stmt->execute([$interventionId, $garageId]);
        $iv = $stmt->fetch();
        if ($iv) {
            $conn->prepare("UPDATE intervention SET statut = 'EN_COURS' WHERE idIntervention = ? AND idGarage = ?")->execute([$interventionId, $garageId]);
            garage_log($conn, $interventionId, 'Intervention démarrée', null, (int)$iv['idTechnicien']);
            header('Location: interventions.php?success=started');
            exit;
        }
    }
    header('Location: interventions.php');
    exit;
}

// ============================================================
// Filtres : statut, date, technicien, véhicule
// ============================================================
$statutFilter = $_GET['statut'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$technicienFilter = filter_var($_GET['technicien'] ?? null, FILTER_VALIDATE_INT);
$vehiculeFilter = filter_var($_GET['vehicule'] ?? null, FILTER_VALIDATE_INT);

$where = ['i.idGarage = ?'];
$params = [$garageId];
if ($statutFilter === 'nouvelle') { $where[] = "i.idTechnicien IS NULL AND i.statut = 'PLANIFIEE'"; }
elseif ($statutFilter === 'planifiee') { $where[] = "i.idTechnicien IS NOT NULL AND i.statut = 'PLANIFIEE'"; }
elseif ($statutFilter === 'en_cours') { $where[] = "i.statut = 'EN_COURS'"; }
elseif ($statutFilter === 'terminee') { $where[] = "i.statut = 'TERMINEE'"; }
elseif ($statutFilter === 'annulee') { $where[] = "i.statut = 'ANNULEE'"; }
if ($dateFilter) { $where[] = 'DATE(i.dateIntervention) = ?'; $params[] = $dateFilter; }
if ($technicienFilter) { $where[] = 'i.idTechnicien = ?'; $params[] = $technicienFilter; }
if ($vehiculeFilter) { $where[] = 'i.idVehicule = ?'; $params[] = $vehiculeFilter; }
$whereSql = implode(' AND ', $where);

$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut, i.priorite, i.idTechnicien,
           v.idVehicule AS vehicule_id, v.marque, v.modele, v.immatriculation,
           uc.nom AS client_nom, uc.prenom AS client_prenom,
           ut.nom AS technicien_nom, ut.prenom AS technicien_prenom
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    JOIN utilisateur uc ON i.idClient = uc.idUtilisateur
    LEFT JOIN utilisateur ut ON i.idTechnicien = ut.idUtilisateur
    WHERE $whereSql
    ORDER BY i.dateIntervention DESC
");
$stmt->execute($params);
$interventions = $stmt->fetchAll();

// Techniciens et véhicules du garage, pour les filtres
$stmt = $conn->prepare("SELECT u.idUtilisateur AS id, u.nom, u.prenom FROM technicien t JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien WHERE t.idGarage = ? ORDER BY u.nom");
$stmt->execute([$garageId]);
$techniciens = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT DISTINCT v.idVehicule AS id, v.marque, v.modele, v.immatriculation FROM vehicule v JOIN intervention i ON i.idVehicule = v.idVehicule WHERE i.idGarage = ? ORDER BY v.marque");
$stmt->execute([$garageId]);
$vehiculesGarage = $stmt->fetchAll();

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

// Étape de la frise de progression (1 à 3) affichée pour chaque intervention.
$steps = ['PLANIFIEE' => 1, 'EN_COURS' => 2, 'TERMINEE' => 3];

$pageTitle = 'Interventions';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'interventions'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">

        <?php if (isset($_GET['success']) && $_GET['success'] === 'started'): ?>
            <div class="gv2-alert success">Intervention démarrée.</div>
        <?php endif; ?>

        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Interventions</h1>
                <p class="gv2-sub">Suivi complet des interventions de votre garage, de la planification à la clôture.</p>
            </div>
        </div>

        <form method="GET" class="gv2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="nouvelle" <?php echo $statutFilter === 'nouvelle' ? 'selected' : ''; ?>>Nouvelle demande</option>
                <option value="planifiee" <?php echo $statutFilter === 'planifiee' ? 'selected' : ''; ?>>Planifiée</option>
                <option value="en_cours" <?php echo $statutFilter === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                <option value="terminee" <?php echo $statutFilter === 'terminee' ? 'selected' : ''; ?>>Terminée</option>
                <option value="annulee" <?php echo $statutFilter === 'annulee' ? 'selected' : ''; ?>>Refusée / Annulée</option>
            </select>
            <input type="date" name="date" value="<?php echo h($dateFilter); ?>" onchange="this.form.submit()">
            <?php if (count($techniciens) > 1): ?>
                <select name="technicien" onchange="this.form.submit()">
                    <option value="">Tous les techniciens</option>
                    <?php foreach ($techniciens as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $technicienFilter === (int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['prenom'] . ' ' . $t['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if (count($vehiculesGarage) > 1): ?>
                <select name="vehicule" onchange="this.form.submit()">
                    <option value="">Tous les véhicules</option>
                    <?php foreach ($vehiculesGarage as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" <?php echo $vehiculeFilter === (int)$v['id'] ? 'selected' : ''; ?>><?php echo h($v['marque'] . ' ' . $v['modele'] . ' — ' . $v['immatriculation']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($statutFilter || $dateFilter || $technicienFilter || $vehiculeFilter): ?>
                <a href="interventions.php" class="gv2-btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">Effacer</a>
            <?php endif; ?>
        </form>

        <?php if (empty($interventions)): ?>
            <div class="gv2-card" style="padding:8px;"><div class="gv2-empty">Aucune intervention pour ce filtre.</div></div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php foreach ($interventions as $iv): $info = garage_status_info($iv); $step = $steps[$iv['statut']] ?? 0; ?>
                    <div class="gv2-card gv2-panel" id="iv-<?php echo (int)$iv['id']; ?>">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                            <div>
                                <div class="gv2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?> (<?php echo h($iv['immatriculation']); ?>)</div>
                                <div class="gv2-row-meta">Client : <?php echo h($iv['client_prenom'] . ' ' . $iv['client_nom']); ?> · <?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?><?php echo h($iv['technicien_nom'] ? ' · Technicien : ' . $iv['technicien_prenom'] . ' ' . $iv['technicien_nom'] : ''); ?></div>
                            </div>
                            <span class="gv2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span>
                        </div>

                        <?php if ($iv['statut'] !== 'ANNULEE'): ?>
                        <div style="display:flex; align-items:center; gap:6px; margin:14px 0;">
                            <?php foreach (['Planifiée' => 1, 'En cours' => 2, 'Terminée' => 3] as $label => $n): ?>
                                <div style="display:flex; align-items:center; gap:6px; <?php echo $n < 3 ? 'flex-grow:1;' : ''; ?>">
                                    <div style="width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; flex-shrink:0; <?php echo $step >= $n ? 'background:#0D9488; color:#fff;' : 'background:#EEF2F2; color:#8AA0A3;'; ?>"><?php echo (int)$n; ?></div>
                                    <span style="font-size:11.5px; color:<?php echo $step >= $n ? '#12262A' : '#8AA0A3'; ?>; font-weight:<?php echo $step >= $n ? '700' : '500'; ?>;"><?php echo h($label); ?></span>
                                    <?php if ($n < 3): ?><div style="flex-grow:1; height:2px; background:<?php echo $step > $n ? '#0D9488' : '#EEF2F2'; ?>;"></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($iv['description']): ?>
                            <p style="margin:0 0 12px; font-size:13px; color:#5C7276; font-style:italic;"><?php echo h($iv['description']); ?></p>
                        <?php endif; ?>

                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <a href="<?php echo SITE_URL; ?>garage/vehicule.php?id=<?php echo (int)$iv['vehicule_id']; ?>" class="gv2-btn-outline gv2-btn-xs" style="text-decoration:none;">Voir le véhicule</a>
                            <?php if ($info['key'] === 'planifiee'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="form" value="start">
                                    <input type="hidden" name="intervention_id" value="<?php echo (int)$iv['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <button type="submit" class="gv2-btn-primary gv2-btn-xs">Démarrer</button>
                                </form>
                            <?php elseif ($info['key'] === 'en_cours'): ?>
                                <a href="reparations.php?action=new&intervention_id=<?php echo (int)$iv['id']; ?>" class="gv2-btn-primary gv2-btn-xs" style="text-decoration:none;">Terminer &amp; renseigner la réparation</a>
                                <a href="anomalies.php?action=new&intervention_id=<?php echo (int)$iv['id']; ?>" class="gv2-btn-outline gv2-btn-xs" style="text-decoration:none;">Constater une anomalie</a>
                            <?php elseif ($info['key'] === 'nouvelle'): ?>
                                <a href="demandes.php" class="gv2-btn-primary gv2-btn-xs" style="text-decoration:none;">Traiter dans Demandes</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
