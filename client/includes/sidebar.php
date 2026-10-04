<?php
/**
 * Sidebar partagée du dashboard "v2" (Client particulier / entreprise).
 * Incluse depuis client/, messages/ ou la racine : tous les liens utilisent
 * SITE_URL pour rester corrects quel que soit le dossier appelant.
 *
 * Variables attendues avant l'include :
 *   $activeNav           'dashboard'|'vehicules'|'interventions'|'anomalies'|'reparations'|
 *                         'messages'|'sav'|'assistant'|'abonnement'|'profil'|'parametres'
 *   $interventionsBadge  (int, optionnel) nombre d'interventions actives à afficher en badge
 *   $isEntreprise        (bool, optionnel) libellés "parc" + item "Anomalies" + sidebar bleu profond
 * Nécessite que config/config.php et la session client soient déjà chargés
 * (fait par la page appelante avant l'include). Si la page a ouvert $conn
 * (PDO), le badge de l'item « Abonnement » indique GRATUIT ou PREMIUM
 * (clientIsPremium(), includes/subscription.php) ; sans $conn, ou tant que la
 * migration des abonnements n'est pas appliquée, il reste « GRATUIT ».
 * Pastilles rouges de nouveautés (onglets
 * « Journal d'activité » et « Interventions ») :
 * activity_log_sidebar_counts() / activity_log_unread_badge()
 * (includes/activity_log.php), même cloisonnement que le journal du rôle,
 * hors actions du visiteur ; elles disparaissent à l'ouverture de l'onglet
 * (activity_log_mark_seen()). Sans $conn, ou tant que la migration
 * onglet_vu n'est pas appliquée, aucune pastille.
 * Onglet « Interventions » : la pastille rouge (nouveautés) se place à
 * droite du badge orange existant (interventions actives) ; les deux
 * restent visibles, accolées (écart de 6px), le rouge et le liseré blanc
 * distinguent la pastille (règle .nav-unread, assets/css/style.css).
 */
