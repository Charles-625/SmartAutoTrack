<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/ai.php';
require_once '../includes/subscription.php';

/**
 * Endpoint AJAX (JSON) de l'assistant IA, appelé par assets/js/assistant.js.
 *
 * Accès : client ou admin connecté, POST uniquement avec jeton CSRF.
 * POST action=reset : efface l'historique de conversation en session.
 * POST message=... : envoie la question au modèle avec un contexte propre
 * au rôle (données du client, ou vue globale pour l'admin) et l'historique
 * récent. Longueur et débit limités (AI_MAX_MESSAGE_LENGTH, aiAllowMessage).
 * Un client sans abonnement Premium est en plus limité à SUB_FREE_AI_PER_DAY
 * messages par jour (subscriptionAiAllow(), compteur ia_usage ; sans effet
 * pour l'admin, le Premium, ou tant que la migration n'est pas appliquée).
 *
 * Réponse : {success, answer, remaining} ou {success:false, message, remaining} ;
 * remaining = messages gratuits restants aujourd'hui (null si illimité),
 * affiché par client/assistant.php. Toute la logique (contexte, appel au
 * fournisseur, historique) est dans includes/ai.php.
 */
header('Content-Type: application/json');

requireJsonAuth();
$role = $_SESSION['role'];
if (!in_array($role, [ROLE_CLIENT, ROLE_ADMIN], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Accès refusé']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyRequestCSRF()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Session expirée, merci de recharger la page.']));
}

if (($_POST['action'] ?? '') === 'reset') {
    aiResetHistory($role);
    exit(json_encode(['success' => true]));
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Écrivez une question.']));
}
if (mb_strlen($message) > AI_MAX_MESSAGE_LENGTH) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Message trop long (' . AI_MAX_MESSAGE_LENGTH . ' caractères maximum).']));
}
if (!aiAllowMessage()) {
    http_response_code(429);
    exit(json_encode(['success' => false, 'message' => 'Vous avez envoyé beaucoup de messages. Réessayez dans quelques minutes.']));
}

$conn = null;
$quotaCounted = false;
try {
    $conn = (new Database())->getConnection();
    // Quota quotidien de la formule gratuite, compté avant l'appel à l'IA
    // (et rendu par subscriptionAiRefund() si le fournisseur ne répond pas).
    if ($role === ROLE_CLIENT && !subscriptionAiAllow($conn, (int)$_SESSION['user_id'])) {
        http_response_code(429);
        exit(json_encode([
            'success' => false,
            'message' => 'Vous avez utilisé vos ' . SUB_FREE_AI_PER_DAY . ' messages gratuits d\'aujourd\'hui. Passez au Premium pour un assistant illimité.',
            'remaining' => 0,
        ]));
    }
    $quotaCounted = $role === ROLE_CLIENT;
    $context = $role === ROLE_ADMIN ? aiAdminContext($conn) : aiClientContext($conn, (int)$_SESSION['user_id']);
    $messages = array_merge(
        [['role' => 'system', 'content' => aiSystemPrompt($role, $context)]],
        aiHistory($role),
        [['role' => 'user', 'content' => $message]]
    );
    $answer = aiChat($messages);
    aiRemember($role, $message, $answer);
    $remaining = $role === ROLE_CLIENT ? subscriptionAiRemaining($conn, (int)$_SESSION['user_id']) : null;
    echo json_encode(['success' => true, 'answer' => $answer, 'remaining' => $remaining]);
} catch (AiException $e) {
    http_response_code(503);
    $text = $e->getMessage();
    // Le détail technique de l'erreur n'est montré qu'à l'admin.
    if ($role === ROLE_ADMIN && $e->detail !== '') {
        $text .= ' Détail : ' . $e->detail;
    }
    // Pas de réponse : le message ne compte pas dans le quota gratuit.
    $remaining = null;
    if ($quotaCounted && $conn) {
        try {
            subscriptionAiRefund($conn, (int)$_SESSION['user_id']);
            $remaining = subscriptionAiRemaining($conn, (int)$_SESSION['user_id']);
        } catch (Throwable $refundError) {
            error_log('[SmartAutoTrack] assistant_chat (quota) : ' . $refundError->getMessage());
        }
    }
    echo json_encode(['success' => false, 'message' => $text, 'remaining' => $remaining]);
} catch (Throwable $e) {
    error_log('[SmartAutoTrack] assistant_chat : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur de l\'assistant IA.']);
}
