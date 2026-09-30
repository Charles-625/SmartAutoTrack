<?php
/**
 * Configuration et fonctions communes de l'API REST HÉRITÉE (dossier api/).
 *
 * Attention : cette API est antérieure au site actuel. Elle travaille sur
 * l'ANCIENNE base `smartautotrack` (tables users/vehicles/anomalies/
 * reparations/interventions/push_tokens, snake_case), et non sur la base
 * `charles` utilisée par le reste de l'application (config/database.php).
 * Elle a sa propre session ($_SESSION['user'], rôle dans `statut`) et ses
 * propres variables d'environnement (SAT_DB_*, SAT_FCM_SERVER_KEY).
 *
 * Ce fichier : gestion d'erreurs silencieuse (réponse JSON générique 500),
 * CORS par liste blanche, connexion PDO, helpers JSON et contrôle d'accès,
 * envoi de notifications push FCM. Inclus par api/index.php et api/obd_sim.php.
 */
// SmartAutoTrack - Configuration

// Ne jamais afficher les erreurs PHP/SQL : elles vont dans les logs serveur.
// Pour déboguer en local, définir la variable d'environnement HCH_DEBUG=1.
ini_set('display_errors', getenv('HCH_DEBUG') ? '1' : '0');
ini_set('log_errors', '1');
set_exception_handler(function ($e) {
  error_log('[SmartAutoTrack API] ' . get_class($e) . ': ' . $e->getMessage());
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode(['error' => 'erreur_serveur']);
  exit;
});

// CORS : liste blanche d'origines. Par défaut, seule l'origine du site lui-même est
// autorisée. Pour en ajouter : variable d'environnement HCH_CORS_ORIGINS
// (origines séparées par des virgules, ex. "https://app.exemple.com,http://localhost:3000").
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$allowedOrigins = [$scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')];
$extraOrigins = getenv('HCH_CORS_ORIGINS');
if ($extraOrigins) {
  foreach (explode(',', $extraOrigins) as $o) {
    $o = rtrim(trim($o), '/');
    if ($o !== '') $allowedOrigins[] = $o;
  }
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Vary: Origin');
if ($origin !== '') {
  if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  } elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(403);
    exit;
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Paramètres de la base héritée `smartautotrack` (surchargeables par l'environnement).
$DB_HOST = getenv('SAT_DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('SAT_DB_PORT') ?: '3306';
$DB_NAME = getenv('SAT_DB_NAME') ?: 'smartautotrack';
$DB_USER = getenv('SAT_DB_USER') ?: 'root';
$DB_PASS = getenv('SAT_DB_PASS') ?: '';

/**
 * Renvoie la connexion PDO à la base héritée, créée au premier appel puis
 * réutilisée (singleton statique). Erreurs en exceptions, lignes en tableaux
 * associatifs.
 *
 * @return PDO
 */
function db() {
  static $pdo = null;
  global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
  if ($pdo) return $pdo;
  $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
  $opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $opts);
  return $pdo;
}

/**
 * Envoie une réponse JSON et termine le script.
 *
 * @param mixed $data Données à encoder.
 * @param int   $code Code HTTP (200 par défaut).
 * @return never
 */
function json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Lit le corps de la requête comme JSON.
 *
 * @return array Données décodées, ou tableau vide si le corps est absent ou invalide.
 */
function read_json() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

/**
 * Renvoie l'utilisateur connecté à l'API (démarre la session si besoin).
 *
 * @return array|null Ligne `users` sans mot de passe (id_user, nom, email, statut), ou null.
 */
function auth_user() {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

/**
 * Exige une session API ouverte ; sinon répond 401 et termine.
 *
 * @return array Utilisateur connecté.
 */
function require_auth() {
  $u = auth_user();
  if (!$u) json(['error' => 'non_authentifie'], 401);
  return $u;
}

/**
 * Exige que l'utilisateur connecté ait l'un des rôles donnés ; sinon 401
 * (non connecté) ou 403 (rôle insuffisant) et fin du script.
 *
 * @param string[] $roles Rôles autorisés ('admin', 'technicien', 'client').
 * @return array Utilisateur connecté.
 */
function only_roles($roles) {
  $u = require_auth();
  if (!in_array($u['statut'], $roles, true)) json(['error' => 'acces_interdit'], 403);
  return $u;
}

// Notifications FCM (optionnel)
/**
 * Envoie une notification push via l'ancienne API HTTP de Firebase Cloud
 * Messaging. Sans clé SAT_FCM_SERVER_KEY, ne fait rien : les notifications
 * sont facultatives et ne doivent jamais bloquer l'action principale.
 *
 * @param string|string[] $tokens Jeton(s) d'appareil destinataires.
 * @param string          $title  Titre de la notification.
 * @param string          $body   Texte de la notification.
 * @param array           $data   Données supplémentaires transmises à l'application.
 * @return string|false Réponse brute de FCM, ou false (pas de clé, pas de jeton, erreur cURL).
 */
function fcm_send($tokens, $title, $body, $data = []) {
  $serverKey = getenv('SAT_FCM_SERVER_KEY') ?: '';
  if (!$serverKey || !$tokens) return false;
  $payload = [
    'registration_ids' => is_array($tokens) ? $tokens : [$tokens],
    'notification' => [ 'title' => $title, 'body' => $body ],
    'data' => $data,
  ];
  $ch = curl_init('https://fcm.googleapis.com/fcm/send');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: key=' . $serverKey,
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  return $err ? false : $resp;
}


