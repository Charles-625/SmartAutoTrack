<?php
require_once 'config/config.php';

/**
 * Aiguillage vers le tableau de bord du rôle connecté (admin/, technicien/,
 * client/, garage/). Le rôle vient de la session, posée à la connexion à
 * partir de la base — jamais d'un paramètre de la requête.
 *
 * Accès : tout utilisateur connecté (sinon renvoi vers la connexion).
 */

requireAuth();

// Redirection selon le rôle
switch ($_SESSION['role']) {
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
        redirect('auth/login.php');
}
?>
