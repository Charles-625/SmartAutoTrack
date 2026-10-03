<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';
require_once '../includes/anomaly_types.php';

/**
 * Supervision des anomalies détectées sur les véhicules (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Lecture seule : aucune action POST. Filtres GET : `statut` (active|resolue),
 * `garage`, `technicien`, `date_from`, `date_to` (bornes de dateDetection).
 *
 * Colonne « Constatée par » : « Client » pour une anomalie déclarée par le
 * client dans sa demande d'intervention (reconnue à son entrée de journal
 * ANOMALY_CLIENT_LOG_NAME écrite par ce client), sinon le technicien de
 * l'intervention.
 *
 * Tables lues : anomalie, vehicule, utilisateur (client et technicien),
 * intervention, garage, technicien, journalactivites.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Supervision uniquement — rappel de la règle métier : une anomalie est
// constatée par un garage/technicien à la suite d'une intervention, ou
// déclarée par le client en demandant une intervention (motif « Anomalie
// constatée ») ; jamais créée hors d'une intervention par le client.
$statutFilter = $_GET['statut'] ?? '';
$garageFilter = filter_var($_GET['garage'] ?? null, FILTER_VALIDATE_INT);
$technicienFilter = filter_var($_GET['technicien'] ?? null, FILTER_VALIDATE_INT);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// « active » = NOUVELLE/EN_COURS, « resolue » = TRAITEE/IGNOREE. Les dates
// saisies sont étendues à la journée entière (00:00:00 → 23:59:59).
$where = [];
$params = [];
if ($statutFilter === 'active') { $where[] = "a.statut IN ('NOUVELLE', 'EN_COURS')"; }
elseif ($statutFilter === 'resolue') { $where[] = "a.statut IN ('TRAITEE', 'IGNOREE')"; }
if ($garageFilter) { $where[] = 'i.idGarage = ?'; $params[] = $garageFilter; }
if ($technicienFilter) { $where[] = 'i.idTechnicien = ?'; $params[] = $technicienFilter; }
if ($dateFrom) { $where[] = 'a.dateDetection >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $where[] = 'a.dateDetection <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

// LEFT JOIN sur intervention : une anomalie peut exister sans intervention
// rattachée. Liste plafonnée à 200 lignes pour garder la page légère.
$stmt = $conn->prepare("
    SELECT a.idAnomalie AS id, a.description, a.type, a.niveau, a.statut, a.dateDetection,
           v.marque, v.modele, v.immatriculation,
           uc.prenom AS client_prenom, uc.nom AS client_nom,
           i.idIntervention, i.type AS intervention_type,
           ut.prenom AS technicien_prenom, ut.nom AS technicien_nom,
           g.nomGarage,
           EXISTS (SELECT 1 FROM journalactivites j
                   WHERE j.idAnomalie = a.idAnomalie AND j.nomActivite = ? AND j.idUtilisateur = v.idClient) AS declared_by_client
    FROM anomalie a
    JOIN vehicule v ON v.idVehicule = a.idVehicule
    JOIN utilisateur uc ON uc.idUtilisateur = v.idClient
    LEFT JOIN intervention i ON i.idIntervention = a.idIntervention
    LEFT JOIN utilisateur ut ON ut.idUtilisateur = i.idTechnicien
    LEFT JOIN garage g ON g.idGarage = i.idGarage
    WHERE $whereSql
    ORDER BY a.dateDetection DESC
    LIMIT 200
");
$stmt->execute(array_merge([ANOMALY_CLIENT_LOG_NAME], $params));
$anomalies = $stmt->fetchAll();

$garagesList = $conn->query("SELECT idGarage, nomGarage FROM garage WHERE statutGarage = 'VALIDE' ORDER BY nomGarage")->fetchAll();
$techniciensList = $conn->query("SELECT u.idUtilisateur AS id, u.nom, u.prenom FROM utilisateur u JOIN technicien t ON t.idTechnicien = u.idUtilisateur ORDER BY u.nom")->fetchAll();

// Compteurs globaux des cartes de synthèse, indépendants des filtres.
$stats = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS actives,
           SUM(CASE WHEN niveau = 'CRITIQUE' AND statut IN ('NOUVELLE','EN_COURS') THEN 1 ELSE 0 END) AS critiques
    FROM anomalie
")->fetch();

$pageTitle = 'Anomalies';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'anomalies'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Anomalies</h1>
                <p class="av2-sub">Toutes les anomalies constatées sur la plateforme — par un garage ou un technicien, ou signalées par le client dans sa demande d'intervention.</p>
            </div>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#6D74A0" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total anomalies</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FDEDEE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3L22 20H2L12 3Z" stroke="#E5484D" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['actives']; ?></div><div class="av2-stat-label">Actives</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['critiques']; ?></div><div class="av2-stat-label">Critiques actives</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <select name="statut" onchange="this.form.submit()">
                <option value="">Toutes</option>
                <option value="active" <?php echo $statutFilter === 'active' ? 'selected' : ''; ?>>Actives</option>
                <option value="resolue" <?php echo $statutFilter === 'resolue' ? 'selected' : ''; ?>>Résolues</option>
            </select>
            <select name="garage" onchange="this.form.submit()">
                <option value="">Tous les garages</option>
                <?php foreach ($garagesList as $g): ?>
                    <option value="<?php echo (int)$g['idGarage']; ?>" <?php echo $garageFilter === (int)$g['idGarage'] ? 'selected' : ''; ?>><?php echo h($g['nomGarage']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="technicien" onchange="this.form.submit()">
                <option value="">Tous les techniciens</option>
                <?php foreach ($techniciensList as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>" <?php echo $technicienFilter === (int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['prenom'] . ' ' . $t['nom']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" onchange="this.form.submit()">
            <?php if ($statutFilter || $garageFilter || $technicienFilter || $dateFrom || $dateTo): ?>
                <a href="anomalies.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($anomalies)): ?>
                <div class="av2-empty">Aucune anomalie ne correspond aux critères.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Véhicule</th><th>Client</th><th>Anomalie</th><th>Niveau</th><th>Intervention</th><th>Garage</th><th>Constatée par</th><th>Date</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anomalies as $a): $isActive = in_array($a['statut'], ['NOUVELLE', 'EN_COURS'], true); $niveauBadge = $a['niveau'] === 'CRITIQUE' ? 'bad' : ($a['niveau'] === 'FAIBLE' ? 'neutral' : 'warn'); ?>
                                <tr>
                                    <td><?php echo h($a['marque'] . ' ' . $a['modele']); ?><div style="font-size:11.5px; color:#8B90B3;"><?php echo h($a['immatriculation']); ?></div></td>
                                    <td><?php echo h($a['client_prenom'] . ' ' . $a['client_nom']); ?></td>
                                    <td style="max-width:220px;"><?php echo h($a['description']); ?></td>
                                    <td><span class="av2-badge <?php echo h($niveauBadge); ?>"><?php echo h(ucfirst(strtolower($a['niveau']))); ?></span></td>
                                    <td><?php if ($a['idIntervention']): ?><?php echo h($a['intervention_type'] ?: 'Intervention'); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php if ($a['nomGarage']): ?><?php echo h($a['nomGarage']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php if ($a['declared_by_client']): ?>Client<?php elseif ($a['technicien_nom']): ?><?php echo h($a['technicien_prenom'] . ' ' . $a['technicien_nom']); ?><?php else: ?><span style="color:#8B90B3;">—</span><?php endif; ?></td>
                                    <td><?php echo h(date('d/m/Y', strtotime($a['dateDetection']))); ?></td>
                                    <td><span class="av2-badge <?php echo $isActive ? 'bad' : 'ok'; ?>"><?php echo $isActive ? 'Active' : 'Résolue'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
