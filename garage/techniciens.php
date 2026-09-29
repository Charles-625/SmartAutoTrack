<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);
$errors = [];

// Les 3 actions ci-dessous modifient des données : elles n'acceptent que
// POST + un jeton CSRF valide. Chaque UPDATE est en plus borné par
// "AND idGarage = ?" (jamais par le seul id transmis dans le formulaire) —
// un garage ne peut donc jamais créer, suspendre ou réactiver un technicien
// en dehors de sa propre équipe, même en forgeant la requête.
$action = $_GET['action'] ?? '';
$postOk = $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '');

// Ajouter un technicien à son équipe (C.5) : créé directement VALIDE — c'est
// le garage lui-même, déjà partenaire actif, qui se porte garant de son
// propre employé (même principe que la création d'un technicien par l'admin).
if ($action === 'create' && $postOk) {
    $newNom = sanitize($_POST['nom'] ?? '');
    $newPrenom = sanitize($_POST['prenom'] ?? '');
    $newEmail = sanitize($_POST['email'] ?? '');
    $newTelephone = sanitize($_POST['telephone'] ?? '');
    $newSpecialite = sanitize($_POST['specialite'] ?? '');
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
                VALUES (?, 'GARAGE', ?, ?, 'VALIDE')
            ")->execute([$newUserId, $garageId, $newSpecialite ?: null]);
            $conn->commit();

            log_activity($conn, 'Technicien ajouté par le garage', [
                'idUtilisateur' => $_SESSION['user_id'] ?? null,
                'idTechnicien' => $newUserId,
                'idGarage' => $garageId,
                'description' => 'Technicien ajouté : ' . $newPrenom . ' ' . $newNom,
                'categorie' => 'technicien',
            ]);
            $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', 'Bienvenue sur SmartAutoTrack', ?)")
                ->execute([$newUserId, 'Votre compte technicien a été créé par votre garage. Vous pouvez vous connecter avec les identifiants qui vous ont été transmis.']);

            header("Location: techniciens.php?success=created");
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Erreur lors de la création du technicien.';
        }
    }
}

