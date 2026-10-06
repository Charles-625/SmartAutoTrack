<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/password_policy.php';
require_once '../includes/activity_log.php';
require_once '../includes/login_throttle.php';

/**
 * Page de réinitialisation du mot de passe.
 *
 * Vérifie le jeton transmis en paramètre GET/POST, affiche le formulaire
 * de nouveau mot de passe (avec contrôle en direct via data-password-policy),
 * met à jour la base et invalide le jeton utilisé.
 */

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$success = false;
$tokenValid = false;
$resetRecord = null;

$db = new Database();
$conn = $db->getConnection();

if (!empty($rawToken)) {
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $conn->prepare("
        SELECT pr.*, u.idUtilisateur, u.email, u.nom, u.prenom
        FROM password_resets pr
        JOIN utilisateur u ON u.idUtilisateur = pr.idUtilisateur
        WHERE pr.token_hash = ? AND pr.used = 0 AND pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resetRecord) {
        $tokenValid = true;
    } else {
        $errors[] = 'Ce lien de réinitialisation est invalide, a expiré ou a déjà été utilisé.';
    }
} else {
    $errors[] = 'Aucun jeton de réinitialisation fourni.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide. Veuillez réessayer.';
    } else {
        $password = $_POST['nouveau_mot_de_passe'] ?? '';
        $passwordConfirm = $_POST['confirmation_mot_de_passe'] ?? '';

        // Validation selon la politique de mot de passe
        $policyError = passwordPolicyError($password, 'Le nouveau mot de passe');
        if ($policyError) {
            $errors[] = $policyError;
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Les deux mots de passe ne correspondent pas.';
        }

        if (empty($errors)) {
            // Mise à jour du mot de passe
            $newHash = hashPassword($password);

            $conn->beginTransaction();
            try {
                // 1. Mettre à jour le mot de passe de l'utilisateur
                $stmt = $conn->prepare("UPDATE utilisateur SET motDePasse = ? WHERE idUtilisateur = ?");
                $stmt->execute([$newHash, $resetRecord['idUtilisateur']]);

                // 2. Marquer ce jeton comme utilisé
                $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
                $stmt->execute([$resetRecord['id']]);

                // 3. Invalider tous les autres tokens éventuels de cet utilisateur
                $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE idUtilisateur = ? AND used = 0");
                $stmt->execute([$resetRecord['idUtilisateur']]);

                $conn->commit();

                // 4. Réinitialiser le compteur de blocage de force brute
                loginThrottleClear($resetRecord['email']);

                // 5. Journaliser l'activité si la table existe
                logActivity($conn, (int)$resetRecord['idUtilisateur'], 'RESET_MDP', 'Réinitialisation réussie du mot de passe');

                $success = true;
            } catch (Exception $e) {
                $conn->rollBack();
                error_log('[Reset Password] Erreur lors de la mise à jour : ' . $e->getMessage());
                $errors[] = 'Une erreur est survenue lors de l\'enregistrement. Veuillez réessayer.';
            }
        }
    }
}

$pageTitle = 'Nouveau mot de passe';
$bodyClass = 'authv2';
$hideNavbar = true;
$extraStylesheets = ['assets/css/auth_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>

<div class="authv2-shell">
    <div class="authv2-wrap">
        <a href="<?php echo SITE_URL; ?>auth/login.php" class="authv2-back">&larr; Retour à la connexion</a>

        <div class="authv2-card">
            <div class="authv2-logo-wrap">
                <img src="<?php echo SITE_URL; ?>assets/img/logo.png" alt="<?php echo SITE_NAME; ?>">
            </div>

            <h1>Nouveau mot de passe</h1>
            <p class="authv2-sub">Définissez un mot de passe robuste pour sécuriser votre compte.</p>

            <?php if ($success): ?>
                <div class="authv2-alert success">
                    Votre mot de passe a été modifié avec succès ! Vous pouvez maintenant vous connecter.
                </div>
                <div class="authv2-footer" style="margin-top: 25px;">
                    <a href="login.php" class="authv2-submit" style="display: block; text-decoration: none; text-align: center;">Se connecter</a>
                </div>
            <?php elseif (!$tokenValid): ?>
                <div class="authv2-alert error">
                    <?php echo h($errors[0] ?? 'Lien invalide.'); ?>
                </div>
                <div class="authv2-footer" style="margin-top: 25px;">
                    <a href="forgot_password.php" class="authv2-submit" style="display: block; text-decoration: none; text-align: center;">Faire une nouvelle demande</a>
                </div>
            <?php else: ?>
                <?php foreach ($errors as $error): ?>
                    <div class="authv2-alert error"><?php echo h($error); ?></div>
                <?php endforeach; ?>

                <form method="POST" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="token" value="<?php echo h($rawToken); ?>">

                    <div class="authv2-field">
                        <label for="newPassword">Nouveau mot de passe <span class="authv2-required" aria-hidden="true">*</span></label>
                        <div class="authv2-input-wrap has-toggle">
                            <span class="authv2-icon"><i class="fas fa-lock"></i></span>
                            <input type="password" name="nouveau_mot_de_passe" id="newPassword" required
                                   minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                                   data-password-policy autocomplete="new-password" placeholder="••••••••">
                            <button type="button" class="authv2-toggle" id="toggleNewPassword" aria-label="Afficher le mot de passe">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="authv2-field">
                        <label for="confirmPassword">Confirmer le mot de passe <span class="authv2-required" aria-hidden="true">*</span></label>
                        <div class="authv2-input-wrap has-toggle">
                            <span class="authv2-icon"><i class="fas fa-lock"></i></span>
                            <input type="password" name="confirmation_mot_de_passe" id="confirmPassword" required
                                   autocomplete="new-password" placeholder="••••••••">
                            <button type="button" class="authv2-toggle" id="toggleConfirmPassword" aria-label="Afficher le mot de passe">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="authv2-submit" id="resetSubmit">
                        <span class="authv2-spinner"></span>
                        <span class="authv2-submit-label">Enregistrer le nouveau mot de passe</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    function setupToggle(toggleId, inputId) {
        $(toggleId).click(function() {
            const input = $(inputId);
            const icon = $(this).find('i');
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                icon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                input.attr('type', 'password');
                icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });
    }

    setupToggle('#toggleNewPassword', '#newPassword');
    setupToggle('#toggleConfirmPassword', '#confirmPassword');

    $('#resetForm').on('submit', function() {
        $('#resetSubmit').addClass('loading').prop('disabled', true);
        $('.authv2-submit-label').text('Enregistrement...');
    });
});
</script>

<?php include '../includes/footer.php'; ?>
