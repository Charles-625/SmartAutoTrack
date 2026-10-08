<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/maintenance.php';

/**
 * Fiche détaillée d'un véhicule du client.
 *
 * Accès : rôle client uniquement, et seulement pour ses propres véhicules :
 * un id absent ou appartenant à un autre client renvoie vers vehicles.php.
 * GET : id (identifiant du véhicule, obligatoire, entier) ; success
 * (dates | entretien | supprime : message après un enregistrement).
 * Les détails d'une anomalie ou d'une réparation sont chargés en AJAX
 * (ajax/get_anomaly_details.php, ajax/get_reparation_details.php).
 *
 * Section « Échéances et entretien » (seulement si maintenanceReady()) :
 * tableau des échéances du véhicule (maintenanceVehicleSchedule() : assurance,
 * visite technique, vidange, freins, pneus), historique des entretiens
 * enregistrés (réparation ou déclaration du client) et trois formulaires,
 * tous en POST + CSRF sur le véhicule dont la propriété est vérifiée plus
 * haut, puis redirection (?success=…#echeances) :
 *   form=maintenance_dates  : date d'expiration de l'assurance et date de la
 *                             prochaine visite technique (vides = effacées ;
 *                             maintenanceSetVehicleDates()) ;
 *   form=maintenance_record : déclaration d'un entretien passé (type de
 *                             MAINTENANCE_SERVICE_TYPES, date au plus
 *                             aujourd'hui, kilométrage facultatif en chiffres
 *                             et au plus celui du véhicule ; maintenanceRecord(),
 *                             source CLIENT) ;
 *   form=maintenance_delete : suppression d'un entretien déclaré par le client
 *                             (source CLIENT uniquement ; ceux issus d'une
 *                             réparation ne sont pas supprimables).
 * « Aujourd'hui » est calculé en PHP (fuseau différent de MySQL).
 * Tables lues : vehicule, anomalie, reparation, intervention, utilisateur,
 * entretien, entretien_regle ; écrites : vehicule (dates d'échéance),
 * entretien.
 */
requireRole('client');

/** Classe de badge (.v2-badge) et de pastille (.v2-due-dot) par statut d'échéance. */
const VEHICLE_DUE_BADGES = ['overdue' => 'bad', 'soon' => 'warn', 'ok' => 'ok', 'unknown' => 'neutral'];

/**
 * Reste à courir d'une échéance, en texte brut (à échapper avec h()) :
 * « dans 12 jours · reste 800 km », « dépassée de 3 jours », « à renseigner ».
 *
 * @param array $item Échéance (élément de maintenanceVehicleSchedule()).
 * @return string
 */
function vehicleDueLeft(array $item): string {
    if ($item['status'] === 'unknown') {
        return 'À renseigner';
    }
    $parts = [];
    $days = $item['daysLeft'];
    if ($days !== null) {
        $parts[] = $days > 0 ? 'dans ' . $days . ' jour' . ($days > 1 ? 's' : '')
            : ($days === 0 ? "aujourd'hui" : 'dépassée de ' . -$days . ' jour' . ($days < -1 ? 's' : ''));
    }
    if ($item['kmLeft'] !== null) {
        $parts[] = $item['kmLeft'] > 0 ? 'reste ' . maintenanceFormatKm((int)$item['kmLeft'])
            : maintenanceFormatKm(-(int)$item['kmLeft']) . ' au-delà';
    }
    return ucfirst(implode(' · ', $parts));
}

$db = new Database();
$conn = $db->getConnection();

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');
// Badge de la sidebar : interventions actives (planifiées ou en cours) du client.
$stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmtBadge->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmtBadge->fetchColumn();

// Récupérer l'ID du véhicule (entier : la valeur est réaffichée dans des liens)
$vehicle_id = (int)($_GET['id'] ?? 0);

if ($vehicle_id <= 0) {
    header('Location: vehicles.php');
    exit;
}

