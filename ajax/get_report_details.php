<?php
require_once '../config/config.php';
require_once '../config/database.php';

/**
 * Endpoint AJAX (JSON) : détail d'un rapport de réparation rédigé par le
 * technicien connecté (un « rapport » est une ligne de la table reparation).
 *
 * Accès : technicien uniquement, et seulement pour ses propres réparations.
 * GET : id (identifiant de la réparation).
 * Tables lues : reparation, intervention, vehicule, utilisateur.
 */
header('Content-Type: application/json');

requireJsonAuth('technicien');

$report_id = $_GET['id'] ?? null;

if (!$report_id) {
    echo json_encode(['success' => false, 'message' => 'ID de rapport manquant']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Récupérer les détails du rapport. Statut au vocabulaire historique du
    // technicien (en_attente/valide), TERMINEE étant assimilée à "validé".
    $stmt = $conn->prepare("
        SELECT r.idReparation AS id, r.titre, r.description, r.diagnostic,
               r.travauxEffectues AS travaux_effectues, r.piecesUtilisees AS pieces_utilisees, r.recommandations,
               r.cout, r.dureeIntervention AS duree_intervention, r.dateReparation AS created_at,
               CASE r.statut WHEN 'TERMINEE' THEN 'valide' ELSE 'en_attente' END AS statut,
               v.marque, v.modele, v.immatriculation,
               u.prenom as client_prenom, u.nom as client_nom
        FROM reparation r
        JOIN intervention i ON r.idIntervention = i.idIntervention
        JOIN vehicule v ON i.idVehicule = v.idVehicule
        JOIN utilisateur u ON i.idClient = u.idUtilisateur
        WHERE r.idReparation = ? AND r.idTechnicien = ?
    ");
    $stmt->execute([$report_id, $_SESSION['user_id']]);
    $report = $stmt->fetch();
    
    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Rapport introuvable']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'report' => $report
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération du rapport']);
}
?>
