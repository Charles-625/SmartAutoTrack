<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

// Jusqu'ici cet endpoint n'était protégé par aucun jeton CSRF : un formulaire
// piégé sur un site tiers, visité par un utilisateur connecté, aurait pu lui
// faire envoyer un message à son insu.
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Session expirée, merci de recharger la page.']);
    exit;
}

$destinataire_id = filter_var($_POST['destinataire_id'] ?? null, FILTER_VALIDATE_INT);
$sujet = sanitize($_POST['sujet'] ?? '');
$contenu = sanitize($_POST['contenu'] ?? '');

if (!$destinataire_id || empty($sujet) || empty($contenu)) {
    echo json_encode(['success' => false, 'message' => 'Tous les champs sont requis']);
    exit;
}

if ($destinataire_id === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Destinataire introuvable']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Vérifier que le destinataire existe, que son compte est utilisable (un
    // technicien/garage non validé ne peut pas recevoir de messages), ET que
    // son rôle diffère du nôtre. La messagerie est volontairement ouverte
    // ENTRE rôles différents (cf. le sélecteur de destinataire de
    // messages/index.php, qui exclut déjà son propre rôle) — mais rien ne
    // l'imposait côté serveur : n'importe quel compte pouvait, en postant
    // directement sur cet endpoint, écrire à n'importe quel autre compte de
    // la plateforme (même rôle inclus), sans lien avec son propre périmètre.
    // Un seul message générique en cas d'échec, quelle qu'en soit la raison
    // exacte : ne pas laisser deviner si un destinataire existe.
    $destinataire = getUserProfile($conn, $destinataire_id);

    if (!$destinataire || !isAccountUsable($destinataire) || $destinataire['role'] === $_SESSION['role']) {
        echo json_encode(['success' => false, 'message' => 'Destinataire introuvable']);
        exit;
    }

    // Insérer le message
    $stmt = $conn->prepare("
        INSERT INTO messages (expediteur_id, destinataire_id, sujet, contenu, lu)
        VALUES (?, ?, ?, ?, 'non')
    ");
    $stmt->execute([$_SESSION['user_id'], $destinataire_id, $sujet, $contenu]);

    // Créer une notification pour le destinataire
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, titre, message)
        VALUES (?, 'message', 'Nouveau message', ?)
    ");
    $message_notification = "Vous avez reçu un nouveau message de " . $_SESSION['prenom'] . " " . $_SESSION['nom'];
    $stmt->execute([$destinataire_id, $message_notification]);

    echo json_encode(['success' => true, 'message' => 'Message envoyé avec succès']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'envoi du message']);
}
?>