// Vérifier que le véhicule appartient au client (etat reconverti en statut
// historique minuscule pour laisser le gabarit d'affichage inchangé)
$stmt = $conn->prepare("
    SELECT idVehicule AS id, marque, modele, immatriculation, annee, couleur, kilometrage, dateCreation AS created_at,
           CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
    FROM vehicule WHERE idVehicule = ? AND idClient = ?
");
$stmt->execute([$vehicle_id, $_SESSION['user_id']]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    header('Location: vehicles.php');
    exit;
}

// La propriété du véhicule est vérifiée ci-dessus : les requêtes suivantes
// (et les formulaires d'échéances) peuvent filtrer sur son seul identifiant.

// Échéances et entretien : rien n'est lu ni écrit tant que la migration
// (section 8) n'est pas appliquée.
$today = date('Y-m-d');
$maintenanceEnabled = maintenanceReady($conn);
$dueErrors = [];
$dueForm = (string)($_POST['form'] ?? '');
if ($maintenanceEnabled && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($dueForm, ['maintenance_dates', 'maintenance_record', 'maintenance_delete'], true)) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $dueErrors[] = 'Session expirée, merci de réessayer.';
    } else {
        try {
            if ($dueForm === 'maintenance_dates') {
                maintenanceSetVehicleDates($conn, $vehicle_id,
                    (string)($_POST['dateExpirationAssurance'] ?? ''), (string)($_POST['dateProchaineVisiteTechnique'] ?? ''));
                $dueSuccess = 'dates';
            } elseif ($dueForm === 'maintenance_record') {
                $dueDate = trim((string)($_POST['dateEntretien'] ?? ''));
                $dueKmRaw = trim((string)($_POST['kilometrage'] ?? ''));
                if ($dueDate === '') {
                    throw new InvalidArgumentException('La date de l\'entretien est requise.');
                }
                if ($dueKmRaw !== '' && !validateDigitsOnly($dueKmRaw)) {
                    throw new InvalidArgumentException('Le kilométrage ne doit contenir que des chiffres.');
                }
                if (strlen($dueKmRaw) > strlen((string)MAINTENANCE_MAX_KM)) {
                    throw new InvalidArgumentException('Le kilométrage de l\'entretien est invalide.');
                }
                $dueKm = $dueKmRaw === '' ? null : (int)$dueKmRaw;
                // Un entretien passé ne peut pas avoir eu lieu au-delà du
                // kilométrage actuel : sinon les échéances seraient faussées.
                if ($dueKm !== null && $vehicle['kilometrage'] !== null && $dueKm > (int)$vehicle['kilometrage']) {
                    throw new InvalidArgumentException('Le kilométrage de l\'entretien dépasse celui du véhicule ('
                        . maintenanceFormatKm((int)$vehicle['kilometrage']) . '). Mettez d\'abord à jour le kilométrage dans « Mes véhicules ».');
                }
                maintenanceRecord($conn, $vehicle_id, (string)($_POST['type'] ?? ''), $dueDate, $dueKm, 'CLIENT');
                $dueSuccess = 'entretien';
            } else {
                $stmt = $conn->prepare("DELETE FROM entretien WHERE idEntretien = ? AND idVehicule = ? AND source = 'CLIENT'");
                $stmt->execute([(int)($_POST['idEntretien'] ?? 0), $vehicle_id]);
                if ($stmt->rowCount() !== 1) {
                    throw new InvalidArgumentException('Cet entretien est introuvable ou ne peut pas être supprimé.');
                }
                $dueSuccess = 'supprime';
            }
            header('Location: vehicle_details.php?id=' . $vehicle_id . '&success=' . $dueSuccess . '#echeances');
            exit;
        } catch (InvalidArgumentException $e) {
            $dueErrors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[SmartAutoTrack] échéances du véhicule ' . $vehicle_id . ' : ' . $e->getMessage());
            $dueErrors[] = 'Erreur lors de l\'enregistrement. Merci de réessayer.';
        }
    }
}
$dueMessages = [
    'dates' => 'Les dates d\'assurance et de visite technique sont enregistrées.',
    'entretien' => 'L\'entretien est enregistré : les échéances sont recalculées.',
    'supprime' => 'L\'entretien déclaré est supprimé.',
];
$dueSuccessMessage = is_string($_GET['success'] ?? null) ? ($dueMessages[$_GET['success']] ?? null) : null;

