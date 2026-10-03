<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/ai.php';
require_once '../includes/subscription.php';

/**
 * Assistant IA de l'espace client.
 *
 * Accès : rôle client uniquement.
 * La page n'affiche que l'interface de conversation : les questions partent en
 * AJAX depuis assets/js/assistant.js ; la logique IA est dans includes/ai.php.
 * Aucune action POST ici. Sans clé d'IA configurée (aiIsConfigured()), la
 * page le signale au lieu de proposer la saisie.
 * Client gratuit : affiche les messages encore disponibles aujourd'hui
 * (subscriptionAiRemaining(), limite SUB_FREE_AI_PER_DAY), tenus à jour par
 * assets/js/assistant.js ; rien n'est affiché en Premium ou sans migration.
 * Tables lues : intervention (badge sidebar), abonnement et ia_usage (quota).
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

$aiEnabled = aiIsConfigured();
// Messages gratuits restants aujourd'hui ; null = illimité (Premium, ou migration non appliquée).
$aiRemaining = subscriptionAiRemaining($conn, (int)$_SESSION['user_id']);
// Questions proposées en un clic pour amorcer la conversation.
$suggestions = [
    'Quand dois-je faire vidanger mon véhicule ?',
    'Comment suivre ma demande d\'intervention ?',
    'À quoi correspond l\'anomalie sur mon véhicule ?',
];

$pageTitle = 'Assistant IA';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css', 'assets/css/assistant.css'];
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

        <div class="v2-ai-card ai-chat" id="aiChat" style="max-width: 720px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" fill="#8DE0FF"/></svg>
                <h2 style="font-size:17px;">Comment puis-je vous aider ?</h2>
            </div>
            <p>Posez vos questions sur l'entretien de vos véhicules, vos anomalies, vos démarches et votre compte SmartAutoTrack. L'assistant connaît vos véhicules et leur historique ; il oriente et informe, mais ne remplace pas le diagnostic d'un technicien.</p>

            <?php if ($aiEnabled): ?>
                <div class="ai-chat-log" id="aiChatLog" aria-live="polite"></div>

                <div class="ai-suggestions">
                    <?php foreach ($suggestions as $question): ?>
                        <button type="button" class="ai-suggestion" data-question="<?php echo h($question); ?>">« <?php echo h($question); ?> »</button>
                    <?php endforeach; ?>
                </div>

                <form class="v2-ai-input" id="aiChatForm" autocomplete="off">
                    <input type="text" id="aiChatInput" placeholder="Écrire un message…" maxlength="<?php echo AI_MAX_MESSAGE_LENGTH; ?>" aria-label="Votre question">
                    <button type="submit" aria-label="Envoyer" title="Envoyer">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 12L21 3L14 21L11 13L3 12Z" stroke="#FFFFFF" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    </button>
                </form>
                <?php if ($aiRemaining !== null): ?>
                    <p class="v2-ai-foot" style="margin-top:10px;">Formule gratuite : <strong id="aiRemaining"><?php echo (int)$aiRemaining; ?></strong> message(s) restant(s) aujourd'hui sur <?php echo (int)SUB_FREE_AI_PER_DAY; ?>. <a href="abonnement.php" style="color:#8DE0FF; font-weight:600;">Premium : assistant illimité</a></p>
                <?php endif; ?>
                <div class="ai-chat-toolbar">
                    <button type="button" class="ai-chat-reset" id="aiChatReset">Nouvelle conversation</button>
                </div>
            <?php else: ?>
                <div class="v2-ai-input">
                    <input type="text" placeholder="Assistant momentanément indisponible" disabled>
                </div>
            <?php endif; ?>

            <p class="v2-ai-foot">Pour une question sur votre dossier, notre <a href="sav.php" style="color:#8DE0FF; font-weight:600;">SAV</a> répond directement via la messagerie.</p>
        </div>
    </main>
</div>

<?php if ($aiEnabled): ?>
<script>window.AI_HISTORY = <?php echo json_encode(aiHistory(ROLE_CLIENT), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo SITE_URL; ?>assets/js/assistant.js"></script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
