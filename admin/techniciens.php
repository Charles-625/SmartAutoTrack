<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$technicien_id = $_GET['id'] ?? null;
$errors = [];

function technicien_notify_and_log(PDO $conn, $technicien_id, string $nomActivite, string $notifTitre, string $notifMessage): void {
    $stmt = $conn->prepare("SELECT nom, prenom FROM utilisateur WHERE idUtilisateur = ?");
    $stmt->execute([$technicien_id]);
    $t = $stmt->fetch();

    log_activity($conn, $nomActivite, [
        'idUtilisateur' => $_SESSION['user_id'] ?? null,
        'idTechnicien' => (int)$technicien_id,
        'description' => $t ? ($nomActivite . ' : ' . $t['prenom'] . ' ' . $t['nom']) : null,
        'categorie' => 'technicien',
    ]);

    $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', ?, ?)")
        ->execute([$technicien_id, $notifTitre, $notifMessage]);
}

// Les actions ci-dessous modifient des données : elles n'acceptent que
// POST + un jeton CSRF valide (les liens de validation/rejet/désactivation
// sont protégés côté client par une boîte de confirmation JS — qui n'arrête
// pas une requête forgée depuis un site tiers, seulement un clic accidentel).
$postOk = $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '');

// Créer un compte technicien (C.2) : interne, ou technicien de garage
// directement rattaché à un garage choisi dans le formulaire. Créé
// directement VALIDE — c'est l'admin qui, en le créant lui-même, se porte
// garant du compte (contrairement à l'auto-inscription publique d'un
// technicien interne, qui elle reste EN_ATTENTE, cf. auth/register.php).
if ($action === 'create_technicien' && $postOk) {
    $newNom = sanitize($_POST['nom'] ?? '');
    $newPrenom = sanitize($_POST['prenom'] ?? '');
    $newEmail = sanitize($_POST['email'] ?? '');
    $newTelephone = sanitize($_POST['telephone'] ?? '');
    $newSpecialite = sanitize($_POST['specialite'] ?? '');
    $newType = ($_POST['type_technicien'] ?? '') === 'GARAGE' ? 'GARAGE' : 'INTERNE';
    $newGarageId = filter_var($_POST['garage_id'] ?? null, FILTER_VALIDATE_INT);
    $newPassword = trim($_POST['mot_de_passe'] ?? '');
    $newPasswordConfirm = trim($_POST['confirmation'] ?? '');

    if (empty($newNom)) $errors[] = 'Le nom est requis.';
    elseif (!validateLettersOnly($newNom)) $errors[] = 'Le nom ne doit contenir que des lettres.';
    if (empty($newPrenom)) $errors[] = 'Le prénom est requis.';
    elseif (!validateLettersOnly($newPrenom)) $errors[] = 'Le prénom ne doit contenir que des lettres.';
    if (empty($newEmail) || !validateEmail($newEmail)) $errors[] = 'Email invalide.';
    if (empty($newTelephone)) $errors[] = 'Le téléphone est requis.';
    elseif (!validateDigitsOnly($newTelephone)) $errors[] = 'Le téléphone ne doit contenir que des chiffres.';
    if (strlen($newPassword) < PASSWORD_MIN_LENGTH) $errors[] = 'Le mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caractères.';
    if ($newPassword !== $newPasswordConfirm) $errors[] = 'Les mots de passe ne correspondent pas.';

    $attachedGarage = null;
    if ($newType === 'GARAGE') {
        if (!$newGarageId) {
            $errors[] = 'Merci de choisir un garage pour un technicien de garage.';
        } else {
            $stmt = $conn->prepare("SELECT idGarage, nomGarage FROM garage WHERE idGarage = ? AND statutGarage = 'VALIDE'");
            $stmt->execute([$newGarageId]);
            $attachedGarage = $stmt->fetch();
            if (!$attachedGarage) $errors[] = 'Garage introuvable ou non validé.';
        }
    } else {
        $newGarageId = null;
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT idUtilisateur FROM utilisateur WHERE email = ?");
        $stmt->execute([$newEmail]);
        if ($stmt->fetch()) $errors[] = 'Cet email est déjà utilisé.';
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            $conn->prepare("INSERT INTO utilisateur (nom, prenom, email, telephone, motDePasse) VALUES (?, ?, ?, ?, ?)")
                ->execute([$newNom, $newPrenom, $newEmail, $newTelephone, hashPassword($newPassword)]);
            $newUserId = (int)$conn->lastInsertId();

            $conn->prepare("
                INSERT INTO technicien (idTechnicien, typeTechnicien, idGarage, specialite, statutValidation)
                VALUES (?, ?, ?, ?, 'VALIDE')
            ")->execute([$newUserId, $newType, $newGarageId, $newSpecialite ?: null]);
            $conn->commit();

            log_activity($conn, 'Compte technicien créé par l\'administrateur', [
                'idUtilisateur' => $_SESSION['user_id'] ?? null,
                'idTechnicien' => $newUserId,
                'idGarage' => $newGarageId,
                'description' => 'Technicien créé : ' . $newPrenom . ' ' . $newNom . ($attachedGarage ? ' (' . $attachedGarage['nomGarage'] . ')' : ' (interne)'),
                'categorie' => 'technicien',
            ]);
            $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', 'Bienvenue sur SmartAutoTrack', ?)")
                ->execute([$newUserId, 'Votre compte technicien a été créé par un administrateur. Vous pouvez vous connecter avec les identifiants qui vous ont été transmis.']);

            header("Location: techniciens.php?success=created");
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Erreur lors de la création du technicien.';
        }
    }
}

