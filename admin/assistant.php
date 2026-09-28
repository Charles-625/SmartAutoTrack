<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('admin');

$pageTitle = 'Assistant IA';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
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
        </div>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
