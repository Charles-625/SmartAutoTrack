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
    
    // Compter les messages non lus
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM messages 
        WHERE destinataire_id = ? AND lu = 'non'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $count = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'count' => $count
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du comptage des messages']);
}
?>
