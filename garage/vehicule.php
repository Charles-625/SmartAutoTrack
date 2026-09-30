<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Fiche d'un véhicule suivi par le garage.
 *
 * Accès : rôle « garage », et seulement pour un véhicule qui a au moins une
 * intervention dans CE garage (sinon redirection vers interventions.php).
 * Paramètre GET : id (idVehicule).
 * Page en lecture seule : interventions, réparations et anomalies du
 * véhicule, limitées à celles du garage.
 * Tables lues : vehicule, utilisateur, intervention, reparation, anomalie.
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);

$vehiculeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$vehiculeId) { header('Location: interventions.php'); exit; }

// Le véhicule n'est accessible que s'il est lié à au moins une intervention
// de CE garage — jamais une liste globale de tous les véhicules de la plateforme.
$stmt = $conn->prepare("
    SELECT v.idVehicule AS id, v.marque, v.modele, v.immatriculation, v.kilometrage, v.annee, v.couleur,
           CASE v.etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut,
           u.idUtilisateur AS client_id, u.nom AS client_nom, u.prenom AS client_prenom
    FROM vehicule v
    JOIN utilisateur u ON u.idUtilisateur = v.idClient
    WHERE v.idVehicule = ? AND EXISTS (SELECT 1 FROM intervention i WHERE i.idVehicule = v.idVehicule AND i.idGarage = ?)
");
$stmt->execute([$vehiculeId, $garageId]);
$vehicule = $stmt->fetch();
if (!$vehicule) { header('Location: interventions.php'); exit; }

// Interventions de CE garage sur ce véhicule uniquement
$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, i.statut, i.idTechnicien,
           ut.nom AS technicien_nom, ut.prenom AS technicien_prenom
    FROM intervention i
    LEFT JOIN utilisateur ut ON i.idTechnicien = ut.idUtilisateur
    WHERE i.idVehicule = ? AND i.idGarage = ?
    ORDER BY i.dateIntervention DESC
");
$stmt->execute([$vehiculeId, $garageId]);
$interventions = $stmt->fetchAll();

// Réparations issues de ces interventions
$stmt = $conn->prepare("
    SELECT r.idReparation AS id, r.titre, r.statut, r.cout, r.dateReparation
    FROM reparation r
    JOIN intervention i ON r.idIntervention = i.idIntervention
    WHERE i.idVehicule = ? AND i.idGarage = ?
    ORDER BY r.dateReparation DESC
");
$stmt->execute([$vehiculeId, $garageId]);
$reparations = $stmt->fetchAll();

// Anomalies constatées par CE garage sur ce véhicule (via ses propres interventions)
$stmt = $conn->prepare("
    SELECT DISTINCT a.idAnomalie AS id, a.description, a.niveau, a.statut, a.dateDetection
    FROM anomalie a
    WHERE a.idVehicule = ? AND EXISTS (
        SELECT 1 FROM intervention i WHERE i.idVehicule = a.idVehicule AND i.idGarage = ?
    )
    ORDER BY a.dateDetection DESC
");
$stmt->execute([$vehiculeId, $garageId]);
$anomalies = $stmt->fetchAll();

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$pageTitle = 'Véhicule — ' . $vehicule['marque'] . ' ' . $vehicule['modele'];
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'interventions'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">
        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1"><?php echo h($vehicule['marque'] . ' ' . $vehicule['modele']); ?></h1>
                <p class="gv2-sub">Client : <?php echo h($vehicule['client_prenom'] . ' ' . $vehicule['client_nom']); ?> — informations utiles au traitement de vos interventions.</p>
            </div>
            <a href="interventions.php" class="gv2-btn-outline" style="text-decoration:none;">← Retour aux interventions</a>
        </div>

        <div class="gv2-card gv2-panel">
            <div class="gv2-panel-head"><h2>Informations du véhicule</h2></div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:16px;">
                <div><div style="font-size:11px; color:#8AA0A3; text-transform:uppercase; letter-spacing:0.04em;">Immatriculation</div><div style="font-weight:700; margin-top:3px;"><?php echo h($vehicule['immatriculation']); ?></div></div>
                <div><div style="font-size:11px; color:#8AA0A3; text-transform:uppercase; letter-spacing:0.04em;">Année</div><div style="font-weight:700; margin-top:3px;"><?php echo h($vehicule['annee'] ?: 'N/A'); ?></div></div>
                <div><div style="font-size:11px; color:#8AA0A3; text-transform:uppercase; letter-spacing:0.04em;">Kilométrage</div><div style="font-weight:700; margin-top:3px;"><?php echo number_format((float)$vehicule['kilometrage'], 0, ',', ' '); ?> km</div></div>
                <div><div style="font-size:11px; color:#8AA0A3; text-transform:uppercase; letter-spacing:0.04em;">Couleur</div><div style="font-weight:700; margin-top:3px;"><?php echo h($vehicule['couleur'] ?: 'N/A'); ?></div></div>
                <div><div style="font-size:11px; color:#8AA0A3; text-transform:uppercase; letter-spacing:0.04em;">État</div><div style="margin-top:3px;"><span class="gv2-badge <?php echo $vehicule['statut'] === 'actif' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(str_replace('_', ' ', $vehicule['statut']))); ?></span></div></div>
            </div>
        </div>

        <div class="gv2-body">
            <div class="gv2-col">
                <div class="gv2-card gv2-panel">
                    <div class="gv2-panel-head"><h2>Interventions (votre garage)</h2></div>
                    <?php if (empty($interventions)): ?>
                        <div class="gv2-empty">Aucune intervention.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($interventions as $iv): $info = garage_status_info($iv); ?>
                                <div class="gv2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="gv2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?></div>
                                        <div class="gv2-row-meta"><?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?><?php echo h($iv['technicien_nom'] ? ' · ' . $iv['technicien_prenom'] . ' ' . $iv['technicien_nom'] : ''); ?></div>
                                    </div>
                                    <span class="gv2-badge <?php echo h($info['badge']); ?>"><?php echo h($info['label']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="gv2-card gv2-panel">
                    <div class="gv2-panel-head"><h2>Réparations (votre garage)</h2></div>
                    <?php if (empty($reparations)): ?>
                        <div class="gv2-empty">Aucune réparation enregistrée.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($reparations as $r): ?>
                                <div class="gv2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="gv2-row-title"><?php echo h($r['titre'] ?: 'Réparation #' . $r['id']); ?></div>
                                        <div class="gv2-row-meta"><?php echo h(date('d/m/Y', strtotime($r['dateReparation']))); ?> · <?php echo number_format((float)$r['cout'], 0, ',', ' '); ?> XAF</div>
                                    </div>
                                    <span class="gv2-badge <?php echo $r['statut'] === 'TERMINEE' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(strtolower($r['statut']))); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gv2-col">
                <div class="gv2-card gv2-panel-sm">
                    <div class="gv2-panel-head"><h2>Anomalies constatées</h2></div>
                    <?php if (empty($anomalies)): ?>
                        <div class="gv2-empty">Aucune anomalie constatée par votre garage.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($anomalies as $a): $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true); ?>
                                <div>
                                    <div style="font-size:13px; font-weight:600;"><?php echo h($a['description']); ?></div>
                                    <div style="font-size:11.5px; color:#8AA0A3; margin-top:2px;"><?php echo h(date('d/m/Y', strtotime($a['dateDetection']))); ?> · <span class="gv2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo $isActive ? 'Active' : 'Résolue'; ?></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
