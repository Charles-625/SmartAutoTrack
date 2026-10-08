<?php
/**
 * Échéances d'entretien et rappels automatiques des véhicules des clients.
 *
 * Cinq échéances par véhicule, toujours calculées par l'APPLICATION avec des
 * règles fixes (jamais par l'assistant IA, qui reçoit le résultat déjà
 * calculé pour le rappeler et répondre) :
 *   ASSURANCE         -> date d'expiration saisie (vehicule.dateExpirationAssurance),
 *                        sans calcul : les contrats durent 3, 6 ou 12 mois au Cameroun ;
 *   VISITE_TECHNIQUE  -> date de la prochaine visite saisie
 *                        (vehicule.dateProchaineVisiteTechnique) ;
 *   VIDANGE, FREINS,  -> calculées à partir du DERNIER entretien enregistré
 *   PNEUS                (table `entretien`) et de la règle du type (table
 *                        `entretien_regle`, modifiable par l'admin) : la
 *                        première limite atteinte, en mois ou en kilomètres.
 *
 * Règles par défaut (MAINTENANCE_DEFAULT_RULES), adaptées aux conditions
 * sévères du Cameroun (chaleur, poussière, routes dégradées) : vidange tous
 * les 5 000 km ou 6 mois, contrôle des freins tous les 10 000 km ou 6 mois,
 * remplacement des pneus tous les 40 000 km ou 24 mois.
 *
 * Statut d'une échéance :
 *   overdue  -> « En retard » : date dépassée ou kilométrage atteint ;
 *   soon     -> « Bientôt »   : dans MAINTENANCE_SOON_DAYS jours au plus ou
 *               à MAINTENANCE_SOON_KM km au plus ;
 *   ok       -> « À jour » ;
 *   unknown  -> « Inconnue »  : aucune donnée (le client est invité à la renseigner).
 *
 * Les derniers entretiens viennent de la clôture d'une réparation (source
 * REPARATION : cases cochées par le technicien ou le garage, kilométrage
 * relevé du rapport) ou d'une déclaration du client (source CLIENT :
 * historique antérieur à la plateforme).
 *
 * Tous les clients voient leurs échéances. Les rappels automatiques
 * (maintenanceSendReminders(), lancé chaque jour par scripts/send_reminders.php)
 * sont réservés aux clients Premium : notification aux paliers J30, J7, J0 et
 * RETARD, email Brevo aux paliers J7 et RETARD. Chaque rappel n'est envoyé
 * qu'une fois par véhicule, type, échéance, palier et canal (table `rappel_envoye`).
 *
 * Dates : PHP et MySQL n'ont pas le même fuseau horaire sur ce serveur ;
 * « aujourd'hui » est toujours calculé en PHP (date('Y-m-d')) et passé en
 * paramètre (pas de CURDATE() / NOW() SQL).
 *
 * Tables : vehicule (colonnes dateExpirationAssurance et
 * dateProchaineVisiteTechnique), entretien, entretien_regle, rappel_envoye
 * (lecture et écriture), client, utilisateur (lecture), notifications
 * (écriture). Créées par scripts/migrate_structure.php (section 8) ; tant
 * qu'elles manquent, maintenanceReady() renvoie false et le site se comporte
 * comme avant (aucune échéance affichée, aucun rappel).
 */

require_once __DIR__ . '/subscription.php';
require_once __DIR__ . '/mailer.php';

/** Types d'entretien calculés à partir du dernier entretien (liste blanche) : valeur stockée => libellé. */
const MAINTENANCE_SERVICE_TYPES = [
    'VIDANGE' => 'Vidange',
    'FREINS' => 'Contrôle des freins',
    'PNEUS' => 'Remplacement des pneus',
];

/** Échéances saisies directement sur le véhicule : type => libellé. */
const MAINTENANCE_DATE_TYPES = [
    'ASSURANCE' => 'Assurance',
    'VISITE_TECHNIQUE' => 'Visite technique',
];

/** Toutes les échéances d'un véhicule, dans l'ordre d'affichage à statut égal : type => libellé. */
const MAINTENANCE_TYPES = MAINTENANCE_DATE_TYPES + MAINTENANCE_SERVICE_TYPES;

/** Libellés des statuts d'échéance. */
const MAINTENANCE_STATUS_LABELS = [
    'overdue' => 'En retard',
    'soon' => 'Bientôt',
    'ok' => 'À jour',
    'unknown' => 'Inconnue',
];

/** Ordre de tri des statuts : les plus urgents d'abord. */
const MAINTENANCE_STATUS_ORDER = ['overdue' => 0, 'soon' => 1, 'ok' => 2, 'unknown' => 3];

/** Règles par défaut (conditions sévères du Cameroun), utilisées tant que l'admin ne les a pas modifiées. */
const MAINTENANCE_DEFAULT_RULES = [
    'VIDANGE' => ['intervalleMois' => 6, 'intervalleKm' => 5000, 'actif' => true],
    'FREINS' => ['intervalleMois' => 6, 'intervalleKm' => 10000, 'actif' => true],
    'PNEUS' => ['intervalleMois' => 24, 'intervalleKm' => 40000, 'actif' => true],
];

