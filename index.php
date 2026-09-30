<?php
require_once 'config/config.php';

/**
 * Page d'accueil publique (vitrine) de SmartAutoTrack.
 *
 * Accès : visiteurs non connectés ; un utilisateur connecté est renvoyé vers
 * son espace. Aucune action POST, aucune lecture en base.
 * Styles propres : assets/css/home.css.
 */

// Un visiteur déjà connecté n'a rien à faire sur la vitrine publique : on le
// renvoie directement vers son espace (dashboard.php route lui-même selon
// son rôle réel, jamais une valeur transmise par le navigateur).
if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$pageTitle = 'Accueil';
$bodyClass = 'homev2';
$hideNavbar = true;
$extraStylesheets = ['assets/css/home.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap'];
include 'includes/header.php';
?>

<!-- ============ NAVIGATION ============ -->
<nav class="homev2-nav">
    <div class="homev2-container homev2-nav-inner">
        <div class="homev2-nav-brand">
            <img src="<?php echo SITE_URL; ?>assets/img/logo.png" alt="<?php echo SITE_NAME; ?>">
            <span>SmartAutoTrack</span>
        </div>
        <div class="homev2-nav-links" id="homeNavLinks">
            <a href="#solution">Solution</a>
            <a href="#espaces">Espaces</a>
            <a href="#garages">Garages</a>
            <a href="#ia">Assistant IA</a>
            <a href="<?php echo SITE_URL; ?>auth/login.php" class="homev2-btn homev2-btn-primary">Se connecter</a>
        </div>
        <button class="homev2-nav-toggle" id="homeNavToggle" aria-label="Ouvrir le menu"><i class="fas fa-bars"></i></button>
    </div>
</nav>

