<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

/**
 * Paramètres de l'espace client : choix du thème d'affichage.
 *
 * Accès : rôle client uniquement.
 * POST action=change_theme (jeton CSRF requis) : enregistre le thème en
 * session et dans utilisateur.themePreference. Seuls les thèmes de la liste
 * blanche sont acceptés ; une autre valeur est ignorée sans message.
 * Tables lues/écrites : utilisateur (themePreference) ; lue : intervention (badge).
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');
// Badge de la sidebar : interventions actives (planifiées ou en cours) du client.
$stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmtBadge->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmtBadge->fetchColumn();

// Traitement du changement de thème
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_theme') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session expirée, merci de réessayer.';
    } else {
        $theme = sanitize($_POST['theme'] ?? '');

        if (in_array($theme, ['light', 'dark', 'blue', 'green', 'purple', 'orange'])) {
            $_SESSION['theme'] = $theme;

            // Sauvegarder en base de données
            try {
                $stmt = $conn->prepare("UPDATE utilisateur SET themePreference = ? WHERE idUtilisateur = ?");
                $stmt->execute([$theme, $_SESSION['user_id']]);

                $success = 'Thème mis à jour avec succès !';
            } catch (Exception $e) {
                $error = 'Erreur lors de la sauvegarde du thème.';
            }
        }
    }
}

// Récupérer les préférences utilisateur
$stmt = $conn->prepare("SELECT themePreference FROM utilisateur WHERE idUtilisateur = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Priorité : préférence en base, puis session, puis thème clair par défaut.
$current_theme = $user['themePreference'] ?? $_SESSION['theme'] ?? 'light';

$pageTitle = 'Paramètres';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'parametres'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>
    <main class="v2-main">

<div class="v2-page-head">
    <div>
        <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
        <h1 class="v2-h1">Paramètres</h1>
        <p class="v2-sub">Thème d'affichage et préférences de votre compte</p>
    </div>
</div>

<?php if (isset($success)): ?>
    <div class="v2-alert success"><?php echo h($success); ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="v2-alert error"><?php echo h($error); ?></div>
<?php endif; ?>

<div class="settings-container">
    <!-- Section Apparence -->
    <div class="settings-section">
        <div class="section-header">
            <div class="section-icon">
                <i class="fas fa-palette"></i>
            </div>
            <div class="section-info">
                <h3>Apparence</h3>
                <p>Personnalisez l'apparence de votre interface</p>
            </div>
        </div>
        
        <div class="section-content">
            <form method="POST" class="theme-form">
                <input type="hidden" name="action" value="change_theme">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group">
                    <label class="form-label">Thème de couleur</label>
                    <div class="theme-selector">
                        <div class="theme-option" data-theme="light">
                            <div class="theme-preview light-theme">
                                <div class="preview-header"></div>
                                <div class="preview-content">
                                    <div class="preview-card"></div>
                                    <div class="preview-card"></div>
                                </div>
                            </div>
                            <div class="theme-info">
                                <h4>Clair</h4>
                                <p>Interface claire et moderne</p>
                            </div>
                            <input type="radio" name="theme" value="light" <?php echo $current_theme === 'light' ? 'checked' : ''; ?>>
                        </div>
                        
                        <div class="theme-option" data-theme="dark">
                            <div class="theme-preview dark-theme">
                                <div class="preview-header"></div>
                                <div class="preview-content">
                                    <div class="preview-card"></div>
                                    <div class="preview-card"></div>
                                </div>
                            </div>
                            <div class="theme-info">
                                <h4>Sombre</h4>
                                <p>Interface sombre pour les yeux</p>
                            </div>
                            <input type="radio" name="theme" value="dark" <?php echo $current_theme === 'dark' ? 'checked' : ''; ?>>
                        </div>
                        
                        <div class="theme-option" data-theme="blue">
                            <div class="theme-preview blue-theme">
                                <div class="preview-header"></div>
                                <div class="preview-content">
                                    <div class="preview-card"></div>
                                    <div class="preview-card"></div>
                                </div>
                            </div>
                            <div class="theme-info">
                                <h4>Bleu</h4>
                                <p>Thème bleu professionnel</p>
                            </div>
                            <input type="radio" name="theme" value="blue" <?php echo $current_theme === 'blue' ? 'checked' : ''; ?>>
                        </div>
                        
                        <div class="theme-option" data-theme="green">
                            <div class="theme-preview green-theme">
                                <div class="preview-header"></div>
                                <div class="preview-content">
                                    <div class="preview-card"></div>
                                    <div class="preview-card"></div>
                                </div>
                            </div>
                            <div class="theme-info">
                                <h4>Vert</h4>
                                <p>Thème vert naturel</p>
                            </div>
                            <input type="radio" name="theme" value="green" <?php echo $current_theme === 'green' ? 'checked' : ''; ?>>
                        </div>
                        
                        <div class="theme-option" data-theme="purple">
                            <div class="theme-preview purple-theme">
                                <div class="preview-header"></div>
                                <div class="preview-content">
                                    <div class="preview-card"></div>
                                    <div class="preview-card"></div>
                                </div>
                            </div>
                            <div class="theme-info">
                                <h4>Violet</h4>
                                <p>Thème violet élégant</p>
                            </div>
                            <input type="radio" name="theme" value="purple" <?php echo $current_theme === 'purple' ? 'checked' : ''; ?>>
                        </div>
                        
                        <div class="theme-option" data-theme="orange">
                            <div class="theme-preview orange-theme">
                                <div class="preview-header"></div>
                                <div class="preview-content">
                                    <div class="preview-card"></div>
                                    <div class="preview-card"></div>
                                </div>
                            </div>
                            <div class="theme-info">
                                <h4>Orange</h4>
                                <p>Thème orange énergique</p>
                            </div>
                            <input type="radio" name="theme" value="orange" <?php echo $current_theme === 'orange' ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Appliquer le thème
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Section Compte -->
    <div class="settings-section">
        <div class="section-header">
            <div class="section-icon">
                <i class="fas fa-user-cog"></i>
            </div>
            <div class="section-info">
                <h3>Compte</h3>
                <p>Vos informations et votre mot de passe se gèrent depuis votre profil</p>
            </div>
        </div>
        <div class="section-content">
            <p style="color: var(--text-light); margin-bottom: 1rem;">
                Pour modifier votre nom, votre email, votre téléphone ou votre mot de passe, rendez-vous sur votre
                <a href="<?php echo SITE_URL; ?>profile.php" style="color: var(--primary-color); font-weight: 600;">page de profil</a>.
            </p>
            <a href="<?php echo SITE_URL; ?>profile.php" class="btn btn-outline"><i class="fas fa-user"></i> Aller à mon profil</a>
        </div>
    </div>
</div>

<style>
.settings-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 2rem;
}

.settings-section {
    background: var(--secondary-color);
    border-radius: var(--border-radius);
    margin-bottom: 2rem;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: var(--transition);
}

.settings-section:hover {
    box-shadow: var(--shadow-hover);
}

.section-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    background: var(--background-color);
    border-bottom: 1px solid var(--border-color);
}

.section-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.section-info h3 {
    margin: 0 0 0.25rem 0;
    color: var(--text-color);
}

.section-info p {
    margin: 0;
    color: var(--text-light);
    font-size: 0.9rem;
}

.section-content {
    padding: 2rem;
}

/* Sélecteur de thème */
.theme-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.theme-option {
    position: relative;
    cursor: pointer;
    border: 2px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 1rem;
    transition: var(--transition);
    background: var(--background-color);
}

