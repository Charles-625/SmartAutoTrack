<?php
/**
 * Abonnement Premium des clients (particuliers et entreprises).
 *
 * Formules :
 *   Gratuit  -> sans ligne en base ; 1 véhicule (particulier) ou 3 (entreprise),
 *               assistant IA limité à SUB_FREE_AI_PER_DAY messages par jour,
 *               historique consultable limité aux SUB_FREE_HISTORY_MONTHS derniers mois
 *   Premium  -> une ligne ACTIF de la table `abonnement` couvrant la date du jour ;
 *               3 véhicules (particulier) ou le nombre choisi (entreprise),
 *               assistant IA et historique sans limite propre à l'abonnement
 *
 * Cycle d'un abonnement (table `abonnement`) :
 *   EN_ATTENTE  -> créé au lancement du paiement Mobile Money (paiement lié par paiement.idAbonnement)
 *   ACTIF       -> essai (ESSAI), mois offert par l'admin (OFFERT) ou paiement confirmé
 *                  (MENSUEL / ANNUEL) ; couvre [dateDebut, dateFin[
 *   ECHOUE      -> le paiement lié a échoué
 *   ANNULE      -> réservé à une annulation manuelle
 *
 * Payer (ou recevoir un mois offert) alors qu'un abonnement est actif
 * prolonge : la nouvelle période commence à la dateFin de l'abonnement actif
 * le plus tardif. Les véhicules déjà enregistrés sont toujours conservés :
 * la limite ne bloque que les nouveaux ajouts.
 *
 * Toutes les dates sont calculées en PHP puis passées en paramètres : PHP et
 * MySQL n'ont pas le même fuseau horaire sur ce serveur (pas de NOW() SQL).
 * Tant que scripts/migrate_structure.php n'est pas appliqué,
 * subscriptionsReady() renvoie false et tout le site se comporte comme avant
 * (aucune limite, aucune lecture des tables d'abonnement).
 */

require_once __DIR__ . '/payments.php';

/** Prix Premium d'un particulier, en XAF. */
const SUB_PRICE_PARTICULIER_MENSUEL = 1000;
const SUB_PRICE_PARTICULIER_ANNUEL = 10000;
/** Nombre de véhicules autorisés (gratuit / Premium). */
const SUB_FREE_VEHICLES_PARTICULIER = 1;
const SUB_PREMIUM_VEHICLES_PARTICULIER = 3;
const SUB_FREE_VEHICLES_ENTREPRISE = 3;
/** Une entreprise Premium choisit au moins ce nombre de véhicules... */
const SUB_ENTREPRISE_MIN_VEHICLES = 4;
/** ... et au plus celui-ci en ligne ; au-delà, l'abonnement se fait sur devis. */
const SUB_ENTREPRISE_MAX_ONLINE = 50;
/** Messages à l'assistant IA par jour pour un client gratuit. */
const SUB_FREE_AI_PER_DAY = 10;
/** Profondeur de l'historique consultable par un client gratuit. */
const SUB_FREE_HISTORY_MONTHS = 12;
/** Durée de l'essai Premium (et du mois offert par l'admin). */
const SUB_TRIAL_DAYS = 30;

/**
 * Prix mensuel d'un véhicule pour une entreprise, selon le palier de la
 * flotte entière : 1–5 -> 2 000, 6–20 -> 1 500, 21–50 -> 1 200 XAF.
 *
 * @return int|null null hors des paliers payables en ligne (< 1 ou > 50 : sur devis)
 */
function subscriptionEntrepriseUnitPrice(int $nbVehicules): ?int {
    if ($nbVehicules < 1 || $nbVehicules > SUB_ENTREPRISE_MAX_ONLINE) {
        return null;
    }
    if ($nbVehicules <= 5) {
        return 2000;
    }
    return $nbVehicules <= 20 ? 1500 : 1200;
}

/**
 * Prix Premium en XAF. Particulier : prix fixe. Entreprise : nombre de
 * véhicules × prix unitaire du palier, l'annuel valant 10 mois (2 offerts).
 *
 * @param string $typeClient  'PARTICULIER' ou 'ENTREPRISE'
 * @param string $periodicite 'MENSUEL' ou 'ANNUEL'
 * @param int    $nbVehicules Véhicules couverts (entreprise seulement)
 * @return int|null null si non payable en ligne (périodicité inconnue,
 *                  entreprise sous SUB_ENTREPRISE_MIN_VEHICLES ou au-delà de SUB_ENTREPRISE_MAX_ONLINE)
 */