$dueSchedule = [];
$dueHistory = [];
$dueRules = [];
$dueCurrent = ['ASSURANCE' => null, 'VISITE_TECHNIQUE' => null];
if ($maintenanceEnabled) {
    $dueSchedule = maintenanceVehicleSchedule($conn, $vehicle_id, $today);
    foreach ($dueSchedule as $item) {
        if (array_key_exists($item['type'], $dueCurrent)) {
            $dueCurrent[$item['type']] = $item['dueDate'];
        }
    }
    $dueRules = maintenanceRules($conn);
    $stmt = $conn->prepare("
        SELECT e.idEntretien, e.type, e.dateEntretien, e.kilometrage, e.source, r.titre AS reparation_titre
        FROM entretien e
        LEFT JOIN reparation r ON r.idReparation = e.idReparation
        WHERE e.idVehicule = ?
        ORDER BY e.dateEntretien DESC, e.idEntretien DESC
        LIMIT 30
    ");
    $stmt->execute([$vehicle_id]);
    $dueHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Après une erreur, les champs reprennent la saisie du client.
$dueOld = $dueErrors ? $_POST : [];

// Récupérer les anomalies du véhicule
$stmt = $conn->prepare("
    SELECT idAnomalie AS id, description, dateDetection AS date_detection, dateResolution AS date_resolution,
           COALESCE(type, 'Anomalie') AS type, LOWER(niveau) AS niveau,
           CASE statut WHEN 'NOUVELLE' THEN 'detectee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'resolue' END AS statut
    FROM anomalie
    WHERE idVehicule = ?
    ORDER BY dateDetection DESC
");
$stmt->execute([$vehicle_id]);
$anomalies = $stmt->fetchAll();

// Récupérer les réparations du véhicule (via son intervention : plus de
// vehicle_id/anomalie_id directs sur reparation dans le nouveau schéma)
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.description, r.titre, r.diagnostic,
           r.travauxEffectues AS travaux_effectues, r.piecesUtilisees AS pieces_utilisees, r.recommandations,
           r.cout, r.dureeIntervention AS duree_intervention, r.idTechnicien AS technicien_id,
           r.dateReparation AS created_at,
           CASE r.statut WHEN 'EN_ATTENTE' THEN 'planifiee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'validee' END AS statut,
           ut.prenom as technicien_prenom, ut.nom as technicien_nom,
           NULL as anomalie_type
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
    WHERE i.idVehicule = ?
    ORDER BY r.dateReparation DESC
");
$stmt->execute([$vehicle_id]);
$reparations = $stmt->fetchAll();

// Récupérer les interventions du véhicule
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type AS type_intervention, i.description,
           i.dateIntervention AS date_planifiee, LOWER(i.statut) AS statut,
           u.prenom as technicien_prenom, u.nom as technicien_nom
    FROM intervention i
    LEFT JOIN utilisateur u ON i.idTechnicien = u.idUtilisateur
    WHERE i.idVehicule = ?
    ORDER BY i.dateIntervention DESC
");
$stmt->execute([$vehicle_id]);
$interventions = $stmt->fetchAll();

// Statistiques
$stats = [];
$stats['anomalies_total'] = count($anomalies);
$stats['anomalies_actives'] = count(array_filter($anomalies, fn($a) => $a['statut'] !== 'resolue'));
$stats['reparations_total'] = count($reparations);
$stats['reparations_en_cours'] = count(array_filter($reparations, fn($r) => in_array($r['statut'], ['planifiee', 'en_cours'])));
$stats['interventions_total'] = count($interventions);
$stats['interventions_planifiees'] = count(array_filter($interventions, fn($i) => $i['statut'] === 'planifiee'));

$pageTitle = 'Détails du véhicule - ' . $vehicle['marque'] . ' ' . $vehicle['modele'];
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'vehicules'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>
    <main class="v2-main">

<div class="v2-page-head">
    <div>
        <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
        <h1 class="v2-h1"><?php echo h($vehicle['marque'] . ' ' . $vehicle['modele']); ?></h1>
        <p class="v2-sub">Détails complets et historique du véhicule</p>
    </div>
    <a href="vehicles.php" class="v2-btn-outline" style="text-decoration:none; display:inline-block;">← Retour</a>
</div>

<!-- Informations du véhicule -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-info-circle"></i> Informations du véhicule</h3>
    </div>
    <div class="card-body">
        <div class="vehicle-info-grid">
            <div class="vehicle-info-item">
                <div class="info-label">Marque</div>
                <div class="info-value"><?php echo h($vehicle['marque']); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Modèle</div>
                <div class="info-value"><?php echo h($vehicle['modele']); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Immatriculation</div>
                <div class="info-value"><?php echo h($vehicle['immatriculation']); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Année</div>
                <div class="info-value"><?php echo h($vehicle['annee'] ?? 'N/A'); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Couleur</div>
                <div class="info-value"><?php echo h($vehicle['couleur'] ?? 'N/A'); ?></div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Kilométrage</div>
                <div class="info-value"><?php echo number_format($vehicle['kilometrage']); ?> km</div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Statut</div>
                <div class="info-value">
                    <span class="badge badge-<?php echo h($vehicle['statut'] === 'actif' ? 'success' : 
                            ($vehicle['statut'] === 'en_panne' ? 'danger' : 'warning')); ?>">
                        <?php echo h(ucfirst($vehicle['statut'])); ?>
                    </span>
                </div>
            </div>
            <div class="vehicle-info-item">
                <div class="info-label">Date d'enregistrement</div>
                <div class="info-value"><?php echo formatDate($vehicle['created_at']); ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($maintenanceEnabled):
    // Valeurs des formulaires : la saisie refusée est reproposée, sinon les
    // dates enregistrées (assurance, visite) ou des champs vides (entretien).
    $oldDates = $dueForm === 'maintenance_dates' ? $dueOld : [];
    $oldRecord = $dueForm === 'maintenance_record' ? $dueOld : [];
    $valAssurance = (string)($oldDates['dateExpirationAssurance'] ?? ($dueCurrent['ASSURANCE'] ?? ''));
    $valVisite = (string)($oldDates['dateProchaineVisiteTechnique'] ?? ($dueCurrent['VISITE_TECHNIQUE'] ?? ''));
?>
<!-- Échéances et entretien -->
<div class="v2-card v2-panel" id="echeances">
    <div class="v2-panel-head">
        <h2>Échéances et entretien</h2>
    </div>
    <?php if ($dueSuccessMessage !== null): ?>
        <div class="v2-alert success" style="margin-bottom:14px;"><?php echo h($dueSuccessMessage); ?></div>
    <?php endif; ?>
    <?php foreach ($dueErrors as $err): ?>
        <div class="v2-alert error" style="margin-bottom:14px;"><?php echo h($err); ?></div>
    <?php endforeach; ?>

    <div class="v2-table-wrap">
        <table class="v2-table v2-due-table">
            <thead>
                <tr><th>Échéance</th><th>Statut</th><th>Prochaine échéance</th><th>Reste</th><th>Dernier entretien</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dueSchedule as $item):
                    $dueClass = VEHICLE_DUE_BADGES[$item['status']];
                    $isDateType = isset(MAINTENANCE_DATE_TYPES[$item['type']]);
                ?>
                    <tr>
                        <td><strong><?php echo h($item['libelle']); ?></strong></td>
                        <td>
                            <span class="v2-due-status">
                                <span class="v2-due-dot <?php echo h($dueClass); ?>" aria-hidden="true"></span>
                                <span class="v2-badge <?php echo h($dueClass); ?>"><?php echo h($item['status'] === 'unknown' ? 'À renseigner' : MAINTENANCE_STATUS_LABELS[$item['status']]); ?></span>
                            </span>
                        </td>
                        <td>
                            <?php if ($item['dueDate'] !== null): ?>
                                <?php echo h(maintenanceFormatDate($item['dueDate'])); ?>
                                <?php if ($item['dueKm'] !== null): ?><small>ou à <?php echo h(maintenanceFormatKm((int)$item['dueKm'])); ?></small><?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo h(vehicleDueLeft($item)); ?></td>
                        <td>
                            <?php if ($isDateType): ?>
                                <?php if ($item['dueDate'] === null): ?><small style="margin:0;">Saisissez la date ci-dessous</small><?php else: ?>—<?php endif; ?>
                            <?php elseif ($item['lastDate'] !== null): ?>
                                <?php echo h(maintenanceFormatDate($item['lastDate'])); ?>
                                <?php if ($item['lastKm'] !== null): ?><small>à <?php echo h(maintenanceFormatKm((int)$item['lastKm'])); ?></small><?php endif; ?>
                            <?php else: ?>
                                <small style="margin:0;">Aucun — déclarez-le ci-dessous</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    // Règles en vigueur (modifiables par l'admin), pour que le client
    // comprenne le calcul : « Vidange : tous les 5 000 km ou 6 mois ».
    $ruleTexts = [];
    foreach ($dueRules as $type => $rule) {
        if (!empty($rule['actif'])) {
            $ruleTexts[] = MAINTENANCE_SERVICE_TYPES[$type] . ' : tous les '
                . ($rule['intervalleKm'] !== null ? maintenanceFormatKm((int)$rule['intervalleKm']) . ' ou ' : '')
                . (int)$rule['intervalleMois'] . ' mois';
        }
    }
    ?>
    <?php if ($ruleTexts): ?>
        <p class="v2-note">Calcul à partir du dernier entretien, au premier des deux seuils atteint (conditions de route camerounaises) — <?php echo h(implode(' ; ', $ruleTexts)); ?>.</p>
    <?php endif; ?>

    <div class="v2-due-forms">
        <form method="POST" action="vehicle_details.php?id=<?php echo (int)$vehicle_id; ?>#echeances" class="v2-due-form">
            <input type="hidden" name="form" value="maintenance_dates">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <h3>Assurance et visite technique</h3>
            <p class="v2-note">Reportez les dates de votre attestation d'assurance et de votre certificat de visite technique. Laissez vide pour effacer.</p>
            <div class="v2-form-group">
                <label for="dueAssurance">Date d'expiration de l'assurance</label>
                <input type="date" id="dueAssurance" name="dateExpirationAssurance" placeholder="Ex. 31/03/2027" value="<?php echo h($valAssurance); ?>">
            </div>
            <div class="v2-form-group">
                <label for="dueVisite">Date de la prochaine visite technique</label>
                <input type="date" id="dueVisite" name="dateProchaineVisiteTechnique" placeholder="Ex. 15/06/2027" value="<?php echo h($valVisite); ?>">
            </div>
            <button type="submit" class="v2-btn-primary">Enregistrer les dates</button>
        </form>

        <form method="POST" action="vehicle_details.php?id=<?php echo (int)$vehicle_id; ?>#echeances" class="v2-due-form">
            <input type="hidden" name="form" value="maintenance_record">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <h3>Déclarer un entretien passé</h3>
            <p class="v2-note">Un entretien fait hors de la plateforme : il sert de point de départ au calcul de la prochaine échéance.</p>
            <div class="v2-form-group">
                <label for="dueType">Entretien</label>
                <select id="dueType" name="type" required>
                    <?php foreach (MAINTENANCE_SERVICE_TYPES as $type => $label): ?>
                        <option value="<?php echo h($type); ?>" <?php echo ($oldRecord['type'] ?? '') === $type ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="v2-form-group">
                <label for="dueDate">Date de l'entretien</label>
                <input type="date" id="dueDate" name="dateEntretien" required max="<?php echo h($today); ?>" placeholder="Ex. 15/09/2026" value="<?php echo h((string)($oldRecord['dateEntretien'] ?? '')); ?>">
            </div>
            <div class="v2-form-group">
                <label for="dueKm">Kilométrage relevé (facultatif)</label>
                <input type="number" id="dueKm" name="kilometrage" data-only="digits" inputmode="numeric" min="0" max="<?php echo (int)MAINTENANCE_MAX_KM; ?>" placeholder="Ex. 85000" value="<?php echo h((string)($oldRecord['kilometrage'] ?? '')); ?>">
            </div>
            <button type="submit" class="v2-btn-primary">Déclarer l'entretien</button>
        </form>
    </div>

    <div class="v2-due-history">
        <h3>Entretiens enregistrés</h3>
        <?php if (empty($dueHistory)): ?>
            <div class="v2-empty" style="padding:14px 4px;">Aucun entretien enregistré pour ce véhicule.</div>
        <?php else: ?>
            <div class="v2-table-wrap">
                <table class="v2-table v2-due-table">
                    <thead>
                        <tr><th>Date</th><th>Entretien</th><th>Kilométrage</th><th>Origine</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dueHistory as $row): ?>
                            <tr>
                                <td><?php echo h(maintenanceFormatDate((string)$row['dateEntretien'])); ?></td>
                                <td><?php echo h(MAINTENANCE_SERVICE_TYPES[$row['type']] ?? $row['type']); ?></td>
                                <td><?php echo h($row['kilometrage'] === null ? '—' : maintenanceFormatKm((int)$row['kilometrage'])); ?></td>
                                <td>
                                    <?php if ($row['source'] === 'REPARATION'): ?>
                                        <span class="v2-badge ok">Réparation</span>
                                        <?php if ($row['reparation_titre']): ?><small><?php echo h($row['reparation_titre']); ?></small><?php endif; ?>
                                    <?php else: ?>
                                        <span class="v2-badge neutral">Déclaration</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['source'] === 'CLIENT'): ?>
                                        <form method="POST" action="vehicle_details.php?id=<?php echo (int)$vehicle_id; ?>#echeances" onsubmit="return confirm('Supprimer cet entretien déclaré ?');">
                                            <input type="hidden" name="form" value="maintenance_delete">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="idEntretien" value="<?php echo (int)$row['idEntretien']; ?>">
                                            <button type="submit" class="v2-due-delete">Supprimer</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Statistiques -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon danger">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['anomalies_actives']; ?></div>
        <div class="stat-label">Anomalies actives</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-tools"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['reparations_en_cours']; ?></div>
        <div class="stat-label">Réparations en cours</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['interventions_planifiees']; ?></div>
        <div class="stat-label">Interventions planifiées</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-history"></i>
        </div>
        <div class="stat-value"><?php echo (int)$stats['reparations_total']; ?></div>
        <div class="stat-label">Total réparations</div>
    </div>
