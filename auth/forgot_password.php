<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/login_throttle.php';
require_once '../includes/mailer.php';

/**
 * Page de demande de réinitialisation du mot de passe oublié.
 *
 * Envoie un email transactionnel via Brevo contenant un lien unique
 * sécurisé avec un token aléatoire à usage unique valable 1 heure.
 */

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide. Veuillez recharger la page.';
    } else {
        $email = sanitize(trim($_POST['email'] ?? ''));

        if (empty($email)) {
            $errors[] = 'L\'adresse email est requise.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'adresse email est invalide.';
        }

        // Anti force brute / limitation de requêtes par IP
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
        if (empty($errors)) {
            $retryAfter = loginThrottleRetryAfter('forgot_' . $email, $clientIp);
            if ($retryAfter > 0) {
                http_response_code(429);
                header('Retry-After: ' . $retryAfter);
                $errors[] = 'Trop de demandes. Réessayez dans ' . (int)ceil($retryAfter / 60) . ' minute(s).';
            }
        }

        if (empty($errors)) {
            $db = new Database();
            $conn = $db->getConnection();

            $user = findUserByEmail($conn, $email);

            if ($user) {
                // 1. Invalider les anciens tokens non utilisés
                $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE idUtilisateur = ? AND used = 0");
                $stmt->execute([$user['idUtilisateur']]);

                // 2. Générer un token cryptographique sécurisé
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 heure de validité

                // 3. Stocker le hash en base
                $stmt = $conn->prepare("INSERT INTO password_resets (idUtilisateur, token_hash, expires_at, used) VALUES (?, ?, ?, 0)");
                $stmt->execute([$user['idUtilisateur'], $tokenHash, $expiresAt]);

                // 4. Préparer et envoyer l'email via Brevo
                $name = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
                $resetUrl = SITE_URL . 'auth/reset_password.php?token=' . urlencode($rawToken);
                $html = buildPasswordResetEmailHtml($name ?: 'Utilisateur', $resetUrl);
                $text = "Bonjour,\n\nVous avez demandé la réinitialisation de votre mot de passe pour SmartAutoTrack.\n\nCliquez sur ce lien pour choisir un nouveau mot de passe :\n" . $resetUrl . "\n\nCe lien expirera dans 1 heure.\nSi vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email.";

                $mailResult = sendBrevoEmail(
                    $user['email'],
                    $name,
                    'Réinitialisation de votre mot de passe - ' . SITE_NAME,
                    $html,
                    $text
                );

                if (!$mailResult['success']) {
                    error_log('[Forgot Password] Échec d\'envoi email Brevo : ' . ($mailResult['error'] ?? ''));
                }
            } else {
                // Pour éviter l'énumération des comptes existants, on enregistre un petit throttle
                loginThrottleRecordFailure('forgot_' . $email, $clientIp);
            }

            // Message volontairement générique (conforme aux normes OWASP)
            $successMessage = 'Si cette adresse email correspond à un compte existant, vous recevrez un lien de réinitialisation dans quelques instants. Pensez à vérifier votre dossier spams.';
        }
    }
}

$pageTitle = 'Mot de passe oublié';
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

            <h1>Mot de passe oublié</h1>
            <p class="authv2-sub">Saisissez votre adresse email pour recevoir un lien de réinitialisation sécurisé.</p>

            <?php if ($successMessage): ?>
                <div class="authv2-alert success">
                    <?php echo h($successMessage); ?>
                </div>
                <div class="authv2-footer" style="margin-top: 25px;">
                    <a href="login.php" class="authv2-submit" style="display: block; text-decoration: none; text-align: center;">Retour à la connexion</a>
                </div>
            <?php else: ?>
                <?php foreach ($errors as $error): ?>
                    <div class="authv2-alert error"><?php echo h($error); ?></div>
                <?php endforeach; ?>

                <form method="POST" id="forgotForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="authv2-field">
                        <label for="forgotEmail">Adresse email <span class="authv2-required" aria-hidden="true">*</span></label>
                        <div class="authv2-input-wrap">
                            <span class="authv2-icon"><i class="fas fa-envelope"></i></span>
                            <input type="email" name="email" id="forgotEmail" required autocomplete="email"
                                   value="<?php echo h($_POST['email'] ?? ''); ?>" placeholder="exemple@gmail.com">
                        </div>
                    </div>

                    <button type="submit" class="authv2-submit" id="forgotSubmit">
                        <span class="authv2-spinner"></span>
                        <span class="authv2-submit-label">Envoyer le lien</span>
                    </button>
                </form>

                <div class="authv2-footer">
                    Vous vous souvenez de votre mot de passe ? <a href="login.php">Se connecter</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#forgotForm').on('submit', function() {
        $('#forgotSubmit').addClass('loading').prop('disabled', true);
        $('.authv2-submit-label').text('Envoi en cours...');
    });
});
</script>

<?php include '../includes/footer.php'; ?>