function subscriptionPrice(string $typeClient, string $periodicite, int $nbVehicules = 0): ?int {
    if (!in_array($periodicite, ['MENSUEL', 'ANNUEL'], true)) {
        return null;
    }
    if ($typeClient === 'PARTICULIER') {
        return $periodicite === 'MENSUEL' ? SUB_PRICE_PARTICULIER_MENSUEL : SUB_PRICE_PARTICULIER_ANNUEL;
    }
    if ($typeClient !== 'ENTREPRISE' || $nbVehicules < SUB_ENTREPRISE_MIN_VEHICLES) {
        return null;
    }
    $unit = subscriptionEntrepriseUnitPrice($nbVehicules);
    if ($unit === null) {
        return null;
    }
    $monthly = $nbVehicules * $unit;
    return $periodicite === 'MENSUEL' ? $monthly : 10 * $monthly;
}

/**
 * Fin d'une période d'abonnement : ESSAI / OFFERT +SUB_TRIAL_DAYS jours,
 * MENSUEL +1 mois, ANNUEL +12 mois. Les mois sont calendaires et ramenés au
 * dernier jour du mois d'arrivée (31 janvier + 1 mois = 28 ou 29 février),
 * là où DateTime::modify('+1 month') déborderait sur mars.
 *
 * @throws InvalidArgumentException périodicité inconnue
 */
function subscriptionPeriodEnd(DateTimeImmutable $start, string $periodicite): DateTimeImmutable {
    switch ($periodicite) {
        case 'ESSAI':
        case 'OFFERT':
            return $start->modify('+' . SUB_TRIAL_DAYS . ' days');
        case 'MENSUEL':
            $months = 1;
            break;
        case 'ANNUEL':
            $months = 12;
            break;
        default:
            throw new InvalidArgumentException('Périodicité inconnue : ' . $periodicite);
    }
    $total = (int)$start->format('Y') * 12 + (int)$start->format('n') - 1 + $months;
    $year = intdiv($total, 12);
    $month = $total % 12 + 1;
    $lastDay = (int)$start->setDate($year, $month, 1)->format('t');
    return $start->setDate($year, $month, min((int)$start->format('j'), $lastDay));
}

/**
 * Nombre maximal de véhicules d'un client. Particulier : 1 (gratuit) ou 3
 * (Premium). Entreprise : 3 (gratuit) ou le nombre de véhicules de
 * l'abonnement, jamais moins de SUB_ENTREPRISE_MIN_VEHICLES.
 *
 * @param array|null $activeSub Résultat de subscriptionActive(), null pour un client gratuit
 */
function subscriptionVehicleLimit(string $typeClient, ?array $activeSub): int {
    if ($typeClient === 'ENTREPRISE') {
        return $activeSub === null
            ? SUB_FREE_VEHICLES_ENTREPRISE
            : max((int)($activeSub['nbVehicules'] ?? 0), SUB_ENTREPRISE_MIN_VEHICLES);
    }
    return $activeSub === null ? SUB_FREE_VEHICLES_PARTICULIER : SUB_PREMIUM_VEHICLES_PARTICULIER;
}

/** Les tables `abonnement` et `ia_usage` et la colonne paiement.idAbonnement existent (scripts/migrate_structure.php appliqué). */
function subscriptionsReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        $tables = (int)$conn->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('abonnement', 'ia_usage')
        ")->fetchColumn();
        $column = (int)$conn->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paiement' AND COLUMN_NAME = 'idAbonnement'
        ")->fetchColumn();
        $ready = $tables === 2 && $column === 1;
    }
    return $ready;
}

/** Type du client d'après son profil (client.typeClient) : 'ENTREPRISE', sinon 'PARTICULIER'. */
function subscriptionClientType(PDO $conn, int $clientId): string {
    $stmt = $conn->prepare('SELECT typeClient FROM client WHERE idClient = ?');
    $stmt->execute([$clientId]);
    return strtoupper((string)$stmt->fetchColumn()) === 'ENTREPRISE' ? 'ENTREPRISE' : 'PARTICULIER';
}