</div>

<div class="row">
    <!-- Anomalies récentes -->
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Anomalies récentes</h3>
                <a href="anomalies.php?vehicle=<?php echo $vehicle_id; ?>" class="btn btn-outline btn-sm">Voir toutes</a>
            </div>
            <div class="card-body">
                <?php if (empty($anomalies)): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-check-circle"></i>
                        <p>Aucune anomalie détectée</p>
                    </div>
                <?php else: ?>
                    <div class="anomalies-list">
                        <?php foreach (array_slice($anomalies, 0, 5) as $anomalie): ?>
                            <div class="anomaly-item-small">
                                <div class="anomaly-icon-small">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="anomaly-content-small">
                                    <div class="anomaly-title"><?php echo h($anomalie['type']); ?></div>
                                    <div class="anomaly-meta">
                                        <span class="badge badge-<?php echo h($anomalie['niveau'] === 'critique' ? 'danger' : 
                                                ($anomalie['niveau'] === 'moyen' ? 'warning' : 'info')); ?>">
                                            <?php echo h(ucfirst($anomalie['niveau'])); ?>
                                        </span>
                                        <span class="date"><?php echo formatDate($anomalie['date_detection']); ?></span>
                                    </div>
                                </div>
                                <div class="anomaly-actions-small">
                                    <button class="btn btn-outline btn-xs" onclick="viewAnomalyDetails(<?php echo $anomalie['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Réparations récentes -->
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tools"></i> Réparations récentes</h3>
                <a href="reparations.php?vehicle=<?php echo $vehicle_id; ?>" class="btn btn-outline btn-sm">Voir toutes</a>
            </div>
            <div class="card-body">
                <?php if (empty($reparations)): ?>
                    <div class="empty-state-small">
                        <i class="fas fa-tools"></i>
                        <p>Aucune réparation enregistrée</p>
                    </div>
                <?php else: ?>
                    <div class="reparations-list">
                        <?php foreach (array_slice($reparations, 0, 5) as $reparation): ?>
                            <div class="reparation-item-small">
                                <div class="reparation-content-small">
                                    <div class="reparation-title"><?php echo h($reparation['titre'] ?? 'Réparation #' . $reparation['id']); ?></div>
                                    <div class="reparation-meta">
                                        <span class="badge badge-<?php echo h($reparation['statut'] === 'validee' ? 'success' : 
                                                ($reparation['statut'] === 'terminee' ? 'info' : 
                                                ($reparation['statut'] === 'en_cours' ? 'warning' : 'primary'))); ?>">
                                            <?php echo h(ucfirst($reparation['statut'])); ?>
                                        </span>
                                        <span class="date"><?php echo formatDate($reparation['created_at']); ?></span>
                                        <span class="cost"><?php echo number_format($reparation['cout'], 0, ',', ' '); ?> XAF</span>
                                    </div>
                                </div>
                                <div class="reparation-actions-small">
                                    <button class="btn btn-outline btn-xs" onclick="viewReparationDetails(<?php echo $reparation['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Interventions planifiées -->
