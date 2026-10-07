<?php
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

/**
 * En-tête HTML commun à toutes les pages (inclus après la logique de la page).
 *
 * Variables facultatives définies par la page appelante AVANT l'inclusion :
 *   $pageTitle        titre de l'onglet (suffixé par SITE_NAME) ;
 *   $bodyClass        classe du <body> (ex. 'v2', 'gv2', 'tv2', 'av2' pour
 *                     les habillages par rôle, 'homev2' pour l'accueil) ;
 *   $hideNavbar       true pour masquer la barre de navigation historique
 *                     (les pages v2 affichent leur propre sidebar) ;
 *   $extraStylesheets feuilles CSS supplémentaires, relatives à SITE_URL ;
 *   $extraFonts       URL complètes de polices (Google Fonts).
 *
 * Publie le jeton CSRF dans <meta name="csrf-token"> (repris par
 * assets/js/main.js pour les requêtes AJAX) et ouvre <main>, refermé par
 * includes/footer.php.
 */

// Vérifier si l'utilisateur est connecté
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $isLoggedIn ? $_SESSION['role'] : null;
$userName = $isLoggedIn ? $_SESSION['nom'] . ' ' . $_SESSION['prenom'] : '';
$showNavbar = $isLoggedIn && empty($hideNavbar);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(isset($pageTitle) ? $pageTitle . ' - ' . SITE_NAME : SITE_NAME); ?></title>
    
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <?php
    if (!function_exists('asset_url')) {
        /**
         * URL d'un fichier de assets/ suivie de ?v=<date de modification> :
         * le navigateur recharge le fichier dès qu'il change, au lieu de
         * garder une ancienne copie en cache (ex. après un changement de
         * couleurs dans une feuille de style).
         *
         * @param string $rel Chemin relatif à la racine du site (ex. 'assets/css/style.css').
         * @return string URL à échapper avec h() avant affichage.
         */
        function asset_url(string $rel): string {
            $file = __DIR__ . '/../' . ltrim($rel, '/');
            $version = is_file($file) ? (string)filemtime($file) : '';
            return SITE_URL . ltrim($rel, '/') . ($version !== '' ? '?v=' . $version : '');
        }
    }
    ?>
    <!-- CSS personnalisé -->
    <link rel="stylesheet" href="<?php echo h(asset_url('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo h(asset_url('assets/css/themes.css')); ?>">
    <?php if (!empty($extraStylesheets)): foreach ((array)$extraStylesheets as $extraCss): ?>
        <link rel="stylesheet" href="<?php echo h(asset_url($extraCss)); ?>">
    <?php endforeach; endif; ?>
    <?php if (!empty($extraFonts)): foreach ((array)$extraFonts as $extraFont): ?>
        <link rel="stylesheet" href="<?php echo h($extraFont); ?>">
    <?php endforeach; endif; ?>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo h(asset_url('assets/js/main.js')); ?>"></script>
    <script src="<?php echo h(asset_url('assets/js/themes.js')); ?>"></script>
    
    <!-- Script pour initialiser le thème -->
    <script>
        // Définir le thème actuel depuis PHP
        window.currentTheme = '<?php echo h($_SESSION['theme'] ?? 'light'); ?>';
    </script>
    
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>">
</head>
<body class="<?php echo h(isset($bodyClass) ? $bodyClass : ''); ?>">
    
    <?php if ($showNavbar): ?>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-brand">
            <?php 
            $logoFilePath = __DIR__ . '/../assets/img/logo.png';
            if (file_exists($logoFilePath)) {
            ?>
                <img class="navbar-logo" src="<?php echo SITE_URL; ?>assets/img/logo.png" alt="<?php echo SITE_NAME; ?>">
            <?php } else { ?>
                <i class="fas fa-car"></i>
            <?php } ?>
            <span>SmartAutoTrack</span>
        </div>
        
        <button class="navbar-toggle" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="navbarMenu">
            <i class="fas fa-bars"></i>
        </button>

        <div class="navbar-menu" id="navbarMenu">
            <a href="<?php echo SITE_URL; ?>dashboard.php" class="navbar-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <?php if ($userRole === 'client'): ?>
                <a href="<?php echo SITE_URL; ?>client/vehicles.php" class="navbar-item">
                    <i class="fas fa-car"></i>
                    <span>Mes Véhicules</span>
                </a>
                <a href="<?php echo SITE_URL; ?>client/anomalies.php" class="navbar-item">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Anomalies</span>
                </a>
                <a href="<?php echo SITE_URL; ?>client/reparations.php" class="navbar-item">
                    <i class="fas fa-tools"></i>
                    <span>Réparations</span>
                </a>
            <?php elseif ($userRole === 'technicien'): ?>
                <a href="<?php echo SITE_URL; ?>technicien/taches.php" class="navbar-item">
                    <i class="fas fa-tasks"></i>
                    <span>Mes Tâches</span>
                </a>
                <a href="<?php echo SITE_URL; ?>technicien/journal.php" class="navbar-item">
                    <i class="fas fa-scroll"></i>
                    <span>Journal d'activité</span>
                </a>
            <?php elseif ($userRole === 'admin'): ?>
                <a href="<?php echo SITE_URL; ?>admin/clients.php" class="navbar-item">
                    <i class="fas fa-users"></i>
                    <span>Clients</span>
                </a>
                <a href="<?php echo SITE_URL; ?>admin/techniciens.php" class="navbar-item">
                    <i class="fas fa-wrench"></i>
                    <span>Techniciens</span>
                </a>
                <a href="<?php echo SITE_URL; ?>admin/garages.php" class="navbar-item">
                    <i class="fas fa-warehouse"></i>
                    <span>Garages</span>
                </a>
                <a href="<?php echo SITE_URL; ?>admin/interventions.php" class="navbar-item">
                    <i class="fas fa-calendar-check"></i>
                    <span>Interventions</span>
                </a>
                <a href="<?php echo SITE_URL; ?>admin/statistics.php" class="navbar-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Statistiques</span>
                </a>
                <a href="<?php echo SITE_URL; ?>admin/journal.php" class="navbar-item">
                    <i class="fas fa-scroll"></i>
                    <span>Journal d'activité</span>
                </a>
            <?php endif; ?>
            
            <a href="<?php echo SITE_URL; ?>messages/index.php" class="navbar-item">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
                <span class="message-counter" id="messageCounter">0</span>
            </a>
        </div>
        
        <div class="navbar-user">
            <!-- Notifications -->
            <div class="notification-dropdown">
                <button class="notification-btn" id="notificationBtn">
                    <i class="fas fa-bell"></i>
                    <span class="notification-counter" id="notificationCounter">0</span>
                </button>
                <div class="notification-panel" id="notificationPanel">
                    <div class="notification-header">
                        <h3>Notifications</h3>
                        <button class="mark-all-read" id="markAllRead">Tout marquer comme lu</button>
                    </div>
                    <div class="notification-list" id="notificationList">
                        <!-- Les notifications seront chargées via AJAX -->
                    </div>
                </div>
            </div>
            
            <!-- Menu utilisateur -->
            <div class="user-dropdown">
                <button class="user-btn">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="user-name"><?php echo h($userName); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div class="user-menu">
                    <a href="<?php echo SITE_URL; ?>profile.php" class="user-menu-item">
                        <i class="fas fa-user"></i>
                        <span>Mon Profil</span>
                    </a>
                    <a href="<?php echo SITE_URL; ?>settings.php" class="user-menu-item">
                        <i class="fas fa-cog"></i>
                        <span>Paramètres</span>
                    </a>
                    <hr>
                    <a href="<?php echo SITE_URL; ?>auth/logout.php" class="user-menu-item logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Contenu principal -->
    <main class="main-content">
    <?php endif; ?>
