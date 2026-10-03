<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Fiche détaillée d'une intervention (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * GET `id` : identifiant de l'intervention ; si absent ou inconnu,
 * redirection vers interventions.php.
 * Actions POST (jeton CSRF requis), passées par `action` :
 *   - reassign_garage : réaffecte la demande à un autre garage validé via
 *     admin_reassign_garage() ;
 *   - assign_internal : affecte une demande « à affecter » (sans technicien,
 *     sans garage ou refusée) à un technicien SmartAutoTrack INTERNE validé,
 *     garage partenaire en appui facultatif, via admin_assign_internal().
 * Affiche le technicien (SmartAutoTrack ou de garage), le garage (partenaire
 * en appui quand le technicien est interne) et les anomalies ouvertes liées
 * à l'intervention avec leur gravité (CRITIQUE en rouge).
 *
 * Tables lues : intervention, vehicule, utilisateur (client, technicien),
 * technicien, garage, anomalie, journal d'activité (historique complet de
 * l'intervention).
 * Liens : admin/includes/helpers.php, interventions.php.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

$interventionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$interventionId) { header('Location: interventions.php'); exit; }

$errors = [];

// Réaffectation du garage — même règle métier que la liste (admin_reassign_garage,
// admin/includes/helpers.php), mais avec son propre traitement ici pour
// pouvoir réafficher une erreur sur CETTE page (jamais de redirection qui
// perdrait le message).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign_garage') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $newGarageId = filter_var($_POST['garage_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$newGarageId) {
            $errors[] = 'Merci de choisir un garage.';
        } else {
            $reassignError = admin_reassign_garage($conn, $interventionId, $newGarageId);
            if ($reassignError !== null) {
                $errors[] = $reassignError;
            } else {
                header("Location: intervention_detail.php?id=$interventionId&success=garage_assigned");
                exit;
            }
        }
    }
}

// Affectation à un technicien SmartAutoTrack — même règle que la liste
// (admin_assign_internal, admin/includes/helpers.php), erreur réaffichée ici.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_internal') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $technicienId = filter_var($_POST['technicien_id'] ?? null, FILTER_VALIDATE_INT);
        // Garage en appui facultatif : valeur vide = aucun garage.
        $supportGarageRaw = (string)($_POST['support_garage_id'] ?? '');
        $supportGarageId = $supportGarageRaw === '' ? null : filter_var($supportGarageRaw, FILTER_VALIDATE_INT);
        if (!$technicienId) {
            $errors[] = 'Merci de choisir un technicien SmartAutoTrack.';
        } elseif ($supportGarageId === false) {
            $errors[] = 'Garage invalide.';
        } else {
            $assignError = admin_assign_internal($conn, $interventionId, $technicienId, $supportGarageId);
            if ($assignError !== null) {
                $errors[] = $assignError;
            } else {
                header("Location: intervention_detail.php?id=$interventionId&success=internal_assigned");
                exit;
            }
        }
    }
}

$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut, i.priorite,
           i.idGarage, i.idTechnicien,
           v.idVehicule, v.marque, v.modele, v.immatriculation,
           uc.idUtilisateur AS client_id, uc.nom AS client_nom, uc.prenom AS client_prenom, uc.email AS client_email,
           g.nomGarage, g.statutGarage,
           ut.nom AS technicien_nom, ut.prenom AS technicien_prenom, t.typeTechnicien
    FROM intervention i
    JOIN vehicule v ON v.idVehicule = i.idVehicule
    JOIN utilisateur uc ON uc.idUtilisateur = i.idClient
    LEFT JOIN garage g ON g.idGarage = i.idGarage
    LEFT JOIN utilisateur ut ON ut.idUtilisateur = i.idTechnicien
    LEFT JOIN technicien t ON t.idTechnicien = i.idTechnicien
    WHERE i.idIntervention = ?
");
$stmt->execute([$interventionId]);
$iv = $stmt->fetch();
if (!$iv) { header('Location: interventions.php'); exit; }
$isInternal = $iv['typeTechnicien'] === 'INTERNE';