.theme-option:hover {
    border-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.theme-option input[type="radio"] {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 20px;
    height: 20px;
    accent-color: var(--primary-color);
}

.theme-option input[type="radio"]:checked + .theme-option {
    border-color: var(--primary-color);
    background: rgba(57, 86, 232, 0.05);
}

.theme-preview {
    width: 100%;
    height: 80px;
    border-radius: 8px;
    margin-bottom: 1rem;
    overflow: hidden;
    position: relative;
}

.preview-header {
    height: 20px;
    background: #333;
}

.preview-content {
    height: 60px;
    padding: 8px;
    display: flex;
    gap: 4px;
}

.preview-card {
    flex: 1;
    height: 100%;
    border-radius: 4px;
}

/* Thèmes de prévisualisation */
.light-theme {
    background: #f8f9fa;
}

.light-theme .preview-header {
    background: #007bff;
}

.light-theme .preview-card {
    background: #ffffff;
    border: 1px solid #e9ecef;
}

.dark-theme {
    background: #1a1a1a;
}

.dark-theme .preview-header {
    background: #495057;
}

.dark-theme .preview-card {
    background: #2d3748;
}

.blue-theme {
    background: #e3f2fd;
}

.blue-theme .preview-header {
    background: #1976d2;
}

.blue-theme .preview-card {
    background: #bbdefb;
}

.green-theme {
    background: #e8f5e8;
}

.green-theme .preview-header {
    background: #388e3c;
}

.green-theme .preview-card {
    background: #c8e6c9;
}

.purple-theme {
    background: #f3e5f5;
}

.purple-theme .preview-header {
    background: #7b1fa2;
}

.purple-theme .preview-card {
    background: #e1bee7;
}

.orange-theme {
    background: #fff3e0;
}

.orange-theme .preview-header {
    background: #f57c00;
}

.orange-theme .preview-card {
    background: #ffcc02;
}

.theme-info h4 {
    margin: 0 0 0.25rem 0;
    color: var(--text-color);
}

.theme-info p {
    margin: 0;
    color: var(--text-light);
    font-size: 0.8rem;
}

/* Paramètres de notification */
.notification-settings,
.vehicle-settings {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.setting-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: var(--background-color);
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
}

.setting-info h4 {
    margin: 0 0 0.25rem 0;
    color: var(--text-color);
}

.setting-info p {
    margin: 0;
    color: var(--text-light);
    font-size: 0.9rem;
}

/* Toggle switch */
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: 0.4s;
    border-radius: 24px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.4s;
    border-radius: 50%;
}

input:checked + .toggle-slider {
    background-color: var(--primary-color);
}

input:checked + .toggle-slider:before {
    transform: translateX(26px);
}

.form-actions {
    margin-top: 2rem;
    text-align: center;
}

@media (max-width: 768px) {
    .settings-container {
        padding: 1rem;
    }
    
    .theme-selector {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        flex-direction: column;
        text-align: center;
    }
    
    .setting-item {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
}
</style>

<script>
$(document).ready(function() {
    // Prévisualisation des thèmes
    $('.theme-option').click(function() {
        $('.theme-option').removeClass('selected');
        $(this).addClass('selected');
        $(this).find('input[type="radio"]').prop('checked', true);
    });
    
    // Animation des cartes de thème
    $('.theme-option').hover(
        function() {
            $(this).find('.theme-preview').addClass('preview-hover');
        },
        function() {
            $(this).find('.theme-preview').removeClass('preview-hover');
        }
    );
});
</script>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