<!-- ============ HERO ============ -->
<header class="homev2-hero">
    <div class="homev2-container homev2-hero-inner">
        <div>
            <div class="homev2-kicker">Gestion &amp; suivi de l'entretien automobile après-vente</div>
            <h1>SMARTAUTOTRACK</h1>
            <p class="homev2-hero-tagline">Solution numérique intelligente pour l'optimisation de la gestion et du contrôle de l'entretien automobile après-vente.</p>
            <div class="homev2-hero-ctas">
                <a href="<?php echo SITE_URL; ?>auth/login.php" class="homev2-btn homev2-btn-primary">Se connecter</a>
                <a href="#solution" class="homev2-btn homev2-btn-ghost-light">Découvrir la solution</a>
            </div>
            <div class="homev2-hero-roles">
                <div class="homev2-hero-role-chip"><span class="homev2-hero-role-dot" style="background:#5B7CFA;"></span>Client particulier</div>
                <div class="homev2-hero-role-chip"><span class="homev2-hero-role-dot" style="background:#2540C4;"></span>Client entreprise</div>
                <div class="homev2-hero-role-chip"><span class="homev2-hero-role-dot" style="background:#14B8A6;"></span>Garage</div>
                <div class="homev2-hero-role-chip"><span class="homev2-hero-role-dot" style="background:#F59E0B;"></span>Technicien</div>
                <div class="homev2-hero-role-chip"><span class="homev2-hero-role-dot" style="background:#8B93C7;"></span>Administrateur</div>
            </div>
        </div>

        <div class="homev2-mockup">
            <div class="homev2-mockup-float homev2-mockup-float-1">
                <div class="homev2-mockup-row-icon" style="background:#E9F6EE;"><i class="fas fa-check" style="color:#1E8A4C; font-size:11px;"></i></div>
                <div>
                    <div style="font-size:11.5px; font-weight:800; color:#171B33;">Intervention clôturée</div>
                    <div style="font-size:10px; color:#8B90B3;">Garage Nord Auto</div>
                </div>
            </div>

            <div class="homev2-mockup-card">
                <div class="homev2-mockup-head">
                    <div class="homev2-mockup-head-title">Tableau de bord</div>
                    <div class="homev2-mockup-dots"><span></span><span></span><span></span></div>
                </div>
                <div class="homev2-mockup-stats">
                    <div class="homev2-mockup-stat">
                        <div class="homev2-mockup-stat-value" style="color:#3956E8;">12</div>
                        <div class="homev2-mockup-stat-label">Interventions</div>
                    </div>
                    <div class="homev2-mockup-stat">
                        <div class="homev2-mockup-stat-value" style="color:#0D9488;">4</div>
                        <div class="homev2-mockup-stat-label">Garages actifs</div>
                    </div>
                    <div class="homev2-mockup-stat">
                        <div class="homev2-mockup-stat-value" style="color:#D97706;">2</div>
                        <div class="homev2-mockup-stat-label">Anomalies</div>
                    </div>
                </div>
                <div class="homev2-mockup-rows">
                    <div class="homev2-mockup-row">
                        <div class="homev2-mockup-row-icon" style="background:#EEF1FF;"><i class="fas fa-calendar-check" style="color:#3956E8; font-size:11px;"></i></div>
                        <div class="homev2-mockup-row-text">Diagnostic — Peugeot 308</div>
                        <div class="homev2-mockup-row-badge" style="background:#FFF4E2; color:#C8871A;">En cours</div>
                    </div>
                    <div class="homev2-mockup-row">
                        <div class="homev2-mockup-row-icon" style="background:#E4F7EE;"><i class="fas fa-wrench" style="color:#0D9488; font-size:11px;"></i></div>
                        <div class="homev2-mockup-row-text">Technicien affecté</div>
                        <div class="homev2-mockup-row-badge" style="background:#E9F6EE; color:#1E8A4C;">Planifiée</div>
                    </div>
                    <div class="homev2-mockup-row">
                        <div class="homev2-mockup-row-icon" style="background:#FDEDEE;"><i class="fas fa-triangle-exclamation" style="color:#E5484D; font-size:11px;"></i></div>
                        <div class="homev2-mockup-row-text">Anomalie constatée</div>
                        <div class="homev2-mockup-row-badge" style="background:#FDEDEE; color:#E5484D;">À traiter</div>
                    </div>
                </div>
            </div>

            <div class="homev2-mockup-float homev2-mockup-float-2">
                <div class="homev2-mockup-row-icon" style="background:#EEF1FF;"><i class="fas fa-bell" style="color:#3956E8; font-size:11px;"></i></div>
                <div>
                    <div style="font-size:11.5px; font-weight:800; color:#171B33;">Nouvelle demande</div>
                    <div style="font-size:10px; color:#8B90B3;">Reçue à l'instant</div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ============ SOLUTION ============ -->
<section class="homev2-section" id="solution">
    <div class="homev2-container">
        <div class="homev2-section-head">
            <span class="homev2-eyebrow">La solution</span>
            <h2>Une plateforme pour piloter tout l'après-vente</h2>
            <p>SmartAutoTrack centralise la gestion des clients, des véhicules et des interventions, de la demande initiale jusqu'à la clôture — avec une traçabilité complète à chaque étape.</p>
        </div>
        <div class="homev2-feature-grid">
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-users"></i></div>
                <h3>Gestion des clients</h3>
                <p>Particuliers et entreprises, avec leurs véhicules et leur historique.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-car"></i></div>
                <h3>Gestion des véhicules</h3>
                <p>Un parc suivi précisément, véhicule par véhicule.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-paper-plane"></i></div>
                <h3>Demandes d'intervention</h3>
                <p>Le client choisit un garage partenaire dès sa demande.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-calendar-check"></i></div>
                <h3>Planification</h3>
                <p>Des interventions organisées du premier contact à la clôture.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-warehouse"></i></div>
                <h3>Garages partenaires</h3>
                <p>Chaque garage supervise ses propres demandes et son équipe.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-user-gear"></i></div>
                <h3>Gestion des techniciens</h3>
                <p>Internes ou rattachés à un garage, chacun avec ses tâches.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-screwdriver-wrench"></i></div>
                <h3>Suivi des réparations</h3>
                <p>Diagnostic, travaux effectués, pièces utilisées, recommandations.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-triangle-exclamation"></i></div>
                <h3>Anomalies constatées</h3>
                <p>Consignées par le garage ou le technicien pendant l'intervention.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-bell"></i></div>
                <h3>Notifications</h3>
                <p>Chaque acteur informé des changements qui le concernent.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-clock-rotate-left"></i></div>
                <h3>Historique</h3>
                <p>L'ensemble du parcours d'un véhicule conservé et consultable.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Supervision de l'activité</h3>
                <p>Une vision d'ensemble pour l'administrateur, un journal détaillé pour chacun.</p>
            </div>
            <div class="homev2-feature-card">
                <div class="homev2-feature-icon"><i class="fas fa-wand-magic-sparkles"></i></div>
                <h3>Assistant IA</h3>
                <p>Analyse l'historique pour proposer des recommandations utiles.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ ESPACES UTILISATEURS ============ -->