// Rattacher un technicien existant à un garage, ou le détacher (retour en
// interne) : couvre le cas où un technicien a été créé/inscrit sans garage,
// ou doit être transféré vers un autre garage.
if ($action === 'attach_garage' && $postOk) {
    $attachTechnicienId = filter_var($_POST['technicien_id'] ?? null, FILTER_VALIDATE_INT);
    $targetGarageRaw = $_POST['garage_id'] ?? '';
    $targetGarageId = $targetGarageRaw === '' ? null : filter_var($targetGarageRaw, FILTER_VALIDATE_INT);

    if (!$attachTechnicienId) {
        $errors[] = 'Technicien invalide.';
    } else {
        $stmt = $conn->prepare("SELECT nom, prenom FROM utilisateur WHERE idUtilisateur = ?");
        $stmt->execute([$attachTechnicienId]);
        $attachTech = $stmt->fetch();
        $techLabel = $attachTech ? ($attachTech['prenom'] . ' ' . $attachTech['nom']) : ('technicien #' . $attachTechnicienId);

        try {
            if ($targetGarageId) {
                $stmt = $conn->prepare("SELECT nomGarage FROM garage WHERE idGarage = ? AND statutGarage = 'VALIDE'");
                $stmt->execute([$targetGarageId]);
                $targetGarage = $stmt->fetch();
                if (!$targetGarage) {
                    $errors[] = 'Garage introuvable ou non validé.';
                } else {
                    $conn->prepare("UPDATE technicien SET typeTechnicien = 'GARAGE', idGarage = ? WHERE idTechnicien = ?")
                        ->execute([$targetGarageId, $attachTechnicienId]);

                    log_activity($conn, 'Technicien rattaché à un garage par l\'administrateur', [
                        'idUtilisateur' => $_SESSION['user_id'] ?? null,
                        'idTechnicien' => $attachTechnicienId,
                        'idGarage' => $targetGarageId,
                        'description' => $techLabel . ' rattaché à ' . $targetGarage['nomGarage'],
                        'categorie' => 'technicien',
                    ]);
                    $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', 'Rattachement à un garage', ?)")
                        ->execute([$attachTechnicienId, 'Votre compte a été rattaché au garage "' . $targetGarage['nomGarage'] . '" par un administrateur.']);

                    header("Location: techniciens.php?success=attached");
                    exit;
                }
            } else {
                $conn->prepare("UPDATE technicien SET typeTechnicien = 'INTERNE', idGarage = NULL WHERE idTechnicien = ?")
                    ->execute([$attachTechnicienId]);

                log_activity($conn, 'Technicien détaché de son garage par l\'administrateur', [
                    'idUtilisateur' => $_SESSION['user_id'] ?? null,
                    'idTechnicien' => $attachTechnicienId,
                    'description' => $techLabel . ' repasse en technicien interne',
                    'categorie' => 'technicien',
                ]);
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', 'Détachement du garage', ?)")
                    ->execute([$attachTechnicienId, 'Votre compte a été détaché de son garage par un administrateur et repasse en technicien interne.']);

                header("Location: techniciens.php?success=detached");
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors du rattachement du technicien.';
        }
    }
}

// Valider un technicien
if ($action === 'validate' && $technicien_id && $postOk) {
    try {
        $conn->prepare("UPDATE technicien SET statutValidation = 'VALIDE' WHERE idTechnicien = ?")->execute([$technicien_id]);
        technicien_notify_and_log($conn, $technicien_id, 'Compte technicien validé', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.');
        header("Location: techniciens.php?success=validated");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la validation du technicien.';
    }
}

// Rejeter un technicien
if ($action === 'reject' && $technicien_id && $postOk) {
    try {
        $conn->prepare("UPDATE technicien SET statutValidation = 'REJETE' WHERE idTechnicien = ?")->execute([$technicien_id]);
        technicien_notify_and_log($conn, $technicien_id, 'Compte technicien rejeté', 'Compte rejeté', 'Votre demande de compte technicien a été rejetée.');
        header("Location: techniciens.php?success=rejected");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors du rejet du technicien.';
    }
}

// Désactiver / suspendre un technicien déjà validé. Ne touche à AUCUNE
// donnée historique (interventions, réparations, anomalies, journal restent
// intactes) : seul le statut change, ce qui bloque la connexion
// (isAccountUsable()) et donc l'attribution de nouvelles tâches. Ses
// interventions en cours ne sont ni supprimées ni réaffectées automatiquement.
if ($action === 'deactivate' && $technicien_id && $postOk) {
    try {
        $conn->prepare("UPDATE technicien SET statutValidation = 'SUSPENDU' WHERE idTechnicien = ? AND statutValidation = 'VALIDE'")->execute([$technicien_id]);
        technicien_notify_and_log($conn, $technicien_id, 'Technicien désactivé', 'Compte désactivé', 'Votre compte technicien a été désactivé par un administrateur. Contactez le support pour plus d\'informations.');
        header("Location: techniciens.php?success=deactivated");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la désactivation du technicien.';
    }
}

// Réactiver un technicien désactivé
if ($action === 'reactivate' && $technicien_id && $postOk) {
    try {
        $conn->prepare("UPDATE technicien SET statutValidation = 'VALIDE' WHERE idTechnicien = ? AND statutValidation = 'SUSPENDU'")->execute([$technicien_id]);
        technicien_notify_and_log($conn, $technicien_id, 'Technicien réactivé', 'Compte réactivé', 'Votre compte technicien a été réactivé par un administrateur.');
        header("Location: techniciens.php?success=reactivated");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la réactivation du technicien.';
    }
}

// Filtres
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$statusToDb = ['actif' => 'VALIDE', 'pending' => 'EN_ATTENTE', 'rejete' => 'REJETE', 'suspendu' => 'SUSPENDU'];
$where = [];
$params = [];
if ($status_filter && isset($statusToDb[$status_filter])) { $where[] = 't.statutValidation = ?'; $params[] = $statusToDb[$status_filter]; }
if ($type_filter === 'interne') { $where[] = "t.typeTechnicien = 'INTERNE'"; }
elseif ($type_filter === 'garage') { $where[] = "t.typeTechnicien = 'GARAGE'"; }
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, u.telephone, u.dateCreation AS created_at,
           t.typeTechnicien, t.idGarage, t.specialite, t.statutValidation, g.nomGarage,
           SUM(CASE WHEN i.statut IN ('PLANIFIEE','EN_COURS') THEN 1 ELSE 0 END) AS interventions_actives,
           COUNT(i.idIntervention) AS interventions_total
    FROM utilisateur u
    JOIN technicien t ON t.idTechnicien = u.idUtilisateur
    LEFT JOIN garage g ON g.idGarage = t.idGarage
    LEFT JOIN intervention i ON i.idTechnicien = t.idTechnicien
    WHERE $whereSql
    GROUP BY u.idUtilisateur
    ORDER BY u.dateCreation DESC
");
$stmt->execute($params);
$techniciens = $stmt->fetchAll();

$stats = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN statutValidation = 'VALIDE' THEN 1 ELSE 0 END) AS actifs,
           SUM(CASE WHEN statutValidation = 'EN_ATTENTE' THEN 1 ELSE 0 END) AS en_attente,
           SUM(CASE WHEN statutValidation = 'SUSPENDU' THEN 1 ELSE 0 END) AS suspendus
    FROM technicien
")->fetch();

// Garages actifs pouvant recevoir un technicien (création ou rattachement).
$garagesList = $conn->query("SELECT idGarage, nomGarage FROM garage WHERE statutGarage = 'VALIDE' ORDER BY nomGarage")->fetchAll();

$pageTitle = 'Techniciens';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'techniciens'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="av2-alert success">
                <?php
                $successMsg = ['created' => 'Compte technicien créé avec succès.', 'attached' => 'Technicien rattaché au garage.', 'detached' => 'Technicien détaché, repassé en interne.', 'validated' => 'Technicien validé avec succès.', 'rejected' => 'Technicien rejeté.', 'deactivated' => 'Technicien désactivé.', 'reactivated' => 'Technicien réactivé.'];
                echo h($successMsg[$_GET['success']] ?? 'Action effectuée.');
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="av2-alert error"><?php echo h($err); ?></div><?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Techniciens</h1>
                <p class="av2-sub">Techniciens internes et techniciens de garage — vision globale, tous garages confondus.</p>
            </div>
            <button type="button" class="av2-btn-primary" id="openNewTechnicien">+ Nouveau technicien</button>
        </div>

        <div class="av2-stats">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#C8871A" stroke-width="1.7" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total techniciens</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['actifs']; ?></div><div class="av2-stat-label">Actifs</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#1E7DBF" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['en_attente']; ?></div><div class="av2-stat-label">En attente</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#6D74A0" stroke-width="1.8"/><path d="M8 8L16 16M16 8L8 16" stroke="#6D74A0" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['suspendus']; ?></div><div class="av2-stat-label">Suspendus</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <select name="type" onchange="this.form.submit()">
                <option value="">Tous les types</option>
                <option value="interne" <?php echo $type_filter === 'interne' ? 'selected' : ''; ?>>Interne</option>
                <option value="garage" <?php echo $type_filter === 'garage' ? 'selected' : ''; ?>>Garage</option>
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="actif" <?php echo $status_filter === 'actif' ? 'selected' : ''; ?>>Actif</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>En attente</option>
                <option value="suspendu" <?php echo $status_filter === 'suspendu' ? 'selected' : ''; ?>>Suspendu</option>
                <option value="rejete" <?php echo $status_filter === 'rejete' ? 'selected' : ''; ?>>Rejeté</option>
            </select>
            <?php if ($status_filter || $type_filter): ?><a href="techniciens.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($techniciens)): ?>
                <div class="av2-empty">Aucun technicien ne correspond aux critères sélectionnés.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Technicien</th><th>Type</th><th>Garage</th><th>Spécialité</th><th>Statut</th><th>Interventions</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($techniciens as $t): $isGarageType = $t['typeTechnicien'] === 'GARAGE'; ?>
                                <tr>
                                    <td>
                                        <div class="av2-table-entity">
                                            <div class="av2-table-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#1E7DBF" stroke-width="1.6" stroke-linejoin="round"/></svg></div>
                                            <?php echo h($t['prenom'] . ' ' . $t['nom']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($t['email']); ?></div>
                                    </td>
                                    <td><span class="av2-badge <?php echo $isGarageType ? 'info' : 'neutral'; ?>"><?php echo $isGarageType ? 'Garage' : 'Interne'; ?></span></td>
                                    <td>
                                        <form method="POST" action="techniciens.php?action=attach_garage" style="display:flex; gap:6px; align-items:center;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="technicien_id" value="<?php echo (int)$t['id']; ?>">
                                            <select name="garage_id" style="font-size:12.5px; padding:4px 6px; border-radius:6px; border:1px solid #DDE0F0;" onchange="this.closest('form').querySelector('.av2-attach-confirm').style.display='inline-block';">
                                                <option value="">— Interne —</option>
                                                <?php foreach ($garagesList as $gOpt): ?>
                                                    <option value="<?php echo (int)$gOpt['idGarage']; ?>" <?php echo ((int)$t['idGarage'] === (int)$gOpt['idGarage']) ? 'selected' : ''; ?>><?php echo h($gOpt['nomGarage']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="av2-table-link av2-link-btn av2-attach-confirm btn-confirm" data-confirm="Confirmer ce rattachement de garage ?" style="display:none;">OK</button>
                                        </form>
                                    </td>
                                    <td><?php echo h($t['specialite'] ?: '—'); ?></td>
                                    <td>
                                        <span class="av2-badge <?php echo h(av2_status_badge($t['statutValidation'])); ?>"><?php echo h(av2_status_label($t['statutValidation'])); ?></span>
                                        <?php if ($t['statutValidation'] === 'SUSPENDU' && (int)$t['interventions_actives'] > 0): ?>
                                            <div style="font-size:11px; color:#C8871A; margin-top:3px;">⚠ <?php echo (int)$t['interventions_actives']; ?> intervention(s) active(s) à réaffecter</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="av2-badge neutral"><?php echo (int)$t['interventions_actives']; ?> active(s)</span> <span style="font-size:11.5px; color:#8B90B3;">· <?php echo (int)$t['interventions_total']; ?> au total</span></td>
                                    <td>
                                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <?php if ($t['statutValidation'] === 'EN_ATTENTE'): ?>
                                                <form method="POST" action="techniciens.php?action=validate&id=<?php echo (int)$t['id']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Valider ce technicien ?">Valider</button>
                                                </form>
                                                <form method="POST" action="techniciens.php?action=reject&id=<?php echo (int)$t['id']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Rejeter ce technicien ?" style="color:#E5484D;">Rejeter</button>
                                                </form>
                                            <?php elseif ($t['statutValidation'] === 'VALIDE'): ?>
                                                <form method="POST" action="techniciens.php?action=deactivate&id=<?php echo (int)$t['id']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Désactiver ce technicien ? Il ne pourra plus se connecter ni recevoir de nouvelles tâches. Son historique (interventions, réparations, anomalies) est conservé." style="color:#C8871A;">Désactiver</button>
                                                </form>
                                            <?php elseif ($t['statutValidation'] === 'SUSPENDU'): ?>
                                                <form method="POST" action="techniciens.php?action=reactivate&id=<?php echo (int)$t['id']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Réactiver ce technicien ?">Réactiver</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal nouveau technicien -->
<div class="av2-modal-overlay" id="newTechnicienOverlay">
    <div class="av2-modal">
        <h3>Nouveau technicien</h3>
        <p class="av2-modal-sub">Compte créé directement actif — interne, ou rattaché à un garage existant.</p>
        <?php if (!empty($errors)): ?><div class="av2-alert error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div><?php endif; ?>
        <form method="POST" action="techniciens.php?action=create_technicien">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="av2-form-group"><label>Nom</label><input type="text" name="nom" required></div>
            <div class="av2-form-group"><label>Prénom</label><input type="text" name="prenom" required></div>
            <div class="av2-form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="av2-form-group"><label>Téléphone</label><input type="tel" name="telephone" required></div>
            <div class="av2-form-group"><label>Spécialité</label><input type="text" name="specialite" placeholder="Ex. : Freinage, Moteur & diagnostic... (optionnel)"></div>
            <div class="av2-form-group">
                <label>Type</label>
                <select name="type_technicien" id="newTechType">
                    <option value="INTERNE">Interne (SmartAutoTrack)</option>
                    <option value="GARAGE">Technicien de garage</option>
                </select>
            </div>
            <div class="av2-form-group" id="newTechGarageGroup" style="display:none;">
                <label>Garage de rattachement</label>
                <select name="garage_id">
                    <option value="">Sélectionner un garage</option>
                    <?php foreach ($garagesList as $gOpt): ?>
                        <option value="<?php echo (int)$gOpt['idGarage']; ?>"><?php echo h($gOpt['nomGarage']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="av2-form-group"><label>Mot de passe</label><input type="password" name="mot_de_passe" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"></div>
            <div class="av2-form-group"><label>Confirmation</label><input type="password" name="confirmation" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"></div>
            <div class="av2-modal-actions">
                <button type="button" class="av2-btn-outline" id="closeNewTechnicien">Annuler</button>
                <button type="submit" class="av2-btn-primary">Créer le technicien</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('newTechnicienOverlay');
    document.getElementById('openNewTechnicien').addEventListener('click', function () { overlay.classList.add('show'); });
    document.getElementById('closeNewTechnicien').addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });
    <?php if (!empty($errors)): ?>overlay.classList.add('show');<?php endif; ?>

    var typeSelect = document.getElementById('newTechType');
    var garageGroup = document.getElementById('newTechGarageGroup');
    typeSelect.addEventListener('change', function () {
        garageGroup.style.display = (typeSelect.value === 'GARAGE') ? 'block' : 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
