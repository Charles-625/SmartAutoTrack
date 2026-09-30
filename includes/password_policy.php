<?php
/**
 * Règles de robustesse des mots de passe, communes à toutes les pages qui
 * en définissent un (inscription, profil, création de garage/technicien)
 * et au script CLI scripts/set_password.php.
 *
 * Le contrôle navigateur (assets/js/main.js, champs data-password-policy)
 * n'est qu'un confort : c'est cette validation serveur qui fait foi.
 */

const PASSWORD_MIN_LENGTH = 8;

/**
 * Règles non respectées par $password, en libellés courts
 * (ex. ['une lettre majuscule', 'un chiffre']). Tableau vide = conforme.
 */
function passwordPolicyMissing(string $password): array {
    $missing = [];
    if (mb_strlen($password, 'UTF-8') < PASSWORD_MIN_LENGTH) $missing[] = 'au moins ' . PASSWORD_MIN_LENGTH . ' caractères';
    if (!preg_match('/[a-z]/', $password)) $missing[] = 'une lettre minuscule';
    if (!preg_match('/[A-Z]/', $password)) $missing[] = 'une lettre majuscule';
    if (!preg_match('/[0-9]/', $password)) $missing[] = 'un chiffre';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $missing[] = 'un caractère spécial (ex. ! @ # $ %)';
    return $missing;
}

/**
 * Message d'erreur prêt à afficher, ou null si le mot de passe est conforme.
 */
function passwordPolicyError(string $password, string $label = 'Le mot de passe'): ?string {
    $missing = passwordPolicyMissing($password);
    return $missing ? $label . ' doit contenir ' . implode(', ', $missing) . '.' : null;
}