<?php if (!empty($interventions)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-calendar-check"></i> Interventions planifiées</h3>
    </div>
    <div class="card-body">
        <div class="interventions-list">
            <?php foreach ($interventions as $intervention): ?>
                <div class="intervention-item">
                    <div class="intervention-header">
                        <div class="intervention-info">
                            <h4><?php echo h($intervention['type_intervention']); ?></h4>
                            <div class="intervention-meta">
                                <span class="date">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo formatDate($intervention['date_planifiee']); ?>
                                </span>
                                <span class="technicien">
                                    <i class="fas fa-user"></i>
                                    <?php echo h($intervention['technicien_prenom'] . ' ' . $intervention['technicien_nom']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="intervention-status">
                            <span class="badge badge-<?php echo h($intervention['statut'] === 'terminee' ? 'success' : 
                                    ($intervention['statut'] === 'en_cours' ? 'warning' : 'primary')); ?>">
                                <?php echo h(ucfirst($intervention['statut'])); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($intervention['description']): ?>
                        <div class="intervention-description">
                            <p><?php echo h($intervention['description']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modals -->
<div id="anomalyModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3>Détails de l'anomalie</h3>
            <button class="modal-close" id="closeAnomalyModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="anomalyDetails">
            <!-- Le contenu sera chargé via AJAX -->
        </div>
    </div>
</div>

<div id="reparationModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3>Détails de la réparation</h3>
            <button class="modal-close" id="closeReparationModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="reparationDetails">
            <!-- Le contenu sera chargé via AJAX -->
        </div>
    </div>
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.page-header-content h1 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.page-header-content p {
    color: var(--text-light);
    margin: 0;
}

.page-header-actions {
    display: flex;
    gap: 1rem;
}

.vehicle-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}

.vehicle-info-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.info-label {
    font-weight: 600;
    color: var(--text-light);
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    color: var(--text-color);
    font-size: 1.1rem;
    font-weight: 500;
}

.anomalies-list, .reparations-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.anomaly-item-small, .reparation-item-small {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--background-color);
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
    transition: var(--transition);
}

