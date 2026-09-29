<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

requireJsonAuth('admin');

$technician_id = $_GET['id'] ?? null;

if (!$technician_id) {
    echo json_encode(['success' => false, 'message' => 'ID de technicien manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Récupérer les détails du technicien. Statut/statut_validation historiques
    // reconstitués depuis statutValidation pour laisser le JS consommateur inchangé.
    $stmt = $conn->prepare("
        SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, u.telephone, u.dateCreation AS created_at,
               t.competences, t.experience,
               CASE t.statutValidation WHEN 'VALIDE' THEN 'actif' WHEN 'EN_ATTENTE' THEN 'pending' ELSE 'rejete' END AS statut,
               CASE t.statutValidation WHEN 'VALIDE' THEN 'valide' WHEN 'EN_ATTENTE' THEN 'en_attente' ELSE 'rejete' END AS statut_validation
        FROM utilisateur u
        JOIN technicien t ON t.idTechnicien = u.idUtilisateur
        WHERE u.idUtilisateur = ?
    ");
    $stmt->execute([$technician_id]);
    $technician = $stmt->fetch();

    if (!$technician) {
        echo json_encode(['success' => false, 'message' => 'Technicien introuvable']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'technician' => $technician
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération du technicien']);
}
?>