/** Une échéance est « bientôt » à MAINTENANCE_SOON_DAYS jours ou moins... */
const MAINTENANCE_SOON_DAYS = 30;
/** ... ou à MAINTENANCE_SOON_KM km ou moins. */
const MAINTENANCE_SOON_KM = 1000;

/** Bornes des règles modifiables par l'admin. */
const MAINTENANCE_RULE_MIN_MONTHS = 1;
const MAINTENANCE_RULE_MAX_MONTHS = 60;
const MAINTENANCE_RULE_MIN_KM = 500;
const MAINTENANCE_RULE_MAX_KM = 200000;

/** Kilométrage maximal accepté pour un entretien (reste dans un INT MySQL, comme REPAIR_MAX_KILOMETRAGE). */
const MAINTENANCE_MAX_KM = 9999999;

/** Origines d'un entretien enregistré (liste blanche de entretien.source). */
const MAINTENANCE_SOURCES = ['REPARATION', 'CLIENT'];

/** Paliers de rappel envoyés par email (les notifications couvrent tous les paliers). */
const MAINTENANCE_EMAIL_LEVELS = ['J7', 'RETARD'];

/**
 * Les tables `entretien`, `entretien_regle`, `rappel_envoye` et les deux
 * colonnes de date de `vehicule` existent (scripts/migrate_structure.php,
 * section 8, appliqué).
 *
 * @param PDO $conn Connexion à la base.
 * @return bool
 */
function maintenanceReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        $tables = (int)$conn->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('entretien', 'entretien_regle', 'rappel_envoye')
        ")->fetchColumn();
        $columns = (int)$conn->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule'
              AND COLUMN_NAME IN ('dateExpirationAssurance', 'dateProchaineVisiteTechnique')
        ")->fetchColumn();
        $ready = $tables === 3 && $columns === 2;
    }
    return $ready;
}

/**
 * Indique si une chaîne est une date réelle au format AAAA-MM-JJ (refuse
 * 2026-02-30, 2026-2-3 ou une date suivie d'une heure).
 *
 * @param string $date Date à contrôler.
 * @return bool
 */
function maintenanceValidDate(string $date): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/**
 * Ajoute des mois calendaires à une date, ramenée au dernier jour du mois
 * d'arrivée (31 août + 6 mois = 28 ou 29 février), là où
 * DateTime::modify('+6 months') déborderait sur mars. Même calcul que
 * subscriptionPeriodEnd().
 *
 * @param string $date   Date de départ AAAA-MM-JJ.
 * @param int    $months Nombre de mois à ajouter.
 * @return string Date d'arrivée AAAA-MM-JJ.
 */
function maintenanceAddMonths(string $date, int $months): string {
    $start = new DateTimeImmutable($date);
    $total = (int)$start->format('Y') * 12 + (int)$start->format('n') - 1 + $months;
    $year = intdiv($total, 12);
    $month = $total % 12 + 1;
    $lastDay = (int)$start->setDate($year, $month, 1)->format('t');
    return $start->setDate($year, $month, min((int)$start->format('j'), $lastDay))->format('Y-m-d');
}

/**
 * Nombre de jours (signé) entre aujourd'hui et une date : positif dans le
 * futur, 0 aujourd'hui, négatif si la date est passée.
 *
 * @param string $today Date du jour AAAA-MM-JJ (calculée en PHP).
 * @param string $date  Date cible AAAA-MM-JJ.
 * @return int
 */
function maintenanceDaysBetween(string $today, string $date): int {
    $diff = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($date));
    return $diff->invert ? -(int)$diff->days : (int)$diff->days;
}

/**
 * Statut d'une échéance d'après les jours et les kilomètres restants (le
 * premier seuil atteint l'emporte). FONCTION PURE.
 *
 * @param int|null $daysLeft Jours restants (null : pas de date).
 * @param int|null $kmLeft   Kilomètres restants (null : pas de limite connue).
 * @return string 'overdue', 'soon' ou 'ok'.
 */
function maintenanceStatusFromLeft(?int $daysLeft, ?int $kmLeft): string {
    if (($daysLeft !== null && $daysLeft < 0) || ($kmLeft !== null && $kmLeft <= 0)) {
        return 'overdue';
    }
    if (($daysLeft !== null && $daysLeft <= MAINTENANCE_SOON_DAYS) || ($kmLeft !== null && $kmLeft <= MAINTENANCE_SOON_KM)) {
        return 'soon';
    }
    return 'ok';
}

