<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/activity_log.php';
require_once '../includes/login_throttle.php';
require_once '../includes/google_oauth.php';

/**
 * Traitement du retour (callback) de l'authentification Google OAuth 2.0.
 *
 * Échange le code temporaire contre un jeton d'accès, récupère le profil
 * de l'utilisateur Google, lie le compte existant ou en crée un nouveau
 * (rôle client particulier), puis ouvre la session sécurisée.
 */

if (!empty($_GET['error'])) {
    $errorDescription = $_GET['error_description'] ?? $_GET['error'];
    redirect('auth/login.php?google_error=' . urlencode('Connexion Google annulée ou refusée : ' . $errorDescription));
}

// 1. Contrôle du jeton CSRF "state"
$expectedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']);

$receivedState = $_GET['state'] ?? '';
if (empty($expectedState) || !hash_equals($expectedState, $receivedState)) {
    redirect('auth/login.php?google_error=' . urlencode('Session expirée ou requête invalide (erreur de jeton CSRF Google).'));
}

// 2. Contrôle du code d'autorisation
$code = $_GET['code'] ?? '';
if (empty($code)) {
    redirect('auth/login.php?google_error=' . urlencode('Aucun code d\'autorisation reçu de la part de Google.'));
}

// 3. Échange du code contre un jeton d'accès
$tokenResult = exchangeGoogleCode($code);
if (!$tokenResult['success'] || empty($tokenResult['accessToken'])) {
    $err = $tokenResult['error'] ?? 'Échec de communication avec Google.';
    redirect('auth/login.php?google_error=' . urlencode('Impossible de valider la connexion Google : ' . $err));
}

// 4. Récupération des informations de profil
$profileResult = fetchGoogleUserProfile($tokenResult['accessToken']);
if (!$profileResult['success'] || empty($profileResult['user'])) {
    $err = $profileResult['error'] ?? 'Données de profil inaccessibles.';
    redirect('auth/login.php?google_error=' . urlencode('Impossible de récupérer votre profil Google : ' . $err));
}

$gUser = $profileResult['user'];
$googleId = trim((string)($gUser['sub'] ?? ''));
$email = trim(strtolower((string)($gUser['email'] ?? '')));
$firstName = trim((string)($gUser['given_name'] ?? ''));
$lastName = trim((string)($gUser['family_name'] ?? ''));

if (empty($firstName) && empty($lastName)) {
    $fullName = trim((string)($gUser['name'] ?? ''));
    $parts = explode(' ', $fullName, 2);
    $firstName = $parts[0] ?? 'Utilisateur';
    $lastName = $parts[1] ?? '';
}

if (empty($email)) {
    redirect('auth/login.php?google_error=' . urlencode('Votre compte Google n\'a pas partagé d\'adresse email valide.'));
}

$db = new Database();
$conn = $db->getConnection();

// 5. Recherche de l'utilisateur par googleId ou par email
$user = null;
if (!empty($googleId)) {
    $stmt = $conn->prepare("SELECT * FROM utilisateur WHERE googleId = ? LIMIT 1");
    $stmt->execute([$googleId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user) {
    // Recherche par email
    $stmt = $conn->prepare("SELECT * FROM utilisateur WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($googleId)) {
        // Lier le googleId au compte existant
        $upStmt = $conn->prepare("UPDATE utilisateur SET googleId = ? WHERE idUtilisateur = ?");
        $upStmt->execute([$googleId, $user['idUtilisateur']]);
        $user['googleId'] = $googleId;
    }
}

// 6. Si l'utilisateur n'existe pas, création d'un nouveau compte client (particulier)
if (!$user) {
    $conn->beginTransaction();
    try {
        // Mot de passe aléatoire non devinable
        $randomPwd = hashPassword(bin2hex(random_bytes(16)));

        $insUser = $conn->prepare("
            INSERT INTO utilisateur (nom, prenom, email, motDePasse, googleId, dateCreation)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $insUser->execute([
            $lastName ?: 'Google',
            $firstName ?: 'Utilisateur',
            $email,
            $randomPwd,
            $googleId ?: null
        ]);
        $userId = (int)$conn->lastInsertId();

        // Créer l'entrée client
        $insClient = $conn->prepare("INSERT INTO client (idClient, typeClient) VALUES (?, 'PARTICULIER')");
        $insClient->execute([$userId]);

        // Créer l'entrée particulier
        $insPart = $conn->prepare("INSERT INTO particulier (idClient, adresse) VALUES (?, '')");
        $insPart->execute([$userId]);

        $conn->commit();

        // Récupérer le nouvel utilisateur
        $stmt = $conn->prepare("SELECT * FROM utilisateur WHERE idUtilisateur = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        logActivity($conn, $userId, 'INSCRIPTION_GOOGLE', 'Création automatique de compte client via Google OAuth');
    } catch (Exception $e) {
        $conn->rollBack();
        error_log('[Google OAuth] Erreur création de compte : ' . $e->getMessage());
        redirect('auth/login.php?google_error=' . urlencode('Erreur lors de la création de votre compte via Google.'));
    }
}

// 7. Vérification du statut du compte
$profile = getUserProfile($conn, (int)$user['idUtilisateur']);
if (!$profile || !isAccountUsable($profile)) {
    $statusMsg = $profile ? accountStatusMessage($profile) : 'Ce compte n\'est pas utilisable.';
    redirect('auth/login.php?google_error=' . urlencode($statusMsg));
}

// 8. Connexion réussie
session_regenerate_id(true);
loginThrottleClear($email);

$_SESSION['user_id'] = $user['idUtilisateur'];
$_SESSION['nom'] = $user['nom'];
$_SESSION['prenom'] = $user['prenom'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $profile['role'];

$statutMap = ['VALIDE' => 'actif', 'EN_ATTENTE' => 'pending', 'REJETE' => 'rejete'];
if ($profile['role'] === ROLE_TECHNICIEN) {
    $_SESSION['statut'] = $statutMap[$profile['statutValidation']] ?? 'pending';
} elseif ($profile['role'] === ROLE_GARAGE) {
    $_SESSION['statut'] = $statutMap[$profile['statutGarage']] ?? 'pending';
} else {
    $_SESSION['statut'] = 'actif';
}

logActivity($conn, (int)$user['idUtilisateur'], 'CONNEXION_GOOGLE', 'Connexion réussie via Google OAuth');

// Redirection selon le rôle
switch ($profile['role']) {
    case 'admin':
        redirect('admin/dashboard.php');
        break;
    case 'technicien':
        redirect('technicien/dashboard.php');
        break;
    case 'client':
        redirect('client/dashboard.php');
        break;
    case 'garage':
        redirect('garage/dashboard.php');
        break;
    default:
        redirect('dashboard.php');
}
