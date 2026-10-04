<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/payments.php';
require_once '../includes/receipt.php';

/**
 * Téléchargement du reçu de paiement d'une réparation, en PDF
 * (receiptBuildPdf(), includes/receipt.php, sur le moteur
 * includes/pdf_lite.php, dans le style de ajax/download_report.php).
 *
 * Accès : tout utilisateur connecté, cloisonné par rôle : le client pour ses
 * propres paiements (paiement.idClient), l'admin pour tous ; tout autre rôle
 * répond 403. Un paiement inexistant, d'un autre client, d'abonnement
 * (idReparation NULL) ou non confirmé (statut autre que PAYE) répond 404,
 * avec la même réponse dans tous les cas.
 * GET : id (identifiant du paiement, entier > 0, sinon 400).
 * PDF : bandeau « SmartAutoTrack — Reçu de paiement », n° de reçu
 * REC-<idPaiement>, date du paiement, blocs Client (raison sociale pour une
 * entreprise), Réparation (titre, n°, intervention, véhicule, prise en
 * charge : garage ou SmartAutoTrack) et Paiement (montant, Mobile Money,
 * opérateur, numéro masqué, références CamPay et SmartAutoTrack), mention
 * « Paiement confirmé par CamPay », et, si l'API CamPay de démonstration est
 * configurée (campayIsDemo()), la mention du plafonnement du montant
 * réellement débité ; nom de fichier recu_paiement_<id>.pdf.
 * Tables lues : paiement, reparation, intervention, vehicule, utilisateur,
 * client, entreprise, garage. Aucune écriture.
 */
requireAuth();

$paiement_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$paiement_id) {
    http_response_code(400);
    exit('ID de paiement invalide');
}

// Seuls le client (ses paiements) et l'admin (tous) obtiennent un reçu.
$role = $_SESSION['role'];
if ($role !== ROLE_CLIENT && $role !== ROLE_ADMIN) {
    http_response_code(403);
    exit('Accès refusé');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Colonnes CamPay absentes (migration non appliquée) : aucun reçu possible.
    if (!paymentsReady($conn)) {
        http_response_code(404);
        exit('Reçu introuvable');
    }

    // Paiement de réparation confirmé uniquement ; le client est restreint à
    // ses propres paiements dans la requête même (sinon 404, comme inexistant).
    $sql = "
        SELECT p.idPaiement, p.idClient, p.montant, p.datePaiement, p.telephone, p.operateur,
               p.referenceCampay, p.referenceExterne, p.idReparation,
               r.titre AS reparation_titre,
               i.idIntervention, i.type AS intervention_type,
               v.marque, v.modele, v.immatriculation,
               u.nom AS client_nom, u.prenom AS client_prenom,
               CASE WHEN c.typeClient = 'ENTREPRISE' THEN e.raisonSociale END AS raisonSociale,
               g.nomGarage AS garage_nom
        FROM paiement p
        JOIN reparation r ON r.idReparation = p.idReparation
        JOIN intervention i ON i.idIntervention = r.idIntervention
        LEFT JOIN vehicule v ON v.idVehicule = i.idVehicule
        JOIN utilisateur u ON u.idUtilisateur = p.idClient
        LEFT JOIN client c ON c.idClient = p.idClient
        LEFT JOIN entreprise e ON e.idClient = p.idClient
        LEFT JOIN garage g ON g.idGarage = i.idGarage
        WHERE p.idPaiement = ? AND p.idReparation IS NOT NULL AND p.statut = 'PAYE'
    ";
    $params = [$paiement_id];
    if ($role === ROLE_CLIENT) {
        $sql .= ' AND p.idClient = ?';
        $params[] = $_SESSION['user_id'];
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        http_response_code(404);
        exit('Reçu introuvable');
    }

    // Démo CamPay : le montant débité est plafonné (campayChargedAmount()) ;
    // le plafond n'est cité que s'il s'applique réellement à ce montant.
    $demoNote = null;
    if (campayIsDemo()) {
        $due = (int)round((float)$paiement['montant']);
        $charged = campayChargedAmount($due);
        $demoNote = 'Mode démonstration : montant réellement débité plafonné'
            . ($charged < $due
                ? ' à ' . receiptFormatAmount($charged) . ' par l\'environnement de démonstration CamPay ; le montant indiqué est le montant dû.'
                : ' par l\'environnement de démonstration CamPay.');
    }

    $pdf = receiptBuildPdf($paiement, $demoNote);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="recu_paiement_' . $paiement_id . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;

} catch (Throwable $e) {
    error_log('[SmartAutoTrack] download_receipt : ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur lors de la génération du reçu');
}
