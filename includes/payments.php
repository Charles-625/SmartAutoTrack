<?php
/**
 * Paiement des réparations par Mobile Money (CamPay).
 *
 * Cycle d'un paiement (table `paiement`) :
 *   EN_ATTENTE  -> créé au lancement, le client valide sur son téléphone
 *   PAYE        -> confirmé par CamPay (webhook ou suivi du statut) ; définitif
 *   ECHOUE      -> refusé, expiré, ou montant confirmé différent du montant dû
 */

require_once __DIR__ . '/campay.php';
require_once __DIR__ . '/activity_log.php';

/** Au-delà, un paiement resté EN_ATTENTE n'empêche plus une nouvelle tentative. */
const PAYMENT_PENDING_LOCK_SECONDS = 900;

/** Les colonnes CamPay de `paiement` existent (scripts/migrate_structure.php appliqué). */
function paymentsReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paiement'
              AND COLUMN_NAME IN ('idReparation', 'referenceExterne', 'referenceCampay')
        ");
        $ready = (int)$stmt->fetchColumn() === 3;
    }
    return $ready;
}

/** Réparation terminée, au coût non nul, appartenant à ce client ; sinon null. */
function paymentPayableRepair(PDO $conn, int $clientId, int $repairId, bool $forUpdate = false): ?array {
    $stmt = $conn->prepare("
        SELECT r.idReparation, r.titre, r.cout, i.idIntervention, i.idClient
        FROM reparation r
        JOIN intervention i ON i.idIntervention = r.idIntervention
        WHERE r.idReparation = ? AND i.idClient = ? AND r.statut = 'TERMINEE' AND r.cout > 0
    " . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$repairId, $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * État de paiement de chaque réparation : 'PAYE', 'EN_ATTENTE' (tentative
 * récente non terminée) ou absent du tableau (à payer).
 *
 * @param int[] $repairIds
 * @return array<int, string>
 */
function paymentStatesForRepairs(PDO $conn, array $repairIds, ?int $now = null): array {
    $repairIds = array_values(array_unique(array_map('intval', $repairIds)));
    if (!$repairIds || !paymentsReady($conn)) {
        return [];
    }
    $now = $now ?? time();
    $in = implode(',', array_fill(0, count($repairIds), '?'));
    $stmt = $conn->prepare("SELECT idReparation, statut, datePaiement FROM paiement WHERE idReparation IN ($in) AND statut IN ('PAYE', 'EN_ATTENTE')");
    $stmt->execute($repairIds);

    $states = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['idReparation'];
        if ($row['statut'] === 'PAYE') {
            $states[$id] = 'PAYE';
        } elseif (($states[$id] ?? null) !== 'PAYE' && strtotime((string)$row['datePaiement']) > $now - PAYMENT_PENDING_LOCK_SECONDS) {
            $states[$id] = 'EN_ATTENTE';
        }
    }
    return $states;
}

/**
 * Trace une étape de paiement dans le journal d'activité (catégorie
 * « reparation »), au nom du client. Un échec d'écriture du journal est
 * seulement journalisé : il ne doit jamais faire échouer le paiement.
 */
function paymentLog(PDO $conn, string $nomActivite, array $paiement, ?string $description = null): void {
    try {
        log_activity($conn, $nomActivite, [
            'idUtilisateur' => (int)$paiement['idClient'],
            'idIntervention' => $paiement['idIntervention'] !== null ? (int)$paiement['idIntervention'] : null,
            'idReparation' => $paiement['idReparation'] !== null ? (int)$paiement['idReparation'] : null,
            'description' => $description,
            'categorie' => 'reparation',
        ]);
    } catch (Throwable $e) {
        error_log('[SmartAutoTrack] journal paiement : ' . $e->getMessage());
    }
}

/**
 * Lance le paiement d'une réparation : enregistre le paiement EN_ATTENTE puis
 * demande à CamPay d'envoyer l'invite de confirmation au téléphone du client.
 *
 * @return array{idPaiement:int, montant:int, ussd_code:?string, operator:?string}
 * @throws CampayException message affichable au client
 */