<section class="homev2-section homev2-section-alt" id="espaces">
    <div class="homev2-container">
        <div class="homev2-section-head">
            <span class="homev2-eyebrow">Cinq espaces, cinq rôles</span>
            <h2>Un espace dédié à chaque utilisateur</h2>
            <p>Chaque rôle dispose de son propre espace et de ses propres droits — personne n'accède aux données d'un autre périmètre.</p>
        </div>
        <div class="homev2-role-grid">
            <div class="homev2-role-card" style="background:#3956E8;">
                <div class="homev2-role-icon"><i class="fas fa-user"></i></div>
                <h3>Client particulier</h3>
                <p>Gestion de ses propres véhicules et suivi de ses interventions.</p>
            </div>
            <div class="homev2-role-card" style="background:#2540C4;">
                <div class="homev2-role-icon"><i class="fas fa-building"></i></div>
                <h3>Client entreprise</h3>
                <p>Gestion et suivi de son parc automobile.</p>
            </div>
            <div class="homev2-role-card" style="background:#0D9488;">
                <div class="homev2-role-icon"><i class="fas fa-warehouse"></i></div>
                <h3>Garage</h3>
                <p>Gestion de son garage, de ses interventions et de ses techniciens.</p>
            </div>
            <div class="homev2-role-card" style="background:#D97706;">
                <div class="homev2-role-icon"><i class="fas fa-screwdriver-wrench"></i></div>
                <h3>Technicien</h3>
                <p>Gestion de ses tâches, interventions, réparations et anomalies.</p>
            </div>
            <div class="homev2-role-card" style="background:#131B3E;">
                <div class="homev2-role-icon"><i class="fas fa-shield-halved"></i></div>
                <h3>Administrateur</h3>
                <p>Supervision globale de la plateforme.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ GARAGES PARTENAIRES ============ -->
<section class="homev2-section" id="garages">
    <div class="homev2-container homev2-split">
        <div>
            <span class="homev2-eyebrow">Pour les garages partenaires</span>
            <h2 style="font-size:28px; font-weight:800; margin:0 0 14px; color:var(--home-navy);">Un espace dédié à la gestion de votre garage</h2>
            <p style="font-size:15px; line-height:1.65; color:var(--home-text-light); margin:0;">Une fois partenaire, votre garage dispose de son propre espace pour organiser son activité au quotidien.</p>
            <ul class="homev2-split-list">
                <li><span class="homev2-check"><i class="fas fa-check"></i></span> Gérer les demandes d'intervention reçues</li>
                <li><span class="homev2-check"><i class="fas fa-check"></i></span> Gérer votre équipe de techniciens</li>
                <li><span class="homev2-check"><i class="fas fa-check"></i></span> Suivre l'avancement des réparations</li>
                <li><span class="homev2-check"><i class="fas fa-check"></i></span> Constater et consigner les anomalies</li>
                <li><span class="homev2-check"><i class="fas fa-check"></i></span> Suivre l'activité de votre garage</li>
            </ul>
        </div>
        <div class="homev2-split-panel">
            <h3>Déjà partenaire ?</h3>
            <p>Le partenariat avec SmartAutoTrack se conclut directement avec l'équipe SmartAutoTrack. Une fois l'accord conclu, votre compte garage vous est fourni pour accéder à votre espace.</p>
            <div class="homev2-note"><i class="fas fa-circle-info" style="margin-top:2px;"></i> Vous avez déjà un compte garage ? Connectez-vous depuis le bouton en haut de page.</div>
        </div>
    </div>
