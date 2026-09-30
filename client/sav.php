<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

/**
 * Page SAV (service après-vente) de l'espace client.
 *
 * Accès : rôle client uniquement.
 * Page d'aide en lecture seule : questions fréquentes et lien direct vers la
 * messagerie (messages/index.php) avec un administrateur. Aucune action POST.
 * Tables lues : intervention (badge sidebar), utilisateur et administrateur
 * (contact SAV).
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

// Premier administrateur disponible, pour le contact direct "Contacter le SAV"
$stmt = $conn->query("SELECT u.idUtilisateur, u.nom, u.prenom FROM utilisateur u JOIN administrateur a ON a.idAdministrateur = u.idUtilisateur ORDER BY u.idUtilisateur LIMIT 1");
$admin = $stmt->fetch();

$pageTitle = 'SAV';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'sav'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">

        <div class="v2-page-head">
            <div>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1">SAV — Besoin d'aide ?</h1>
                <p class="v2-sub">Notre service client répond à vos questions sur votre compte, vos véhicules ou vos interventions.</p>
            </div>
        </div>

        <div class="v2-body">
            <div class="v2-col">
                <div class="v2-card v2-panel">
                    <div class="v2-panel-head"><h2>Contacter le SAV</h2></div>
                    <p style="color:#666C8E; font-size:14px; line-height:1.6; margin:0 0 16px;">
                        Une question sur une réparation, une facture, votre compte ou l'utilisation de l'application ?
                        Écrivez-nous directement, un membre de l'équipe SmartAutoTrack vous répondra via la messagerie.
                    </p>
                    <?php if ($admin): ?>
                        <a href="<?php echo SITE_URL; ?>messages/index.php?contact=<?php echo (int)$admin['idUtilisateur']; ?>" class="v2-btn-accent" style="display:inline-block; text-decoration:none; padding:12px 22px; width:auto;">Écrire au SAV</a>
                    <?php else: ?>
                        <a href="<?php echo SITE_URL; ?>messages/index.php" class="v2-btn-accent" style="display:inline-block; text-decoration:none; padding:12px 22px; width:auto;">Aller à la messagerie</a>
                    <?php endif; ?>
                </div>

                <div class="v2-card v2-panel">
                    <div class="v2-panel-head"><h2>Questions fréquentes</h2></div>
                    <div style="display:flex; flex-direction:column; gap:14px;">
                        <div>
                            <div style="font-weight:700; font-size:14px; margin-bottom:4px;">Comment demander une intervention sur mon véhicule ?</div>
                            <div style="color:#666C8E; font-size:13.5px; line-height:1.5;">Depuis <a href="interventions.php" style="color:#3956E8; font-weight:600;">Interventions</a>, cliquez sur « Demander une intervention », choisissez le véhicule et décrivez le problème. Un administrateur affecte ensuite un garage ou un technicien et fixe la date.</div>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:14px; margin-bottom:4px;">Comment télécharger le rapport d'une réparation ?</div>
                            <div style="color:#666C8E; font-size:13.5px; line-height:1.5;">Depuis <a href="reparations.php" style="color:#3956E8; font-weight:600;">Réparations &amp; historique</a>, cliquez sur l'icône de téléchargement à côté d'une réparation terminée.</div>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:14px; margin-bottom:4px;">D'où viennent les anomalies affichées sur mes véhicules ?</div>
                            <div style="color:#666C8E; font-size:13.5px; line-height:1.5;">Elles sont constatées par un technicien ou un garage lors d'une intervention ou d'un diagnostic — vous ne pouvez pas en créer vous-même.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="v2-col">
                <div class="v2-card v2-panel-sm">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#171B33" stroke-width="1.8"/><circle cx="12" cy="12" r="3.4" stroke="#171B33" stroke-width="1.8"/><path d="M12 3V5.6M12 18.4V21M3 12H5.6M18.4 12H21M5.6 5.6L7.4 7.4M16.6 16.6L18.4 18.4M18.4 5.6L16.6 7.4M7.4 16.6L5.6 18.4" stroke="#171B33" stroke-width="1.5"/></svg>
                        <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:15px; font-weight:700;">Bon à savoir</h2>
                    </div>
                    <p style="margin:0; font-size:13px; line-height:1.6; color:#666C8E;">
                        Toutes vos conversations avec le SAV, les techniciens et les garages passent par la
                        <a href="<?php echo SITE_URL; ?>messages/index.php" style="color:#3956E8; font-weight:600;">messagerie</a> — vous retrouverez l'historique complet de vos échanges à tout moment.
                    </p>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
