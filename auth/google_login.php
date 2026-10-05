<?php
require_once '../config/config.php';
require_once '../includes/google_oauth.php';

/**
 * Point d'entrée de la connexion avec Google.
 *
 * Vérifie la configuration, génère un jeton state anti-CSRF en session
 * puis redirige l'utilisateur vers la page de consentement Google.
 */

if (!isGoogleOAuthReady()) {
    $msg = 'La connexion avec Google n\'est pas encore configurée (GOOGLE_CLIENT_ID ou GOOGLE_CLIENT_SECRET manquant dans config/local.php).';
    redirect('auth/login.php?google_error=' . urlencode($msg));
}

// Jeton aléatoire anti-CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$authUrl = buildGoogleAuthUrl($state);
header('Location: ' . $authUrl);
exit();
