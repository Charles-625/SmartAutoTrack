<?php
/**
 * SmartAutoTrack — Détection de rôle et profil utilisateur (nouveau schéma).
 *
 * Le nouveau schéma de `charles` n'a plus de colonne `role` sur `utilisateur` :
 * le rôle se déduit de la table dans laquelle l'identifiant apparaît
 * (administrateur / client / technicien). Ce fichier centralise cette
 * détection pour que le reste du code n'ait jamais à la refaire à la main.
 *
 * À inclure explicitement là où nécessaire :
 *   require_once '../config/roles.php';
 *
 * Ne modifie rien en base ; lecture seule.
 */

require_once __DIR__ . '/config.php';

/**
 * Détermine le rôle d'un utilisateur (ROLE_ADMIN, ROLE_CLIENT, ROLE_TECHNICIEN
 * — constantes définies dans config.php) à partir de son idUtilisateur.
 *
 * @return string|null null si l'identifiant n'existe dans aucune table de rôle.
 */
function getUserRole(PDO $conn, int $idUtilisateur): ?string {
    $stmt = $conn->prepare(
        "SELECT 'admin' AS role FROM administrateur WHERE idAdministrateur = ?
         UNION ALL
         SELECT 'client' FROM client WHERE idClient = ?
         UNION ALL
         SELECT 'technicien' FROM technicien WHERE idTechnicien = ?
         UNION ALL
         SELECT 'garage' FROM garage WHERE idUtilisateur = ?
         LIMIT 1"
    );
    $stmt->execute([$idUtilisateur, $idUtilisateur, $idUtilisateur, $idUtilisateur]);
    $role = $stmt->fetchColumn();
    return $role !== false ? $role : null;
}

/**
 * Charge le profil complet d'un utilisateur : les colonnes communes de
 * `utilisateur`, fusionnées avec les colonnes propres à son rôle.
 *
 * Forme du tableau retourné :
 *   idUtilisateur, nom, prenom, email, telephone, motDePasse,
 *   themePreference, photoProfil, role,
 *   + selon le rôle :
 *     - technicien : idGarage, specialite, statutValidation, competences, experience
 *     - client     : typeClient, adresse (et raisonSociale/idEntreprise si entreprise)
 *     - admin      : (rien de plus)
 *
 * @return array|null null si l'utilisateur n'existe pas ou n'a aucun rôle.
 */
function getUserProfile(PDO $conn, int $idUtilisateur): ?array {
    $stmt = $conn->prepare('SELECT * FROM utilisateur WHERE idUtilisateur = ?');
    $stmt->execute([$idUtilisateur]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) return null;

    $role = getUserRole($conn, $idUtilisateur);
    if ($role === null) return null;
    $profile['role'] = $role;

    if ($role === ROLE_TECHNICIEN) {
        $stmt = $conn->prepare('SELECT idGarage, specialite, statutValidation, competences, experience FROM technicien WHERE idTechnicien = ?');
        $stmt->execute([$idUtilisateur]);
        $profile += $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($role === ROLE_CLIENT) {
        $stmt = $conn->prepare('SELECT typeClient FROM client WHERE idClient = ?');
        $stmt->execute([$idUtilisateur]);
        $profile += $stmt->fetch(PDO::FETCH_ASSOC);

        if ($profile['typeClient'] === 'ENTREPRISE') {
            $stmt = $conn->prepare('SELECT idEntreprise, raisonSociale, adresse FROM entreprise WHERE idClient = ?');
            $stmt->execute([$idUtilisateur]);
            $profile += ($stmt->fetch(PDO::FETCH_ASSOC) ?: ['idEntreprise' => null, 'raisonSociale' => null, 'adresse' => null]);
        } else {
            $stmt = $conn->prepare('SELECT adresse FROM particulier WHERE idClient = ?');
            $stmt->execute([$idUtilisateur]);
            $profile += ($stmt->fetch(PDO::FETCH_ASSOC) ?: ['adresse' => null]);
        }
    } elseif ($role === ROLE_GARAGE) {
        $stmt = $conn->prepare('SELECT idGarage, nomGarage, adresse, statutGarage FROM garage WHERE idUtilisateur = ?');
        $stmt->execute([$idUtilisateur]);
        $profile += $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $profile;
}

/**
 * Recherche un utilisateur par email (utilisé par l'authentification).
 * Retourne la ligne brute de `utilisateur` (avec motDePasse), ou null.
 */
function findUserByEmail(PDO $conn, string $email): ?array {
    $stmt = $conn->prepare('SELECT * FROM utilisateur WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Un technicien (ou un garage) ne peut se connecter que si son compte a été
 * validé par un administrateur. Les clients et administrateurs n'ont pas
 * cette notion de validation dans le nouveau schéma : ils sont utilisables
 * dès leur création.
 */
function isAccountUsable(array $profile): bool {
    if ($profile['role'] === ROLE_TECHNICIEN) {
        return ($profile['statutValidation'] ?? null) === 'VALIDE';
    }
    if ($profile['role'] === ROLE_GARAGE) {
        return ($profile['statutGarage'] ?? null) === 'VALIDE';
    }
    return true;
}

/**
 * Message clair à afficher à la connexion quand isAccountUsable() est faux —
 * jamais le générique "email ou mot de passe incorrect" (identifiants
 * corrects, c'est bien le STATUT du compte qui bloque). Ne s'appelle qu'après
 * avoir vérifié le mot de passe, pour ne jamais révéler le statut d'un compte
 * à partir du seul email.
 */
function accountStatusMessage(array $profile): string {
    $statut = $profile['role'] === ROLE_TECHNICIEN ? ($profile['statutValidation'] ?? null)
        : ($profile['role'] === ROLE_GARAGE ? ($profile['statutGarage'] ?? null) : null);

    switch ($statut) {
        case 'EN_ATTENTE':
            return 'Votre compte est en attente de validation par un administrateur.';
        case 'REJETE':
            return 'Votre demande de compte a été rejetée. Contactez l\'administrateur pour plus d\'informations.';
        case 'SUSPENDU':
            return 'Votre compte est actuellement désactivé. Veuillez contacter l\'administrateur.';
        default:
            return 'Votre compte est actuellement désactivé. Veuillez contacter l\'administrateur.';
    }
}