.anomaly-item-small:hover, .reparation-item-small:hover {
    box-shadow: var(--shadow);
}

.anomaly-icon-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: rgba(220, 53, 69, 0.1);
    color: var(--danger-color);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.anomaly-content-small, .reparation-content-small {
    flex: 1;
}

.anomaly-title, .reparation-title {
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--text-color);
}

.anomaly-meta, .reparation-meta {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.anomaly-meta .date, .reparation-meta .date {
    font-size: 0.8rem;
    color: var(--text-light);
}

.reparation-meta .cost {
    font-size: 0.8rem;
    color: var(--primary-color);
    font-weight: 600;
}

.anomaly-actions-small, .reparation-actions-small {
    display: flex;
    gap: 0.5rem;
}

.btn-xs {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.interventions-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.intervention-item {
    background: var(--background-color);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 1.5rem;
}

.intervention-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.intervention-info h4 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.intervention-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.intervention-meta span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: var(--text-light);
}

.intervention-meta i {
    color: var(--primary-color);
}

.intervention-description {
    background: var(--secondary-color);
    padding: 1rem;
    border-radius: var(--border-radius);
}

.intervention-description p {
    margin: 0;
    color: var(--text-light);
    line-height: 1.6;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
    
    .vehicle-info-grid {
        grid-template-columns: 1fr;
    }
    
    .row {
        flex-direction: column;
    }
    
    .intervention-meta {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .intervention-header {
        flex-direction: column;
        gap: 1rem;
    }
}
</style>

<script>
$(document).ready(function() {
    // Fermer les modals
    $('#closeAnomalyModal, #closeReparationModal').click(function() {
        $('#anomalyModal, #reparationModal').fadeOut();
    });
    
    // Fermer les modals en cliquant à l'extérieur
    $('#anomalyModal, #reparationModal').click(function(e) {
        if (e.target === this) {
            $(this).fadeOut();
        }
    });
});

function viewAnomalyDetails(anomalyId) {
    $.ajax({
        url: SITE_URL + 'ajax/get_anomaly_details.php',
        method: 'GET',
        data: { id: anomalyId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayAnomalyDetails(response.anomaly);
                $('#anomalyModal').fadeIn();
            } else {
                showToast('Erreur lors du chargement des détails', 'error');
            }
        },
        error: function() {
            showToast('Erreur lors du chargement des détails', 'error');
        }
    });
}

function viewReparationDetails(reparationId) {
    $.ajax({
        url: SITE_URL + 'ajax/get_reparation_details.php',
        method: 'GET',
        data: { id: reparationId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayReparationDetails(response.reparation);
                $('#reparationModal').fadeIn();
            } else {
                showToast('Erreur lors du chargement des détails', 'error');
            }
        },
        error: function() {
            showToast('Erreur lors du chargement des détails', 'error');
        }
    });
}

