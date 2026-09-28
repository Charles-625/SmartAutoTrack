<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('technicien');

$db = new Database();
$conn = $db->getConnection();
$selfId = (int)$_SESSION['user_id'];

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_theme') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $theme = sanitize($_POST['theme'] ?? '');
        if (in_array($theme, ['light', 'dark'], true)) {
            $_SESSION['theme'] = $theme;
            $conn->prepare("UPDATE utilisateur SET themePreference = ? WHERE idUtilisateur = ?")->execute([$theme, $selfId]);
            $success = 'Thème mis à jour avec succès !';
        }
    }
}

$stmt = $conn->prepare("SELECT themePreference FROM utilisateur WHERE idUtilisateur = ?");
$stmt->execute([$selfId]);
$currentTheme = $stmt->fetchColumn() ?: 'light';

$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
$stmt->execute([$selfId]);
$tachesADemarrer = (int)$stmt->fetchColumn();

$pageTitle = 'Paramètres';
$hideNavbar = true;
$bodyClass = 'tv2';
$extraStylesheets = ['assets/css/technicien_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="tv2-shell">
    <?php $activeNav = 'parametres'; $tachesBadge = $tachesADemarrer; include 'includes/sidebar.php'; ?>

    <main class="tv2-main">
        <div class="tv2-page-head">
            <div>
                <h1 class="tv2-h1">Paramètres</h1>
                <p class="tv2-sub">Préférences d'affichage de votre espace technicien.</p>
            </div>
        </div>

        <?php if ($success): ?><div class="tv2-alert success"><?php echo h($success); ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="tv2-alert error"><?php echo h($e); ?></div><?php endforeach; ?>

        <div class="tv2-card tv2-panel" style="max-width:520px;">
            <div class="tv2-panel-head"><h2>Apparence</h2></div>
            <form method="POST">
                <input type="hidden" name="action" value="change_theme">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="tv2-form-group">
                    <label for="themeSelect">Thème</label>
                    <select name="theme" id="themeSelect">
                        <option value="light" <?php echo $currentTheme === 'light' ? 'selected' : ''; ?>>Clair</option>
                        <option value="dark" <?php echo $currentTheme === 'dark' ? 'selected' : ''; ?>>Sombre</option>
                    </select>
                </div>
                <button type="submit" class="tv2-btn-primary">Appliquer</button>
            </form>
        </div>

        <div class="tv2-card tv2-panel" style="max-width:520px;">
            <div class="tv2-panel-head"><h2>Compte</h2></div>
            <p style="color:#7A6A57; font-size:14px; line-height:1.6; margin:0 0 14px;">
                Pour modifier vos informations de contact, vos compétences ou votre mot de passe, rendez-vous sur votre
                <a href="<?php echo SITE_URL; ?>profile.php" style="color:#D97706; font-weight:600;">page de profil</a>.
            </p>
            <a href="<?php echo SITE_URL; ?>profile.php" class="tv2-btn-outline" style="text-decoration:none; display:inline-block;">Aller à mon profil</a>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