/**
 * Prochaine échéance d'un entretien (vidange, freins, pneus) à partir du
 * dernier entretien : dernière date + intervalleMois, et dernier kilométrage
 * + intervalleKm quand les deux sont connus. Le statut retient la première
 * limite atteinte. FONCTION PURE.
 *
 * Sans dernier entretien (date null) : statut 'unknown'. Sans kilométrage au
 * dernier entretien : seule la date compte. Sans kilométrage actuel : dueKm
 * est calculé mais kmLeft reste null et seule la date compte.
 *
 * @param array       $rule      Règle : ['intervalleMois' => int, 'intervalleKm' => ?int].
 * @param string|null $lastDate  Date du dernier entretien AAAA-MM-JJ.
 * @param int|null    $lastKm    Kilométrage au dernier entretien.
 * @param int|null    $currentKm Kilométrage actuel du véhicule.
 * @param string      $today     Date du jour AAAA-MM-JJ (calculée en PHP).
 * @return array{dueDate: ?string, dueKm: ?int, status: string, daysLeft: ?int, kmLeft: ?int}
 *         status : 'ok', 'soon', 'overdue' ou 'unknown'.
 */
function maintenanceNextDue(array $rule, ?string $lastDate, ?int $lastKm, ?int $currentKm, string $today): array {
    if ($lastDate === null || $lastDate === '') {
        return ['dueDate' => null, 'dueKm' => null, 'status' => 'unknown', 'daysLeft' => null, 'kmLeft' => null];
    }
    $dueDate = maintenanceAddMonths($lastDate, (int)$rule['intervalleMois']);
    $daysLeft = maintenanceDaysBetween($today, $dueDate);
    $intervalKm = $rule['intervalleKm'] ?? null;
    $dueKm = ($lastKm !== null && $intervalKm !== null) ? $lastKm + (int)$intervalKm : null;
    $kmLeft = ($dueKm !== null && $currentKm !== null) ? $dueKm - $currentKm : null;
    return [
        'dueDate' => $dueDate,
        'dueKm' => $dueKm,
        'status' => maintenanceStatusFromLeft($daysLeft, $kmLeft),
        'daysLeft' => $daysLeft,
        'kmLeft' => $kmLeft,
    ];
}

/**
 * Statut d'une échéance saisie directement (assurance, visite technique) :
 * même forme que maintenanceNextDue(), sans kilométrage. FONCTION PURE.
 *
 * @param string|null $dueDate Date d'échéance AAAA-MM-JJ (null : non renseignée).
 * @param string      $today   Date du jour AAAA-MM-JJ (calculée en PHP).
 * @return array{dueDate: ?string, dueKm: null, status: string, daysLeft: ?int, kmLeft: null}
 */
function maintenanceDateStatus(?string $dueDate, string $today): array {
    if ($dueDate === null || $dueDate === '') {
        return ['dueDate' => null, 'dueKm' => null, 'status' => 'unknown', 'daysLeft' => null, 'kmLeft' => null];
    }
    $daysLeft = maintenanceDaysBetween($today, $dueDate);
    return [
        'dueDate' => $dueDate,
        'dueKm' => null,
        'status' => maintenanceStatusFromLeft($daysLeft, null),
        'daysLeft' => $daysLeft,
        'kmLeft' => null,
    ];
}

/**
 * Palier de rappel dû pour une échéance. FONCTION PURE.
 *
 *   overdue                        -> 'RETARD'
 *   soon, daysLeft = 0             -> 'J0'
 *   soon, daysLeft de 1 à 7        -> 'J7'
 *   soon, daysLeft de 8 à 30, ou
 *   « bientôt » par le kilométrage
 *   seul (date lointaine ou absente) -> 'J30'
 *   ok, unknown                    -> null
 *
 * Appelé chaque jour, il renvoie le même palier plusieurs jours de suite :
 * c'est `rappel_envoye` qui garantit un seul envoi par palier.
 *
 * @param string   $status   Statut de l'échéance ('ok', 'soon', 'overdue', 'unknown').
 * @param int|null $daysLeft Jours restants avant l'échéance.
 * @return string|null 'J30', 'J7', 'J0', 'RETARD' ou null (aucun rappel).
 */
function maintenanceReminderLevel(string $status, ?int $daysLeft): ?string {
    if ($status === 'overdue') {
        return 'RETARD';
    }
    if ($status !== 'soon') {
        return null;
    }
    if ($daysLeft === null || $daysLeft > 7) {
        return 'J30';
    }
    if ($daysLeft < 0) {
        return 'RETARD';
    }
    return $daysLeft === 0 ? 'J0' : 'J7';
}

/**
 * Contrôle une règle d'entretien avant enregistrement. FONCTION PURE.
 *
 * @param string   $type Type d'entretien (clé de MAINTENANCE_SERVICE_TYPES).
 * @param int      $mois Intervalle en mois (MAINTENANCE_RULE_MIN_MONTHS à MAINTENANCE_RULE_MAX_MONTHS).
 * @param int|null $km   Intervalle en km (MAINTENANCE_RULE_MIN_KM à MAINTENANCE_RULE_MAX_KM), ou null (date seule).
 * @return string|null Message d'erreur en français, ou null si la règle est valide.
 */