function displayAnomalyDetails(anomaly) {
    const details = $(`
        <div class="anomaly-details-full">
            <div class="detail-section">
                <h4>Informations de l'anomalie</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Type :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(anomaly.type)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Niveau :</label>
                        <span class="badge badge-${anomaly.niveau === 'critique' ? 'danger' : (anomaly.niveau === 'moyen' ? 'warning' : 'info')}">${SmartAutoTrack.utils.escapeHtml(anomaly.niveau)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Statut :</label>
                        <span class="badge badge-${anomaly.statut === 'resolue' ? 'success' : (anomaly.statut === 'en_cours' ? 'warning' : 'primary')}">${SmartAutoTrack.utils.escapeHtml(anomaly.statut)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Date de détection :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(anomaly.date_detection)}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Description</h4>
                <p>${SmartAutoTrack.utils.escapeHtml(anomaly.description)}</p>
            </div>
        </div>
    `);
    
    $('#anomalyDetails').html(details);
}

function displayReparationDetails(reparation) {
    const details = $(`
        <div class="reparation-details-full">
            <div class="detail-section">
                <h4>Informations de la réparation</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Titre :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.titre || 'Réparation #' + reparation.id)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Statut :</label>
                        <span class="badge badge-${reparation.statut === 'validee' ? 'success' : (reparation.statut === 'terminee' ? 'info' : (reparation.statut === 'en_cours' ? 'warning' : 'primary'))}">${SmartAutoTrack.utils.escapeHtml(reparation.statut)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Coût :</label>
                        <span>${Math.round(parseFloat(reparation.cout)).toLocaleString('fr-FR')} XAF</span>
                    </div>
                    <div class="detail-item">
                        <label>Date :</label>
                        <span>${SmartAutoTrack.utils.escapeHtml(reparation.created_at)}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4>Description</h4>
                <p>${SmartAutoTrack.utils.escapeHtml(reparation.description)}</p>
            </div>
        </div>
    `);
    
    $('#reparationDetails').html(details);
}
</script>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
