<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

$contact_id = $_GET['contact_id'] ?? null;

if (!$contact_id) {
    echo json_encode(['success' => false, 'message' => 'ID de contact manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Récupérer les informations du contact (le rôle se déduit de la table où l'id apparaît)
    $stmt = $conn->prepare("SELECT idUtilisateur AS id, nom, prenom FROM utilisateur WHERE idUtilisateur = ?");
    $stmt->execute([$contact_id]);
    $contact = $stmt->fetch();

    if (!$contact) {
        echo json_encode(['success' => false, 'message' => 'Contact introuvable']);
        exit;
    }
    $contact['role'] = getUserRole($conn, (int)$contact_id);

    // Récupérer les messages entre l'utilisateur et le contact (table inchangée)
    $stmt = $conn->prepare("
        SELECT m.*, u.nom, u.prenom
        FROM messages m
        JOIN utilisateur u ON m.expediteur_id = u.idUtilisateur
        WHERE (m.expediteur_id = ? AND m.destinataire_id = ?)
           OR (m.expediteur_id = ? AND m.destinataire_id = ?)
        ORDER BY m.date_envoi ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $contact_id, $contact_id, $_SESSION['user_id']]);
    $messages = $stmt->fetchAll();
    
    // Marquer les messages comme lus
    $stmt = $conn->prepare("
        UPDATE messages 
        SET lu = 'oui' 
        WHERE expediteur_id = ? AND destinataire_id = ? AND lu = 'non'
    ");
    $stmt->execute([$contact_id, $_SESSION['user_id']]);
    
    echo json_encode([
        'success' => true,
        'contact' => $contact,
        'messages' => $messages
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du chargement de la conversation']);
}
?>
