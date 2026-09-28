<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

requireRole('technicien');

$task_id = $_GET['id'] ?? null;

if (!$task_id) {
    echo json_encode(['success' => false, 'message' => 'ID de tâche manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Récupérer les détails de la tâche (statut/priorité reconvertis en
    // minuscules pour laisser le JS consommateur inchangé)
    $stmt = $conn->prepare("
        SELECT i.idIntervention AS id, i.type AS type_intervention, i.description,
               i.dateIntervention AS date_planifiee, LOWER(i.statut) AS statut, LOWER(i.priorite) AS priorite,
               v.marque, v.modele, v.immatriculation,
               u.prenom as client_prenom, u.nom as client_nom,
               u.telephone as client_telephone, u.email as client_email
        FROM intervention i
        JOIN vehicule v ON i.idVehicule = v.idVehicule
        JOIN utilisateur u ON i.idClient = u.idUtilisateur
        WHERE i.idIntervention = ? AND i.idTechnicien = ?
    ");
    $stmt->execute([$task_id, $_SESSION['user_id']]);
    $task = $stmt->fetch();

    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Tâche introuvable']);
        exit;
    }

    // Récupérer le rapport associé si la tâche est terminée. Le nouveau schéma
    // impose idIntervention (NOT NULL) sur reparation : la recherche directe
    // suffit toujours, plus besoin du repli par véhicule/date de l'ancien code.
    $report = null;
    if ($task['statut'] === 'terminee') {
        $stmt = $conn->prepare("
            SELECT idReparation AS id, titre, description, diagnostic,
                   travauxEffectues AS travaux_effectues, piecesUtilisees AS pieces_utilisees, recommandations,
                   cout, dureeIntervention AS duree_intervention, dateReparation AS created_at,
                   CASE statut WHEN 'TERMINEE' THEN 'valide' ELSE 'en_attente' END AS statut
            FROM reparation
            WHERE idIntervention = ?
            ORDER BY dateReparation DESC
            LIMIT 1
        ");
        $stmt->execute([$task_id]);
        $report = $stmt->fetch();
    }
    
    echo json_encode([
        'success' => true,
        'task' => $task,
        'report' => $report
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération de la tâche']);
}
?>
