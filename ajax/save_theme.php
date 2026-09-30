<?php
require_once '../config/config.php';
require_once '../config/database.php';

/**
 * Endpoint AJAX (JSON) : enregistre le thème d'affichage choisi par
 * l'utilisateur (assets/js/themes.js), en base et dans la session.
 *
 * Accès : tout utilisateur connecté, POST avec jeton CSRF.
 * POST : theme, parmi la liste blanche ci-dessous.
 * Table écrite : utilisateur (colonne themePreference).
 */
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyRequestCSRF()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expirée, merci de recharger la page.']);
    exit;
}

$theme = $_POST['theme'] ?? '';

// Valider le thème
$allowed_themes = ['light', 'dark', 'blue', 'green', 'purple', 'orange'];
if (!in_array($theme, $allowed_themes, true)) {
    http_response_code(400);
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
    error_log('[SmartAutoTrack] save_theme : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde']);
}
?>