function maintenanceRuleError(string $type, int $mois, ?int $km): ?string {
    if (!isset(MAINTENANCE_SERVICE_TYPES[$type])) {
        return "Type d'entretien inconnu.";
    }
    if ($mois < MAINTENANCE_RULE_MIN_MONTHS || $mois > MAINTENANCE_RULE_MAX_MONTHS) {
        return 'L\'intervalle doit être compris entre ' . MAINTENANCE_RULE_MIN_MONTHS . ' et ' . MAINTENANCE_RULE_MAX_MONTHS . ' mois.';
    }
    if ($km !== null && ($km < MAINTENANCE_RULE_MIN_KM || $km > MAINTENANCE_RULE_MAX_KM)) {
        return 'L\'intervalle doit être compris entre ' . number_format(MAINTENANCE_RULE_MIN_KM, 0, ',', ' ')
            . ' et ' . number_format(MAINTENANCE_RULE_MAX_KM, 0, ',', ' ') . ' km (ou laissé vide).';
    }
    return null;
}

/**
 * Règles d'entretien en vigueur : celles de `entretien_regle`, complétées
 * par MAINTENANCE_DEFAULT_RULES pour un type absent (ou toutes, tant que la
 * migration n'est pas appliquée).
 *
 * @param PDO $conn Connexion à la base.
 * @return array<string, array{intervalleMois: int, intervalleKm: ?int, actif: bool}> Indexé par type, dans l'ordre de MAINTENANCE_SERVICE_TYPES.
 */
function maintenanceRules(PDO $conn): array {
    $rules = MAINTENANCE_DEFAULT_RULES;
    if (!maintenanceReady($conn)) {
        return $rules;
    }
    foreach ($conn->query('SELECT type, intervalleMois, intervalleKm, actif FROM entretien_regle') as $row) {
        if (!isset($rules[$row['type']])) {
            continue;
        }
        $rules[$row['type']] = [
            'intervalleMois' => (int)$row['intervalleMois'],
            'intervalleKm' => $row['intervalleKm'] === null ? null : (int)$row['intervalleKm'],
            'actif' => (bool)$row['actif'],
        ];
    }
    return $rules;
}

/**
 * Enregistre (crée ou remplace) la règle d'un type d'entretien. Réservé à
 * l'admin : le contrôle du rôle est fait par la page appelante.
 *
 * @param PDO      $conn  Connexion à la base.
 * @param string   $type  Type d'entretien (clé de MAINTENANCE_SERVICE_TYPES).
 * @param int      $mois  Intervalle en mois.
 * @param int|null $km    Intervalle en km, ou null (date seule).
 * @param bool     $actif Règle appliquée (false : échéance masquée, aucun rappel).
 * @return void
 * @throws InvalidArgumentException règle invalide (message affichable, voir maintenanceRuleError()).
 * @throws RuntimeException         migration non appliquée.
 */
function maintenanceSaveRule(PDO $conn, string $type, int $mois, ?int $km, bool $actif): void {
    $error = maintenanceRuleError($type, $mois, $km);
    if ($error !== null) {
        throw new InvalidArgumentException($error);
    }
    if (!maintenanceReady($conn)) {
        throw new RuntimeException('Échéances indisponibles : scripts/migrate_structure.php n\'est pas appliqué.');
    }
    $conn->prepare("
        INSERT INTO entretien_regle (type, intervalleMois, intervalleKm, actif) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE intervalleMois = VALUES(intervalleMois), intervalleKm = VALUES(intervalleKm), actif = VALUES(actif)
    ")->execute([$type, $mois, $km, $actif ? 1 : 0]);
}

/**
 * Échéances d'un véhicule à partir de données déjà lues. FONCTION PURE,
 * partagée par maintenanceVehicleSchedule() et maintenanceClientSchedule().
 *
 * Les types dont la règle est inactive sont omis. Tri : en retard, bientôt,
 * à jour, inconnue ; à statut égal, l'échéance la plus proche d'abord, puis
 * l'ordre de MAINTENANCE_TYPES.
 *
 * @param array  $vehicle    Ligne vehicule : kilometrage, dateExpirationAssurance, dateProchaineVisiteTechnique.
 * @param array  $lastByType Dernier entretien par type : type => ['dateEntretien' => string, 'kilometrage' => ?int].
 * @param array  $rules      Règles (maintenanceRules()).
 * @param string $today      Date du jour AAAA-MM-JJ (calculée en PHP).
 * @return array<int, array{type: string, libelle: string, lastDate: ?string, lastKm: ?int,
 *         dueDate: ?string, dueKm: ?int, status: string, daysLeft: ?int, kmLeft: ?int}>
 */
function maintenanceBuildSchedule(array $vehicle, array $lastByType, array $rules, string $today): array {
    $currentKm = isset($vehicle['kilometrage']) ? (int)$vehicle['kilometrage'] : null;
    $items = [];
    $dateColumns = ['ASSURANCE' => 'dateExpirationAssurance', 'VISITE_TECHNIQUE' => 'dateProchaineVisiteTechnique'];
    foreach ($dateColumns as $type => $column) {
        $due = $vehicle[$column] ?? null;
        $items[] = ['type' => $type, 'libelle' => MAINTENANCE_TYPES[$type], 'lastDate' => null, 'lastKm' => null]
            + maintenanceDateStatus($due !== null ? (string)$due : null, $today);
    }
    foreach (MAINTENANCE_SERVICE_TYPES as $type => $label) {
        $rule = $rules[$type] ?? MAINTENANCE_DEFAULT_RULES[$type];
        if (empty($rule['actif'])) {
            continue;
        }
        $last = $lastByType[$type] ?? null;
        $lastDate = $last ? (string)$last['dateEntretien'] : null;
        $lastKm = ($last && $last['kilometrage'] !== null) ? (int)$last['kilometrage'] : null;
        $items[] = ['type' => $type, 'libelle' => $label, 'lastDate' => $lastDate, 'lastKm' => $lastKm]
            + maintenanceNextDue($rule, $lastDate, $lastKm, $currentKm, $today);
    }
    $typeOrder = array_flip(array_keys(MAINTENANCE_TYPES));
    usort($items, function (array $a, array $b) use ($typeOrder): int {
        return [MAINTENANCE_STATUS_ORDER[$a['status']], $a['daysLeft'] ?? PHP_INT_MAX, $typeOrder[$a['type']]]
            <=> [MAINTENANCE_STATUS_ORDER[$b['status']], $b['daysLeft'] ?? PHP_INT_MAX, $typeOrder[$b['type']]];
    });
    return $items;
}

/**
 * Dernier entretien de chaque type pour une liste de véhicules (le plus
 * récent par date, puis par ordre d'enregistrement).
 *
 * @param PDO   $conn       Connexion à la base.
 * @param int[] $vehicleIds Identifiants des véhicules.
 * @return array<int, array<string, array{dateEntretien: string, kilometrage: ?int}>> idVehicule => type => dernier entretien.
 */
function maintenanceLastServices(PDO $conn, array $vehicleIds): array {
    $vehicleIds = array_values(array_unique(array_map('intval', $vehicleIds)));
    if (!$vehicleIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($vehicleIds), '?'));
    $stmt = $conn->prepare("
        SELECT idVehicule, type, dateEntretien, kilometrage FROM entretien
        WHERE idVehicule IN ($in)
        ORDER BY dateEntretien DESC, idEntretien DESC
    ");
    $stmt->execute($vehicleIds);
    $last = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['idVehicule'];
        if (!isset($last[$id][$row['type']])) {
            $last[$id][$row['type']] = [
                'dateEntretien' => (string)$row['dateEntretien'],
                'kilometrage' => $row['kilometrage'] === null ? null : (int)$row['kilometrage'],
            ];
        }
    }
    return $last;
}

