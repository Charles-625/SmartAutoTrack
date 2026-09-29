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
    
    // Récupérer les informations du technicien
    $stmt = $conn->prepare("
        SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email
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
    
    // Récupérer les documents du technicien
    $stmt = $conn->prepare("
        SELECT id, type_document, nom_fichier, taille_fichier, uploaded_at FROM technician_documents
        WHERE technicien_id = ? 
        ORDER BY type_document, uploaded_at DESC
    ");
    $stmt->execute([$technician_id]);
    $documents = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'technician' => $technician,
        'documents' => $documents
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des documents']);
}
?>
