<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

requireRole('client');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

$stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmtBadge->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmtBadge->fetchColumn();

$pageTitle = 'Assistant IA';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'assistant'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">

        <div class="v2-page-head">
            <div>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1">Assistant IA</h1>
                <p class="v2-sub">Un outil d'information et d'orientation — pas un outil de diagnostic automatique.</p>
            </div>
        </div>

        <div class="v2-ai-card" style="max-width: 720px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" fill="#8DE0FF"/></svg>
                <h2 style="font-size:17px;">Comment puis-je vous aider ?</h2>
            </div>
            <p>L'assistant IA pourra bientôt répondre à vos questions sur l'entretien de vos véhicules, vos démarches et votre compte SmartAutoTrack. Il oriente et informe ; il ne remplace pas le diagnostic d'un technicien.</p>

            <div style="display:flex; flex-direction:column; gap:8px; margin: 16px 0;">
                <div style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 14px; font-size:13px; color:#C7CBEA;">« Quand dois-je faire vidanger mon véhicule ? »</div>
                <div style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 14px; font-size:13px; color:#C7CBEA;">« Comment suivre ma demande d'intervention ? »</div>
                <div style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 14px; font-size:13px; color:#C7CBEA;">« À quoi correspond l'anomalie sur mon véhicule ? »</div>
            </div>

            <div class="v2-ai-input">
                <input type="text" placeholder="Écrire un message… (bientôt disponible)" disabled>
                <button type="button" disabled aria-label="Envoyer" title="Bientôt disponible">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 12L21 3L14 21L11 13L3 12Z" stroke="#FFFFFF" stroke-width="1.6" stroke-linejoin="round"/></svg>
                </button>
            </div>
            <p class="v2-ai-foot">En attendant, notre <a href="sav.php" style="color:#8DE0FF; font-weight:600;">SAV</a> répond directement à vos questions via la messagerie.</p>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
