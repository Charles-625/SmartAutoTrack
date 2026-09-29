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

$paiementId = filter_var($_POST['paiement_id'] ?? null, FILTER_VALIDATE_INT);
if (!$paiementId) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Paiement invalide.']));
}

try {
    $conn = (new Database())->getConnection();
    $paiement = paymentFindForClient($conn, $paiementId, (int)$_SESSION['user_id']);
    if (!$paiement) {
        http_response_code(404);
        exit(json_encode(['success' => false, 'message' => 'Paiement introuvable.']));
    }
    $statut = paymentRefresh($conn, $paiement);
    echo json_encode(['success' => true, 'statut' => $statut]);
} catch (Throwable $e) {
    // Erreur passagère (réseau, CamPay) : la page réessaiera au prochain tour.
    error_log('[SmartAutoTrack] campay_status : ' . $e->getMessage());
    echo json_encode(['success' => true, 'statut' => 'EN_ATTENTE']);
}
