<?php
require_once 'config/config.php';

// Rediriger vers la page de paramètres appropriée selon le rôle
if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/settings.php');
            break;
        case 'technicien':
            header('Location: technicien/parametres.php');
            break;
        case 'client':
            header('Location: client/settings.php');
            break;
        case 'garage':
            header('Location: garage/parametres.php');
            break;
        default:
            header('Location: auth/login.php');
            break;
    }
} else {
    header('Location: auth/login.php');
}
exit;
?>