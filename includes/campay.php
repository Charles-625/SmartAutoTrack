<?php
/**
 * Client de l'API de paiement Mobile Money CamPay (MTN / Orange, XAF).
 *
 * Configuration (config/local.php ou variables d'environnement) :
 * CAMPAY_USE_DEMO, CAMPAY_BASE_URL, CAMPAY_TOKEN ou CAMPAY_USERNAME/PASSWORD,
 * CAMPAY_WEBHOOK_KEY, CAMPAY_SIMULATION, CAMPAY_DEMO_MAX_AMOUNT.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/http_client.php';

/** Erreur de paiement dont le message peut être affiché tel quel au client. */
class CampayException extends RuntimeException {}

/**
 * URL de l'API CamPay, sans barre finale : CAMPAY_BASE_URL si renseignée,
 * sinon démo ou production selon CAMPAY_USE_DEMO (démo par défaut).
 */
function campayBaseUrl(): string {
    $url = (string)appConfig('CAMPAY_BASE_URL', '');
    if ($url === '') {
        $url = appConfigBool('CAMPAY_USE_DEMO', true) ? 'https://demo.campay.net' : 'https://www.campay.net';
    }
    return rtrim($url, '/');
}

/** Mode simulation : aucun appel réseau, chaque paiement réussit (développement hors ligne). */
function campayIsSimulation(): bool {
    return appConfigBool('CAMPAY_SIMULATION', false);
}

/** Identifiants CamPay présents (jeton permanent ou couple identifiant/mot de passe), ou mode simulation. */
function campayIsConfigured(): bool {
    return campayIsSimulation()
        || appConfig('CAMPAY_TOKEN')
        || (appConfig('CAMPAY_USERNAME') && appConfig('CAMPAY_PASSWORD'));
}

/** L'API utilisée est celle de démonstration (aucun argent réel débité). */
function campayIsDemo(): bool {
    return parse_url(campayBaseUrl(), PHP_URL_HOST) === 'demo.campay.net';
}

/**
 * Montant réellement débité par CamPay. La démo CamPay refuse les montants
 * supérieurs à 25 XAF : le montant est alors plafonné à CAMPAY_DEMO_MAX_AMOUNT
 * (0 pour désactiver), le montant dû restant celui enregistré en base.
 */
function campayChargedAmount(int $amount): int {
    $max = (int)appConfig('CAMPAY_DEMO_MAX_AMOUNT', 25);
    return campayIsDemo() && $max > 0 ? min($amount, $max) : $amount;
}

/**
 * Numéro Mobile Money camerounais au format attendu par CamPay (2376XXXXXXXX),
 * ou null s'il n'est pas valide.
 */
function campayNormalizePhone(string $phone): ?string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 9) {
        $digits = '237' . $digits;
    }
    return preg_match('/^2376\d{8}$/', $digits) ? $digits : null;
}

/** Statut CamPay (SUCCESSFUL / FAILED / PENDING) vers le statut de la table paiement. */
function campayStatusToPaiement(string $campayStatus): string {
    switch (strtoupper($campayStatus)) {
        case 'SUCCESSFUL':
            return 'PAYE';
        case 'FAILED':
            return 'ECHOUE';
        default:
            return 'EN_ATTENTE';
    }
}

/**
 * Jeton d'accès à l'API : CAMPAY_TOKEN s'il est configuré, sinon jeton
 * temporaire demandé à /api/token/ avec CAMPAY_USERNAME/PASSWORD.
 *
 * @throws CampayException si CamPay refuse les identifiants.
 */
function campayAccessToken(): string {
    $token = (string)appConfig('CAMPAY_TOKEN', '');
    if ($token !== '') {
        return $token;
    }
    $response = httpJsonRequest('POST', campayBaseUrl() . '/api/token/', [], [
        'username' => (string)appConfig('CAMPAY_USERNAME', ''),
        'password' => (string)appConfig('CAMPAY_PASSWORD', ''),
    ]);
    if ($response['status'] !== 200 || empty($response['data']['token'])) {
        error_log('[SmartAutoTrack] CamPay : obtention du jeton impossible (HTTP ' . $response['status'] . ')');
        throw new CampayException('Le service de paiement est momentanément indisponible.');
    }
    return $response['data']['token'];
}

