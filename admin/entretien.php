<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/maintenance.php';
require_once 'includes/helpers.php';

/**
 * Réglages des échéances d'entretien et des rappels automatiques (espace
 * Administrateur).
 *
 * Accès : rôle admin uniquement (requireRole).
 * Actions :
 *   - POST action=save_rules (jeton CSRF requis) : enregistre les règles de
 *     la vidange, du contrôle des freins et du remplacement des pneus
 *     (`mois[TYPE]`, `km[TYPE]` facultatif, case `actif[TYPE]`). Les trois
 *     règles sont contrôlées (maintenanceRuleError()) avant toute écriture,
 *     puis enregistrées ensemble (maintenanceSaveRule(), dans une
 *     transaction) ; redirection (PRG) pour qu'un rafraîchissement ne
 *     renvoie pas le formulaire.
 * Affichage (lecture seule) :
 *   - rappel des paliers (J-30, J-7, jour J / retard) et de la règle Premium ;
 *   - échéances en retard / bientôt sur tous les véhicules, par type
 *     (maintenanceBuildSchedule(), « aujourd'hui » calculé en PHP) ;
 *   - rappels envoyés les 30 derniers jours (rappel_envoye), par canal ;
 *   - commande de la tâche planifiée quotidienne (scripts/send_reminders.php).
 *
 * Les échéances sont toujours calculées par l'application avec ces règles,
 * jamais par l'assistant IA (qui reçoit le résultat, voir includes/ai.php).
 *
 * Tant que scripts/migrate_structure.php (section 8) n'est pas appliqué
 * (maintenanceReady() faux) : règles par défaut affichées en lecture seule,
 * aucune statistique, avec une note invitant à appliquer la migration.
 *
 * Tables : entretien_regle (lecture et écriture), vehicule, entretien,
 * rappel_envoye (lecture).
 * Liens : includes/maintenance.php (règles, calcul, rappels),
 * scripts/send_reminders.php, admin/includes/sidebar.php.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();
$ready = maintenanceReady($conn);
$errors = [];

$rules = maintenanceRules($conn);
// Valeurs affichées dans le formulaire : les règles en vigueur, ou la saisie
// refusée (pour que l'admin corrige sans tout retaper).
$formValues = [];
foreach ($rules as $type => $rule) {
    $formValues[$type] = [
        'mois' => (string)$rule['intervalleMois'],
        'km' => $rule['intervalleKm'] === null ? '' : (string)$rule['intervalleKm'],
        'actif' => $rule['actif'],
    ];
}

// Enregistrement des règles. Modifie des données : POST + jeton CSRF valide.
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rules') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, veuillez réessayer.';
    } else {
        $postedMois = is_array($_POST['mois'] ?? null) ? $_POST['mois'] : [];
        $postedKm = is_array($_POST['km'] ?? null) ? $_POST['km'] : [];
        $postedActif = is_array($_POST['actif'] ?? null) ? $_POST['actif'] : [];
        $toSave = [];
        // Liste blanche : seuls les types connus sont lus, dans leur ordre.
        foreach (MAINTENANCE_SERVICE_TYPES as $type => $label) {
            $mois = trim((string)($postedMois[$type] ?? ''));
            $km = trim((string)($postedKm[$type] ?? ''));
            $actif = isset($postedActif[$type]);
            $formValues[$type] = ['mois' => $mois, 'km' => $km, 'actif' => $actif];
            if (!ctype_digit($mois)) {
                $errors[] = $label . ' : indiquez l\'intervalle en mois (nombre entier).';
                continue;
            }
            if ($km !== '' && !ctype_digit($km)) {
                $errors[] = $label . ' : l\'intervalle en kilomètres doit être un nombre entier (ou laissé vide).';
                continue;
            }
            $kmValue = $km === '' ? null : (int)$km;
            $error = maintenanceRuleError($type, (int)$mois, $kmValue);
            if ($error !== null) {
                $errors[] = $label . ' : ' . $error;
                continue;
            }
            $toSave[$type] = [(int)$mois, $kmValue, $actif];
        }
        if (!$errors) {
            try {
                $conn->beginTransaction();
                foreach ($toSave as $type => [$mois, $kmValue, $actif]) {
                    maintenanceSaveRule($conn, $type, $mois, $kmValue, $actif);
                }
                $conn->commit();
                header('Location: entretien.php?success=1');
                exit;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('[SmartAutoTrack] règles d\'entretien : ' . $e->getMessage());
                $errors[] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Erreur lors de l\'enregistrement des règles.';
            }
        }
    }
}

// « Aujourd'hui » calculé en PHP : MySQL n'a pas le même fuseau sur ce serveur.
$today = date('Y-m-d');
$fleet = null;
$sentStats = null;
if ($ready) {
    // Échéances de tous les véhicules, avec les mêmes règles que les écrans
    // client et les rappels (maintenanceBuildSchedule()) : trois requêtes au total.
    $fleet = ['vehicles' => 0, 'vehiclesOverdue' => 0, 'overdue' => 0, 'soon' => 0, 'byType' => []];
    foreach (MAINTENANCE_TYPES as $type => $label) {
        $fleet['byType'][$type] = ['overdue' => 0, 'soon' => 0, 'unknown' => 0];
    }
    $vehicles = $conn->query('SELECT idVehicule, kilometrage, dateExpirationAssurance, dateProchaineVisiteTechnique FROM vehicule')->fetchAll(PDO::FETCH_ASSOC);
    $lastServices = maintenanceLastServices($conn, array_column($vehicles, 'idVehicule'));
    foreach ($vehicles as $v) {
        $fleet['vehicles']++;
        $hasOverdue = false;
        foreach (maintenanceBuildSchedule($v, $lastServices[(int)$v['idVehicule']] ?? [], $rules, $today) as $item) {
            if (isset($fleet['byType'][$item['type']][$item['status']])) {
                $fleet['byType'][$item['type']][$item['status']]++;
            }
            if ($item['status'] === 'overdue') {
                $fleet['overdue']++;
                $hasOverdue = true;
            } elseif ($item['status'] === 'soon') {
                $fleet['soon']++;
            }
        }
        $fleet['vehiclesOverdue'] += $hasOverdue ? 1 : 0;
    }

    // Rappels envoyés depuis 30 jours (borne calculée en PHP), par canal.
    $stmt = $conn->prepare('SELECT canal, COUNT(*) AS n FROM rappel_envoye WHERE dateEnvoi >= ? GROUP BY canal');
    $stmt->execute([date('Y-m-d H:i:s', strtotime('-30 days'))]);
    $sentStats = ['NOTIF' => 0, 'EMAIL' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($sentStats[$row['canal']])) {
            $sentStats[$row['canal']] = (int)$row['n'];
        }
    }
}

$successMessage = ($ready && ($_GET['success'] ?? '') === '1' && !$errors) ? 'Règles d\'entretien enregistrées : les échéances et les prochains rappels en tiennent compte.' : null;

// Commande de la tâche planifiée Windows (même texte que le docblock de scripts/send_reminders.php).
$scheduleCommand = 'schtasks /Create /TN "SmartAutoTrack rappels" /SC DAILY /ST 07:00 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\HCH\scripts\send_reminders.php"';

$pageTitle = 'Entretien & rappels';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'entretien'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <?php if ($successMessage): ?>
            <div class="av2-alert success"><?php echo h($successMessage); ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="av2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Entretien &amp; rappels</h1>
                <p class="av2-sub">Règles des échéances d'entretien et rappels automatiques des clients.</p>
            </div>
        </div>

        <?php if (!$ready): ?>
            <div class="av2-card av2-panel" style="background:#E7F3FC; border-color:#C9E2F5;">
                <div style="display:flex; gap:12px; align-items:flex-start;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/><path d="M12 8V13M12 16V16.1" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <div style="font-size:13.5px; color:#12417A; line-height:1.6;">
                        <strong>Échéances et rappels pas encore activés.</strong> Les tables d'entretien n'existent pas encore dans la base : aucune échéance n'est calculée et aucun rappel n'est envoyé. Appliquez la migration : <code>php scripts/migrate_structure.php --apply</code>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="av2-stats">
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#FDEDEE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#E5484D" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$fleet['overdue']; ?></div><div class="av2-stat-label">Échéances en retard (<?php echo (int)$fleet['vehiclesOverdue']; ?> véhicule<?php echo $fleet['vehiclesOverdue'] > 1 ? 's' : ''; ?>)</div></div>
                </div>
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/><path d="M12 7V12L15 14" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$fleet['soon']; ?></div><div class="av2-stat-label">Échéances bientôt (<?php echo (int)MAINTENANCE_SOON_DAYS; ?> jours ou <?php echo h(maintenanceFormatKm(MAINTENANCE_SOON_KM)); ?>)</div></div>
                </div>
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 8C6 4.7 8.7 3 12 3C15.3 3 18 4.7 18 8V13L20 17H4L6 13V8Z" stroke="#1E7DBF" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20H14" stroke="#1E7DBF" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$sentStats['NOTIF']; ?></div><div class="av2-stat-label">Notifications de rappel (30 derniers jours)</div></div>
                </div>
                <div class="av2-card av2-stat-card">
                    <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#1E8A4C" stroke-width="1.8"/><path d="M3 6.5L12 13L21 6.5" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <div><div class="av2-stat-value"><?php echo (int)$sentStats['EMAIL']; ?></div><div class="av2-stat-label">Emails de rappel (30 derniers jours)</div></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="av2-body">
            <div class="av2-col">
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head">
                        <h2>Règles d'entretien</h2>
                        <?php if (!$ready): ?><span class="av2-badge neutral">Valeurs par défaut</span><?php endif; ?>
                    </div>
                    <p style="margin:0 0 14px; font-size:13.5px; color:#6D74A0; line-height:1.6;">
                        La prochaine échéance est calculée à partir du dernier entretien enregistré (clôture d'une réparation ou déclaration du client) : la première limite atteinte, en mois ou en kilomètres, l'emporte.
                        Laissez les kilomètres vides pour ne compter que la durée. Une règle inactive masque l'échéance et ne déclenche aucun rappel.
                    </p>
                    <form method="POST" action="entretien.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="save_rules">
                        <div class="av2-table-wrap">
                            <table class="av2-table" style="min-width:560px;">
                                <thead>
                                    <tr><th>Entretien</th><th>Tous les (mois)</th><th>Ou tous les (km)</th><th>Actif</th><th>Par défaut</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (MAINTENANCE_SERVICE_TYPES as $type => $label):
                                        $values = $formValues[$type];
                                        $default = MAINTENANCE_DEFAULT_RULES[$type];
                                    ?>
                                        <tr>
                                            <td style="font-weight:700;"><?php echo h($label); ?></td>
                                            <td class="av2-form-group" style="margin:0;">
                                                <input type="number" name="mois[<?php echo h($type); ?>]" aria-label="<?php echo h($label . ' : intervalle en mois'); ?>" value="<?php echo h($values['mois']); ?>" min="<?php echo (int)MAINTENANCE_RULE_MIN_MONTHS; ?>" max="<?php echo (int)MAINTENANCE_RULE_MAX_MONTHS; ?>" step="1" data-only="digits" inputmode="numeric" required placeholder="Ex. <?php echo (int)$default['intervalleMois']; ?>" style="max-width:90px;" <?php echo $ready ? '' : 'disabled'; ?>>
                                            </td>
                                            <td class="av2-form-group" style="margin:0;">
                                                <input type="number" name="km[<?php echo h($type); ?>]" aria-label="<?php echo h($label . ' : intervalle en kilomètres'); ?>" value="<?php echo h($values['km']); ?>" min="<?php echo (int)MAINTENANCE_RULE_MIN_KM; ?>" max="<?php echo (int)MAINTENANCE_RULE_MAX_KM; ?>" step="1" data-only="digits" inputmode="numeric" placeholder="Ex. <?php echo (int)$default['intervalleKm']; ?>" style="max-width:120px;" <?php echo $ready ? '' : 'disabled'; ?>>
                                            </td>
                                            <td>
                                                <input type="checkbox" name="actif[<?php echo h($type); ?>]" value="1" aria-label="<?php echo h($label . ' : règle active'); ?>" style="width:18px; height:18px; accent-color:#3956E8;" <?php echo $values['actif'] ? 'checked' : ''; ?> <?php echo $ready ? '' : 'disabled'; ?>>
                                            </td>
                                            <td style="font-size:13px; color:#6D74A0; line-height:1.45;">
                                                <span style="white-space:nowrap;"><?php echo h((int)$default['intervalleMois'] . ' mois'); ?></span><br>
                                                <span style="white-space:nowrap;"><?php echo h('ou ' . maintenanceFormatKm((int)$default['intervalleKm'])); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p style="margin:12px 0 0; font-size:12.5px; color:#8B90B3;">
                            Bornes : <?php echo (int)MAINTENANCE_RULE_MIN_MONTHS; ?> à <?php echo (int)MAINTENANCE_RULE_MAX_MONTHS; ?> mois, <?php echo h(maintenanceFormatKm(MAINTENANCE_RULE_MIN_KM)); ?> à <?php echo h(maintenanceFormatKm(MAINTENANCE_RULE_MAX_KM)); ?>.
                            Valeurs par défaut adaptées aux conditions sévères du Cameroun (chaleur, poussière, routes dégradées).
                        </p>
                        <?php if ($ready): ?>
                            <div style="display:flex; justify-content:flex-end; margin-top:16px;">
                                <button type="submit" class="av2-btn-primary">Enregistrer les règles</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($ready): ?>
                    <div class="av2-card av2-panel">
                        <div class="av2-panel-head"><h2>Échéances par type (<?php echo (int)$fleet['vehicles']; ?> véhicule<?php echo $fleet['vehicles'] > 1 ? 's' : ''; ?>)</h2></div>
                        <div class="av2-table-wrap">
                            <table class="av2-table" style="min-width:480px;">
                                <thead>
                                    <tr><th>Échéance</th><th>En retard</th><th>Bientôt</th><th>Inconnue</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (MAINTENANCE_TYPES as $type => $label):
                                        if (isset(MAINTENANCE_SERVICE_TYPES[$type]) && empty($rules[$type]['actif'])) {
                                            continue;
                                        }
                                        $c = $fleet['byType'][$type];
                                    ?>
                                        <tr>
                                            <td style="font-weight:700;"><?php echo h($label); ?></td>
                                            <td><span class="av2-badge <?php echo $c['overdue'] > 0 ? 'bad' : 'neutral'; ?>"><?php echo (int)$c['overdue']; ?></span></td>
                                            <td><span class="av2-badge <?php echo $c['soon'] > 0 ? 'warn' : 'neutral'; ?>"><?php echo (int)$c['soon']; ?></span></td>
                                            <td><span class="av2-badge neutral"><?php echo (int)$c['unknown']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p style="margin:12px 0 0; font-size:12.5px; color:#8B90B3;">« Inconnue » : date non renseignée ou aucun entretien enregistré ; le client est invité à compléter la fiche du véhicule.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="av2-col">
                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Rappels automatiques</h2></div>
                    <div style="font-size:13.5px; line-height:1.65; color:#2A2F52;">
                        <p style="margin:0 0 10px;">Tous les clients voient leurs échéances (tableau de bord, fiche véhicule, assistant IA). Les <strong>rappels automatiques</strong> sont réservés aux clients <span class="av2-badge ok">Premium</span>.</p>
                        <div class="av2-row" style="margin-bottom:8px;"><span class="av2-badge info">J-30</span><span>Notification</span></div>
                        <div class="av2-row" style="margin-bottom:8px;"><span class="av2-badge warn">J-7</span><span>Notification et email</span></div>
                        <div class="av2-row" style="margin-bottom:8px;"><span class="av2-badge warn">Jour J</span><span>Notification</span></div>
                        <div class="av2-row" style="margin-bottom:10px;"><span class="av2-badge bad">Retard</span><span>Notification et email</span></div>
                        <p style="margin:0 0 6px; font-size:12.5px; color:#6D74A0;">Chaque rappel n'est envoyé qu'une fois par véhicule, échéance, palier et canal. Un seul email récapitulatif par client et par jour, si l'envoi d'emails (Brevo) est configuré.</p>
                    </div>
                </div>

                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Tâche planifiée</h2></div>
                    <div style="font-size:13.5px; line-height:1.6; color:#2A2F52;">
                        <p style="margin:0 0 8px;">Les rappels partent quand le script suivant est lancé, une fois par jour (de préférence le matin) :</p>
                        <pre style="margin:0 0 10px; padding:10px 12px; background:#F4F5FB; border:1px solid #E6E8F3; border-radius:10px; font-size:12.5px; white-space:pre-wrap; word-break:break-all;">php scripts/send_reminders.php</pre>
                        <p style="margin:0 0 8px;">Sous Windows (XAMPP), à créer une seule fois dans une invite de commandes administrateur :</p>
                        <pre style="margin:0 0 10px; padding:10px 12px; background:#F4F5FB; border:1px solid #E6E8F3; border-radius:10px; font-size:12.5px; white-space:pre-wrap; word-break:break-all;"><?php echo h($scheduleCommand); ?></pre>
                        <p style="margin:0; font-size:12.5px; color:#6D74A0;">Sous Linux : <code>0 7 * * * php /chemin/vers/HCH/scripts/send_reminders.php</code> dans <code>crontab -e</code>.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
