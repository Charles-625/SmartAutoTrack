<?php
/**
 * Fonctions d'authentification Google OAuth 2.0.
 *
 * Implémente le flux officiel Google OAuth 2.0 Authorization Code Flow
 * via cURL natif PHP (sans SDK externe lourd).
 *
 * Paramètres lus via appConfig() :
 * - GOOGLE_CLIENT_ID
 * - GOOGLE_CLIENT_SECRET
 * - GOOGLE_REDIRECT_URI (facultatif, déduit par défaut : SITE_URL/auth/google_callback.php)
 */

require_once __DIR__ . '/../config/config.php';

const GOOGLE_AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
const GOOGLE_USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

/**
 * Indique si les identifiants Google OAuth 2.0 sont configurés.
 */
function isGoogleOAuthReady(): bool {
    $clientId = appConfig('GOOGLE_CLIENT_ID', '');
    $clientSecret = appConfig('GOOGLE_CLIENT_SECRET', '');
    return !empty(trim((string)$clientId)) && !empty(trim((string)$clientSecret));
}

/**
 * Renvoie l'URL de redirection (callback) configurée ou calculée automatiquement.
 */
function getGoogleRedirectUri(): string {
    $custom = appConfig('GOOGLE_REDIRECT_URI', '');
    if (!empty(trim((string)$custom))) {
        return trim($custom);
    }
    return SITE_URL . 'auth/google_callback.php';
}

/**
 * Génère l'URL d'autorisation Google vers laquelle rediriger l'utilisateur.
 *
 * @param string $state Jeton aléatoire anti-CSRF stocké en session.
 * @return string URL Google complète.
 */
function buildGoogleAuthUrl(string $state): string {
    $clientId = appConfig('GOOGLE_CLIENT_ID', '');
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => getGoogleRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'state' => $state,
        'prompt' => 'select_account',
    ];

    return GOOGLE_AUTH_ENDPOINT . '?' . http_build_query($params);
}

/**
 * Échange le code d'autorisation contre un token d'accès auprès de Google.
 *
 * @param string $code Code retourné par Google dans ?code=...
 * @return array{success: bool, accessToken: ?string, error: ?string}
 */
function exchangeGoogleCode(string $code): array {
    $clientId = appConfig('GOOGLE_CLIENT_ID', '');
    $clientSecret = appConfig('GOOGLE_CLIENT_SECRET', '');

    $postData = [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => getGoogleRedirectUri(),
        'grant_type' => 'authorization_code',
    ];

    $ch = curl_init(GOOGLE_TOKEN_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[Google OAuth] Erreur cURL token : ' . $curlErr);
        return ['success' => false, 'accessToken' => null, 'error' => 'Erreur de connexion au serveur Google.'];
    }

    $data = json_decode((string)$response, true);
    if ($httpCode === 200 && !empty($data['access_token'])) {
        return ['success' => true, 'accessToken' => $data['access_token'], 'error' => null];
    }

    $errorMsg = $data['error_description'] ?? ($data['error'] ?? 'Échec de l\'échange de code d\'autorisation Google');
    error_log('[Google OAuth] Échange token échoué (' . $httpCode . ') : ' . $errorMsg);
    return ['success' => false, 'accessToken' => null, 'error' => $errorMsg];
}

/**
 * Récupère le profil de l'utilisateur Google à partir de l'access token.
 *
 * @param string $accessToken Jeton d'accès OAuth Google.
 * @return array{success: bool, user: ?array, error: ?string}
 */
function fetchGoogleUserProfile(string $accessToken): array {
    $ch = curl_init(GOOGLE_USERINFO_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[Google OAuth] Erreur cURL userinfo : ' . $curlErr);
        return ['success' => false, 'user' => null, 'error' => 'Erreur lors de la récupération des informations de profil Google.'];
    }

    $data = json_decode((string)$response, true);
    if ($httpCode === 200 && !empty($data['email'])) {
        return ['success' => true, 'user' => $data, 'error' => null];
    }

    $errorMsg = $data['error_description'] ?? 'Impossible de récupérer le profil Google';
    return ['success' => false, 'user' => null, 'error' => $errorMsg];
}
