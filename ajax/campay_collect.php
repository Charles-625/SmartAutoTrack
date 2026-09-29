<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/payments.php';

header('Content-Type: application/json');

requireJsonAuth(ROLE_CLIENT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyRequestCSRF()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Session expirée, merci de recharger la page.']));
}

$repairId = filter_var($_POST['reparation_id'] ?? null, FILTER_VALIDATE_INT);
if (!$repairId) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Réparation invalide.']));
}

try {
    $conn = (new Database())->getConnection();
    $result = paymentStart($conn, (int)$_SESSION['user_id'], $repairId, (string)($_POST['telephone'] ?? ''));
    echo json_encode([
        'success' => true,
        'paiement_id' => $result['idPaiement'],
        'montant' => $result['montant'],
        'ussd_code' => $result['ussd_code'],
        'operator' => $result['operator'],
    ]);
} catch (CampayException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[SmartAutoTrack] campay_collect : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors du lancement du paiement.']);
}
