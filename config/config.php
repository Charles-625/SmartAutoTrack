<?php
/**
 * Configuration générale de SmartAutoTrack
 */

// Ne jamais afficher les erreurs PHP/SQL au visiteur : elles vont dans les logs serveur.
// Pour déboguer en local, définir la variable d'environnement HCH_DEBUG=1.
ini_set('display_errors', getenv('HCH_DEBUG') ? '1' : '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

if (!defined('SITE_NAME')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $documentRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $basePath = '/HCH/';

    if ($documentRoot && $projectRoot && strpos($projectRoot, $documentRoot) === 0) {
        $relativePath = trim(substr($projectRoot, strlen($documentRoot)), '/');
        $basePath = $relativePath === '' ? '/' : '/' . $relativePath . '/';
    }

    define('SITE_NAME', 'SmartAutoTrack');
    define('BASE_PATH', $basePath);
    define('SITE_URL', $protocol . '://' . $host . $basePath);
    define('UPLOAD_PATH', __DIR__ . '/../uploads/');
    define('UPLOAD_URL', SITE_URL . 'uploads/');

    if (!file_exists(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0755, true);
    }
    if (!file_exists(UPLOAD_PATH . 'techniciens/')) {
        mkdir(UPLOAD_PATH . 'techniciens/', 0755, true);
    }

    define('ROLE_ADMIN', 'admin');
    define('ROLE_TECHNICIEN', 'technicien');
    define('ROLE_CLIENT', 'client');
    define('ROLE_GARAGE', 'garage');

    define('STATUT_ACTIF', 'actif');
    define('STATUT_PENDING', 'pending');
    define('STATUT_REJETE', 'rejete');

    define('NOTIF_ANOMALIE', 'anomalie');
    define('NOTIF_INTERVENTION', 'intervention');
    define('NOTIF_MESSAGE', 'message');
    define('NOTIF_RAPPORT', 'rapport');
    define('NOTIF_VALIDATION', 'validation');
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('HCHSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE_PATH,
        'domain' => '',
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Supprimer l'ancien cookie PHPSESSID global (path /) qui provoquait des connexions fantômes
if (isset($_COOKIE['PHPSESSID'])) {
    setcookie('PHPSESSID', '', time() - 42000, '/');
    unset($_COOKIE['PHPSESSID']);
}

/**
 * Paramètre de configuration : variable d'environnement du même nom si elle
 * est définie, sinon clé du tableau renvoyé par config/local.php (non versionné).
 */
function appConfig(string $key, $default = null) {
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    static $local = null;
    if ($local === null) {
        $file = __DIR__ . '/local.php';
        $loaded = is_file($file) ? require $file : [];
        $local = is_array($loaded) ? $loaded : [];
    }
    return $local[$key] ?? $default;
}

function appConfigBool(string $key, bool $default = false): bool {
    $value = appConfig($key);
    if ($value === null) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function redirect($url) {
    header('Location: ' . SITE_URL . ltrim($url, '/'));
    exit();
}

// requireAuth()/requireRole() ne lisent que la session : ils fonctionnent quel que
// soit le schéma de base tant que $_SESSION['user_id']/['role'] sont posés à la
// connexion. Depuis la migration vers le nouveau schéma, `role` n'est plus une
// colonne de la base : il est déterminé par config/roles.php::getUserRole()
// (regarde dans quelle table — administrateur/client/technicien — l'utilisateur
// apparaît), puis stocké en session par auth/login.php comme avant.
function requireAuth() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        redirect('auth/login.php');
    }
}

function requireRole($role) {
    requireAuth();
    if ($_SESSION['role'] !== $role) {
        redirect('dashboard.php');
    }
}

/**
 * Équivalent de requireAuth()/requireRole() pour les endpoints JSON :
 * répond 401/403 en JSON au lieu de rediriger vers une page HTML.
 */
function requireJsonAuth($role = null) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Non authentifié']));
    }
    if ($role !== null && $_SESSION['role'] !== $role) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => 'Accès refusé']));
    }
}

function formatDate($date) {
    return date('d/m/Y H:i', strtotime($date));
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Pour les endpoints AJAX : jeton accepté en champ POST `csrf_token` ou en
 * en-tête `X-CSRF-Token` (posé automatiquement par assets/js/main.js).
 */
function verifyRequestCSRF() {
    return verifyCSRFToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
}

/**
 * Échappe une valeur pour l'affichage HTML (anti-XSS).
 * double_encode = false : les champs saisis passent par sanitize() avant d'être
 * stockés, donc déjà encodés ; on n'affiche pas "&amp;#039;" mais toute balise
 * brute (<script>, etc.) est neutralisée.
 */
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validation serveur (jamais uniquement côté navigateur) pour un champ
 * "nom/prénom" : lettres, accents, espace, tiret et apostrophe (noms
 * composés type "Jean-Pierre", "O'Brien") — jamais de chiffre ni de symbole.
 * html_entity_decode() d'abord : sanitize() encode déjà l'apostrophe en
 * "&#039;" avant que cette fonction ne s'exécute (cf. h(), qui ne
 * ré-encode pas), donc un nom valide contenant une apostrophe arrive ici
 * sous forme encodée — sans ce décodage il serait rejeté à tort.
 */
function validateLettersOnly($value) {
    $decoded = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
    return $decoded !== '' && preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ' -]+$/u", $decoded) === 1;
}

/**
 * Validation serveur pour un champ "téléphone" : chiffres uniquement.
 */
function validateDigitsOnly($value) {
    return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
}

const PASSWORD_MIN_LENGTH = 8;

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

require_once __DIR__ . '/../includes/activity_log.php';

function destroySession() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    if (isset($_COOKIE['PHPSESSID'])) {
        setcookie('PHPSESSID', '', time() - 42000, '/');
    }
}

/**
 * Un compte supprimé, suspendu ou rejeté perd son accès dès sa requête
 * suivante, et pas seulement à sa prochaine connexion : sa session est vidée
 * et reçoit un nouvel identifiant (requireAuth() le renverra alors au login).
 */
function enforceActiveSession() {
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/roles.php';

    $conn = (new Database())->getConnection();
    if (!$conn) {
        return;
    }

    $profile = getUserProfile($conn, (int)$_SESSION['user_id']);
    if (!$profile || $profile['role'] !== ($_SESSION['role'] ?? null) || !isAccountUsable($profile)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}

enforceActiveSession();