/**
 * Échéances d'un véhicule, les plus urgentes d'abord (voir
 * maintenanceBuildSchedule()). Le contrôle de propriété du véhicule est fait
 * par la page appelante.
 *
 * @param PDO    $conn       Connexion à la base.
 * @param int    $idVehicule Véhicule.
 * @param string $today      Date du jour AAAA-MM-JJ (calculée en PHP).
 * @return array Liste des échéances ; vide si le véhicule n'existe pas ou si la migration n'est pas appliquée.
 */
function maintenanceVehicleSchedule(PDO $conn, int $idVehicule, string $today): array {
    if (!maintenanceReady($conn)) {
        return [];
    }
    $stmt = $conn->prepare('SELECT idVehicule, kilometrage, dateExpirationAssurance, dateProchaineVisiteTechnique FROM vehicule WHERE idVehicule = ?');
    $stmt->execute([$idVehicule]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vehicle) {
        return [];
    }
    $last = maintenanceLastServices($conn, [$idVehicule]);
    return maintenanceBuildSchedule($vehicle, $last[$idVehicule] ?? [], maintenanceRules($conn), $today);
}

/**
 * Échéances de tous les véhicules d'un client (trois requêtes au total,
 * quel que soit le nombre de véhicules).
 *
 * @param PDO    $conn     Connexion à la base.
 * @param int    $idClient Client (client.idClient = utilisateur.idUtilisateur).
 * @param string $today    Date du jour AAAA-MM-JJ (calculée en PHP).
 * @return array<int, array{idVehicule: int, marque: string, modele: string, immatriculation: string,
 *         kilometrage: ?int, echeances: array}> Un élément par véhicule (ordre d'enregistrement),
 *         echeances au format de maintenanceVehicleSchedule() ; vide si la migration n'est pas appliquée.
 */