</section>

<!-- ============ ASSISTANT IA ============ -->
<section class="homev2-section homev2-section-alt" id="ia">
    <div class="homev2-container homev2-split">
        <div class="homev2-split-panel" style="order:2;">
            <div class="homev2-feature-icon" style="margin-bottom:18px;"><i class="fas fa-wand-magic-sparkles"></i></div>
            <h3>Analyse, pas diagnostic automatique</h3>
            <p>L'assistant IA n'est ni un boîtier connecté, ni un système de détection automatique de panne : il s'appuie sur les données déjà enregistrées dans la plateforme (interventions, réparations, anomalies) pour aider à la décision.</p>
        </div>
        <div style="order:1;">
            <span class="homev2-eyebrow">Assistant IA</span>
            <h2 style="font-size:28px; font-weight:800; margin:0 0 14px; color:var(--home-navy);">Des recommandations à partir de vos données réelles</h2>
            <p style="font-size:15px; line-height:1.65; color:var(--home-text-light); margin:0;">L'assistant IA aide à analyser l'historique des interventions et anomalies afin de fournir des recommandations et des synthèses utiles — jamais un diagnostic automatique par capteurs.</p>
        </div>
    </div>
</section>

<!-- ============ ABONNEMENTS ============ -->
<section class="homev2-section">
    <div class="homev2-container">
        <div class="homev2-section-head">
            <span class="homev2-eyebrow">Abonnements</span>
            <h2>Des formules pensées pour chaque profil</h2>
            <p>Formules adaptées aux besoins des particuliers et des entreprises. Le modèle est encore en cours de finalisation.</p>
        </div>
        <div class="homev2-plans">
            <div class="homev2-plan-card">
                <h3>Particuliers</h3>
                <p>Une formule pensée pour le suivi d'un ou plusieurs véhicules personnels.</p>
            </div>
            <div class="homev2-plan-card">
                <h3>Entreprises</h3>
                <p>Une formule pensée pour la gestion d'un parc automobile professionnel.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ CTA FINAL ============ -->
<section class="homev2-section" style="padding-top:0;">
    <div class="homev2-container">
        <div class="homev2-cta">
            <h2>Accédez à votre espace SmartAutoTrack</h2>
            <p>Client, garage, technicien ou administrateur : connectez-vous pour retrouver votre espace.</p>
            <div class="homev2-cta-ctas">
                <a href="<?php echo SITE_URL; ?>auth/login.php" class="homev2-btn homev2-btn-primary">Se connecter</a>
                <a href="<?php echo SITE_URL; ?>auth/register.php" class="homev2-btn homev2-btn-ghost-light">Créer un compte client</a>
            </div>
        </div>
    </div>
</section>

<!-- ============ FOOTER ============ -->
<footer class="homev2-footer">
    <div class="homev2-container homev2-footer-inner">
        <div class="homev2-footer-brand">
            <img src="<?php echo SITE_URL; ?>assets/img/logo.png" alt="<?php echo SITE_NAME; ?>">
            <span>SmartAutoTrack</span>
        </div>
        <div class="homev2-footer-copy">&copy; <?php echo date('Y'); ?> SmartAutoTrack — Suivi &amp; entretien automobile après-vente.</div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('homeNavToggle');
    var links = document.getElementById('homeNavLinks');
    if (toggle && links) {
        toggle.addEventListener('click', function () { links.classList.toggle('show'); });
        links.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () { links.classList.remove('show'); });
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