// Anomalies ouvertes (NOUVELLE ou EN_COURS) liées à l'intervention, la plus
// grave d'abord.
$stmt = $conn->prepare("
    SELECT type, niveau, description FROM anomalie
    WHERE idIntervention = ? AND statut IN ('NOUVELLE', 'EN_COURS')
    ORDER BY FIELD(niveau, 'CRITIQUE', 'MOYEN', 'FAIBLE'), idAnomalie ASC
");
$stmt->execute([$interventionId]);
$openAnomalies = $stmt->fetchAll();

// Historique complet de cette intervention (toutes catégories) — c'est ici
// qu'on retrouve, sans nouvelle colonne ni nouvelle table, le garage choisi
// par le client, la recommandation SmartAutoTrack le cas échéant, et chaque
// réaffectation admin : le journal d'activité suffit (cf. section 10 du cadrage).
$journal = activity_log_fetch($conn, 'admin', (int)$_SESSION['user_id'], [], ['idIntervention' => $interventionId, 'limit' => 50]);

// Retrouve dans le journal le choix initial du client et l'éventuelle
// recommandation automatique (on garde la première occurrence de chacune).
$clientChoiceEntry = null;
$recommendationEntry = null;
foreach ($journal as $j) {
    if ($clientChoiceEntry === null && $j['nomActivite'] === 'Client a sélectionné un garage pour sa demande') {
        $clientChoiceEntry = $j;
    }
    if ($recommendationEntry === null && $j['nomActivite'] === 'SmartAutoTrack a recommandé un garage pour cette demande') {
        $recommendationEntry = $j;
    }
}

// Réaffectation possible seulement tant que l'intervention n'a pas démarré
// (PLANIFIEE) ou après un refus du garage (ANNULEE). Le garage actuel est
// exclu de la liste proposée.
$canReassign = in_array($iv['statut'], ['PLANIFIEE', 'ANNULEE'], true);
$otherGarages = $conn->prepare("SELECT idGarage, nomGarage FROM garage WHERE statutGarage = 'VALIDE' AND idGarage != ? ORDER BY nomGarage");
$otherGarages->execute([(int)($iv['idGarage'] ?? 0)]);
$otherGarages = $otherGarages->fetchAll();

// Demande « à affecter » (même définition que le compteur non_affectees de
// interventions.php) : on propose aussi un technicien SmartAutoTrack, avec
// tous les garages validés comme appui possible.
$canAssignInternal = $iv['idTechnicien'] === null && $canReassign
    && ($iv['idGarage'] === null || $iv['statut'] === 'ANNULEE');
$techniciensInternes = [];
$supportGarages = [];
if ($canAssignInternal) {
    $techniciensInternes = $conn->query("
        SELECT u.idUtilisateur AS id, u.nom, u.prenom, t.specialite FROM utilisateur u
        JOIN technicien t ON t.idTechnicien = u.idUtilisateur
        WHERE t.typeTechnicien = 'INTERNE' AND t.statutValidation = 'VALIDE' ORDER BY u.nom
    ")->fetchAll();
    $supportGarages = $conn->query("SELECT idGarage, nomGarage FROM garage WHERE statutGarage = 'VALIDE' ORDER BY nomGarage")->fetchAll();
}

$statutLabels = ['PLANIFIEE' => 'Planifiée', 'EN_COURS' => 'En cours', 'TERMINEE' => 'Terminée', 'ANNULEE' => 'Annulée'];
$statutBadge = ['PLANIFIEE' => 'info', 'EN_COURS' => 'warn', 'TERMINEE' => 'ok', 'ANNULEE' => 'bad'];

$pageTitle = 'Intervention #' . $iv['id'];
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'interventions'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <?php if (isset($_GET['success'])): ?>
            <div class="av2-alert success"><?php echo h($_GET['success'] === 'internal_assigned' ? 'Technicien SmartAutoTrack affecté avec succès.' : 'Garage affecté avec succès.'); ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="av2-alert error"><?php echo h($err); ?></div><?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <div class="av2-kicker">Intervention #<?php echo (int)$iv['id']; ?></div>
                <h1 class="av2-h1"><?php echo h($iv['type'] ?: 'Intervention'); ?></h1>
                <p class="av2-sub"><?php echo h($iv['marque'] . ' ' . $iv['modele'] . ' · ' . $iv['immatriculation']); ?></p>
            </div>
            <a href="interventions.php" class="av2-btn-outline" style="text-decoration:none;">← Retour aux interventions</a>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#6D74A0" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value" style="font-size:17px;"><?php echo h($statutLabels[$iv['statut']] ?? $iv['statut']); ?></div><div class="av2-stat-label">Statut</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#C8871A" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value" style="font-size:17px;"><?php echo h(ucfirst(strtolower($iv['priorite']))); ?></div><div class="av2-stat-label">Priorité</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#1E7DBF" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value" style="font-size:15px;"><?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?></div><div class="av2-stat-label">Date</div></div>
            </div>
        </div>

        <div class="av2-body">
            <div class="av2-col">
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head"><h2>Client &amp; véhicule</h2></div>
                    <div style="display:flex; flex-direction:column; gap:8px; font-size:13.5px;">
                        <div><strong>Client</strong><br><?php echo h($iv['client_prenom'] . ' ' . $iv['client_nom']); ?> — <?php echo h($iv['client_email']); ?></div>
                        <div><strong>Véhicule</strong><br><?php echo h($iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ')'); ?></div>
                        <?php if ($iv['description']): ?><div><strong>Description du client</strong><br><?php echo h($iv['description']); ?></div><?php endif; ?>
                        <?php if (!empty($openAnomalies)): ?>
                            <div>
                                <strong>Anomalie(s) ouverte(s)</strong>
                                <?php foreach ($openAnomalies as $a): ?>
                                    <div style="margin-top:4px;">
                                        <?php echo h($a['type'] ?: 'Type non précisé'); ?>
                                        <span class="av2-badge <?php echo h(admin_anomaly_badge($a['niveau'])); ?>"><?php echo h('Gravité : ' . ucfirst(strtolower($a['niveau']))); ?></span>
                                        <?php if ($a['description']): ?><div class="av2-row-meta"><?php echo h($a['description']); ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="av2-card av2-panel">
                    <div class="av2-panel-head"><h2>Historique de l'affectation</h2></div>
                    <?php if (empty($journal)): ?>
                        <div class="av2-empty">Aucun événement enregistré.</div>
                    <?php else: ?>
                        <div class="av2-timeline">
                            <?php foreach ($journal as $j): ?>
                                <div class="av2-timeline-item">
                                    <div class="av2-timeline-dot"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" fill="#3956E8"/></svg></div>
                                    <div>
                                        <div class="av2-timeline-title"><?php echo h($j['nomActivite']); ?></div>
                                        <?php if ($j['description']): ?><div class="av2-row-meta"><?php echo h($j['description']); ?></div><?php endif; ?>
                                        <div class="av2-timeline-time"><?php echo h(date('d/m/Y H:i', strtotime($j['dateHeure']))); ?> · <?php echo h(activity_log_role_label($j['acteur_role'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="av2-col">
                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Affectation</h2></div>
                    <div style="display:flex; flex-direction:column; gap:10px; font-size:13.5px; margin-bottom:16px;">
                        <div>
                            <strong><?php echo h($isInternal ? 'Garage' : 'Garage actuellement affecté'); ?></strong><br>
                            <?php if ($iv['nomGarage']): ?>
                                <?php echo h($iv['nomGarage']); ?> <span class="av2-badge <?php echo h(av2_status_badge($iv['statutGarage'])); ?>"><?php echo h(av2_status_label($iv['statutGarage'])); ?></span>
                            <?php elseif ($iv['idTechnicien'] === null): ?>
                                <span class="av2-badge warn">Aucun — demande à SmartAutoTrack, à affecter</span>
                            <?php else: ?>
                                <span class="av2-badge info">Aucun garage — <?php echo h($iv['technicien_prenom'] . ' ' . $iv['technicien_nom']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong>Choisi par le client</strong><br>
                            <?php if ($clientChoiceEntry): ?>
                                <?php echo h($clientChoiceEntry['description']); ?>
                            <?php else: ?>
                                <span style="color:#8B90B3;">Non renseigné (demande créée avant cette fonctionnalité, ou affectée directement par un administrateur).</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong>Recommandé par SmartAutoTrack</strong><br>
                            <?php if ($recommendationEntry): ?>
                                <?php echo h($recommendationEntry['description']); ?>
                            <?php else: ?>
                                <span style="color:#8B90B3;">Aucune recommandation enregistrée pour cette demande.</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($iv['technicien_nom']): ?>
                            <div>
                                <strong>Technicien affecté</strong><br><?php echo h($iv['technicien_prenom'] . ' ' . $iv['technicien_nom']); ?>
                                <span class="av2-badge <?php echo h($isInternal ? 'info' : 'neutral'); ?>"><?php echo h($isInternal ? 'Technicien interne' : 'Technicien du garage'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($canAssignInternal): ?>
                        <?php if (empty($techniciensInternes)): ?>
                            <div class="av2-empty">Aucun technicien SmartAutoTrack validé disponible.</div>
                        <?php else: ?>
                            <form method="POST" action="intervention_detail.php?id=<?php echo (int)$iv['id']; ?>" style="margin-bottom:16px;">
                                <input type="hidden" name="action" value="assign_internal">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <div class="av2-form-group">
                                    <label>Technicien SmartAutoTrack</label>
                                    <select name="technicien_id" required>
                                        <option value="">Sélectionner un technicien interne</option>
                                        <?php foreach ($techniciensInternes as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['prenom'] . ' ' . $t['nom'] . ($t['specialite'] ? ' — ' . $t['specialite'] : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="av2-form-group">
                                    <label>Garage (facultatif)</label>
                                    <select name="support_garage_id">
                                        <option value="">Aucun garage</option>
                                        <?php foreach ($supportGarages as $g): ?>
                                            <option value="<?php echo (int)$g['idGarage']; ?>"><?php echo h($g['nomGarage']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="av2-btn-primary btn-confirm" data-confirm="<?php echo h('Affecter cette demande à ce technicien SmartAutoTrack ? Le technicien, le client et le garage éventuel seront notifiés.'); ?>" style="width:100%;">Affecter à un technicien interne</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($canReassign): ?>
                        <?php if (empty($otherGarages)): ?>
                            <div class="av2-empty">Aucun garage validé disponible pour l'affectation.</div>
                        <?php else: ?>
                            <?php
                            $reassignFieldLabel = $iv['idGarage'] ? 'Réaffecter à' : 'Affecter à';
                            $reassignButtonLabel = $iv['idGarage'] ? 'Réaffecter le garage' : 'Affecter le garage';
                            // Un technicien déjà affecté (y compris SmartAutoTrack) est retiré
                            // par admin_reassign_garage() : on le dit avant de confirmer.
                            $reassignConfirm = ($iv['idGarage'] || $iv['idTechnicien'] !== null)
                                ? 'Réaffecter cette intervention à un garage ? Le technicien déjà affecté sera retiré, le client et le nouveau garage seront notifiés.'
                                : 'Affecter cette intervention à ce garage ? Le client et le garage seront notifiés.';
                            ?>
                            <form method="POST" action="intervention_detail.php?id=<?php echo (int)$iv['id']; ?>">
                                <input type="hidden" name="action" value="reassign_garage">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <div class="av2-form-group">
                                    <label><?php echo h($reassignFieldLabel); ?></label>
                                    <select name="garage_id" required>
                                        <option value="">Sélectionner un garage</option>
                                        <?php foreach ($otherGarages as $g): ?>
                                            <option value="<?php echo (int)$g['idGarage']; ?>"><?php echo h($g['nomGarage']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="av2-btn-primary btn-confirm" data-confirm="<?php echo h($reassignConfirm); ?>" style="width:100%;"><?php echo h($reassignButtonLabel); ?></button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="av2-empty">Cette intervention est <?php echo h(strtolower($statutLabels[$iv['statut']] ?? $iv['statut'])); ?> : elle ne peut plus être réaffectée.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
