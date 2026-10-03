<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once '../includes/subscription.php';

/**
 * Véhicules du client : liste, ajout, modification et suppression.
 *
 * Accès : rôle client uniquement.
 * POST action=add (CSRF)        : ajoute un véhicule au client, dans la limite
 *                                 de sa formule (subscriptionVehicleLimit() :
 *                                 1 ou 3 véhicules en gratuit, davantage en
 *                                 Premium). Les véhicules déjà enregistrés
 *                                 restent toujours consultables et modifiables.
 * POST ?action=edit&id=N (CSRF) : modifie un véhicule du client.
 * GET  ?action=delete&id=N      : supprime un véhicule du client ; la base
 *                                 refuse si des interventions y sont liées.
 * GET (client entreprise uniquement) : q (recherche marque/modèle/immat.),
 *     statut, anomalie ('avec' | 'sans'), page (20 véhicules par page).
 * Règles de saisie : validateModel(), validatePlate(), normalizePlate()…
 * (config/config.php) ; l'immatriculation doit être unique.
 * Table écrite : vehicule ; lues : vehicule, anomalie, intervention (badge),
 * abonnement (limite de véhicules, via includes/subscription.php ; aucune
 * limite tant que la migration des abonnements n'est pas appliquée).
 * Fichiers liés : ajax/get_vehicle.php (pré-remplissage du formulaire
 * d'édition), assets/js/main.js (filtres de saisie).
 */
requireRole('client');

$db = new Database();
$conn = $db->getConnection();