function paymentStart(PDO $conn, int $clientId, int $repairId, string $rawPhone): array {
    if (!paymentsReady($conn)) {
        throw new CampayException('Le paiement en ligne n\'est pas encore activé sur cette plateforme.');
    }
    $phone = campayNormalizePhone($rawPhone);
    if ($phone === null) {
        throw new CampayException('Numéro Mobile Money invalide (exemple : 6XX XX XX XX).');
    }

    // Transaction + FOR UPDATE sur la réparation : deux clics simultanés ne
    // peuvent pas créer deux paiements EN_ATTENTE pour la même réparation.
    $conn->beginTransaction();
    try {
        $repair = paymentPayableRepair($conn, $clientId, $repairId, true);
        if (!$repair) {
            throw new CampayException('Cette réparation ne peut pas être payée en ligne.');
        }
        $state = paymentStatesForRepairs($conn, [$repairId])[$repairId] ?? null;
        if ($state === 'PAYE') {
            throw new CampayException('Cette réparation est déjà payée.');
        }
        if ($state === 'EN_ATTENTE') {
            throw new CampayException('Un paiement est déjà en cours pour cette réparation. Validez-le sur votre téléphone ou réessayez dans quelques minutes.');
        }

        $amount = (int)round((float)$repair['cout']);
        $externalReference = 'SAT-' . $repairId . '-' . bin2hex(random_bytes(6));
        $conn->prepare("
            INSERT INTO paiement (idClient, idIntervention, idReparation, montant, datePaiement, typePaiement, statut, referenceExterne, telephone)
            VALUES (?, ?, ?, ?, ?, 'MOBILE_MONEY', 'EN_ATTENTE', ?, ?)
        ")->execute([$clientId, $repair['idIntervention'], $repairId, $amount, date('Y-m-d H:i:s'), $externalReference, $phone]);
        $paiementId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    // L'appel à CamPay se fait hors transaction (réseau lent) ; en cas
    // d'échec, le paiement déjà créé est marqué ECHOUE pour libérer la
    // réparation.
    $paiement = ['idPaiement' => $paiementId, 'idClient' => $clientId, 'idIntervention' => $repair['idIntervention'], 'idReparation' => $repairId];
    $charged = campayChargedAmount($amount);
    try {
        $collect = campayCollect($charged, $phone, 'SmartAutoTrack - ' . ($repair['titre'] ?: 'Réparation #' . $repairId), $externalReference);
    } catch (Throwable $e) {
        $message = $e instanceof CampayException ? $e->getMessage() : 'Le service de paiement est injoignable. Réessayez plus tard.';
        if (!$e instanceof CampayException) {
            error_log('[SmartAutoTrack] CamPay collect : ' . $e->getMessage());
        }
        $conn->prepare("UPDATE paiement SET statut = 'ECHOUE', messageErreur = ? WHERE idPaiement = ?")
            ->execute([mb_substr($message, 0, 255), $paiementId]);
        throw new CampayException($message);
    }

    $conn->prepare("UPDATE paiement SET referenceCampay = ?, operateur = ? WHERE idPaiement = ?")
        ->execute([$collect['reference'], $collect['operator'], $paiementId]);
    paymentLog($conn, 'Paiement initié', $paiement, $amount . ' XAF par Mobile Money'
        . ($charged !== $amount ? ' (démo CamPay : ' . $charged . ' XAF débités)' : ''));

    return ['idPaiement' => $paiementId, 'montant' => $amount, 'ussd_code' => $collect['ussd_code'], 'operator' => $collect['operator']];
}

/** Paiement de ce client (avec le titre de la réparation), ou null. */
function paymentFindForClient(PDO $conn, int $paiementId, int $clientId): ?array {
    $stmt = $conn->prepare("
        SELECT p.*, r.titre AS reparation_titre
        FROM paiement p LEFT JOIN reparation r ON r.idReparation = p.idReparation
        WHERE p.idPaiement = ? AND p.idClient = ?
    ");
    $stmt->execute([$paiementId, $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Paiement correspondant à une notification CamPay (webhooks/campay.php) :
 * recherché par référence CamPay, ou à défaut par notre référence externe
 * (SAT-...) quand elle est fournie.
 */
function paymentFindByCampayReference(PDO $conn, string $reference, string $externalReference = ''): ?array {
    $stmt = $conn->prepare("
        SELECT p.*, r.titre AS reparation_titre
        FROM paiement p LEFT JOIN reparation r ON r.idReparation = p.idReparation
        WHERE p.referenceCampay = ? OR (? <> '' AND p.referenceExterne = ?)
        LIMIT 1
    ");
    $stmt->execute([$reference, $externalReference, $externalReference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Applique le statut d'une transaction CamPay à un paiement. Idempotent :
 * un paiement PAYE n'est plus jamais modifié, et la notification au client
 * n'est envoyée qu'une fois.
 *
 * @return string nouveau statut du paiement
 */
function paymentApplyCampayStatus(PDO $conn, array $paiement, array $transaction): string {
    $current = (string)$paiement['statut'];
    $new = campayStatusToPaiement((string)($transaction['status'] ?? ''));
    if ($current === 'PAYE' || $new === 'EN_ATTENTE' || $new === $current) {
        return $current;
    }

    // Contrôle du montant : un paiement confirmé pour un autre montant que
    // celui attendu n'est pas accepté comme PAYE (vérification manuelle).
    $error = null;
    $expected = campayChargedAmount((int)round((float)$paiement['montant']));
    if ($new === 'PAYE' && isset($transaction['amount']) && (int)round((float)$transaction['amount']) !== $expected) {
        error_log('[SmartAutoTrack] CamPay : montant confirmé ' . $transaction['amount'] . ' différent du montant attendu ' . $expected . ' (paiement #' . $paiement['idPaiement'] . ')');
        $new = 'ECHOUE';
        $error = 'Montant confirmé (' . $transaction['amount'] . ' XAF) différent du montant dû : vérification manuelle nécessaire.';
    } elseif ($new === 'ECHOUE') {
        $error = 'Paiement refusé ou expiré.';
    }

    $stmt = $conn->prepare("
        UPDATE paiement
        SET statut = ?, messageErreur = ?, operateur = COALESCE(?, operateur),
            datePaiement = CASE WHEN ? = 'PAYE' THEN ? ELSE datePaiement END
        WHERE idPaiement = ? AND statut <> 'PAYE'
    ");
    $stmt->execute([$new, $error, $transaction['operator'] ?? null, $new, date('Y-m-d H:i:s'), $paiement['idPaiement']]);

    // rowCount() === 1 seulement pour la requête qui a réellement fait passer
    // le paiement à PAYE : notification et journal ne partent qu'une fois,
    // même si webhook et suivi de statut arrivent en même temps.
    if ($stmt->rowCount() === 1 && $new === 'PAYE') {
        $montant = number_format((float)$paiement['montant'], 0, ',', ' ');
        $titre = $paiement['reparation_titre'] ?? null;
        $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Paiement reçu', ?)")
            ->execute([$paiement['idClient'], 'Votre paiement de ' . $montant . ' XAF' . ($titre ? ' pour « ' . $titre . ' »' : '') . ' a bien été reçu. Merci !']);
        paymentLog($conn, 'Paiement reçu', $paiement, $montant . ' XAF, référence CamPay ' . ($paiement['referenceCampay'] ?? ''));
    }
    return $stmt->rowCount() === 1 ? $new : $current;
}

/** Interroge CamPay pour un paiement encore EN_ATTENTE et met la base à jour. */
function paymentRefresh(PDO $conn, array $paiement): string {
    if ($paiement['statut'] !== 'EN_ATTENTE' || empty($paiement['referenceCampay'])) {
        return (string)$paiement['statut'];
    }
    return paymentApplyCampayStatus($conn, $paiement, campayTransactionStatus((string)$paiement['referenceCampay']));
}