function maintenanceClientSchedule(PDO $conn, int $idClient, string $today): array {
    if (!maintenanceReady($conn)) {
        return [];
    }
    $stmt = $conn->prepare('
        SELECT idVehicule, marque, modele, immatriculation, kilometrage, dateExpirationAssurance, dateProchaineVisiteTechnique
        FROM vehicule WHERE idClient = ? ORDER BY idVehicule
    ');
    $stmt->execute([$idClient]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$vehicles) {
        return [];
    }
    $last = maintenanceLastServices($conn, array_column($vehicles, 'idVehicule'));
    $rules = maintenanceRules($conn);
    $result = [];
    foreach ($vehicles as $v) {
        $id = (int)$v['idVehicule'];
        $result[] = [
            'idVehicule' => $id,
            'marque' => (string)$v['marque'],
            'modele' => (string)$v['modele'],
            'immatriculation' => (string)$v['immatriculation'],
            'kilometrage' => $v['kilometrage'] === null ? null : (int)$v['kilometrage'],
            'echeances' => maintenanceBuildSchedule($v, $last[$id] ?? [], $rules, $today),
        ];
    }
    return $result;
}

/**
 * Enregistre un entretien effectué (vidange, freins, pneus). Ne gère pas de
 * transaction : appelé depuis repairReportClose(), il s'inscrit dans la
 * sienne. Le contrôle de propriété du véhicule et maintenanceReady() sont à
 * la charge de l'appelant.
 *
 * @param PDO      $conn         Connexion à la base.
 * @param int      $idVehicule   Véhicule.
 * @param string   $type         Clé de MAINTENANCE_SERVICE_TYPES.
 * @param string   $date         Date de l'entretien AAAA-MM-JJ, au plus aujourd'hui (date PHP).
 * @param int|null $km           Kilométrage relevé (0 à MAINTENANCE_MAX_KM), ou null.
 * @param string   $source       'REPARATION' (clôture d'une réparation) ou 'CLIENT' (déclaration).
 * @param int|null $idReparation Réparation d'origine (source REPARATION).
 * @return void
 * @throws InvalidArgumentException donnée invalide (message affichable).
 */
function maintenanceRecord(PDO $conn, int $idVehicule, string $type, string $date, ?int $km, string $source, ?int $idReparation = null): void {
    if (!isset(MAINTENANCE_SERVICE_TYPES[$type])) {
        throw new InvalidArgumentException("Type d'entretien inconnu.");
    }
    if (!in_array($source, MAINTENANCE_SOURCES, true)) {
        throw new InvalidArgumentException('Origine de l\'entretien inconnue.');
    }
    if (!maintenanceValidDate($date)) {
        throw new InvalidArgumentException('La date de l\'entretien est invalide.');
    }
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('La date de l\'entretien ne peut pas être dans le futur.');
    }
    if ($km !== null && ($km < 0 || $km > MAINTENANCE_MAX_KM)) {
        throw new InvalidArgumentException('Le kilométrage de l\'entretien est invalide.');
    }
    $conn->prepare('
        INSERT INTO entretien (idVehicule, type, dateEntretien, kilometrage, idReparation, source, dateCreation)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$idVehicule, $type, $date, $km, $idReparation, $source, date('Y-m-d H:i:s')]);
}

/**
 * Enregistre les échéances saisies sur un véhicule (null efface la date).
 * Le contrôle de propriété du véhicule et maintenanceReady() sont à la
 * charge de l'appelant.
 *
 * @param PDO         $conn       Connexion à la base.
 * @param int         $idVehicule Véhicule.
 * @param string|null $assurance  Date d'expiration de l'assurance AAAA-MM-JJ, ou null.
 * @param string|null $visite     Date de la prochaine visite technique AAAA-MM-JJ, ou null.
 * @return void
 * @throws InvalidArgumentException date invalide (message affichable).
 */
function maintenanceSetVehicleDates(PDO $conn, int $idVehicule, ?string $assurance, ?string $visite): void {
    $assurance = ($assurance === null || trim($assurance) === '') ? null : trim($assurance);
    $visite = ($visite === null || trim($visite) === '') ? null : trim($visite);
    if ($assurance !== null && !maintenanceValidDate($assurance)) {
        throw new InvalidArgumentException('La date d\'expiration de l\'assurance est invalide.');
    }
    if ($visite !== null && !maintenanceValidDate($visite)) {
        throw new InvalidArgumentException('La date de la prochaine visite technique est invalide.');
    }
    $conn->prepare('UPDATE vehicule SET dateExpirationAssurance = ?, dateProchaineVisiteTechnique = ? WHERE idVehicule = ?')
        ->execute([$assurance, $visite, $idVehicule]);
}

/**
 * Formate une date AAAA-MM-JJ en JJ/MM/AAAA.
 *
 * @param string $date Date AAAA-MM-JJ.
 * @return string
 */
function maintenanceFormatDate(string $date): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d ? $d->format('d/m/Y') : $date;
}

/**
 * Formate un kilométrage (« 85 000 km »).
 *
 * @param int $km Kilométrage.
 * @return string
 */
function maintenanceFormatKm(int $km): string {
    return number_format($km, 0, ',', ' ') . ' km';
}

/**
 * Phrase en français décrivant une échéance (texte brut, à échapper avec h()
 * à l'affichage) : utilisée par les rappels et pour le contexte de
 * l'assistant IA. FONCTION PURE.
 *
 * Ex. « Vidange : en retard — échéance le 01/10/2026 ou à 85 000 km
 * (dépassée de 6 jours ; 1 200 km au-delà) ; dernier entretien le
 * 01/04/2026 à 80 000 km. »
 *
 * @param array $item Échéance (élément de maintenanceVehicleSchedule()).
 * @return string
 */