// Profil du client connecté : le type (PARTICULIER/ENTREPRISE) règle les
// libellés et la variante de la sidebar.
$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$clientRoleLabel = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE') ? 'Client entreprise' : 'Client particulier';
$isEntreprise = (($profile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');

// L'action vient de l'URL (?action=edit&id=N, ?action=delete&id=N) ou, pour
// l'ajout, du champ caché « action » du formulaire, posté sur vehicles.php.
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$vehicle_id = $_GET['id'] ?? null;
$errors = [];
$limitError = null;

// Limite de véhicules de la formule (gratuit ou Premium). Sans la migration
// des abonnements, aucune limite : le comportement d'origine est conservé.
$vehicleLimit = subscriptionsReady($conn)
    ? subscriptionVehicleLimit($isEntreprise ? 'ENTREPRISE' : 'PARTICULIER', subscriptionActive($conn, (int)$_SESSION['user_id']))
    : null;

// Ajouter un véhicule
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } elseif ($vehicleLimit !== null && subscriptionVehicleCount($conn, (int)$_SESSION['user_id']) >= $vehicleLimit) {
        // Contrôle serveur de la limite : le bouton masqué ne suffit pas. Seuls
        // les nouveaux ajouts sont bloqués, jamais les véhicules existants.
        // Message affiché avec un lien vers abonnement.php (voir plus bas).
        $limitError = 'Vous avez atteint la limite de ' . $vehicleLimit . ' véhicule' . ($vehicleLimit > 1 ? 's' : '')
            . ' de votre formule. Passez au Premium pour suivre davantage de véhicules.';
    } else {
        $marque = sanitize($_POST['marque'] ?? '');
        $modele = sanitize($_POST['modele'] ?? '');
        $immatriculation = normalizePlate(sanitize($_POST['immatriculation'] ?? ''));
        $annee = (int)($_POST['annee'] ?? 0);
        $couleur = sanitize($_POST['couleur'] ?? '');
        $kilometrage = (int)($_POST['kilometrage'] ?? 0);

        // Validation serveur : la saisie est aussi filtrée en JS, mais on ne s'y fie pas.
        if (empty($marque)) $errors[] = 'La marque est requise.';
        elseif (!validateLettersOnly($marque)) $errors[] = 'La marque ne doit contenir que des lettres.';
        if (empty($modele)) $errors[] = 'Le modèle est requis.';
        elseif (!validateModel($modele)) $errors[] = 'Le modèle ne peut contenir que des lettres, des chiffres, des espaces et les signes - . + ! /';
        if (empty($immatriculation)) $errors[] = 'L\'immatriculation est requise.';
        elseif (!validatePlate($immatriculation)) $errors[] = 'L\'immatriculation ne doit contenir que des lettres et des chiffres (espaces et tirets permis).';
        if ($couleur !== '' && !validateLettersOnly($couleur)) $errors[] = 'La couleur ne doit contenir que des lettres.';
        if (!validateDigitsOnly(trim($_POST['annee'] ?? '')) && trim($_POST['annee'] ?? '') !== '') $errors[] = 'L\'année ne doit contenir que des chiffres.';
        elseif ($annee !== 0 && ($annee < 1900 || $annee > (int)date('Y') + 1)) $errors[] = 'L\'année du véhicule n\'est pas valide.';
        if (!validateDigitsOnly(trim($_POST['kilometrage'] ?? '')) && trim($_POST['kilometrage'] ?? '') !== '') $errors[] = 'Le kilométrage ne doit contenir que des chiffres.';

        if (empty($errors)) {
            // Transaction + FOR UPDATE sur la ligne client : deux ajouts
            // simultanés ne peuvent pas dépasser ensemble la limite de la formule
            // (le nombre de véhicules est recompté sous verrou).
            $conn->beginTransaction();
            try {
                $conn->prepare('SELECT idClient FROM client WHERE idClient = ? FOR UPDATE')->execute([$_SESSION['user_id']]);
                $overLimit = $vehicleLimit !== null && subscriptionVehicleCount($conn, (int)$_SESSION['user_id']) >= $vehicleLimit;
                // Unicité de l'immatriculation sur toute la base, pas seulement chez ce client.
                $stmt = $conn->prepare("SELECT idVehicule FROM vehicule WHERE immatriculation = ?");
                $stmt->execute([$immatriculation]);
                if ($overLimit) {
                    $conn->rollBack();
                    $limitError = 'Vous avez atteint la limite de ' . $vehicleLimit . ' véhicule' . ($vehicleLimit > 1 ? 's' : '')
                        . ' de votre formule. Passez au Premium pour suivre davantage de véhicules.';
                } elseif ($stmt->fetch()) {
                    $conn->rollBack();
                    $errors[] = 'Cette immatriculation est déjà enregistrée.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO vehicule (idClient, marque, modele, immatriculation, annee, couleur, kilometrage)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$_SESSION['user_id'], $marque, $modele, $immatriculation, $annee, $couleur, $kilometrage]);
                    $conn->commit();
                    header('Location: vehicles.php?success=added');
                    exit;
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $errors[] = 'Erreur lors de l\'ajout du véhicule.';
            }
        }
    }
}

// Modifier un véhicule
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $vehicle_id) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $marque = sanitize($_POST['marque'] ?? '');
        $modele = sanitize($_POST['modele'] ?? '');
        $immatriculation = normalizePlate(sanitize($_POST['immatriculation'] ?? ''));
        $annee = (int)($_POST['annee'] ?? 0);
        $couleur = sanitize($_POST['couleur'] ?? '');
        $kilometrage = (int)($_POST['kilometrage'] ?? 0);

        // Validation serveur : la saisie est aussi filtrée en JS, mais on ne s'y fie pas.
        if (empty($marque)) $errors[] = 'La marque est requise.';
        elseif (!validateLettersOnly($marque)) $errors[] = 'La marque ne doit contenir que des lettres.';
        if (empty($modele)) $errors[] = 'Le modèle est requis.';
        elseif (!validateModel($modele)) $errors[] = 'Le modèle ne peut contenir que des lettres, des chiffres, des espaces et les signes - . + ! /';
        if (empty($immatriculation)) $errors[] = 'L\'immatriculation est requise.';
        elseif (!validatePlate($immatriculation)) $errors[] = 'L\'immatriculation ne doit contenir que des lettres et des chiffres (espaces et tirets permis).';
        if ($couleur !== '' && !validateLettersOnly($couleur)) $errors[] = 'La couleur ne doit contenir que des lettres.';
        if (!validateDigitsOnly(trim($_POST['annee'] ?? '')) && trim($_POST['annee'] ?? '') !== '') $errors[] = 'L\'année ne doit contenir que des chiffres.';
        elseif ($annee !== 0 && ($annee < 1900 || $annee > (int)date('Y') + 1)) $errors[] = 'L\'année du véhicule n\'est pas valide.';
        if (!validateDigitsOnly(trim($_POST['kilometrage'] ?? '')) && trim($_POST['kilometrage'] ?? '') !== '') $errors[] = 'Le kilométrage ne doit contenir que des chiffres.';

        if (empty($errors)) {
            try {
                // Même contrôle, en excluant le véhicule en cours de modification.
                $stmt = $conn->prepare("SELECT idVehicule FROM vehicule WHERE immatriculation = ? AND idVehicule != ?");
                $stmt->execute([$immatriculation, $vehicle_id]);
                if ($stmt->fetch()) {
                    $errors[] = 'Cette immatriculation est déjà enregistrée.';
                } else {
                    // Le filtre idClient empêche de modifier le véhicule d'un autre client.
                    $stmt = $conn->prepare("
                        UPDATE vehicule
                        SET marque = ?, modele = ?, immatriculation = ?, annee = ?, couleur = ?, kilometrage = ?
                        WHERE idVehicule = ? AND idClient = ?
                    ");
                    $stmt->execute([$marque, $modele, $immatriculation, $annee, $couleur, $kilometrage, $vehicle_id, $_SESSION['user_id']]);
                    header('Location: vehicles.php?success=updated');
                    exit;
                }
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la modification du véhicule.';
            }
        }
    }
}

