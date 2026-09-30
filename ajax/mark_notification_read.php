<?php
require_once '../config/config.php';
require_once '../config/database.php';

/**
 * Endpoint AJAX (JSON) : marque une notification comme lue.
 * Appelé par le menu des notifications (includes/footer.php, assets/js/main.js).
 *
 * Accès : tout utilisateur connecté, POST avec jeton CSRF.
 * POST : notification_id. La clause user_id empêche de modifier la
 * notification d'un autre utilisateur.
 * Table écrite : notifications.
 */
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

$notification_id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);

if (!$notification_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de notification manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Marquer la notification comme lue
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET lu = 'oui' 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$notification_id, $_SESSION['user_id']]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('[SmartAutoTrack] mark_notification_read : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
}
?>
