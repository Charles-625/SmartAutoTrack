<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/login_throttle.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification du token CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $email = sanitize($_POST['email'] ?? '');
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';

        if (empty($email)) $errors[] = 'L\'email est requis.';
        if (empty($mot_de_passe)) $errors[] = 'Le mot de passe est requis.';

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
        if (empty($errors)) {
            $retryAfter = loginThrottleRetryAfter($email, $clientIp);
            if ($retryAfter > 0) {
                http_response_code(429);
                header('Retry-After: ' . $retryAfter);
                $errors[] = 'Trop de tentatives de connexion. Réessayez dans ' . (int)ceil($retryAfter / 60) . ' minute(s).';
            }
        }

        if (empty($errors)) {
            $db = new Database();
            $conn = $db->getConnection();

            // Le rôle n'est jamais transmis par le navigateur (ni ?role=,
            // ni POST['role']) : il est déduit ici, côté serveur, de la table
            // où l'identifiant apparaît réellement (config/roles.php).
            $user = findUserByEmail($conn, $email);
            $profile = $user ? getUserProfile($conn, $user['idUtilisateur']) : null;

            if (!$user || !$profile || !verifyPassword($mot_de_passe, $user['motDePasse'])) {
                // Message volontairement générique : ne jamais révéler si
                // c'est l'email ou le mot de passe qui est en cause.
                loginThrottleRecordFailure($email, $clientIp);
                $errors[] = 'Email ou mot de passe incorrect.';
            } elseif (!isAccountUsable($profile)) {
                loginThrottleClear($email);
                // Identifiants corrects mais compte non utilisable (en attente,
                // rejeté, suspendu) : message clair et spécifique — jamais un
                // dashboard, jamais le message générique ci-dessus.
                $errors[] = accountStatusMessage($profile);
            } else {
                // Connexion réussie : on régénère l'identifiant de session
                // avant d'y écrire quoi que ce soit (protection contre la
                // fixation de session), puis on ne stocke QUE des données
                // authentifiées côté serveur.
                session_regenerate_id(true);
                loginThrottleClear($email);

                $_SESSION['user_id'] = $user['idUtilisateur'];
                $_SESSION['nom'] = $user['nom'];
                $_SESSION['prenom'] = $user['prenom'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $profile['role'];
                // Conserve la convention historique (actif/pending/rejete) pour les pages
                // pas encore adaptées au nouveau schéma, qui lisent $_SESSION['statut'].
                $statutMap = ['VALIDE' => 'actif', 'EN_ATTENTE' => 'pending', 'REJETE' => 'rejete'];
                if ($profile['role'] === ROLE_TECHNICIEN) {
                    $_SESSION['statut'] = $statutMap[$profile['statutValidation']] ?? 'pending';
                } elseif ($profile['role'] === ROLE_GARAGE) {
                    $_SESSION['statut'] = $statutMap[$profile['statutGarage']] ?? 'pending';
                } else {
                    $_SESSION['statut'] = 'actif';
                }

                // Redirection selon le rôle réel (jamais une valeur du
                // formulaire) : technicien interne/de garage et client
                // particulier/entreprise partagent un même espace applicatif,
                // qui s'adapte ensuite lui-même à partir du profil.
                switch ($profile['role']) {
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
                        redirect('dashboard.php');
                }
            }
        }
    }
}

$pageTitle = 'Connexion';
$bodyClass = 'authv2';
$hideNavbar = true;
$extraStylesheets = ['assets/css/auth_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>

<div class="authv2-shell">
    <div class="authv2-wrap">
        <a href="<?php echo SITE_URL; ?>index.php" class="authv2-back">&larr; Retour à l'accueil</a>

        <div class="authv2-card">
            <div class="authv2-logo-wrap">
                <img src="<?php echo SITE_URL; ?>assets/img/logo.png" alt="<?php echo SITE_NAME; ?>">
            </div>

            <h1>Connexion</h1>
            <p class="authv2-sub">Accédez à votre espace SmartAutoTrack.</p>

            <?php if (isset($_GET['deconnecte'])): ?>
                <div class="authv2-alert success">Vous avez été déconnecté avec succès.</div>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="authv2-alert info">
                    Session active : <?php echo h(trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))); ?>.
                    <a href="<?php echo SITE_URL; ?>dashboard.php">Accéder au tableau de bord</a>
                    ou <a href="<?php echo SITE_URL; ?>auth/logout.php">se déconnecter</a>.
                </div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="authv2-alert error"><?php echo h($error); ?></div>
            <?php endforeach; ?>

            <form method="POST" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="authv2-field">
                    <label for="loginEmail">Email</label>
                    <div class="authv2-input-wrap">
                        <span class="authv2-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" id="loginEmail" required autocomplete="username"
                               value="<?php echo h($_POST['email'] ?? ''); ?>" placeholder="votre@email.com">
                    </div>
                </div>

                <div class="authv2-field">
                    <label for="loginPassword">Mot de passe</label>
                    <div class="authv2-input-wrap has-toggle">
                        <span class="authv2-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="mot_de_passe" id="loginPassword" required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="authv2-toggle" id="passwordToggle" aria-label="Afficher le mot de passe">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="authv2-submit" id="loginSubmit">
                    <span class="authv2-spinner"></span>
                    <span class="authv2-submit-label">Se connecter</span>
                </button>
            </form>

            <div class="authv2-footer">
                Pas encore inscrit ? <a href="register.php">Créer un compte</a>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#passwordToggle').click(function() {
        const input = $('#loginPassword');
        const icon = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    $('#loginForm').on('submit', function() {
        // Le formulaire est déjà validé par les attributs required/type=email
        // du navigateur ; on affiche seulement l'état de chargement ici.
        $('#loginSubmit').addClass('loading').prop('disabled', true);
        $('.authv2-submit-label').text('Connexion...');
    });
});
</script>

<?php include '../includes/footer.php'; ?>