// Supprimer un véhicule (RESTRICT : impossible tant que des interventions existent)
if ($action === 'delete' && $vehicle_id) {
    try {
        // Contrôle de propriété dans la requête même : idClient = client connecté.
        $stmt = $conn->prepare("DELETE FROM vehicule WHERE idVehicule = ? AND idClient = ?");
        $stmt->execute([$vehicle_id, $_SESSION['user_id']]);
        header('Location: vehicles.php?success=deleted');
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la suppression du véhicule. Il est peut-être lié à des interventions existantes.';
    }
}

// Nombre total de véhicules du parc (non filtré — utilisé pour le sous-titre,
// indépendamment de la recherche/des filtres appliqués ci-dessous)
$stmt = $conn->prepare("SELECT COUNT(*) FROM vehicule WHERE idClient = ?");
$stmt->execute([$_SESSION['user_id']]);
$totalVehiculesCount = (int)$stmt->fetchColumn();
// Limite atteinte : le bouton d'ajout devient un lien « Passer au Premium ».
$limitReached = $vehicleLimit !== null && $totalVehiculesCount >= $vehicleLimit;

// Recherche/filtres/pagination : uniquement pour le client entreprise (parc
// potentiellement grand). Le client particulier garde la requête d'origine,
// strictement inchangée, pour ne rien casser de son côté.
$searchQuery = '';
$statutFilter = '';
$anomalieFilter = '';
$currentPage = 1;
$totalPages = 1;
$perPage = 20;

