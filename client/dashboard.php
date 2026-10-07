<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/subscription.php';
require_once '../includes/payments.php';

/**
 * Tableau de bord de l'espace client (particulier ou entreprise).
 *
 * Accès : rôle client uniquement.
 * Lecture seule : aucune action POST/GET n'est traitée ici.
 * Affiche : véhicules et leur anomalie active la plus récente, interventions
 * actives (5 max), dernières réparations terminées, messages non lus et
 * aperçu des notifications.
 * Interventions : « qui s'occupe » via v2_intervention_handler() (SmartAutoTrack
 * et son technicien interne, garage partenaire en appui, ou garage seul).
 * Tables lues : vehicule, anomalie, intervention, garage, utilisateur, technicien,
 * reparation, messages, notifications (et entreprise via getUserProfile()),
 * abonnement (encart « Votre abonnement » : GRATUIT ou PREMIUM via clientIsPremium()),
 * paiement (encart « X réparation(s) à payer — total Y XAF » : réparations
 * TERMINEE au coût non nul sans paiement PAYE ni tentative EN_ATTENTE récente
 * (paymentStatesForRepairs()), affiché seulement si paymentsReady() et
 * campayIsConfigured() ; bouton « Payer » vers reparations.php?pay=<id> de la
 * plus ancienne, « Voir » vers reparations.php?status=a_payer).
 * Fichiers liés : client/includes/helpers.php (v2_*), client/includes/sidebar.php.
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

// ============================================================
// Données réelles du client connecté
// ============================================================

// Heure du serveur MySQL : référence des dates relatives (v2_relative), pour
// éviter tout décalage entre le fuseau de PHP et celui de la base.
$dbNow = $conn->query('SELECT NOW()')->fetchColumn();

// Véhicules (statut historique reconstruit depuis `etat`, comme le reste du site)
$stmt = $conn->prepare("
    SELECT idVehicule AS id, marque, modele, immatriculation, annee, couleur, kilometrage,
           CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
    FROM vehicule WHERE idClient = ? ORDER BY dateCreation DESC
");
$stmt->execute([$_SESSION['user_id']]);
$vehicules = $stmt->fetchAll();
$vehicleIds = array_column($vehicules, 'id');

// Anomalies actives (NOUVELLE/EN_COURS), avec la dernière intervention connue
// sur le même véhicule comme provenance affichée ("constatée lors de
// l'intervention du ..."). Le client ne peut pas créer d'anomalie lui-même :
// cette liste ne vient que de constats technicien/garage.
$anomaliesParVehicule = [];
$anomaliesActivesCount = 0;
if ($vehicleIds) {
    $placeholders = str_repeat('?,', count($vehicleIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT a.idAnomalie, a.idVehicule, a.description, a.dateDetection, a.niveau,
               (SELECT i.dateIntervention FROM intervention i
                WHERE i.idVehicule = a.idVehicule AND i.dateIntervention <= a.dateDetection
                ORDER BY i.dateIntervention DESC LIMIT 1) AS intervention_date
        FROM anomalie a
        WHERE a.idVehicule IN ($placeholders) AND a.statut IN ('NOUVELLE', 'EN_COURS')
        ORDER BY a.dateDetection DESC
    ");
    $stmt->execute($vehicleIds);
    $activeAnomalies = $stmt->fetchAll();
    $anomaliesActivesCount = count($activeAnomalies);
    foreach ($activeAnomalies as $a) {
        // On ne garde que la plus récente par véhicule pour l'aperçu carte
        if (!isset($anomaliesParVehicule[$a['idVehicule']])) {
            $anomaliesParVehicule[$a['idVehicule']] = $a;
        }
    }
}
$anomaliesCountParVehicule = array_count_values(array_column($activeAnomalies ?? [], 'idVehicule'));

// Interventions actives (demande envoyée / planifiée / en cours), avec le type
// du technicien (INTERNE/GARAGE) lu par v2_intervention_handler()
$interventions = [];
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.description, i.dateIntervention, i.statut,
           i.idTechnicien, i.idGarage, v.marque, v.modele,
           g.nomGarage, ut.nom AS technicien_nom, ut.prenom AS technicien_prenom,
           t.typeTechnicien AS technicien_type
    FROM intervention i
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    LEFT JOIN garage g ON i.idGarage = g.idGarage
    LEFT JOIN utilisateur ut ON i.idTechnicien = ut.idUtilisateur
    LEFT JOIN technicien t ON i.idTechnicien = t.idTechnicien
    WHERE i.idClient = ? AND i.statut IN ('PLANIFIEE', 'EN_COURS')
    ORDER BY CASE WHEN i.statut = 'EN_COURS' THEN 0 WHEN i.idTechnicien IS NULL THEN 1 ELSE 2 END, i.dateIntervention ASC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$interventions = $stmt->fetchAll();
// Statut d'affichage : une intervention PLANIFIEE sans technicien affecté
// n'est encore qu'une demande en attente d'affectation (par le garage choisi,
// ou par l'admin pour une demande adressée à SmartAutoTrack).
foreach ($interventions as &$iv) {
    if ($iv['statut'] === 'EN_COURS') {
        $iv['display'] = 'en_cours';
    } elseif ($iv['idTechnicien'] === null) {
        $iv['display'] = 'demande_envoyee';
    } else {
        $iv['display'] = 'planifiee';
    }
}
unset($iv);
$interventionsActivesCount = count($interventions);

// Réparations & historique (terminées)
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre, r.cout, r.dateReparation, v.marque, v.modele
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    JOIN vehicule v ON i.idVehicule = v.idVehicule
    WHERE i.idClient = ? AND r.statut = 'TERMINEE'
    ORDER BY r.dateReparation DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$historique = $stmt->fetchAll();

// Réparations à payer par Mobile Money (encart sous les statistiques). Sans
// CamPay configuré ou sans les colonnes de paiement, l'encart n'apparaît pas.
$aPayer = [];
if (campayIsConfigured() && paymentsReady($conn)) {
    $stmt = $conn->prepare("
        SELECT r.idReparation AS id, r.cout
        FROM reparation r
        JOIN intervention i ON r.idIntervention = i.idIntervention
        WHERE i.idClient = ? AND r.statut = 'TERMINEE' AND r.cout > 0
          AND NOT EXISTS (SELECT 1 FROM paiement p WHERE p.idReparation = r.idReparation AND p.statut = 'PAYE')
        ORDER BY COALESCE(r.dateFin, r.dateReparation) ASC, r.idReparation ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $aPayer = $stmt->fetchAll();
    // Une tentative EN_ATTENTE récente bloque un nouveau paiement : exclue.
    $etatsPaiement = paymentStatesForRepairs($conn, array_column($aPayer, 'id'));
    $aPayer = array_values(array_filter($aPayer, fn($r) => !isset($etatsPaiement[(int)$r['id']])));
}
$aPayerTotal = array_sum(array_map(fn($r) => (int)round((float)$r['cout']), $aPayer));

// Messages non lus (messagerie déjà fonctionnelle, on la relie simplement ici)
$stmt = $conn->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND lu = 'non'");
$stmt->execute([$_SESSION['user_id']]);
$messagesNonLus = (int)$stmt->fetchColumn();

// Notifications récentes (aperçu ; le centre de notifications complet — avec
// marquage lu/non lu — reste dans la cloche, alimentée par le système existant)
$stmt = $conn->prepare("
    SELECT id, type, titre, message, lu, date_creation
    FROM notifications WHERE user_id = ? ORDER BY date_creation DESC LIMIT 3
");
$stmt->execute([$_SESSION['user_id']]);
$notificationsApercu = $stmt->fetchAll();

$prenomAffiche = $_SESSION['prenom'] ?? '';
// Salutation entreprise : raison sociale si renseignée (table `entreprise`,
// peut ne pas encore exister pour ce client), sinon repli propre sur le nom
// du contact — jamais d'écran cassé si la donnée manque.
$nomAffiche = $isEntreprise ? ($profile['raisonSociale'] ?? trim($prenomAffiche . ' ' . ($_SESSION['nom'] ?? ''))) : $prenomAffiche;

$pageTitle = 'Tableau de bord';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'dashboard'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">

        <div class="v2-topbar">
            <div>
                <?php if ($isEntreprise): ?>
                    <div class="v2-entreprise-tag">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M3 21V7L12 3L21 7V21" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 21V13H15V21" stroke="currentColor" stroke-width="1.8"/></svg>
                        Supervision de flotte
                    </div>
                <?php endif; ?>
                <div class="v2-kicker">Bienvenue sur SmartAutoTrack</div>
                <h1 class="v2-h1">Bonjour, <?php echo h($nomAffiche); ?></h1>
                <p class="v2-sub"><?php echo h($isEntreprise ? 'Voici l\'état de votre parc automobile' : 'Voici l\'état de vos véhicules'); ?> — <?php echo h(v2_today_fr()); ?></p>
            </div>
            <div class="v2-topbar-actions">
                <div class="v2-notif-wrap">
                    <button class="v2-iconbtn" id="notificationBtn" aria-label="Notifications" type="button">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M6 10C6 6.7 8.7 4 12 4C15.3 4 18 6.7 18 10V14L20 17H4L6 14V10Z" stroke="#171B33" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20C10.4 20.8 11.1 21.3 12 21.3C12.9 21.3 13.6 20.8 14 20" stroke="#171B33" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <span class="v2-notif-dot" id="notificationCounter"></span>
                    </button>
                    <div class="v2-notif-panel" id="notificationPanel">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                            <button class="mark-all-read" id="markAllRead" type="button">Tout marquer comme lu</button>
                        </div>
                        <div class="notification-list" id="notificationList"></div>
                    </div>
                </div>
                <div class="v2-topbar-avatar"><?php echo h(mb_strtoupper(mb_substr($prenomAffiche, 0, 1) . mb_substr($_SESSION['nom'] ?? '', 0, 1))); ?></div>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="v2-stats">
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:<?php echo $isEntreprise ? '#F3F5FE' : '#F3F5FE'; ?>;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="<?php echo $isEntreprise ? '#2540C4' : '#3956E8'; ?>" stroke-width="1.8"/><circle cx="7.5" cy="18.5" r="1.6" stroke="<?php echo $isEntreprise ? '#2540C4' : '#3956E8'; ?>" stroke-width="1.8"/><circle cx="16.5" cy="18.5" r="1.6" stroke="<?php echo $isEntreprise ? '#2540C4' : '#3956E8'; ?>" stroke-width="1.8"/><path d="M5 10L7 5.5H17L19 10" stroke="<?php echo $isEntreprise ? '#2540C4' : '#3956E8'; ?>" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="v2-stat-value"><?php echo count($vehicules); ?></div>
                    <div class="v2-stat-label"><?php echo $isEntreprise ? 'Véhicules du parc' : 'Véhicules suivis'; ?></div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#FDEDEE;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10V14" stroke="#E5484D" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="#E5484D"/></svg>
                </div>
                <div>
                    <div class="v2-stat-value" style="color:#E5484D;"><?php echo (int)$anomaliesActivesCount; ?></div>
                    <div class="v2-stat-label">Anomalie<?php echo $anomaliesActivesCount > 1 ? 's' : ''; ?> active<?php echo $anomaliesActivesCount > 1 ? 's' : ''; ?></div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#FFF4E2;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#C8871A" stroke-width="1.8"/><path d="M8 8H16M8 12H16M8 16H12" stroke="#C8871A" stroke-width="1.8" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)$interventionsActivesCount; ?></div>
                    <div class="v2-stat-label">Intervention<?php echo $interventionsActivesCount > 1 ? 's' : ''; ?> en cours</div>
                </div>
            </div>
            <div class="v2-card v2-stat-card">
                <div class="v2-stat-icon" style="background:#E9F6EE;">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#1E8A4C" stroke-width="1.8"/><path d="M3 6.5L12 13L21 6.5" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="v2-stat-value"><?php echo (int)$messagesNonLus; ?></div>
                    <div class="v2-stat-label">Messages non lus</div>
                </div>
            </div>
        </div>

        <?php if ($aPayer): ?>
            <!-- Réparations à payer (Mobile Money) -->
            <div class="v2-card v2-pay-due">
                <div>
                    <strong><?php echo count($aPayer); ?> réparation<?php echo count($aPayer) > 1 ? 's' : ''; ?> à payer</strong>
                    — total <?php echo number_format($aPayerTotal, 0, ',', ' '); ?> XAF
                    <div class="v2-pay-due-sub">Réglez par MTN Mobile Money ou Orange Money depuis l'onglet Réparations.</div>
                </div>
                <div class="v2-pay-due-actions">
                    <a href="reparations.php?pay=<?php echo (int)$aPayer[0]['id']; ?>" class="v2-pay-due-btn primary"<?php echo count($aPayer) > 1 ? ' title="Payer la plus ancienne"' : ''; ?>>Payer</a>
                    <?php if (count($aPayer) > 1): ?>
                        <a href="reparations.php?status=a_payer" class="v2-pay-due-btn">Voir</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="v2-body">
            <!-- LEFT column -->
            <div class="v2-col">

                <!-- Mes véhicules / Aperçu du parc -->
                <div class="v2-card v2-panel">
                    <div class="v2-panel-head">
                        <h2><?php echo $isEntreprise ? 'Aperçu du parc' : 'Mes véhicules'; ?></h2>
                        <a href="vehicles.php" class="v2-link"><?php echo $isEntreprise ? 'Voir tout le parc →' : 'Voir tous →'; ?></a>
                    </div>
                    <?php if (empty($vehicules)): ?>
                        <div class="v2-empty">Aucun véhicule enregistré. <a href="vehicles.php" class="v2-link">Ajouter un véhicule</a></div>
                    <?php elseif ($isEntreprise): ?>
                        <div class="v2-table-wrap">
                            <table class="v2-mini-table">
                                <thead>
                                    <tr><th>Véhicule</th><th>Immatriculation</th><th>Kilométrage</th><th>Statut</th><th>Anomalie</th><th>Action</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($vehicules, 0, 6) as $v):
                                        $anomalyCount = $anomaliesCountParVehicule[$v['id']] ?? 0;
                                        $hasAnomaly = $anomalyCount > 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="v2-table-vehicle">
                                                    <div class="v2-table-vehicle-icon">
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#2540C4" stroke-width="1.6"/><circle cx="7.5" cy="18.5" r="1.5" stroke="#2540C4" stroke-width="1.6"/><circle cx="16.5" cy="18.5" r="1.5" stroke="#2540C4" stroke-width="1.6"/><path d="M5 10L7 5.5H17L19 10" stroke="#2540C4" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                                    </div>
                                                    <?php echo h($v['marque'] . ' ' . $v['modele']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo h($v['immatriculation']); ?></td>
                                            <td><?php echo number_format((float)$v['kilometrage'], 0, ',', ' '); ?> km</td>
                                            <td><span class="v2-badge <?php echo $v['statut'] === 'actif' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(str_replace('_', ' ', $v['statut']))); ?></span></td>
                                            <td><?php if ($hasAnomaly): ?><span class="v2-badge bad"><?php echo (int)$anomalyCount; ?> anomalie<?php echo $anomalyCount > 1 ? 's' : ''; ?></span><?php else: ?><span class="v2-badge ok">Aucune</span><?php endif; ?></td>
                                            <td><a href="vehicle_details.php?id=<?php echo (int)$v['id']; ?>" class="v2-table-link">Détails</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="v2-vehicles-grid">
                            <?php foreach (array_slice($vehicules, 0, 4) as $v):
                                $anomalyCount = $anomaliesCountParVehicule[$v['id']] ?? 0;
                                $hasAnomaly = $anomalyCount > 0;
                                $anomalie = $anomaliesParVehicule[$v['id']] ?? null;
                                $anomalyProvenance = $anomalie
                                    ? ($anomalie['intervention_date']
                                        ? 'constatée lors de l\'intervention du ' . date('d/m', strtotime($anomalie['intervention_date']))
                                        : 'détectée le ' . date('d/m', strtotime($anomalie['dateDetection'])))
                                    : '';
                            ?>
                                <div class="v2-vehicle-card <?php echo $hasAnomaly ? 'has-anomaly' : ''; ?>">
                                    <div class="v2-vehicle-top">
                                        <div class="v2-vehicle-icon" style="background:<?php echo $hasAnomaly ? '#FDEDEE' : '#F3F5FE'; ?>;">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="<?php echo $hasAnomaly ? '#E5484D' : '#3956E8'; ?>" stroke-width="1.6"/><circle cx="7.5" cy="18.5" r="1.5" stroke="<?php echo $hasAnomaly ? '#E5484D' : '#3956E8'; ?>" stroke-width="1.6"/><circle cx="16.5" cy="18.5" r="1.5" stroke="<?php echo $hasAnomaly ? '#E5484D' : '#3956E8'; ?>" stroke-width="1.6"/><path d="M5 10L7 5.5H17L19 10" stroke="<?php echo $hasAnomaly ? '#E5484D' : '#3956E8'; ?>" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                        </div>
                                        <?php if ($hasAnomaly): ?>
                                            <span class="v2-badge bad"><?php echo (int)$anomalyCount; ?> anomalie<?php echo $anomalyCount > 1 ? 's' : ''; ?></span>
                                        <?php else: ?>
                                            <span class="v2-badge <?php echo $v['statut'] === 'actif' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(str_replace('_', ' ', $v['statut']))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="v2-vehicle-name"><?php echo h($v['marque'] . ' ' . $v['modele']); ?></div>
                                        <div class="v2-vehicle-meta"><?php echo h($v['immatriculation']); ?> · <?php echo number_format((float)$v['kilometrage'], 0, ',', ' '); ?> km</div>
                                    </div>
                                    <?php if ($hasAnomaly && $anomalie): ?>
                                        <div class="v2-vehicle-anomaly">
                                            Anomalie constatée — <?php echo h($anomalie['description']); ?> — <?php echo h($anomalyProvenance); ?>.
                                        </div>
                                    <?php endif; ?>
                                    <a href="vehicle_details.php?id=<?php echo (int)$v['id']; ?>" class="v2-vehicle-link">Voir détails →</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Interventions -->
                <div class="v2-card v2-panel">
                    <div class="v2-panel-head">
                        <h2>Interventions</h2>
                        <a href="interventions.php" class="v2-link">Voir toutes →</a>
                    </div>
                    <?php if (empty($interventions)): ?>
                        <div class="v2-empty">Aucune intervention en cours pour le moment.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($interventions as $iv):
                                // $meta : texte brut, échappé une seule fois à l'affichage (h()).
                                if ($iv['display'] === 'demande_envoyee') {
                                    $rowClass = 'pending'; $iconBg = '#EFF0F6'; $iconColor = '#6D74A0'; $badgeClass = 'neutral'; $label = 'Demande envoyée';
                                    $meta = $iv['marque'] . ' ' . $iv['modele'] . ' · demande envoyée à ' . v2_intervention_handler($iv) . ' ' . v2_relative($iv['dateIntervention'], $dbNow) . ' — en attente d\'affectation d\'un technicien';
                                } elseif ($iv['display'] === 'en_cours') {
                                    $rowClass = ''; $iconBg = '#FFF4E2'; $iconColor = '#C8871A'; $badgeClass = 'warn'; $label = 'En cours';
                                    $qui = v2_intervention_handler($iv);
                                    $meta = $iv['marque'] . ' ' . $iv['modele'] . ' · ' . $qui . ' · ' . date('d/m/Y', strtotime($iv['dateIntervention']));
                                } else {
                                    $rowClass = ''; $iconBg = '#F3F5FE'; $iconColor = '#3956E8'; $badgeClass = 'ok'; $label = 'Planifiée';
                                    $qui = v2_intervention_handler($iv);
                                    $meta = $iv['marque'] . ' ' . $iv['modele'] . ' · ' . $qui . ' · ' . date('d/m/Y', strtotime($iv['dateIntervention']));
                                }
                            ?>
                                <div class="v2-row <?php echo h($rowClass); ?>">
                                    <div class="v2-row-icon" style="background:<?php echo h($iconBg); ?>;">
                                        <?php if ($iv['display'] === 'demande_envoyee'): ?>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L8 11M12 15L16 11" stroke="<?php echo h($iconColor); ?>" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V17" stroke="<?php echo h($iconColor); ?>" stroke-width="1.8" stroke-linecap="round"/></svg>
                                        <?php else: ?>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="<?php echo h($iconColor); ?>" stroke-width="1.8"/><path d="M12 7V12L15.5 14" stroke="<?php echo h($iconColor); ?>" stroke-width="1.8" stroke-linecap="round"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="v2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?></div>
                                        <div class="v2-row-meta"><?php echo h($meta); ?></div>
                                    </div>
                                    <span class="v2-row-status v2-badge <?php echo h($badgeClass); ?>"><?php echo h($label); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="interventions.php" class="v2-dashed-btn" style="display:block; box-sizing:border-box; text-decoration:none; text-align:center;">+ Demander une intervention</a>
                </div>

                <!-- Historique réparations -->
                <div class="v2-card v2-panel">
                    <div class="v2-panel-head">
                        <h2>Historique des réparations</h2>
                        <a href="reparations.php" class="v2-link">Voir tout →</a>
                    </div>
                    <?php if (empty($historique)): ?>
                        <div class="v2-empty">Aucune réparation terminée pour le moment.</div>
                    <?php else: ?>
                        <div>
                            <?php foreach ($historique as $h): ?>
                                <div class="v2-history-item">
                                    <div class="v2-history-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#1E8A4C" stroke-width="1.8"/><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </div>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="v2-history-title"><?php echo h($h['titre'] ?: ($h['marque'] . ' ' . $h['modele'])); ?></div>
                                        <div class="v2-history-meta"><?php echo h($h['marque'] . ' ' . $h['modele']); ?> · <?php echo h(date('d/m/Y', strtotime($h['dateReparation']))); ?> · <?php echo number_format((float)$h['cout'], 0, ',', ' '); ?> XAF</div>
                                    </div>
                                    <a href="<?php echo SITE_URL; ?>ajax/download_report.php?id=<?php echo (int)$h['id']; ?>" class="v2-download-btn" aria-label="Télécharger le rapport" title="Télécharger le rapport">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L8 11M12 15L16 11" stroke="#666C8E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17V19C4 20.1 4.9 21 6 21H18C19.1 21 20 20.1 20 19V17" stroke="#666C8E" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT column -->
            <div class="v2-col">

                <!-- Assistant IA -->
                <div class="v2-ai-card">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2L14 9L21 11L14 13L12 20L10 13L3 11L10 9Z" fill="#FFFFFF"/></svg>
                        <h2>Assistant IA</h2>
                    </div>
                    <p><?php echo $isEntreprise
                        ? "L'assistant IA vous aide à comprendre l'entretien de votre parc, vos interventions et vos démarches."
                        : "Une question sur l'entretien, une démarche ou votre compte ? Posez-la ici."; ?></p>
                    <a href="assistant.php" class="v2-ai-input" style="text-decoration:none;">
                        <span style="flex-grow:1; font-size:13px; color:rgba(255,255,255,0.55);">Ouvrir l'assistant…</span>
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 12L21 3L14 21L11 13L3 12Z" stroke="#FFFFFF" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    </a>
                    <p class="v2-ai-foot">L'assistant IA oriente et informe ; il ne pose pas de diagnostic mécanique et ne remplace pas l'expertise d'un technicien. (Bientôt disponible.)</p>
                </div>

                <!-- Notifications -->
                <div class="v2-card v2-panel-sm">
                    <div class="v2-panel-head">
                        <h2>Notifications</h2>
                        <a href="#" class="v2-link" id="seeAllNotifications">Tout voir</a>
                    </div>
                    <?php if (empty($notificationsApercu)): ?>
                        <div class="v2-empty" style="padding:12px 4px;">Aucune notification récente.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($notificationsApercu as $n): ?>
                                <div class="v2-notiflist-item" style="<?php echo $n['lu'] === 'oui' ? 'opacity:0.55;' : ''; ?>">
                                    <span class="v2-notiflist-dot" style="<?php echo $n['lu'] === 'oui' ? 'background:#DDE0F0;' : ''; ?>"></span>
                                    <div>
                                        <div class="v2-notiflist-title"><?php echo h($n['titre']); ?></div>
                                        <div class="v2-notiflist-time"><?php echo h(v2_relative($n['date_creation'], $dbNow)); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SAV -->
                <div class="v2-card v2-panel-sm">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#171B33" stroke-width="1.8"/><circle cx="12" cy="12" r="3.4" stroke="#171B33" stroke-width="1.8"/><path d="M12 3V5.6M12 18.4V21M3 12H5.6M18.4 12H21M5.6 5.6L7.4 7.4M16.6 16.6L18.4 18.4M18.4 5.6L16.6 7.4M7.4 16.6L5.6 18.4" stroke="#171B33" stroke-width="1.5"/></svg>
                        <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:15px; font-weight:700;">Besoin d'aide ?</h2>
                    </div>
                    <p style="margin:0 0 14px; font-size:13px; line-height:1.5; color:#666C8E;">Notre service client répond à vos questions sur votre compte ou votre suivi de véhicule.</p>
                    <a href="sav.php" class="v2-btn-dark" style="display:block; text-align:center; box-sizing:border-box;">Contacter le SAV</a>
                </div>

                <!-- Abonnement -->
                <div class="v2-card v2-panel-sm" style="border-color:#3956E8; border-width:1.5px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                        <h2 style="margin:0; font-family:'Sora', sans-serif; font-size:15px; font-weight:700;">Votre abonnement</h2>
                        <?php $dashboardPremium = clientIsPremium($conn, (int)$_SESSION['user_id']); ?>
                        <span style="font-size:10.5px; font-weight:700; color:<?php echo $dashboardPremium ? '#1E8A4C' : '#6D74A0'; ?>; background:<?php echo $dashboardPremium ? '#E9F6EE' : '#EFF0F6'; ?>; padding:3px 9px; border-radius:20px;"><?php echo $dashboardPremium ? 'PREMIUM' : 'GRATUIT'; ?></span>
                    </div>
                    <?php if ($dashboardPremium): ?>
                    <p style="margin:0 0 14px; font-size:13px; line-height:1.5; color:#666C8E;">Votre formule Premium est active : plus de véhicules, assistant IA illimité et historique complet.</p>
                    <a href="abonnement.php" class="v2-btn-accent" style="display:block; text-align:center; box-sizing:border-box;">Gérer mon abonnement</a>
                    <?php else: ?>
                    <p style="margin:0 0 14px; font-size:13px; line-height:1.5; color:#666C8E;"><?php echo $isEntreprise
                        ? 'Passez à un palier Premium adapté à la taille de votre parc, avec suivi prioritaire.'
                        : 'Passez à Premium pour un suivi complet et des délais d\'intervention prioritaires.'; ?></p>
                    <a href="abonnement.php" class="v2-btn-accent" style="display:block; text-align:center; box-sizing:border-box;">Découvrir Premium</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var notifBtn = document.getElementById('notificationBtn');
    var notifPanel = document.getElementById('notificationPanel');
    if (notifBtn) {
        notifBtn.addEventListener('click', function (e) { e.stopPropagation(); notifPanel.style.display = (notifPanel.style.display === 'block') ? 'none' : 'block'; });
        document.addEventListener('click', function () { notifPanel.style.display = 'none'; });
    }
    var seeAll = document.getElementById('seeAllNotifications');
    if (seeAll) seeAll.addEventListener('click', function (e) { e.preventDefault(); notifPanel.style.display = 'block'; });
});
</script>

<?php include '../includes/footer.php'; ?>
