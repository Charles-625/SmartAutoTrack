<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

requireJsonAuth('admin');

$client_id = $_GET['id'] ?? null;

if (!$client_id) {
    echo json_encode(['success' => false, 'message' => 'ID de client manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Récupérer les informations du client
    $stmt = $conn->prepare("
        SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, u.telephone, u.dateCreation AS created_at
        FROM utilisateur u
        JOIN client c ON c.idClient = u.idUtilisateur
        WHERE u.idUtilisateur = ?
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();

    if (!$client) {
        echo json_encode(['success' => false, 'message' => 'Client introuvable']);
        exit;
    }

    // Récupérer les véhicules du client (etat reconverti en statut historique
    // minuscule pour laisser le JS consommateur inchangé)
    $stmt = $conn->prepare("
        SELECT idVehicule AS id, marque, modele, immatriculation, kilometrage,
               CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
        FROM vehicule WHERE idClient = ? ORDER BY dateCreation DESC
    ");
    $stmt->execute([$client_id]);
    $vehicles = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'client' => $client,
        'vehicles' => $vehicles
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération du client']);
}
?>
