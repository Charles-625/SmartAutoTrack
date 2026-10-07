<?php
/**
 * Sidebar du dashboard "v2" Garage. Incluse depuis garage/ ou messages/ :
 * tous les liens utilisent SITE_URL pour rester corrects quel que soit le
 * dossier appelant (même convention que client/includes/sidebar.php).
 *
 * Variables attendues avant l'include :
 *   $activeNav      'dashboard'|'demandes'|'interventions'|'techniciens'|
 *                    'reparations'|'anomalies'|'historique'|'journal'|
 *                    'messages'|'profil'|'parametres'
 *   $pendingBadge   (int, optionnel) nombre de demandes non affectées à afficher en badge
 *   $garageNom      (string, optionnel) nom du garage pour la carte utilisateur
 * Pastilles rouges de nouveautés (onglets
 * « Journal d'activité » et « Interventions ») :
 * activity_log_sidebar_counts() / activity_log_unread_badge()
 * (includes/activity_log.php), même cloisonnement que le journal du rôle,
 * hors actions du visiteur ; elles disparaissent à l'ouverture de l'onglet
 * (activity_log_mark_seen()). Sans $conn, ou tant que la migration
 * onglet_vu n'est pas appliquée, aucune pastille.
 */
$activeNav = $activeNav ?? 'dashboard';
$pendingBadge = $pendingBadge ?? 0;
$garageNom = $garageNom ?? 'Garage';
$prenomInitial = mb_substr((string)($_SESSION['prenom'] ?? ''), 0, 1);
$nomInitial = mb_substr((string)($_SESSION['nom'] ?? ''), 0, 1);
$initials = mb_strtoupper($prenomInitial . $nomInitial) ?: '?';
$navActive = function (string $key) use ($activeNav) { return $activeNav === $key ? 'active' : ''; };
// Pastilles de nouveautés (Journal, Interventions) : jamais d'erreur fatale, 0
// partout sans $conn ou sans la table onglet_vu.
$sidebarUnread = activity_log_sidebar_counts($conn ?? null, ROLE_GARAGE, (int)($_SESSION['user_id'] ?? 0));
?>
<aside class="gv2-sidebar">
    <div class="gv2-sidebar-logo">
        <img src="<?php echo SITE_URL; ?>assets/img/logo-mark.png" alt="SmartAutoTrack" width="84" height="84">
    </div>

    <div class="gv2-nav-label">Mon espace</div>
    <nav class="gv2-nav">
        <a href="<?php echo SITE_URL; ?>garage/dashboard.php" class="gv2-navitem <?php echo $navActive('dashboard'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="8" height="8" rx="2" stroke="#DEE1F5" stroke-width="1.8"/><rect x="13" y="3" width="8" height="5" rx="2" stroke="#DEE1F5" stroke-width="1.8"/><rect x="13" y="12" width="8" height="9" rx="2" stroke="#DEE1F5" stroke-width="1.8"/><rect x="3" y="15" width="8" height="6" rx="2" stroke="#DEE1F5" stroke-width="1.8"/></svg>
            <span class="label">Tableau de bord</span>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/demandes.php" class="gv2-navitem <?php echo $navActive('demandes'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L8 11M12 15L16 11" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V17" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Demandes d'intervention</span>
            <?php if ($pendingBadge > 0): ?>
                <span class="gv2-navbadge warning"><?php echo (int)$pendingBadge; ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/interventions.php" class="gv2-navitem <?php echo $navActive('interventions'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#DEE1F5" stroke-width="1.8"/><path d="M8 8H16M8 12H16M8 16H12" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Interventions</span>
            <?php echo activity_log_unread_badge($sidebarUnread['interventions']); ?>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/techniciens.php" class="gv2-navitem <?php echo $navActive('techniciens'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="#DEE1F5" stroke-width="1.8"/><path d="M3.5 20C4.6 16.3 6.9 15 9 15C11.1 15 13.4 16.3 14.5 20" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round"/><circle cx="17" cy="9" r="2.4" stroke="#DEE1F5" stroke-width="1.6"/><path d="M15 20C15.6 17.3 17 16.3 18.5 16.3C19.6 16.3 20.7 16.9 21.3 18.2" stroke="#DEE1F5" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span class="label">Mes techniciens</span>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/reparations.php" class="gv2-navitem <?php echo $navActive('reparations'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#DEE1F5" stroke-width="1.7" stroke-linejoin="round"/><path d="M12.5 6.5L17.5 11.5" stroke="#DEE1F5" stroke-width="1.7"/></svg>
            <span class="label">Réparations</span>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/anomalies.php" class="gv2-navitem <?php echo $navActive('anomalies'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#DEE1F5" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#DEE1F5"/></svg>
            <span class="label">Anomalies</span>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/historique.php" class="gv2-navitem <?php echo $navActive('historique'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#DEE1F5" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Historique</span>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/journal.php" class="gv2-navitem <?php echo $navActive('journal'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#DEE1F5" stroke-width="1.8"/><path d="M8 7.5H16M8 11.5H16M8 15.5H13" stroke="#DEE1F5" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span class="label">Journal d'activité</span>
            <?php echo activity_log_unread_badge($sidebarUnread['journal']); ?>
        </a>
        <a href="<?php echo SITE_URL; ?>messages/index.php" class="gv2-navitem <?php echo $navActive('messages'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#DEE1F5" stroke-width="1.8"/><path d="M3 6.5L12 13L21 6.5" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="label">Messages</span>
            <span class="gv2-navbadge danger" id="messageCounter">0</span>
        </a>
    </nav>

    <div class="gv2-sep"></div>

    <div class="gv2-nav-label">Compte</div>
    <nav class="gv2-nav">
        <a href="<?php echo SITE_URL; ?>profile.php" class="gv2-navitem <?php echo $navActive('profil'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.6" stroke="#DEE1F5" stroke-width="1.8"/><path d="M4.5 20C5.8 16 8.6 14.5 12 14.5C15.4 14.5 18.2 16 19.5 20" stroke="#DEE1F5" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="label">Profil</span>
        </a>
        <a href="<?php echo SITE_URL; ?>garage/parametres.php" class="gv2-navitem <?php echo $navActive('parametres'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="#DEE1F5" stroke-width="1.8"/><path d="M19 12C19 12.4 19 12.8 18.9 13.2L21 14.7L19.5 17.3L17.1 16.4C16.5 16.9 15.8 17.3 15 17.6L14.6 20H11.4L11 17.6C10.2 17.3 9.5 16.9 8.9 16.4L6.5 17.3L5 14.7L7.1 13.2C7 12.8 7 12.4 7 12C7 11.6 7 11.2 7.1 10.8L5 9.3L6.5 6.7L8.9 7.6C9.5 7.1 10.2 6.7 11 6.4L11.4 4H14.6L15 6.4C15.8 6.7 16.5 7.1 17.1 7.6L19.5 6.7L21 9.3L18.9 10.8C19 11.2 19 11.6 19 12Z" stroke="#DEE1F5" stroke-width="1.5" stroke-linejoin="round"/></svg>
            <span class="label">Paramètres</span>
        </a>
    </nav>

    <div class="gv2-sidebar-spacer"></div>

    <div class="gv2-usercard">
        <div class="gv2-avatar"><?php echo h($initials); ?></div>
        <div style="flex-grow: 1; min-width: 0;">
            <div class="gv2-usercard-name"><?php echo h($garageNom); ?></div>
            <div class="gv2-usercard-role">Garage partenaire</div>
        </div>
        <a href="<?php echo SITE_URL; ?>auth/logout.php" class="gv2-usercard-logout" aria-label="Déconnexion" title="Déconnexion">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M9 21H5C3.9 21 3 20.1 3 19V5C3 3.9 3.9 3 5 3H9" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/><path d="M16 17L21 12L16 7" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12H9" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/></svg>
        </a>
    </div>
</aside>