/**
 * Appel authentifié à l'API CamPay. Toute réponse hors 2xx est journalisée
 * côté serveur et convertie en CampayException (avec le message de CamPay
 * quand il en fournit un).
 *
 * @param string     $method 'GET' ou 'POST'.
 * @param string     $path   Chemin de l'API (ex. '/api/collect/').
 * @param array|null $body   Corps JSON, ou null.
 * @return array Réponse JSON décodée (tableau vide si non JSON).
 */
function campayRequest(string $method, string $path, ?array $body = null): array {
    $response = httpJsonRequest($method, campayBaseUrl() . $path, ['Authorization' => 'Token ' . campayAccessToken()], $body);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = is_array($response['data']) ? ($response['data']['message'] ?? null) : null;
        error_log('[SmartAutoTrack] CamPay ' . $path . ' : HTTP ' . $response['status'] . ' ' . substr($response['raw'], 0, 300));
        throw new CampayException($message ? 'CamPay : ' . $message : 'Le service de paiement a refusé la demande.');
    }
    return is_array($response['data']) ? $response['data'] : [];
}

/**
 * Demande de paiement : le client reçoit une invite de confirmation sur son téléphone.
 *
 * @return array{reference:string, ussd_code:?string, operator:?string}
 */
function campayCollect(int $amount, string $phone, string $description, string $externalReference): array {
    if (campayIsSimulation()) {
        return ['reference' => 'SIM-' . $externalReference, 'ussd_code' => null, 'operator' => 'SIMULATION'];
    }
    $data = campayRequest('POST', '/api/collect/', [
        'amount' => (string)$amount,
        'currency' => 'XAF',
        'from' => $phone,
        'description' => mb_substr($description, 0, 100),
        'external_reference' => $externalReference,
    ]);
    if (empty($data['reference'])) {
        throw new CampayException('Réponse inattendue du service de paiement.');
    }
    return [
        'reference' => (string)$data['reference'],
        'ussd_code' => $data['ussd_code'] ?? null,
        'operator' => $data['operator'] ?? null,
    ];
}

/** @return array Transaction CamPay (status, amount, operator, operator_reference, ...). */
function campayTransactionStatus(string $reference): array {
    if (campayIsSimulation()) {
        return ['reference' => $reference, 'status' => 'SUCCESSFUL', 'operator' => 'SIMULATION'];
    }
    return campayRequest('GET', '/api/transaction/' . rawurlencode($reference) . '/');
}

/** Décodage base64url (alphabet -_ sans remplissage) utilisé par les JWT. */
function campayBase64UrlDecode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return (string)base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * Vérifie la signature d'une notification CamPay : un JWT signé en HS256
 * avec la clé webhook de l'application.
 */
function campayVerifyWebhookSignature(string $jwt, ?string $key = null, ?int $now = null): bool {
    $key = $key ?? (string)appConfig('CAMPAY_WEBHOOK_KEY', '');
    if ($key === '' || substr_count($jwt, '.') !== 2) {
        return false;
    }
    [$header64, $payload64, $signature64] = explode('.', $jwt);
    $header = json_decode(campayBase64UrlDecode($header64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return false;
    }
    $expected = hash_hmac('sha256', $header64 . '.' . $payload64, $key, true);
    if (!hash_equals($expected, campayBase64UrlDecode($signature64))) {
        return false;
    }
    $payload = json_decode(campayBase64UrlDecode($payload64), true);
    if (is_array($payload) && isset($payload['exp']) && (int)$payload['exp'] < ($now ?? time())) {
        return false;
    }
    return true;
}
