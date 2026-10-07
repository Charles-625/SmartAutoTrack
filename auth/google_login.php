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

if (!googleRedirectPathMatchesBase()) {
    $msg = 'Configuration Google incohérente : l\'URI de redirection (' . getGoogleRedirectUri() . ') doit commencer par ' . SITE_URL . ' (majuscules comprises). Corrigez GOOGLE_REDIRECT_URI dans config/local.php ou laissez-le vide.';
    redirect('auth/login.php?google_error=' . urlencode($msg));
}

// Démarrer sur la même adresse que celle où Google renverra l'utilisateur,
// sinon la session (et donc le jeton state) est perdue au retour.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$sameOriginLogin = googleLoginUrlOnRedirectOrigin($protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if ($sameOriginLogin !== null && empty($_GET['origin_ok'])) {
    header('Location: ' . $sameOriginLogin . '?origin_ok=1');
    exit();
}

// Jeton aléatoire anti-CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$authUrl = buildGoogleAuthUrl($state);
header('Location: ' . $authUrl);
exit();
