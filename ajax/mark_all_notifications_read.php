<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
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
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
}
?>