function maintenanceDescribe(array $item): string {
    $label = $item['libelle'] ?? (MAINTENANCE_TYPES[$item['type']] ?? $item['type']);
    if ($item['status'] === 'unknown') {
        return $label . ' : inconnue — '
            . (isset(MAINTENANCE_DATE_TYPES[$item['type']]) ? 'date non renseignée.' : 'aucun entretien enregistré.');
    }
    $text = $label . ' : ' . mb_strtolower(MAINTENANCE_STATUS_LABELS[$item['status']]) . ' — échéance le ' . maintenanceFormatDate((string)$item['dueDate']);
    if ($item['dueKm'] !== null) {
        $text .= ' ou à ' . maintenanceFormatKm((int)$item['dueKm']);
    }
    $details = [];
    $days = $item['daysLeft'];
    if ($days !== null) {
        $details[] = $days > 0 ? 'dans ' . $days . ' jour' . ($days > 1 ? 's' : '')
            : ($days === 0 ? "aujourd'hui" : 'dépassée de ' . -$days . ' jour' . ($days < -1 ? 's' : ''));
    }
    if ($item['kmLeft'] !== null) {
        $details[] = $item['kmLeft'] > 0 ? 'reste ' . maintenanceFormatKm((int)$item['kmLeft'])
            : maintenanceFormatKm(-(int)$item['kmLeft']) . ' au-delà';
    }
    if ($details) {
        $text .= ' (' . implode(' ; ', $details) . ')';
    }
    if (!empty($item['lastDate'])) {
        $text .= ' ; dernier entretien le ' . maintenanceFormatDate((string)$item['lastDate'])
            . ($item['lastKm'] !== null ? ' à ' . maintenanceFormatKm((int)$item['lastKm']) : '');
    }
    return $text . '.';
}

/**
 * Réserve un rappel dans `rappel_envoye` AVANT de l'envoyer : la clé
 * primaire (véhicule, type, échéance, palier, canal) garantit qu'un rappel
 * n'est jamais envoyé deux fois, même si deux exécutions se chevauchent.
 *
 * @param PDO    $conn       Connexion à la base.
 * @param int    $idVehicule Véhicule.
 * @param string $type       Type d'échéance (clé de MAINTENANCE_TYPES).
 * @param string $echeance   Date d'échéance AAAA-MM-JJ.
 * @param string $palier     'J30', 'J7', 'J0' ou 'RETARD'.
 * @param string $canal      'NOTIF' ou 'EMAIL'.
 * @return bool true si le rappel vient d'être réservé (à envoyer), false s'il l'était déjà.
 */
function maintenanceClaimReminder(PDO $conn, int $idVehicule, string $type, string $echeance, string $palier, string $canal): bool {
    $stmt = $conn->prepare('
        INSERT IGNORE INTO rappel_envoye (idVehicule, type, echeance, palier, canal, dateEnvoi)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$idVehicule, $type, $echeance, $palier, $canal, date('Y-m-d H:i:s')]);
    return $stmt->rowCount() === 1;
}

/**
 * Désignation lisible d'un véhicule (« Toyota Corolla (LT 123 AB) »). Les
 * valeurs stockées sont déjà encodées par sanitize() : décodées ici pour un
 * texte brut.
 *
 * @param array $vehicle Élément de maintenanceClientSchedule().
 * @return string
 */
