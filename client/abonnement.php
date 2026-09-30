<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

/**
 * Page "Abonnement" de l'espace client (particulier ou entreprise).
 *
 * Accès : rôle client uniquement (requireRole('client')).
 * Page informative en lecture seule : aucune action POST/GET n'est traitée.
 * Tables lues : intervention (badge sidebar), vehicule (taille du parc).
 * Fichiers liés : client/includes/sidebar.php, includes/header.php,
 * assets/css/client_v2.css.
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
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmt->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmt->fetchColumn();

// Nombre de véhicules suivis par le client (taille du parc).
$stmt = $conn->prepare("SELECT COUNT(*) FROM vehicule WHERE idClient = ?");
$stmt->execute([$_SESSION['user_id']]);
$fleetSize = (int)$stmt->fetchColumn();

$pageTitle = 'Abonnement';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'abonnement'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">
        <div>
            <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
            <h1 class="v2-h1">Abonnement</h1>
            <p class="v2-sub">Votre formule actuelle et les prochaines offres SmartAutoTrack.</p>
        </div>

        <div class="v2-card v2-panel" style="max-width: 560px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:17px;">Formule actuelle</h2>
                <span style="font-size:11px; font-weight:700; color:#4A4F73; background:#F1F2F9; padding:4px 10px; border-radius:20px;">GRATUIT</span>
            </div>
            <?php if ($isEntreprise): ?>
                <p style="color:#666C8E; font-size:14px; line-height:1.6;">
                    Vous utilisez actuellement la formule gratuite de SmartAutoTrack pour votre parc de
                    <?php echo (int)$fleetSize; ?> véhicule<?php echo $fleetSize > 1 ? 's' : ''; ?> : supervision de flotte,
                    demandes d'intervention, historique de réparations, consultation des anomalies, messagerie et notifications.
                </p>
                <div class="v2-alert" style="background:#EAEFFC; color:#2540C4; margin-top:16px;">
                    Les formules Premium entreprise seront proposées par <strong>paliers selon la taille de votre parc</strong>
                    (nombre de véhicules suivis), avec suivi prioritaire et délais d'intervention réduits.
                    Les tarifs et paliers n'ont pas encore été activés — revenez bientôt.
                </div>
            <?php else: ?>
                <p style="color:#666C8E; font-size:14px; line-height:1.6;">
                    Vous utilisez actuellement la formule gratuite de SmartAutoTrack : suivi de vos véhicules,
                    demandes d'intervention, historique de réparations, messagerie et notifications.
                </p>
                <div class="v2-alert" style="background:#EEF1FF; color:#3956E8; margin-top:16px;">
                    Les formules Premium (suivi prioritaire, délais d'intervention réduits, options supplémentaires)
                    sont en cours de définition. Les tarifs et paliers n'ont pas encore été activés — revenez bientôt.
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
