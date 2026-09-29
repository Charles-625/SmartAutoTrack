<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
<<<<<<< HEAD

requireRole('admin');

$pageTitle = 'Assistant IA';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
=======
require_once '../includes/ai.php';

requireRole('admin');

$aiEnabled = aiIsConfigured();
$suggestions = [
    'Quels garages ont le plus d\'anomalies critiques ce mois-ci ?',
    'Quels techniciens sont les plus chargés en ce moment ?',
    'Fais-moi une synthèse de l\'activité de la plateforme.',
];

$pageTitle = 'Assistant IA';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css', 'assets/css/assistant.css'];
>>>>>>> 30d6ef683a4e566fe624a0c749d83351ffb15b2f
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'assistant'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <div class="av2-page-head">
            <div>
                <div class="av2-kicker">Centre de contrôle</div>
                <h1 class="av2-h1">Assistant IA</h1>
                <p class="av2-sub">Un outil d'aide à la supervision — pas un système de diagnostic automatique.</p>
            </div>
        </div>

<<<<<<< HEAD
        <div class="av2-ai-card" style="max-width: 720px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <strong style="font-family:'Sora', sans-serif; font-size:16px;">Bientôt disponible</strong>
            </div>
            <p style="margin:0; font-size:13.5px; line-height:1.6; color:rgba(255,255,255,0.85);">
                L'assistant IA pourra analyser les données globales de la plateforme (activité des garages, tendances des interventions, anomalies récurrentes, charge des techniciens...) pour produire des synthèses et des recommandations d'aide à la supervision. Il ne posera jamais de diagnostic mécanique automatique et ne remplace pas l'expertise d'un technicien.
            </p>
            <div class="av2-ai-input">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" stroke="rgba(255,255,255,0.6)" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <input type="text" placeholder="Ex. : Quels garages ont le plus d'anomalies critiques ce mois-ci ?" disabled>
            </div>
=======
        <div class="av2-ai-card ai-chat" id="aiChat" style="max-width: 760px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <strong style="font-family:'Sora', sans-serif; font-size:16px;"><?php echo $aiEnabled ? 'Posez votre question' : 'Assistant non configuré'; ?></strong>
            </div>
            <p style="margin:0; font-size:13.5px; line-height:1.6; color:rgba(255,255,255,0.85);">
                L'assistant analyse les données globales de la plateforme (activité des garages, interventions, anomalies, charge des techniciens, paiements) pour produire des synthèses et des recommandations d'aide à la supervision. Il ne pose jamais de diagnostic mécanique automatique.
            </p>

            <?php
            require_once '../config/config.php';
            require_once '../config/database.php';
            require_once '../config/roles.php';
            require_once 'includes/helpers.php';
            require_once '../includes/ai.php';

            requireRole('admin');

            $aiEnabled = aiIsConfigured();
            $suggestions = [
                'Quels garages ont le plus d\'anomalies critiques ce mois-ci ?',
                'Quels techniciens sont les plus chargés en ce moment ?',
                'Fais-moi une synthèse de l\'activité de la plateforme.',
            ];

            $pageTitle = 'Assistant IA';
            $hideNavbar = true;
            $bodyClass = 'av2';
            $extraStylesheets = ['assets/css/admin_v2.css', 'assets/css/assistant.css'];
            $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
            include '../includes/header.php';
            ?>
            <div class="av2-shell">
                <?php $activeNav = 'assistant'; include 'includes/sidebar.php'; ?>

                <main class="av2-main">

                    <div class="av2-page-head">
                        <div>
                            <div class="av2-kicker">Centre de contrôle</div>
                            <h1 class="av2-h1">Assistant IA</h1>
                            <p class="av2-sub">Un outil d'aide à la supervision — pas un système de diagnostic automatique.</p>
                        </div>
                    </div>

                    <div class="av2-ai-card ai-chat" id="aiChat" style="max-width: 760px;">
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round"/></svg>
                            <strong style="font-family:'Sora', sans-serif; font-size:16px;"><?php echo $aiEnabled ? 'Posez votre question' : 'Assistant non configuré'; ?></strong>
                        </div>
                        <p style="margin:0; font-size:13.5px; line-height:1.6; color:rgba(255,255,255,0.85);">
                            L'assistant analyse les données globales de la plateforme (activité des garages, interventions, anomalies, charge des techniciens, paiements) pour produire des synthèses et des recommandations d'aide à la supervision. Il ne pose jamais de diagnostic mécanique automatique.
                        </p>

                        <?php if ($aiEnabled): ?>
                            <div class="ai-chat-log" id="aiChatLog" aria-live="polite"></div>

                            <div class="ai-suggestions">
                                <?php foreach ($suggestions as $question): ?>
                                    <button type="button" class="ai-suggestion" data-question="<?php echo h($question); ?>"><?php echo h($question); ?></button>
                                <?php endforeach; ?>
                            </div>

                            <form class="av2-ai-input" id="aiChatForm" autocomplete="off">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" stroke="rgba(255,255,255,0.6)" stroke-width="1.5" stroke-linejoin="round"/></svg>
                                <input type="text" id="aiChatInput" placeholder="Ex. : Quels garages ont le plus d'anomalies critiques ce mois-ci ?" maxlength="<?php echo AI_MAX_MESSAGE_LENGTH; ?>" aria-label="Votre question">
                                <button type="submit" aria-label="Envoyer" title="Envoyer">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 12L21 3L14 21L11 13L3 12Z" stroke="#FFFFFF" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                </button>
                            </form>
                            <div class="ai-chat-toolbar">
                                <button type="button" class="ai-chat-reset" id="aiChatReset">Nouvelle conversation</button>
                            </div>
                        <?php else: ?>
                            <p style="margin:16px 0 0; font-size:13px; color:rgba(255,255,255,0.75);">
                                Renseignez <code>HF_TOKEN</code> (et éventuellement <code>HF_MODEL</code>) dans <code>config/local.php</code> pour activer l'assistant.
                            </p>
                        <?php endif; ?>
                    </div>

                </main>
            </div>

            <?php if ($aiEnabled): ?>
            <script>window.AI_HISTORY = <?php echo json_encode(aiHistory(ROLE_ADMIN), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
            <script src="<?php echo SITE_URL; ?>assets/js/assistant.js"></script>
            <?php endif; ?>

            <?php include '../includes/footer.php'; ?>
