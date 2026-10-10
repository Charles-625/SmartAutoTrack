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
 * Action POST (jeton CSRF requis), passée par `action` :
 *   - assign : affecte ou réaffecte l'intervention via admin_assign() —
 *     technicien (SmartAutoTrack ou de garage) et garage facultatifs, au
 *     moins l'un des deux. Formulaire affiché pour PLANIFIEE et ANNULEE
 *     (titre « Affecter » ou « Réaffecter ») ; EN_COURS/TERMINEE : plus
 *     réaffectable. Les listes montrent la charge de chacun.
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

// Affectation / réaffectation — même règle que la liste (admin_assign,
// admin/includes/helpers.php), mais traitée ici pour réafficher une erreur
// sur CETTE page (jamais de redirection qui perdrait le message). Les choix
// envoyés sont gardés pour présélectionner le formulaire après une erreur.
$postedChoice = null; // [technicien, garage] envoyés, gardés si erreur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        // Deux choix facultatifs : valeur vide = aucun.
        $technicienId = admin_assign_parse_id($_POST['technicien_id'] ?? null);
        $garageId = admin_assign_parse_id($_POST['garage_id'] ?? null);
        if ($technicienId === false) {
            $errors[] = 'Technicien invalide.';
        } elseif ($garageId === false) {
            $errors[] = 'Garage invalide.';
        } else {
            $postedChoice = [$technicienId, $garageId];
            $assignError = admin_assign($conn, $interventionId, $technicienId, $garageId, (int)$_SESSION['user_id']);
            if ($assignError !== null) {
                $errors[] = $assignError;
            } else {
                header("Location: intervention_detail.php?id=$interventionId&success=assigned");
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

// (Ré)affectation possible tant que l'intervention n'a pas démarré
// (PLANIFIEE) ou après un refus (ANNULEE). « Réaffecter » si elle est déjà
// confiée (PLANIFIEE avec technicien ou garage), sinon « Affecter ».
$canAssign = in_array($iv['statut'], ['PLANIFIEE', 'ANNULEE'], true);
$isAssigned = $iv['statut'] === 'PLANIFIEE' && ($iv['idTechnicien'] !== null || $iv['idGarage'] !== null);
$assignChoices = $canAssign ? admin_assign_choices($conn) : null;
// Présélection : les choix envoyés après une erreur, sinon l'affectation actuelle
// d'une intervention PLANIFIEE. Une demande refusée (ANNULEE) s'ouvre sans
// présélection : reprendre le garage ou le technicien qui a refusé serait
// rejeté (« déjà affectée ainsi ») et n'aide pas à trouver quelqu'un de disponible.
$keepCurrent = $iv['statut'] === 'PLANIFIEE';
$preTech = $postedChoice !== null ? $postedChoice[0] : ($keepCurrent && $iv['idTechnicien'] !== null ? (int)$iv['idTechnicien'] : null);
$preGarage = $postedChoice !== null ? $postedChoice[1] : ($keepCurrent && $iv['idGarage'] !== null ? (int)$iv['idGarage'] : null);

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
            <div class="av2-alert success"><?php echo h(['assigned' => 'Affectation enregistrée : les personnes concernées ont été notifiées.', 'internal_assigned' => 'Technicien SmartAutoTrack affecté avec succès.'][$_GET['success']] ?? 'Garage affecté avec succès.'); ?></div>
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
                                <span class="av2-badge warn" style="white-space:normal;">Aucun — demande à SmartAutoTrack, à affecter</span>
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

                    <?php if ($canAssign): ?>
                        <?php
                        // Formulaire unique : technicien et garage facultatifs (au
                        // moins l'un des deux), affectation actuelle présélectionnée,
                        // charge de chacun dans les listes (admin_assign_fields()).
                        $assignLabel = $isAssigned ? 'Réaffecter' : 'Affecter';
                        $assignConfirm = $isAssigned
                            ? 'Réaffecter cette intervention ? L\'ancien technicien ou garage, le nouveau et le client seront notifiés.'
                            : 'Affecter cette demande ? Le technicien et/ou le garage choisis et le client seront notifiés.';
                        ?>
                        <div class="av2-form-section"><?php echo h($assignLabel); ?></div>
                        <form method="POST" action="intervention_detail.php?id=<?php echo (int)$iv['id']; ?>" id="assignDetailForm">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <?php admin_assign_fields($assignChoices, 'detail', $preTech, $preGarage); ?>
                            <button type="submit" class="av2-btn-primary" style="width:100%;" data-confirm="<?php echo h($assignConfirm); ?>"><?php echo h($assignLabel); ?></button>
                        </form>
                        <script>
                        // Technicien de garage → son garage présélectionné ; envoi bloqué
                        // sans aucun choix (la vraie validation reste serveur), puis confirmation.
                        document.addEventListener('DOMContentLoaded', function () {
                            var form = document.getElementById('assignDetailForm');
                            var tech = form.querySelector('[data-assign-tech]');
                            var garage = form.querySelector('[data-assign-garage]');
                            var error = form.querySelector('[data-assign-error]');
                            tech.addEventListener('change', function () {
                                var option = tech.options[tech.selectedIndex];
                                if (option && option.getAttribute('data-garage')) garage.value = option.getAttribute('data-garage');
                                error.hidden = true;
                            });
                            garage.addEventListener('change', function () { error.hidden = true; });
                            form.addEventListener('submit', function (e) {
                                if (!tech.value && !garage.value) {
                                    e.preventDefault();
                                    error.hidden = false;
                                    return;
                                }
                                if (!confirm(form.querySelector('[data-confirm]').getAttribute('data-confirm'))) e.preventDefault();
                            });
                        });
                        </script>
                    <?php else: ?>
                        <div class="av2-empty">Cette intervention est <?php echo h(strtolower($statutLabels[$iv['statut']] ?? $iv['statut'])); ?> : elle ne peut plus être réaffectée.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
