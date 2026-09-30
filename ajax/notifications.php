<?php
require_once '../config/config.php';
require_once '../config/database.php';

/**
 * Endpoint AJAX (JSON) : 10 dernières notifications de l'utilisateur
 * connecté et nombre de notifications non lues, pour le menu déroulant
 * de la barre de navigation (assets/js/main.js).
 *
 * Accès : tout utilisateur connecté.
 * Table lue : notifications.
 */
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Récupérer les notifications non lues
    $stmt = $conn->prepare("
        SELECT id, type, titre, message, lu, date_creation 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY date_creation DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll();
    
    // Compter les notifications non lues
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lu = 'non'");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_count = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du chargement des notifications']);
}
?>
