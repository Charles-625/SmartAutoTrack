<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

requireAuth();

$reparation_id = $_GET['id'] ?? null;

if (!$reparation_id) {
    echo json_encode(['success' => false, 'message' => 'ID de réparation manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Réparation vue côté client : statut au vocabulaire historique
    // planifiee/en_cours/validee (TERMINEE = "validée", faute d'étape de
    // validation distincte dans le nouveau schéma). Plus de lien direct vers
    // une anomalie précise : anomalie_type reste donc vide.
    $cols = "r.idReparation AS id, r.titre, r.description, r.diagnostic,
             r.travauxEffectues AS travaux_effectues, r.piecesUtilisees AS pieces_utilisees, r.recommandations,
             r.cout, r.dureeIntervention AS duree_intervention, r.dateReparation AS created_at,
             CASE r.statut WHEN 'EN_ATTENTE' THEN 'planifiee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'validee' END AS statut,
             v.marque, v.modele, v.immatriculation,
             ut.prenom as technicien_prenom, ut.nom as technicien_nom,
             NULL as anomalie_type";
    // Construire la requête selon le rôle. Chaque rôle a sa propre
    // restriction explicite — le "else" d'origine ("pour les admins") était en
    // réalité exécuté pour tout rôle qui n'est ni client ni technicien, donc
    // aussi par le garage (ajouté depuis), avec un accès total non cloisonné.
    if ($_SESSION['role'] === 'client') {
        // Pour les clients, vérifier qu'ils possèdent le véhicule
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ? AND i.idClient = ?
        ");
        $stmt->execute([$reparation_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'technicien') {
        // Pour les techniciens, seulement leurs réparations
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ? AND r.idTechnicien = ?
        ");
        $stmt->execute([$reparation_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'garage') {
        // Pour un garage, seulement les réparations issues de ses propres interventions
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            JOIN garage g ON g.idGarage = i.idGarage
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ? AND g.idUtilisateur = ?
        ");
        $stmt->execute([$reparation_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'admin') {
        // Vision globale de l'administrateur
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ?
        ");
        $stmt->execute([$reparation_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }

    $reparation = $stmt->fetch();
    
    if (!$reparation) {
        echo json_encode(['success' => false, 'message' => 'Réparation introuvable']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'reparation' => $reparation
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération de la réparation']);
}
?>