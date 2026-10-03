<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/subscription.php';

/**
 * Endpoint AJAX (JSON) : lance le paiement Mobile Money d'un abonnement
 * Premium via CamPay. Appelé depuis client/abonnement.php.
 *
 * Accès : client connecté, POST avec jeton CSRF.
 * POST : periodicite ('MENSUEL' | 'ANNUEL'), nb_vehicules (entreprise
 *        seulement, ignoré pour un particulier), telephone (numéro Mobile
 *        Money à débiter).
 * Réponse : paiement_id, montant, ussd_code et operator ; la page interroge
 * ensuite ajax/campay_status.php avec ce paiement_id (l'abonnement devient
 * ACTIF quand le paiement passe à PAYE).
 *
 * Les contrôles (type de client, nombre de véhicules, prix, paiement déjà en
 * cours) et l'écriture des tables abonnement et paiement sont faits par
 * subscriptionStartPayment() (includes/subscription.php).
 */
header('Content-Type: application/json');

requireJsonAuth(ROLE_CLIENT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyRequestCSRF()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Session expirée, merci de recharger la page.']));
}

$periodicite = (string)($_POST['periodicite'] ?? '');
if (!in_array($periodicite, ['MENSUEL', 'ANNUEL'], true)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Choisissez un abonnement mensuel ou annuel.']));
}
// Absent ou invalide pour un particulier : 0 (la valeur est alors ignorée).
$nbVehicules = filter_var($_POST['nb_vehicules'] ?? 0, FILTER_VALIDATE_INT);

try {
    $conn = (new Database())->getConnection();
    $result = subscriptionStartPayment($conn, (int)$_SESSION['user_id'], $periodicite, $nbVehicules === false ? 0 : (int)$nbVehicules, (string)($_POST['telephone'] ?? ''));
    echo json_encode([
        'success' => true,
        'paiement_id' => $result['idPaiement'],
        'montant' => $result['montant'],
        'ussd_code' => $result['ussd_code'],
        'operator' => $result['operator'],
    ]);
} catch (CampayException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[SmartAutoTrack] subscription_collect : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors du lancement du paiement.']);
}