// Suspendre / réactiver un technicien de son équipe : ne touche à aucune
// donnée historique (interventions, réparations, anomalies, journal restent
// intacts), bloque seulement la connexion (isAccountUsable()) et donc
// l'attribution de nouvelles tâches. Réversible à tout moment.
if (($action === 'suspend' || $action === 'reactivate') && $postOk) {
    $targetId = filter_var($_POST['technicien_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$targetId) {
        $errors[] = 'Technicien invalide.';
    } else {
        $fromStatut = $action === 'suspend' ? 'VALIDE' : 'SUSPENDU';
        $toStatut = $action === 'suspend' ? 'SUSPENDU' : 'VALIDE';
        try {
            $stmt = $conn->prepare("SELECT nom, prenom FROM utilisateur WHERE idUtilisateur = ?");
            $stmt->execute([$targetId]);
            $tRow = $stmt->fetch();
            $tLabel = $tRow ? ($tRow['prenom'] . ' ' . $tRow['nom']) : ('technicien #' . $targetId);

            $upd = $conn->prepare("UPDATE technicien SET statutValidation = ? WHERE idTechnicien = ? AND idGarage = ? AND statutValidation = ?");
            $upd->execute([$toStatut, $targetId, $garageId, $fromStatut]);

            if ($upd->rowCount() === 1) {
                log_activity($conn, $action === 'suspend' ? 'Technicien suspendu par le garage' : 'Technicien réactivé par le garage', [
                    'idUtilisateur' => $_SESSION['user_id'] ?? null,
                    'idTechnicien' => $targetId,
                    'idGarage' => $garageId,
                    'description' => $tLabel . ($action === 'suspend' ? ' suspendu' : ' réactivé'),
                    'categorie' => 'technicien',
                ]);
                $notifMsg = $action === 'suspend'
                    ? 'Votre compte technicien a été suspendu par votre garage. Contactez votre garage pour plus d\'informations.'
                    : 'Votre compte technicien a été réactivé par votre garage.';
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', ?, ?)")
                    ->execute([$targetId, $action === 'suspend' ? 'Compte suspendu' : 'Compte réactivé', $notifMsg]);

                header("Location: techniciens.php?success=" . ($action === 'suspend' ? 'suspended' : 'reactivated'));
                exit;
            } else {
                $errors[] = 'Ce technicien ne fait pas partie de votre équipe, ou son statut a déjà changé.';
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la mise à jour du technicien.';
        }
    }
}

// Techniciens de CE garage uniquement (jamais ceux d'un autre garage ni les
// techniciens internes SmartAutoTrack).
$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, u.email, u.telephone,
           t.specialite, t.statutValidation, t.competences, t.experience,
           (SELECT COUNT(*) FROM intervention i WHERE i.idTechnicien = u.idUtilisateur AND i.statut IN ('PLANIFIEE', 'EN_COURS')) AS taches_actives,
           (SELECT COUNT(*) FROM intervention i WHERE i.idTechnicien = u.idUtilisateur AND i.statut = 'TERMINEE') AS taches_terminees
    FROM technicien t
    JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
    WHERE t.idGarage = ?
    ORDER BY u.nom, u.prenom
");
$stmt->execute([$garageId]);
$techniciens = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$statutLabels = ['VALIDE' => ['Validé', 'ok'], 'EN_ATTENTE' => ['En attente de validation', 'warn'], 'REJETE' => ['Rejeté', 'bad'], 'SUSPENDU' => ['Suspendu', 'neutral']];

$pageTitle = 'Mes techniciens';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'techniciens'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">
        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Mes techniciens</h1>
                <p class="gv2-sub"><?php echo count($techniciens); ?> technicien<?php echo count($techniciens) > 1 ? 's' : ''; ?> rattaché<?php echo count($techniciens) > 1 ? 's' : ''; ?> à votre garage.</p>
            </div>
            <button type="button" class="gv2-btn-primary" id="openNewTechnicien">+ Nouveau technicien</button>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="gv2-alert success">
                <?php
                $successMsg = ['created' => 'Technicien ajouté à votre équipe avec succès.', 'suspended' => 'Technicien suspendu.', 'reactivated' => 'Technicien réactivé.'];
                echo h($successMsg[$_GET['success']] ?? 'Action effectuée.');
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="gv2-alert error"><?php echo h($err); ?></div><?php endforeach; ?>

        <?php if (empty($techniciens)): ?>
            <div class="gv2-card" style="padding:24px;">
                <div class="gv2-empty">Aucun technicien pour l'instant. Ajoutez le premier membre de votre équipe avec le bouton "+ Nouveau technicien" ci-dessus.</div>
            </div>
        <?php else: ?>
            <div class="gv2-card" style="padding:8px;">
                <div class="gv2-table-wrap">
                    <table class="gv2-table">
                        <thead>
                            <tr><th>Nom</th><th>Spécialité</th><th>Statut</th><th>Tâches en cours</th><th>Tâches terminées</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($techniciens as $t): $st = $statutLabels[$t['statutValidation']] ?? ['—', 'neutral']; ?>
                                <tr>
                                    <td>
                                        <div class="gv2-table-entity">
                                            <div class="gv2-table-icon">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.4" stroke="#1E7DBF" stroke-width="1.7"/><path d="M4.5 20C5.7 16.2 8.5 14.8 12 14.8C15.5 14.8 18.3 16.2 19.5 20" stroke="#1E7DBF" stroke-width="1.7" stroke-linecap="round"/></svg>
                                            </div>
                                            <?php echo h($t['prenom'] . ' ' . $t['nom']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8AA0A3; margin-top:2px;"><?php echo h($t['email']); ?></div>
                                    </td>
                                    <td><?php echo h($t['specialite'] ?: '—'); ?></td>
                                    <td><span class="gv2-badge <?php echo h($st[1]); ?>"><?php echo h($st[0]); ?></span></td>
                                    <td><?php echo (int)$t['taches_actives']; ?></td>
                                    <td><?php echo (int)$t['taches_terminees']; ?></td>
                                    <td>
                                        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                                            <a href="interventions.php?technicien=<?php echo (int)$t['id']; ?>" class="gv2-table-link">Voir tâches</a>
                                            <?php if ($t['statutValidation'] === 'VALIDE'): ?>
                                                <a href="demandes.php" class="gv2-table-link">Assigner</a>
                                                <form method="POST" action="techniciens.php?action=suspend" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="technicien_id" value="<?php echo (int)$t['id']; ?>">
                                                    <button type="submit" class="gv2-table-link gv2-link-btn btn-confirm" data-confirm="Suspendre ce technicien ? Il ne pourra plus se connecter ni recevoir de nouvelles tâches. Son historique est conservé." style="color:#C8871A;">Suspendre</button>
                                                </form>
                                            <?php elseif ($t['statutValidation'] === 'SUSPENDU'): ?>
                                                <form method="POST" action="techniciens.php?action=reactivate" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="technicien_id" value="<?php echo (int)$t['id']; ?>">
                                                    <button type="submit" class="gv2-table-link gv2-link-btn btn-confirm" data-confirm="Réactiver ce technicien ?">Réactiver</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal nouveau technicien -->
<div class="gv2-modal-overlay" id="newTechnicienOverlay">
    <div class="gv2-modal">
        <h3>Nouveau technicien</h3>
        <p class="gv2-modal-sub">Compte créé directement actif et rattaché à votre garage.</p>
        <?php if (!empty($errors)): ?><div class="gv2-alert error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div><?php endif; ?>
        <form method="POST" action="techniciens.php?action=create">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="gv2-form-row">
                <div class="gv2-form-group"><label>Nom</label><input type="text" name="nom" required></div>
                <div class="gv2-form-group"><label>Prénom</label><input type="text" name="prenom" required></div>
            </div>
            <div class="gv2-form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="gv2-form-group"><label>Téléphone</label><input type="tel" name="telephone" required></div>
            <div class="gv2-form-group"><label>Spécialité</label><input type="text" name="specialite" placeholder="Ex. : Freinage, Moteur & diagnostic... (optionnel)"></div>
            <div class="gv2-form-row">
                <div class="gv2-form-group"><label>Mot de passe</label><input type="password" name="mot_de_passe" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"></div>
                <div class="gv2-form-group"><label>Confirmation</label><input type="password" name="confirmation" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"></div>
            </div>
            <div class="gv2-modal-actions">
                <button type="button" class="gv2-btn-outline" id="closeNewTechnicien">Annuler</button>
                <button type="submit" class="gv2-btn-primary">Ajouter le technicien</button>
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
});
</script>

<?php include '../includes/footer.php'; ?>
