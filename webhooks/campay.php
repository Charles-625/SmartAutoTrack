<?php
/**
 * Notification de CamPay à la fin d'une transaction.
 * URL à déclarer dans le tableau de bord CamPay : <SITE_URL>webhooks/campay.php
 *
 * Aucune session ni jeton CSRF ici : la notification doit porter une
 * `signature` valide (JWT signé avec CAMPAY_WEBHOOK_KEY), ne concerne qu'un
 * paiement existant, et un paiement PAYE est définitif.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json');

$data = $_GET + $_POST;
$json = json_decode((string)file_get_contents('php://input'), true);
if (is_array($json)) {
    $data += $json;
}

if (!campayVerifyWebhookSignature((string)($data['signature'] ?? ''))) {
    error_log('[SmartAutoTrack] Webhook CamPay rejeté : signature invalide (référence ' . substr((string)($data['reference'] ?? ''), 0, 100) . ')');
    http_response_code(403);
    exit(json_encode(['status' => 'rejected']));
}

$reference = (string)($data['reference'] ?? '');
if ($reference === '') {
    http_response_code(400);
    exit(json_encode(['status' => 'missing_reference']));
}

try {
    $conn = (new Database())->getConnection();
    $paiement = paymentFindByCampayReference($conn, $reference, (string)($data['external_reference'] ?? ''));
    if (!$paiement) {
        error_log('[SmartAutoTrack] Webhook CamPay : aucun paiement pour la référence ' . substr($reference, 0, 100));
        exit(json_encode(['status' => 'unknown_reference']));
    }
    // Le statut appliqué est celui que CamPay renvoie pour cette référence,
    // pas celui des paramètres reçus : une signature rejouée ne suffit pas.
    $statut = paymentApplyCampayStatus($conn, $paiement, campayTransactionStatus((string)($paiement['referenceCampay'] ?: $reference)));
    echo json_encode(['status' => 'ok', 'paiement' => $statut]);
} catch (Throwable $e) {
    error_log('[SmartAutoTrack] Webhook CamPay : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
