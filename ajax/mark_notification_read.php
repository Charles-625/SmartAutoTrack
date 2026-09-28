<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

$notification_id = $_POST['notification_id'] ?? null;

if (!$notification_id) {
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
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
}
?>