/** Nombre de véhicules actuellement enregistrés par le client. */
function subscriptionVehicleCount(PDO $conn, int $clientId): int {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM vehicule WHERE idClient = ?');
    $stmt->execute([$clientId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Abonnement ACTIF couvrant l'instant $now (dateDebut <= now < dateFin) ;
 * s'il y en a plusieurs, celui qui finit le plus tard.
 *
 * @param int|null $now Horodatage Unix (maintenant par défaut)
 * @return array|null Ligne de `abonnement`, ou null (client gratuit, ou migration non appliquée)
 */
function subscriptionActive(PDO $conn, int $clientId, ?int $now = null): ?array {
    if (!subscriptionsReady($conn)) {
        return null;
    }
    $at = date('Y-m-d H:i:s', $now ?? time());
    $stmt = $conn->prepare("
        SELECT * FROM abonnement
        WHERE idClient = ? AND statut = 'ACTIF' AND dateDebut <= ? AND dateFin > ?
        ORDER BY dateFin DESC
        LIMIT 1
    ");
    $stmt->execute([$clientId, $at, $at]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Le client a un abonnement Premium actif en ce moment. */
function clientIsPremium(PDO $conn, int $clientId): bool {
    return subscriptionActive($conn, $clientId) !== null;
}

/**
 * Début d'une nouvelle période : maintenant, ou la dateFin de l'abonnement
 * actif le plus tardif s'il finit plus tard (prolongation, y compris d'une
 * période déjà payée qui n'a pas encore commencé).
 */
function subscriptionExtensionStart(PDO $conn, int $clientId, int $now): DateTimeImmutable {
    $nowSql = date('Y-m-d H:i:s', $now);
    $stmt = $conn->prepare("SELECT MAX(dateFin) FROM abonnement WHERE idClient = ? AND statut = 'ACTIF' AND dateFin > ?");
    $stmt->execute([$clientId, $nowSql]);
    $latest = $stmt->fetchColumn();
    return new DateTimeImmutable($latest ? (string)$latest : $nowSql);
}

/**
 * Trace une étape d'abonnement dans le journal d'activité (catégorie
 * « compte », l'ENUM n'ayant pas de valeur propre aux abonnements). Comme
 * paymentLog(), un échec d'écriture du journal est seulement journalisé.
 */
function subscriptionLog(PDO $conn, string $nomActivite, int $userId, ?string $description = null): void {
    try {
        log_activity($conn, $nomActivite, [
            'idUtilisateur' => $userId,
            'description' => $description,
            'categorie' => 'compte',
        ]);
    } catch (Throwable $e) {
        error_log('[SmartAutoTrack] journal abonnement : ' . $e->getMessage());
    }
}

/** Ligne de `abonnement` par son identifiant (relue après insertion). */
function subscriptionFind(PDO $conn, int $abonnementId): ?array {
    $stmt = $conn->prepare('SELECT * FROM abonnement WHERE idAbonnement = ?');
    $stmt->execute([$abonnementId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Le client n'a encore jamais eu d'essai Premium (quel qu'en soit le statut). */
function subscriptionTrialAvailable(PDO $conn, int $clientId): bool {
    if (!subscriptionsReady($conn)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT COUNT(*) FROM abonnement WHERE idClient = ? AND periodicite = 'ESSAI'");
    $stmt->execute([$clientId]);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Active l'essai Premium de SUB_TRIAL_DAYS jours, sans paiement, une seule
 * fois par client. Refusé si un abonnement est déjà actif (l'essai reste
 * alors disponible pour plus tard). Pour une entreprise, l'essai couvre au
 * moins SUB_ENTREPRISE_MIN_VEHICLES véhicules, et tous ceux déjà enregistrés.
 *
 * @return array Ligne de `abonnement` créée
 * @throws RuntimeException message affichable au client
 */
function subscriptionStartTrial(PDO $conn, int $clientId): array {
    if (!subscriptionsReady($conn)) {
        throw new RuntimeException('Les abonnements ne sont pas encore activés sur cette plateforme.');
    }

    // Transaction + FOR UPDATE sur la ligne client : deux clics simultanés
    // ne peuvent pas créer deux essais.
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare('SELECT idClient FROM client WHERE idClient = ? FOR UPDATE');
        $stmt->execute([$clientId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('L\'abonnement Premium est réservé aux clients.');
        }
        if (!subscriptionTrialAvailable($conn, $clientId)) {
            throw new RuntimeException('Vous avez déjà profité de votre mois d\'essai Premium.');
        }
        $now = time();
        if (subscriptionActive($conn, $clientId, $now)) {
            throw new RuntimeException('Vous avez déjà un abonnement Premium actif.');
        }

        $nbVehicules = subscriptionClientType($conn, $clientId) === 'ENTREPRISE'
            ? max(subscriptionVehicleCount($conn, $clientId), SUB_ENTREPRISE_MIN_VEHICLES)
            : null;
        $start = new DateTimeImmutable(date('Y-m-d H:i:s', $now));
        $end = subscriptionPeriodEnd($start, 'ESSAI');
        $conn->prepare("
            INSERT INTO abonnement (idClient, formule, periodicite, nbVehicules, montant, statut, dateCreation, dateDebut, dateFin)
            VALUES (?, 'PREMIUM', 'ESSAI', ?, 0, 'ACTIF', ?, ?, ?)
        ")->execute([$clientId, $nbVehicules, $start->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $abonnementId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    subscriptionLog($conn, 'Essai Premium activé', $clientId, 'Essai de ' . SUB_TRIAL_DAYS . ' jours, jusqu\'au ' . $end->format('d/m/Y'));
    return subscriptionFind($conn, $abonnementId);
}

/**
 * L'admin offre SUB_TRIAL_DAYS jours de Premium (périodicité OFFERT) ; si
 * un abonnement est actif, la période offerte le prolonge. Pour une
 * entreprise, le nombre de véhicules couvert reste celui de l'abonnement
 * actif (au moins les véhicules déjà enregistrés et SUB_ENTREPRISE_MIN_VEHICLES).
 * Le client est notifié.
 *
 * @return array Ligne de `abonnement` créée
 * @throws RuntimeException message affichable à l'admin
 */
function subscriptionGrantFreeMonth(PDO $conn, int $clientId, int $adminId): array {
    if (!subscriptionsReady($conn)) {
        throw new RuntimeException('Les abonnements ne sont pas encore activés sur cette plateforme.');
    }

    // Même verrou que l'essai : deux validations simultanées de l'admin
    // calculent chacune leur début de période après l'autre.
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare('SELECT idClient FROM client WHERE idClient = ? FOR UPDATE');
        $stmt->execute([$clientId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Client introuvable.');
        }
        $now = time();
        $nbVehicules = null;
        if (subscriptionClientType($conn, $clientId) === 'ENTREPRISE') {
            $active = subscriptionActive($conn, $clientId, $now);
            $nbVehicules = max((int)($active['nbVehicules'] ?? 0), subscriptionVehicleCount($conn, $clientId), SUB_ENTREPRISE_MIN_VEHICLES);
        }
        $start = subscriptionExtensionStart($conn, $clientId, $now);
        $end = subscriptionPeriodEnd($start, 'OFFERT');
        $conn->prepare("
            INSERT INTO abonnement (idClient, formule, periodicite, nbVehicules, montant, statut, dateCreation, dateDebut, dateFin, idAdministrateur)
            VALUES (?, 'PREMIUM', 'OFFERT', ?, 0, 'ACTIF', ?, ?, ?, ?)
        ")->execute([$clientId, $nbVehicules, date('Y-m-d H:i:s', $now), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $adminId]);
        $abonnementId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', 'Mois Premium offert', ?)")
        ->execute([$clientId, 'Un mois Premium vous a été offert : votre abonnement Premium est actif jusqu\'au ' . $end->format('d/m/Y') . '.']);
    subscriptionLog($conn, 'Mois Premium offert', $adminId, 'Client #' . $clientId . ', jusqu\'au ' . $end->format('d/m/Y'));
    return subscriptionFind($conn, $abonnementId);
}

/**
 * Lance le paiement Mobile Money d'un abonnement Premium (sur le modèle de
 * paymentStart()) : enregistre l'abonnement et le paiement EN_ATTENTE puis
 * demande à CamPay d'envoyer l'invite de confirmation au téléphone du client.
 * L'abonnement devient ACTIF à la confirmation du paiement
 * (paymentApplyCampayStatus() -> subscriptionActivateFromPayment()).
 *
 * @param string $periodicite 'MENSUEL' ou 'ANNUEL'
 * @param int    $nbVehicules Véhicules couverts (entreprise ; ignoré pour un particulier)
 * @return array{idPaiement:int, montant:int, ussd_code:?string, operator:?string}
 * @throws CampayException message affichable au client
 */
function subscriptionStartPayment(PDO $conn, int $clientId, string $periodicite, int $nbVehicules, string $rawPhone): array {
    if (!paymentsReady($conn) || !subscriptionsReady($conn)) {
        throw new CampayException('Le paiement en ligne des abonnements n\'est pas encore activé sur cette plateforme.');
    }
    if (!in_array($periodicite, ['MENSUEL', 'ANNUEL'], true)) {
        throw new CampayException('Choisissez un abonnement mensuel ou annuel.');
    }
    $phone = campayNormalizePhone($rawPhone);
    if ($phone === null) {
        throw new CampayException('Numéro Mobile Money invalide (exemple : 6XX XX XX XX).');
    }

    // Transaction + FOR UPDATE sur la ligne client : deux clics simultanés ne
    // peuvent pas créer deux paiements d'abonnement EN_ATTENTE.
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare('SELECT idClient FROM client WHERE idClient = ? FOR UPDATE');
        $stmt->execute([$clientId]);
        if (!$stmt->fetchColumn()) {
            throw new CampayException('L\'abonnement Premium est réservé aux clients.');
        }

        $type = subscriptionClientType($conn, $clientId);
        if ($type === 'ENTREPRISE') {
            $minimum = max(subscriptionVehicleCount($conn, $clientId), SUB_ENTREPRISE_MIN_VEHICLES);
            if ($nbVehicules > SUB_ENTREPRISE_MAX_ONLINE) {
                throw new CampayException('Au-delà de ' . SUB_ENTREPRISE_MAX_ONLINE . ' véhicules, l\'abonnement se fait sur devis : contactez-nous.');
            }
            if ($nbVehicules < $minimum) {
                throw new CampayException('Choisissez au moins ' . $minimum . ' véhicules.');
            }
        } else {
            $nbVehicules = null;
        }
        $amount = subscriptionPrice($type, $periodicite, (int)$nbVehicules);
        if ($amount === null) {
            throw new CampayException('Cet abonnement ne peut pas être payé en ligne.');
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM paiement
            WHERE idClient = ? AND idAbonnement IS NOT NULL AND statut = 'EN_ATTENTE' AND datePaiement > ?
        ");
        $stmt->execute([$clientId, date('Y-m-d H:i:s', time() - PAYMENT_PENDING_LOCK_SECONDS)]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new CampayException('Un paiement d\'abonnement est déjà en cours. Validez-le sur votre téléphone ou réessayez dans quelques minutes.');
        }

        $nowSql = date('Y-m-d H:i:s');
        $conn->prepare("
            INSERT INTO abonnement (idClient, formule, periodicite, nbVehicules, montant, statut, dateCreation)
            VALUES (?, 'PREMIUM', ?, ?, ?, 'EN_ATTENTE', ?)
        ")->execute([$clientId, $periodicite, $nbVehicules, $amount, $nowSql]);
        $abonnementId = (int)$conn->lastInsertId();

        $externalReference = 'SAT-ABO-' . $abonnementId . '-' . bin2hex(random_bytes(6));
        $conn->prepare("
            INSERT INTO paiement (idClient, idIntervention, idReparation, idAbonnement, montant, datePaiement, typePaiement, statut, referenceExterne, telephone)
            VALUES (?, NULL, NULL, ?, ?, ?, 'MOBILE_MONEY', 'EN_ATTENTE', ?, ?)
        ")->execute([$clientId, $abonnementId, $amount, $nowSql, $externalReference, $phone]);
        $paiementId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    // L'appel à CamPay se fait hors transaction (réseau lent) ; en cas
    // d'échec, le paiement et l'abonnement déjà créés sont marqués ECHOUE.
    $charged = campayChargedAmount($amount);
    $label = 'SmartAutoTrack - Premium ' . ($periodicite === 'MENSUEL' ? 'mensuel' : 'annuel');
    try {
        $collect = campayCollect($charged, $phone, $label, $externalReference);
    } catch (Throwable $e) {
        $message = $e instanceof CampayException ? $e->getMessage() : 'Le service de paiement est injoignable. Réessayez plus tard.';
        if (!$e instanceof CampayException) {
            error_log('[SmartAutoTrack] CamPay collect (abonnement) : ' . $e->getMessage());
        }
        $conn->prepare("UPDATE paiement SET statut = 'ECHOUE', messageErreur = ? WHERE idPaiement = ?")
            ->execute([mb_substr($message, 0, 255), $paiementId]);
        $conn->prepare("UPDATE abonnement SET statut = 'ECHOUE' WHERE idAbonnement = ? AND statut = 'EN_ATTENTE'")
            ->execute([$abonnementId]);
        throw new CampayException($message);
    }

    $conn->prepare("UPDATE paiement SET referenceCampay = ?, operateur = ? WHERE idPaiement = ?")
        ->execute([$collect['reference'], $collect['operator'], $paiementId]);
    subscriptionLog($conn, 'Paiement d\'abonnement initié', $clientId, 'Premium ' . strtolower($periodicite)
        . ($nbVehicules !== null ? ' (' . $nbVehicules . ' véhicules)' : '') . ', ' . $amount . ' XAF par Mobile Money'
        . ($charged !== $amount ? ' (démo CamPay : ' . $charged . ' XAF débités)' : ''));

    return ['idPaiement' => $paiementId, 'montant' => $amount, 'ussd_code' => $collect['ussd_code'], 'operator' => $collect['operator']];
}

/**
 * Active l'abonnement lié à un paiement qui vient de passer à PAYE (appelée
 * par paymentApplyCampayStatus()). La période commence maintenant, ou à la
 * fin de l'abonnement actif le plus tardif (prolongation). Idempotente : seul
 * un abonnement encore EN_ATTENTE (ou ECHOUE, si CamPay confirme finalement
 * un paiement d'abord marqué échoué) est activé, une seule fois.
 *
 * @param array $paiement Ligne de `paiement` (idAbonnement, idClient)
 */
function subscriptionActivateFromPayment(PDO $conn, array $paiement): void {
    if (empty($paiement['idAbonnement']) || !subscriptionsReady($conn)) {
        return;
    }
    $abonnementId = (int)$paiement['idAbonnement'];
    $clientId = (int)$paiement['idClient'];

    // Verrou sur la ligne client : deux confirmations simultanées pour le même
    // client enchaînent leurs périodes au lieu de commencer au même instant.
    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare('SELECT idClient FROM client WHERE idClient = ? FOR UPDATE')->execute([$clientId]);
        $abonnement = subscriptionFind($conn, $abonnementId);
        $activated = false;
        if (!$abonnement || (int)$abonnement['idClient'] !== $clientId || !in_array($abonnement['periodicite'], ['MENSUEL', 'ANNUEL'], true)) {
            // Le paiement est déjà PAYE : on ne lève pas d'exception (le
            // webhook répondrait 500 sans rien pouvoir corriger), l'anomalie
            // est journalisée pour un traitement manuel.
            error_log('[SmartAutoTrack] abonnement #' . $abonnementId . ' invalide pour le paiement #' . ($paiement['idPaiement'] ?? '?'));
        } else {
            $start = subscriptionExtensionStart($conn, $clientId, time());
            $end = subscriptionPeriodEnd($start, (string)$abonnement['periodicite']);
            $stmt = $conn->prepare("
                UPDATE abonnement SET statut = 'ACTIF', dateDebut = ?, dateFin = ?
                WHERE idAbonnement = ? AND statut IN ('EN_ATTENTE', 'ECHOUE')
            ");
            $stmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $abonnementId]);
            $activated = $stmt->rowCount() === 1;
        }
        if ($ownTransaction) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction) {
            $conn->rollBack();
        }
        throw $e;
    }

    if ($activated) {
        subscriptionLog($conn, 'Abonnement Premium activé', $clientId, 'Premium ' . strtolower((string)$abonnement['periodicite'])
            . ' du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y'));
    }
}

/**
 * Marque ECHOUE l'abonnement EN_ATTENTE lié à un paiement qui vient
 * d'échouer (appelée par paymentApplyCampayStatus()). Sans effet sur un
 * abonnement déjà actif.
 *
 * @param array $paiement Ligne de `paiement` (idAbonnement)
 */
function subscriptionFailFromPayment(PDO $conn, array $paiement): void {
    if (empty($paiement['idAbonnement']) || !subscriptionsReady($conn)) {
        return;
    }
    $conn->prepare("UPDATE abonnement SET statut = 'ECHOUE' WHERE idAbonnement = ? AND statut = 'EN_ATTENTE'")
        ->execute([(int)$paiement['idAbonnement']]);
}

/**
 * Date de début de l'historique consultable : maintenant moins
 * SUB_FREE_HISTORY_MONTHS mois pour un client gratuit (les données plus
 * anciennes restent en base, seulement masquées).
 *
 * @return string|null 'Y-m-d H:i:s', ou null : aucun filtre (Premium, ou migration non appliquée)
 */
function subscriptionHistorySince(PDO $conn, int $clientId): ?string {
    if (!subscriptionsReady($conn) || clientIsPremium($conn, $clientId)) {
        return null;
    }
    return date('Y-m-d H:i:s', strtotime('-' . SUB_FREE_HISTORY_MONTHS . ' months'));
}

/** L'utilisateur est un client sans abonnement actif (soumis aux limites de la formule gratuite). */
function subscriptionIsFreeClient(PDO $conn, int $userId): bool {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM client WHERE idClient = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() > 0 && !clientIsPremium($conn, $userId);
}

/**
 * Compte un message à l'assistant IA pour un client gratuit et indique s'il
 * reste dans la limite de SUB_FREE_AI_PER_DAY messages par jour (compteur
 * `ia_usage` du jour, incrémenté de façon atomique et plafonné à limite + 1).
 * Premium, non-clients et migration non appliquée : toujours autorisé, sans
 * compter (l'anti-abus AI_RATE_LIMIT de includes/ai.php s'applique toujours).
 */
function subscriptionAiAllow(PDO $conn, int $userId): bool {
    if (!subscriptionsReady($conn) || !subscriptionIsFreeClient($conn, $userId)) {
        return true;
    }
    $today = date('Y-m-d');
    $conn->prepare("
        INSERT INTO ia_usage (idUtilisateur, jour, nb) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE nb = LEAST(nb + 1, ?)
    ")->execute([$userId, $today, SUB_FREE_AI_PER_DAY + 1]);
    $stmt = $conn->prepare('SELECT nb FROM ia_usage WHERE idUtilisateur = ? AND jour = ?');
    $stmt->execute([$userId, $today]);
    return (int)$stmt->fetchColumn() <= SUB_FREE_AI_PER_DAY;
}

/**
 * Rend le message compté par subscriptionAiAllow() quand l'assistant IA n'a
 * pas pu répondre (fournisseur indisponible) : une erreur technique ne
 * consomme pas le quota du client gratuit. Sans effet dans les autres cas.
 */
function subscriptionAiRefund(PDO $conn, int $userId): void {
    if (!subscriptionsReady($conn) || !subscriptionIsFreeClient($conn, $userId)) {
        return;
    }
    $conn->prepare('UPDATE ia_usage SET nb = nb - 1 WHERE idUtilisateur = ? AND jour = ? AND nb > 0')
        ->execute([$userId, date('Y-m-d')]);
}

/**
 * Messages à l'assistant IA encore disponibles aujourd'hui.
 *
 * @return int|null null si illimité (Premium, non-client, migration non appliquée)
 */
function subscriptionAiRemaining(PDO $conn, int $userId): ?int {
    if (!subscriptionsReady($conn) || !subscriptionIsFreeClient($conn, $userId)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT nb FROM ia_usage WHERE idUtilisateur = ? AND jour = ?');
    $stmt->execute([$userId, date('Y-m-d')]);
    return max(0, SUB_FREE_AI_PER_DAY - (int)$stmt->fetchColumn());
}
