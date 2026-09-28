<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// Vérifier que l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$theme = $_POST['theme'] ?? '';

// Valider le thème
$allowed_themes = ['light', 'dark', 'blue', 'green', 'purple', 'orange'];
if (!in_array($theme, $allowed_themes)) {
    echo json_encode(['success' => false, 'message' => 'Thème invalide']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Sauvegarder le thème en base de données
    $stmt = $conn->prepare("UPDATE utilisateur SET themePreference = ? WHERE idUtilisateur = ?");
    $stmt->execute([$theme, $_SESSION['user_id']]);
    
    // Mettre à jour la session
    $_SESSION['theme'] = $theme;
    
    echo json_encode(['success' => true, 'message' => 'Thème sauvegardé']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde']);
}
?>
