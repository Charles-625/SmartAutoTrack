<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

requireJsonAuth();

$anomaly_id = $_GET['id'] ?? null;

if (!$anomaly_id) {
    echo json_encode(['success' => false, 'message' => 'ID d\'anomalie manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Statut et niveau reconvertis vers le vocabulaire historique minuscule
    // pour laisser le JS consommateur inchangé.
    $cols = "a.idAnomalie AS id, a.description, a.dateDetection AS date_detection, a.dateResolution AS date_resolution,
             COALESCE(a.type, 'Anomalie') AS type, LOWER(a.niveau) AS niveau,
             CASE a.statut WHEN 'NOUVELLE' THEN 'detectee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'resolue' END AS statut,
             v.marque, v.modele, v.immatriculation";
    // Construire la requête selon le rôle. L'ancien "else" ("techniciens et
    // admins, accès à toutes les anomalies") ne cloisonnait NI le technicien
    // NI le garage (ajouté depuis) : chaque rôle a désormais sa propre
    // restriction explicite.
    if ($_SESSION['role'] === 'client') {
        // Pour les clients, vérifier qu'ils possèdent le véhicule
        $stmt = $conn->prepare("
            SELECT $cols
            FROM anomalie a
            JOIN vehicule v ON a.idVehicule = v.idVehicule
            WHERE a.idAnomalie = ? AND v.idClient = ?
        ");
        $stmt->execute([$anomaly_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'technicien') {
        // Uniquement une anomalie constatée sur une intervention qui lui est assignée
        $stmt = $conn->prepare("
            SELECT $cols
            FROM anomalie a
            JOIN vehicule v ON a.idVehicule = v.idVehicule
            JOIN intervention i ON i.idIntervention = a.idIntervention
            WHERE a.idAnomalie = ? AND i.idTechnicien = ?
        ");
        $stmt->execute([$anomaly_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'garage') {
        // Uniquement une anomalie liée à au moins une intervention de CE garage
        $stmt = $conn->prepare("
            SELECT $cols
            FROM anomalie a
            JOIN vehicule v ON a.idVehicule = v.idVehicule
            WHERE a.idAnomalie = ? AND EXISTS (
                SELECT 1 FROM intervention gi JOIN garage g ON g.idGarage = gi.idGarage
                WHERE gi.idVehicule = a.idVehicule AND g.idUtilisateur = ?
            )
        ");
        $stmt->execute([$anomaly_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'admin') {
        // Vision globale de l'administrateur
        $stmt = $conn->prepare("
            SELECT $cols
            FROM anomalie a
            JOIN vehicule v ON a.idVehicule = v.idVehicule
            WHERE a.idAnomalie = ?
        ");
        $stmt->execute([$anomaly_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }

    $anomaly = $stmt->fetch();
    
    if (!$anomaly) {
        echo json_encode(['success' => false, 'message' => 'Anomalie introuvable']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'anomaly' => $anomaly
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération de l\'anomalie']);
}
?>