if ($isEntreprise) {
    $searchQuery = trim((string)($_GET['q'] ?? ''));
    $statutFilter = $_GET['statut'] ?? '';
    $anomalieFilter = $_GET['anomalie'] ?? '';
    $currentPage = max(1, (int)($_GET['page'] ?? 1));

    $statutDbMap = ['actif' => 'BON', 'en_panne' => 'EN_PANNE', 'en_entretien' => 'EN_ENTRETIEN', 'hors_service' => 'HORS_SERVICE'];

    $where = ['idClient = ?'];
    $params = [$_SESSION['user_id']];

    if ($searchQuery !== '') {
        $where[] = '(marque LIKE ? OR modele LIKE ? OR immatriculation LIKE ?)';
        $like = '%' . $searchQuery . '%';
        array_push($params, $like, $like, $like);
    }
    if (isset($statutDbMap[$statutFilter])) {
        $where[] = 'etat = ?';
        $params[] = $statutDbMap[$statutFilter];
    }
    if ($anomalieFilter === 'avec') {
        $where[] = "EXISTS (SELECT 1 FROM anomalie a WHERE a.idVehicule = vehicule.idVehicule AND a.statut IN ('NOUVELLE','EN_COURS'))";
    } elseif ($anomalieFilter === 'sans') {
        $where[] = "NOT EXISTS (SELECT 1 FROM anomalie a WHERE a.idVehicule = vehicule.idVehicule AND a.statut IN ('NOUVELLE','EN_COURS'))";
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM vehicule WHERE $whereSql");
    $stmt->execute($params);
    $filteredCount = (int)$stmt->fetchColumn();
    $totalPages = max(1, (int)ceil($filteredCount / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT idVehicule AS id, marque, modele, immatriculation, annee, couleur, kilometrage,
               CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
        FROM vehicule WHERE $whereSql ORDER BY dateCreation DESC LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $vehicules = $stmt->fetchAll();
} else {
    // Client particulier : requête d'origine, inchangée.
    $stmt = $conn->prepare("
        SELECT idVehicule AS id, marque, modele, immatriculation, annee, couleur, kilometrage,
               CASE etat WHEN 'EN_PANNE' THEN 'en_panne' WHEN 'EN_ENTRETIEN' THEN 'en_entretien' WHEN 'HORS_SERVICE' THEN 'hors_service' ELSE 'actif' END AS statut
        FROM vehicule WHERE idClient = ? ORDER BY dateCreation DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $vehicules = $stmt->fetchAll();
}

// Anomalies actives des véhicules affichés : leur nombre par véhicule et la
// plus récente, pour l'aperçu de chaque carte ou ligne.
$anomaliesCountParVehicule = [];
$anomaliesParVehicule = [];
if ($vehicules) {
    $vehicleIds = array_column($vehicules, 'id');
    $placeholders = str_repeat('?,', count($vehicleIds) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT idVehicule, description, dateDetection
        FROM anomalie
        WHERE idVehicule IN ($placeholders) AND statut IN ('NOUVELLE', 'EN_COURS')
        ORDER BY dateDetection DESC
    ");
    $stmt->execute($vehicleIds);
    $activeAnomalies = $stmt->fetchAll();
    foreach ($activeAnomalies as $a) {
        $anomaliesCountParVehicule[$a['idVehicule']] = ($anomaliesCountParVehicule[$a['idVehicule']] ?? 0) + 1;
        if (!isset($anomaliesParVehicule[$a['idVehicule']])) {
            $anomaliesParVehicule[$a['idVehicule']] = $a;
        }
    }
}

// Interventions actives, pour le badge sidebar (même calcul que dashboard.php)
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
$stmt->execute([$_SESSION['user_id']]);
$interventionsActivesCount = (int)$stmt->fetchColumn();

// Véhicule à pré-remplir pour modification (via AJAX ajax/get_vehicle.php, comme avant)

$pageTitle = $isEntreprise ? 'Mon parc' : 'Mes véhicules';
$hideNavbar = true;
$bodyClass = 'v2';
$extraStylesheets = ['assets/css/client_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="v2-shell">
    <?php $activeNav = 'vehicules'; $interventionsBadge = $interventionsActivesCount; include 'includes/sidebar.php'; ?>

    <main class="v2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="v2-alert success">
                <?php
                switch ($_GET['success']) {
                    case 'added': echo 'Véhicule ajouté avec succès !'; break;
                    case 'updated': echo 'Véhicule modifié avec succès !'; break;
                    case 'deleted': echo 'Véhicule supprimé avec succès !'; break;
                }
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="v2-alert error"><?php echo h($err); ?></div>
        <?php endforeach; ?>
        <?php if ($limitError !== null): ?>
            <div class="v2-alert error"><?php echo h($limitError); ?> <a href="abonnement.php" style="color:inherit; font-weight:700;">Voir l'abonnement Premium →</a></div>
        <?php endif; ?>

        <div class="v2-page-head">
            <div>
                <?php if ($isEntreprise): ?>
                    <div class="v2-entreprise-tag">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M3 21V7L12 3L21 7V21" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 21V13H15V21" stroke="currentColor" stroke-width="1.8"/></svg>
                        Supervision de flotte
                    </div>
                <?php endif; ?>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1"><?php echo $isEntreprise ? 'Mon parc' : 'Mes véhicules'; ?></h1>
                <p class="v2-sub"><?php echo (int)$totalVehiculesCount; ?> véhicule<?php echo $totalVehiculesCount > 1 ? 's' : ''; ?> <?php echo h($isEntreprise ? 'dans le parc' : 'suivi' . ($totalVehiculesCount > 1 ? 's' : '')); ?><?php if ($vehicleLimit !== null): ?> · <?php echo (int)$totalVehiculesCount; ?> / <?php echo (int)$vehicleLimit; ?> véhicule<?php echo $vehicleLimit > 1 ? 's' : ''; ?> autorisé<?php echo $vehicleLimit > 1 ? 's' : ''; ?> par votre formule<?php endif; ?></p>
            </div>
            <?php if ($limitReached): ?>
                <a href="abonnement.php" class="v2-btn-primary" style="padding:12px 20px; text-decoration:none;">Passer au Premium</a>
            <?php else: ?>
                <button class="v2-btn-primary" id="addVehicleBtn" type="button" style="padding:12px 20px;">+ Ajouter un véhicule</button>
            <?php endif; ?>
        </div>

        <?php if ($isEntreprise): ?>
        <!-- ==================== VUE ENTREPRISE : TABLEAU ==================== -->
        <form method="GET" class="v2-filterbar" style="margin-top:22px;">
            <div class="v2-filterbar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#8B90B3" stroke-width="1.8"/><path d="M21 21L16.5 16.5" stroke="#8B90B3" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="text" name="q" value="<?php echo h($searchQuery); ?>" placeholder="Rechercher par marque, modèle, immatriculation…">
            </div>
            <select name="statut" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="actif" <?php echo $statutFilter === 'actif' ? 'selected' : ''; ?>>Actif</option>
                <option value="en_entretien" <?php echo $statutFilter === 'en_entretien' ? 'selected' : ''; ?>>En entretien</option>
                <option value="en_panne" <?php echo $statutFilter === 'en_panne' ? 'selected' : ''; ?>>En panne</option>
                <option value="hors_service" <?php echo $statutFilter === 'hors_service' ? 'selected' : ''; ?>>Hors service</option>
            </select>
            <select name="anomalie" onchange="this.form.submit()">
                <option value="">Anomalie : indifférent</option>
                <option value="avec" <?php echo $anomalieFilter === 'avec' ? 'selected' : ''; ?>>Avec anomalie active</option>
                <option value="sans" <?php echo $anomalieFilter === 'sans' ? 'selected' : ''; ?>>Sans anomalie</option>
            </select>
            <button type="submit" class="v2-btn-primary" style="width:auto; padding:10px 18px;">Filtrer</button>
            <?php if ($searchQuery !== '' || $statutFilter !== '' || $anomalieFilter !== ''): ?>
                <a href="vehicles.php" class="v2-btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">Effacer</a>
            <?php endif; ?>
        </form>

        <div class="v2-card" style="padding:8px;">
            <?php if (empty($vehicules)): ?>
                <div class="v2-empty">Aucun véhicule ne correspond à ces critères.</div>
            <?php else: ?>
                <div class="v2-table-wrap">
                    <table class="v2-table">
                        <thead>
                            <tr>
                                <th>Véhicule</th>
                                <th>Immatriculation</th>
                                <th>Année</th>
                                <th>Kilométrage</th>
                                <th>Statut</th>
                                <th>Anomalie</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicules as $v):
                                $anomalyCount = $anomaliesCountParVehicule[$v['id']] ?? 0;
                                $hasAnomaly = $anomalyCount > 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="v2-table-vehicle">
                                            <div class="v2-table-vehicle-icon">
                                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><rect x="2" y="10" width="20" height="8" rx="3" stroke="#2540C4" stroke-width="1.6"/><circle cx="7.5" cy="18.5" r="1.5" stroke="#2540C4" stroke-width="1.6"/><circle cx="16.5" cy="18.5" r="1.5" stroke="#2540C4" stroke-width="1.6"/><path d="M5 10L7 5.5H17L19 10" stroke="#2540C4" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                            </div>
                                            <?php echo h($v['marque'] . ' ' . $v['modele']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo h($v['immatriculation']); ?></td>
                                    <td><?php echo h($v['annee'] ?: 'N/A'); ?></td>
                                    <td><?php echo number_format((float)$v['kilometrage'], 0, ',', ' '); ?> km</td>
                                    <td><span class="v2-badge <?php echo $v['statut'] === 'actif' ? 'ok' : 'warn'; ?>"><?php echo h(ucfirst(str_replace('_', ' ', $v['statut']))); ?></span></td>
                                    <td>
                                        <?php if ($hasAnomaly): ?>
                                            <span class="v2-badge bad"><?php echo (int)$anomalyCount; ?> anomalie<?php echo $anomalyCount > 1 ? 's' : ''; ?></span>
                                        <?php else: ?>
                                            <span class="v2-badge ok">Aucune anomalie</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                                            <a href="vehicle_details.php?id=<?php echo (int)$v['id']; ?>" class="v2-table-link">Détails</a>
                                            <button type="button" class="v2-table-link" style="background:none; border:none; cursor:pointer; font-family:inherit; padding:0;" onclick="editVehicle(<?php echo (int)$v['id']; ?>)">Modifier</button>
                                            <button type="button" class="v2-table-link" style="background:none; border:none; cursor:pointer; font-family:inherit; padding:0; color:#E5484D;" onclick="deleteVehicle(<?php echo (int)$v['id']; ?>)">Supprimer</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
            $qs = $_GET;
            $pageUrl = function (int $p) use ($qs) { $qs['page'] = $p; return 'vehicles.php?' . http_build_query($qs); };
            ?>
            <div class="v2-pagination">
                <?php if ($currentPage > 1): ?><a href="<?php echo h($pageUrl($currentPage - 1)); ?>">←</a><?php else: ?><span class="disabled">←</span><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $currentPage): ?><span class="current"><?php echo (int)$p; ?></span><?php else: ?><a href="<?php echo h($pageUrl($p)); ?>"><?php echo (int)$p; ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($currentPage < $totalPages): ?><a href="<?php echo h($pageUrl($currentPage + 1)); ?>">→</a><?php else: ?><span class="disabled">→</span><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ==================== VUE PARTICULIER : CARTES (inchangée) ==================== -->
        <div class="v2-vehicles-grid v2-grid-3" style="margin-top: 22px;">
            <?php foreach ($vehicules as $v):
                $anomalyCount = $anomaliesCountParVehicule[$v['id']] ?? 0;
                $hasAnomaly = $anomalyCount > 0;
                $anomalie = $anomaliesParVehicule[$v['id']] ?? null;
            ?>
                <div class="v2-card v2-vehicle-card <?php echo $hasAnomaly ? 'has-anomaly' : ''; ?>" style="padding:16px;">
                    <div class="v2-vehicle-top">
                        <div class="v2-vehicle-icon" style="background:<?php echo $hasAnomaly ? '#FDEDEE' : '#EEF1FF'; ?>;">
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
                        <div class="v2-vehicle-meta"><?php echo h($v['immatriculation']); ?> · <?php echo h($v['annee'] ?: 'N/A'); ?> · <?php echo number_format((float)$v['kilometrage'], 0, ',', ' '); ?> km<?php echo h($v['couleur'] ? ' · ' . $v['couleur'] : ''); ?></div>
                    </div>
                    <?php if ($hasAnomaly && $anomalie): ?>
                        <div class="v2-vehicle-anomaly">
                            Anomalie constatée — <?php echo h($anomalie['description']); ?> — détectée le <?php echo h(date('d/m', strtotime($anomalie['dateDetection']))); ?>.
                        </div>
                    <?php endif; ?>
                    <div style="display:flex; flex-wrap:wrap; gap:8px 14px; align-items:center; white-space:nowrap;">
                        <a href="vehicle_details.php?id=<?php echo (int)$v['id']; ?>" class="v2-vehicle-link">Voir détails →</a>
                        <button type="button" class="v2-vehicle-link" style="background:none; border:none; cursor:pointer; font-family:inherit; padding:0;" onclick="editVehicle(<?php echo (int)$v['id']; ?>)">Modifier</button>
                        <button type="button" class="v2-vehicle-link" style="background:none; border:none; cursor:pointer; font-family:inherit; padding:0; color:#E5484D;" onclick="deleteVehicle(<?php echo (int)$v['id']; ?>)">Supprimer</button>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($limitReached): ?>
                <a href="abonnement.php" class="v2-add-vehicle-card" style="text-decoration:none; flex-direction:column; gap:4px; text-align:center;">
                    Passer au Premium
                    <span style="font-weight:500; font-size:12.5px; color:#8B90B3;"><?php echo (int)$totalVehiculesCount; ?> / <?php echo (int)$vehicleLimit; ?> véhicule<?php echo $vehicleLimit > 1 ? 's' : ''; ?> — limite de votre formule atteinte</span>
                </a>
            <?php else: ?>
                <div class="v2-add-vehicle-card" id="addFirstVehicleBtn">+ Ajouter un véhicule</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal ajouter/modifier véhicule -->
<div class="v2-modal-overlay" id="vehicleModalOverlay">
    <div class="v2-modal">
        <h3 id="vehicleModalTitle">Ajouter un véhicule</h3>
        <form id="vehicleForm" method="POST" action="vehicles.php">
            <input type="hidden" name="action" id="vehicleAction" value="add">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="v2-form-group">
                <label>Marque *</label>
                <input type="text" name="marque" data-only="letters" required placeholder="Ex. Toyota" style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <div class="v2-form-group">
                <label>Modèle *</label>
                <input type="text" name="modele" data-only="model" maxlength="50" required placeholder="Ex. Corolla" style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <div class="v2-form-group">
                <label>Immatriculation *</label>
                <input type="text" name="immatriculation" data-only="plate" maxlength="15" autocapitalize="characters" required placeholder="Ex. LT 123 AB" style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <div class="v2-form-group">
                <label>Année</label>
                <input type="number" name="annee" data-only="digits" inputmode="numeric" placeholder="Ex. 2018" min="1900" max="<?php echo date('Y'); ?>" style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <div class="v2-form-group">
                <label>Kilométrage</label>
                <input type="number" name="kilometrage" data-only="digits" inputmode="numeric" min="0" placeholder="Ex. 85000" style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <div class="v2-form-group">
                <label>Couleur</label>
                <input type="text" name="couleur" data-only="letters" placeholder="Ex. Gris" style="width:100%; box-sizing:border-box; border:1px solid #DDE0F0; border-radius:10px; padding:10px 12px; font-family:'Manrope', sans-serif; font-size:13.5px;">
            </div>
            <div class="v2-modal-actions">
                <button type="button" class="v2-btn-outline" id="cancelVehicle">Annuler</button>
                <button type="submit" class="v2-btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('vehicleModalOverlay');
    var form = document.getElementById('vehicleForm');
    var title = document.getElementById('vehicleModalTitle');
    var actionField = document.getElementById('vehicleAction');

    function openModal(mode) {
        title.textContent = mode === 'edit' ? 'Modifier le véhicule' : 'Ajouter un véhicule';
        overlay.classList.add('show');
    }
    function closeModal() {
        overlay.classList.remove('show');
        form.reset();
        form.action = 'vehicles.php';
        actionField.value = 'add';
    }

    // Bouton absent quand la limite de véhicules de la formule est atteinte.
    var addBtn = document.getElementById('addVehicleBtn');
    if (addBtn) addBtn.addEventListener('click', function () { openModal('add'); });
    var addFirstBtn = document.getElementById('addFirstVehicleBtn');
    if (addFirstBtn) addFirstBtn.addEventListener('click', function () { openModal('add'); });
    document.getElementById('cancelVehicle').addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });

    window.editVehicle = function (vehicleId) {
        actionField.value = 'edit';
        form.action = 'vehicles.php?action=edit&id=' + vehicleId;
        fetch(SITE_URL + 'ajax/get_vehicle.php?id=' + vehicleId)
            .then(function (r) { return r.json(); })
            .then(function (response) {
                if (response.success) {
                    var v = response.vehicle;
                    form.querySelector('[name="marque"]').value = v.marque;
                    form.querySelector('[name="modele"]').value = v.modele;
                    form.querySelector('[name="immatriculation"]').value = v.immatriculation;
                    form.querySelector('[name="annee"]').value = v.annee;
                    form.querySelector('[name="couleur"]').value = v.couleur;
                    form.querySelector('[name="kilometrage"]').value = v.kilometrage;
                    openModal('edit');
                } else if (typeof showToast === 'function') {
                    showToast('Erreur lors du chargement du véhicule', 'error');
                }
            });
    };

    window.deleteVehicle = function (vehicleId) {
        if (confirm('Êtes-vous sûr de vouloir supprimer ce véhicule ?')) {
            window.location.href = 'vehicles.php?action=delete&id=' + vehicleId;
        }
    };
});
</script>

<?php include '../includes/footer.php'; ?>
