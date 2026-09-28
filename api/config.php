<?php
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

$DB_HOST = getenv('SAT_DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('SAT_DB_PORT') ?: '3306';
$DB_NAME = getenv('SAT_DB_NAME') ?: 'smartautotrack';
$DB_USER = getenv('SAT_DB_USER') ?: 'root';
$DB_PASS = getenv('SAT_DB_PASS') ?: '';

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

function json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function auth_user() {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_auth() {
  $u = auth_user();
  if (!$u) json(['error' => 'non_authentifie'], 401);
  return $u;
}

function only_roles($roles) {
  $u = require_auth();
  if (!in_array($u['statut'], $roles, true)) json(['error' => 'acces_interdit'], 403);
  return $u;
}

// Notifications FCM (optionnel)
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


