<?php
require_once 'config/config.php';

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