require_once __DIR__ . '/../../includes/subscription.php';
$activeNav = $activeNav ?? 'dashboard';
$interventionsBadge = $interventionsBadge ?? 0;
$clientRoleLabel = $clientRoleLabel ?? 'Client particulier';
$isEntreprise = $isEntreprise ?? false;
$prenomInitial = mb_substr((string)($_SESSION['prenom'] ?? ''), 0, 1);
$nomInitial = mb_substr((string)($_SESSION['nom'] ?? ''), 0, 1);
$initials = mb_strtoupper($prenomInitial . $nomInitial) ?: '?';
$navActive = function (string $key) use ($activeNav) { return $activeNav === $key ? 'active' : ''; };
// Badge de formule : une erreur de lecture ne doit jamais casser la navigation.
$sidebarPremium = false;
if (isset($conn) && $conn instanceof PDO && !empty($_SESSION['user_id'])) {
    try {
        $sidebarPremium = clientIsPremium($conn, (int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        error_log('[SmartAutoTrack] sidebar abonnement : ' . $e->getMessage());
    }
}
// Pastilles de nouveautés (Journal, Interventions) : jamais d'erreur fatale, 0
// partout sans $conn ou sans la table onglet_vu.
$sidebarUnread = activity_log_sidebar_counts($conn ?? null, ROLE_CLIENT, (int)($_SESSION['user_id'] ?? 0));
?>
<aside class="v2-sidebar <?php echo $isEntreprise ? 'v2-sidebar-entreprise' : ''; ?>">
    <div class="v2-sidebar-logo">
        <div class="v2-sidebar-logo-badge">
            <img src="<?php echo SITE_URL; ?>assets/img/logo-mark.png" alt="SmartAutoTrack" width="84" height="84">
        </div>
    </div>

    <div class="v2-nav-label">Mon espace</div>
    <nav class="v2-nav">
        <a href="<?php echo SITE_URL; ?>client/dashboard.php" class="v2-navitem <?php echo $navActive('dashboard'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="8" height="8" rx="2" stroke="#FFFFFF" stroke-width="1.8"/><rect x="13" y="3" width="8" height="5" rx="2" stroke="#FFFFFF" stroke-width="1.8"/><rect x="13" y="12" width="8" height="9" rx="2" stroke="#FFFFFF" stroke-width="1.8"/><rect x="3" y="15" width="8" height="6" rx="2" stroke="#FFFFFF" stroke-width="1.8"/></svg>
            <span class="label">Tableau de bord</span>
        </a>
        <a href="<?php echo SITE_URL; ?>client/vehicles.php" class="v2-navitem <?php echo $navActive('vehicules'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#FFFFFF" stroke-width="1.8"/><circle cx="7.5" cy="18.5" r="1.6" stroke="#FFFFFF" stroke-width="1.8"/><circle cx="16.5" cy="18.5" r="1.6" stroke="#FFFFFF" stroke-width="1.8"/><path d="M5 10L7 5.5H17L19 10" stroke="#FFFFFF" stroke-width="1.8" stroke-linejoin="round"/></svg>
            <span class="label"><?php echo $isEntreprise ? 'Mon parc' : 'Mes véhicules'; ?></span>
        </a>
        <a href="<?php echo SITE_URL; ?>client/interventions.php" class="v2-navitem <?php echo $navActive('interventions'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#FFFFFF" stroke-width="1.8"/><path d="M8 8H16M8 12H16M8 16H12" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Interventions</span>
            <?php if ($interventionsBadge > 0): ?>
                <span class="v2-navbadge warning"><?php echo (int)$interventionsBadge; ?></span>
            <?php endif; ?>
            <?php echo activity_log_unread_badge($sidebarUnread['interventions']); ?>
        </a>
        <?php if ($isEntreprise): ?>
        <a href="<?php echo SITE_URL; ?>client/anomalies.php" class="v2-navitem <?php echo $navActive('anomalies'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#FFFFFF" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#FFFFFF"/></svg>
            <span class="label">Anomalies</span>
        </a>
        <?php endif; ?>
        <a href="<?php echo SITE_URL; ?>client/reparations.php" class="v2-navitem <?php echo $navActive('reparations'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#FFFFFF" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Réparations &amp; historique</span>
        </a>
        <a href="<?php echo SITE_URL; ?>client/journal.php" class="v2-navitem <?php echo $navActive('journal'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#FFFFFF" stroke-width="1.8"/><path d="M8 7.5H16M8 11.5H16M8 15.5H13" stroke="#FFFFFF" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span class="label">Journal d'activité</span>
            <?php echo activity_log_unread_badge($sidebarUnread['journal']); ?>
        </a>
        <a href="<?php echo SITE_URL; ?>messages/index.php" class="v2-navitem <?php echo $navActive('messages'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#FFFFFF" stroke-width="1.8"/><path d="M3 6.5L12 13L21 6.5" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="label">Messages</span>
            <span class="v2-navbadge danger" id="messageCounter">0</span>
        </a>
        <a href="<?php echo SITE_URL; ?>client/sav.php" class="v2-navitem <?php echo $navActive('sav'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#FFFFFF" stroke-width="1.8"/><circle cx="12" cy="12" r="3.4" stroke="#FFFFFF" stroke-width="1.8"/><path d="M12 3V5.6M12 18.4V21M3 12H5.6M18.4 12H21M5.6 5.6L7.4 7.4M16.6 16.6L18.4 18.4M18.4 5.6L16.6 7.4M7.4 16.6L5.6 18.4" stroke="#FFFFFF" stroke-width="1.5"/></svg>
            <span class="label">SAV</span>
        </a>
        <a href="<?php echo SITE_URL; ?>client/assistant.php" class="v2-navitem <?php echo $navActive('assistant'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" stroke="#FFFFFF" stroke-width="1.6" stroke-linejoin="round"/></svg>
            <span class="label">Assistant IA</span>
        </a>
    </nav>

    <div class="v2-sep"></div>

    <div class="v2-nav-label">Compte</div>
    <nav class="v2-nav">
        <a href="<?php echo SITE_URL; ?>client/abonnement.php" class="v2-navitem <?php echo $navActive('abonnement'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2.5" stroke="#FFFFFF" stroke-width="1.8"/><path d="M2 10H22" stroke="#FFFFFF" stroke-width="1.8"/></svg>
            <span class="label">Abonnement</span>
            <span style="font-size: 10px; font-weight: 700; color: #12172B; background: <?php echo $sidebarPremium ? '#8DE0FF' : '#C7CBEA'; ?>; border-radius: 20px; padding: 2px 8px;"><?php echo $sidebarPremium ? 'PREMIUM' : 'GRATUIT'; ?></span>
        </a>
        <a href="<?php echo SITE_URL; ?>profile.php" class="v2-navitem <?php echo $navActive('profil'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.6" stroke="#FFFFFF" stroke-width="1.8"/><path d="M4.5 20C5.8 16 8.6 14.5 12 14.5C15.4 14.5 18.2 16 19.5 20" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Profil</span>
        </a>
        <a href="<?php echo SITE_URL; ?>client/settings.php" class="v2-navitem <?php echo $navActive('parametres'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="#FFFFFF" stroke-width="1.8"/><path d="M19 12C19 12.4 19 12.8 18.9 13.2L21 14.7L19.5 17.3L17.1 16.4C16.5 16.9 15.8 17.3 15 17.6L14.6 20H11.4L11 17.6C10.2 17.3 9.5 16.9 8.9 16.4L6.5 17.3L5 14.7L7.1 13.2C7 12.8 7 12.4 7 12C7 11.6 7 11.2 7.1 10.8L5 9.3L6.5 6.7L8.9 7.6C9.5 7.1 10.2 6.7 11 6.4L11.4 4H14.6L15 6.4C15.8 6.7 16.5 7.1 17.1 7.6L19.5 6.7L21 9.3L18.9 10.8C19 11.2 19 11.6 19 12Z" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round"/></svg>
            <span class="label">Paramètres</span>
        </a>
    </nav>

    <div class="v2-sidebar-spacer"></div>

    <div class="v2-usercard">
        <div class="v2-avatar"><?php echo h($initials); ?></div>
        <div style="flex-grow: 1; min-width: 0;">
            <div class="v2-usercard-name"><?php echo h(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
            <div class="v2-usercard-role"><?php echo h($clientRoleLabel); ?></div>
        </div>
        <a href="<?php echo SITE_URL; ?>auth/logout.php" class="v2-usercard-logout" aria-label="Déconnexion" title="Déconnexion">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M9 21H5C3.9 21 3 20.1 3 19V5C3 3.9 3.9 3 5 3H9" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/><path d="M16 17L21 12L16 7" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12H9" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/></svg>
        </a>
    </div>
</aside>
