<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

$vehicle_id = $_GET['id'] ?? null;

if (!$vehicle_id) {
    echo json_encode(['success' => false, 'message' => 'ID de véhicule manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Récupérer le véhicule, cloisonné selon le rôle. Le rôle admin/technicien
    // "accès à tous les véhicules" d'origine s'appliquait en réalité à TOUT
    // rôle non-client (donc au garage aussi, ajouté depuis) : chaque rôle a
    // désormais sa propre restriction explicite, jamais de repli permissif.
    $cols = "v.idVehicule AS id, v.idClient AS client_id, v.marque, v.modele, v.immatriculation, v.annee, v.couleur, v.kilometrage";
    if ($_SESSION['role'] === 'client') {
        $stmt = $conn->prepare("SELECT $cols FROM vehicule v WHERE v.idVehicule = ? AND v.idClient = ?");
        $stmt->execute([$vehicle_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'technicien') {
        // Uniquement un véhicule lié à une intervention qui lui est assignée
        $stmt = $conn->prepare("
            SELECT $cols FROM vehicule v
            WHERE v.idVehicule = ? AND EXISTS (SELECT 1 FROM intervention i WHERE i.idVehicule = v.idVehicule AND i.idTechnicien = ?)
        ");
        $stmt->execute([$vehicle_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'garage') {
        // Uniquement un véhicule lié à au moins une intervention de CE garage
        $stmt = $conn->prepare("
            SELECT $cols FROM vehicule v
            WHERE v.idVehicule = ? AND EXISTS (
                SELECT 1 FROM intervention i JOIN garage g ON g.idGarage = i.idGarage
                WHERE i.idVehicule = v.idVehicule AND g.idUtilisateur = ?
            )
        ");
        $stmt->execute([$vehicle_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'admin') {
        // Vision globale de l'administrateur
        $stmt = $conn->prepare("SELECT $cols FROM vehicule v WHERE v.idVehicule = ?");
        $stmt->execute([$vehicle_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }

    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo json_encode(['success' => false, 'message' => 'Véhicule introuvable']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'vehicle' => $vehicle
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération du véhicule']);
}
?>
