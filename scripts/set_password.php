<?php
/**
 * Définit (ou verrouille) le mot de passe d'un compte. Usage en ligne de commande uniquement.
 *
 *   php scripts/set_password.php <email>            -> génère un mot de passe aléatoire fort et l'affiche
 *   php scripts/set_password.php <email> <motdepasse>
 *   php scripts/set_password.php <email> --lock     -> verrouille le compte (connexion impossible)
 *
 * Agit sur la table `utilisateur` (base par défaut : charles).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/password_policy.php';

$email = $argv[1] ?? '';
$arg = $argv[2] ?? null;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/set_password.php <email> [motdepasse|--lock]\n");
    exit(1);
}

// Verrouillage : une valeur qui n'est pas un hash valide fait toujours
// échouer password_verify(), donc aucune connexion n'est possible.
if ($arg === '--lock') {
    $hash = '!COMPTE_VERROUILLE';
    $password = null;
} else {
    if ($arg !== null) {
        $password = $arg;
    } else {
        // Tirage jusqu'à obtenir un mot de passe conforme aux règles (quelques essais au plus)
        do {
            $password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '!@'), '=');
        } while (passwordPolicyMissing($password));
    }
    // Exigence CLI (12 caractères minimum) en plus de la politique commune.
    if (strlen($password) < 12) {
        fwrite(STDERR, "Le mot de passe doit contenir au moins 12 caractères.\n");
        exit(1);
    }
    if ($pwError = passwordPolicyError($password)) {
        fwrite(STDERR, $pwError . "\n");
        exit(1);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    fwrite(STDERR, "Connexion à la base impossible (voir les logs PHP).\n");
    exit(1);
}

$stmt = $conn->prepare('UPDATE utilisateur SET motDePasse = ? WHERE email = ?');
$stmt->execute([$hash, $email]);

if ($stmt->rowCount() === 0) {
    fwrite(STDERR, "Aucun compte modifié (email introuvable ?).\n");
    exit(1);
}

if ($password === null) {
    echo "Compte $email verrouillé.\n";
} else {
    echo "Mot de passe de $email mis à jour.\n";
    if ($arg === null) {
        echo "Nouveau mot de passe (à noter, il ne sera plus affiché) : $password\n";
    }
}