function maintenanceVehicleName(array $vehicle): string {
    $name = trim($vehicle['marque'] . ' ' . $vehicle['modele']);
    if ($vehicle['immatriculation'] !== '') {
        $name .= ' (' . $vehicle['immatriculation'] . ')';
    }
    return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Email récapitulatif des rappels d'un client (un seul email par client et
 * par exécution, quel que soit le nombre d'échéances).
 *
 * @param string   $name  Nom du client (texte brut).
 * @param string[] $lines Lignes « véhicule : échéance » (texte brut).
 * @return array{html: string, text: string}
 */
function maintenanceReminderEmail(string $name, array $lines): array {
    $siteName = h(SITE_NAME);
    $items = implode('', array_map(fn($l) => '<li style="margin-bottom:8px">' . h($l) . '</li>', $lines));
    $url = h(SITE_URL . 'client/dashboard.php');
    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Rappel d\'entretien</title></head>'
        . '<body style="margin:0;padding:24px 0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">'
        . '<div style="background:#131B3E;padding:24px 28px;color:#ffffff;font-size:20px;font-weight:bold">' . $siteName . '</div>'
        . '<div style="padding:28px;line-height:1.6">'
        . '<p>Bonjour <strong>' . h($name) . '</strong>,</p>'
        . '<p>Voici les échéances de vos véhicules qui demandent votre attention :</p>'
        . '<ul style="padding-left:20px">' . $items . '</ul>'
        . '<p style="text-align:center;margin:28px 0"><a href="' . $url . '" style="background:#3956E8;color:#ffffff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold">Voir mes échéances</a></p>'
        . '<p style="font-size:13px;color:#6b7280">Vous recevez ce rappel car votre abonnement Premium est actif.</p>'
        . '</div></div></body></html>';
    $text = "Bonjour $name,\n\nVoici les échéances de vos véhicules qui demandent votre attention :\n- "
        . implode("\n- ", $lines) . "\n\n" . SITE_URL . "client/dashboard.php\n";
    return ['html' => $html, 'text' => $text];
}

/**
 * Envoie les rappels d'échéance dus aux clients Premium.
 *
 * Pour chaque client Premium (clientIsPremium()) ayant au moins un véhicule,
 * pour chaque échéance dont maintenanceReminderLevel() donne un palier :
 *   - une notification (type 'intervention', titre « Rappel : <libellé> »),
 *     à tous les paliers ;
 *   - aux paliers J7 et RETARD, une ligne dans un email récapitulatif Brevo,
 *     si le mailer est configuré (BREVO_API_KEY) et le client a une adresse.
 * Chaque envoi est réservé dans `rappel_envoye` AVANT d'être fait
 * (maintenanceClaimReminder()) : un échec d'envoi n'est donc pas retenté, mais
 * aucun rappel n'est jamais envoyé deux fois. Toute erreur est journalisée
 * (error_log) sans interrompre les autres clients.
 *
 * Sans effet tant que maintenanceReady() ou subscriptionsReady() est faux.
 *
 * @param PDO      $conn     Connexion à la base.
 * @param string   $today    Date du jour AAAA-MM-JJ (calculée en PHP).
 * @param int|null $idClient Limiter à un client (null : tous les clients).
 * @return array{ready: bool, clients: int, premium: int, notifications: int, emails: int, alreadySent: int, errors: int}
 *         clients : clients examinés ; premium : clients Premium ; notifications / emails : envois faits ;
 *         alreadySent : rappels déjà envoyés auparavant (ignorés) ; errors : échecs journalisés.
 */
function maintenanceSendReminders(PDO $conn, string $today, ?int $idClient = null): array {
    $counts = ['ready' => false, 'clients' => 0, 'premium' => 0, 'notifications' => 0, 'emails' => 0, 'alreadySent' => 0, 'errors' => 0];
    if (!maintenanceReady($conn) || !subscriptionsReady($conn)) {
        return $counts;
    }
    $counts['ready'] = true;
    $mailerReady = (string)appConfig('BREVO_API_KEY', '') !== '';

    $sql = '
        SELECT c.idClient, u.nom, u.prenom, u.email
        FROM client c
        JOIN utilisateur u ON u.idUtilisateur = c.idClient
        WHERE EXISTS (SELECT 1 FROM vehicule v WHERE v.idClient = c.idClient)
    ';
    $params = [];
    if ($idClient !== null) {
        $sql .= ' AND c.idClient = ?';
        $params[] = $idClient;
    }
    $stmt = $conn->prepare($sql . ' ORDER BY c.idClient');
    $stmt->execute($params);
    $notify = $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', ?, ?)");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
        $counts['clients']++;
        $clientId = (int)$client['idClient'];
        try {
            if (!clientIsPremium($conn, $clientId)) {
                continue;
            }
            $counts['premium']++;
            $emailLines = [];
            foreach (maintenanceClientSchedule($conn, $clientId, $today) as $vehicle) {
                $vehicleName = maintenanceVehicleName($vehicle);
                foreach ($vehicle['echeances'] as $item) {
                    $level = maintenanceReminderLevel($item['status'], $item['daysLeft']);
                    if ($level === null || $item['dueDate'] === null) {
                        continue;
                    }
                    $line = $vehicleName . ' : ' . maintenanceDescribe($item);
                    try {
                        if (maintenanceClaimReminder($conn, $vehicle['idVehicule'], $item['type'], $item['dueDate'], $level, 'NOTIF')) {
                            $notify->execute([$clientId, 'Rappel : ' . $item['libelle'], $line]);
                            $counts['notifications']++;
                        } else {
                            $counts['alreadySent']++;
                        }
                        if ($mailerReady && in_array($level, MAINTENANCE_EMAIL_LEVELS, true) && validateEmail((string)$client['email'])) {
                            if (maintenanceClaimReminder($conn, $vehicle['idVehicule'], $item['type'], $item['dueDate'], $level, 'EMAIL')) {
                                $emailLines[] = $line;
                            } else {
                                $counts['alreadySent']++;
                            }
                        }
                    } catch (Throwable $e) {
                        $counts['errors']++;
                        error_log('[SmartAutoTrack] rappel ' . $item['type'] . ' véhicule ' . $vehicle['idVehicule'] . ' : ' . $e->getMessage());
                    }
                }
            }
            if ($emailLines) {
                $name = html_entity_decode(trim($client['prenom'] . ' ' . $client['nom']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $mail = maintenanceReminderEmail($name, $emailLines);
                $subject = count($emailLines) > 1 ? 'Rappel : ' . count($emailLines) . ' échéances de vos véhicules' : "Rappel : une échéance de votre véhicule";
                $sent = sendBrevoEmail((string)$client['email'], $name, $subject, $mail['html'], $mail['text']);
                if ($sent['success']) {
                    $counts['emails']++;
                } else {
                    $counts['errors']++;
                    error_log('[SmartAutoTrack] email de rappel client ' . $clientId . ' : ' . $sent['error']);
                }
            }
        } catch (Throwable $e) {
            $counts['errors']++;
            error_log('[SmartAutoTrack] rappels client ' . $clientId . ' : ' . $e->getMessage());
        }
    }
    return $counts;
}
