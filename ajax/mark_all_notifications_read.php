<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyRequestCSRF()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expirée, merci de recharger la page.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Marquer toutes les notifications comme lues
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET lu = 'oui' 
        WHERE user_id = ? AND lu = 'non'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('[SmartAutoTrack] mark_all_notifications_read : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
}
?>
