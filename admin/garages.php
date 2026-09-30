<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Liste et gestion des garages partenaires (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * Actions POST (jeton CSRF requis), passées par ?action=… :
 *   - create_garage : crée le compte utilisateur + le garage, directement VALIDE ;
 *   - validate / reject : passe le garage à VALIDE / REJETE (?id=N) ; prévu
 *     pour les inscriptions EN_ATTENTE, mais la requête ne vérifie pas le
 *     statut actuel ;
 *   - suspend / reactivate : bascule VALIDE <-> SUSPENDU (?id=N).
 * Filtre GET `status` (actif|pending|rejete|suspendu).
 *
 * Tables : garage, utilisateur (écriture), technicien, intervention
 * (compteurs), notifications, journal d'activité.
 * Liens : garage_detail.php, config/config.php (validations),
 * includes/password_policy.php (passwordPolicyError).
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

// Le partenariat est conclu hors plateforme : il n'existe donc aucune action
// permettant à un garage de "demander" un partenariat depuis l'application —
// c'est l'admin qui crée le compte garage ci-dessous une fois l'accord conclu,
// puis peut le valider/rejeter/suspendre.
$action = $_GET['action'] ?? '';
$garage_id = $_GET['id'] ?? null;
$errors = [];

/**
 * Journalise une décision de l'admin sur un garage et prévient son compte.
 *
 * @param PDO        $conn         Connexion à la base.
 * @param int|string $garage_id    Identifiant du garage concerné.
 * @param string     $nomActivite  Libellé de l'activité pour le journal.
 * @param string     $notifTitre   Titre de la notification envoyée au garage.
 * @param string     $notifMessage Texte de la notification.
 * @return void Écrit dans le journal d'activité et, si le garage a un compte
 *              utilisateur, insère une ligne dans notifications.
 */
function garage_notify_and_log(PDO $conn, $garage_id, string $nomActivite, string $notifTitre, string $notifMessage): void {
    $stmt = $conn->prepare("SELECT idUtilisateur, nomGarage FROM garage WHERE idGarage = ?");
    $stmt->execute([$garage_id]);
    $g = $stmt->fetch();

    log_activity($conn, $nomActivite, [
        'idUtilisateur' => $_SESSION['user_id'] ?? null,
        'idGarage' => (int)$garage_id,
        'description' => $g ? ($nomActivite . ' : ' . $g['nomGarage']) : null,
        'categorie' => 'garage',
    ]);

    if ($g && $g['idUtilisateur']) {
        $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', ?, ?)")
            ->execute([$g['idUtilisateur'], $notifTitre, $notifMessage]);
    }
}

// Toutes les actions ci-dessous modifient des données : elles n'acceptent que
// POST + un jeton CSRF valide (les liens de validation/rejet/suspension sont
// protégés côté client par une boîte de confirmation JS — qui n'arrête pas une
// requête forgée depuis un site tiers, seulement un clic accidentel).
$postOk = $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '');

// Créer un compte garage (C.4) : le partenariat se conclut hors plateforme —
// cette action est le point d'entrée manquant qui matérialise cet accord.
// Le compte est créé directement VALIDE (pas de second passage par la file de
// validation) car c'est l'admin lui-même qui, en le créant, atteste que le
// partenariat est conclu ; le mot de passe est choisi par l'admin puis à
// transmettre au garage par ses propres moyens (aucun envoi d'email ici).
if ($action === 'create_garage' && $postOk) {
    $newNomGarage = sanitize($_POST['nom_garage'] ?? '');
    $newAdresse = sanitize($_POST['adresse'] ?? '');
    $newNom = sanitize($_POST['nom'] ?? '');
    $newPrenom = sanitize($_POST['prenom'] ?? '');
    $newEmail = sanitize($_POST['email'] ?? '');
    $newTelephone = sanitize($_POST['telephone'] ?? '');
    $newPassword = trim($_POST['mot_de_passe'] ?? '');
    $newPasswordConfirm = trim($_POST['confirmation'] ?? '');

    if (empty($newNomGarage)) $errors[] = 'Le nom du garage est requis.';
    if (empty($newNom)) $errors[] = 'Le nom du contact est requis.';
    elseif (!validateLettersOnly($newNom)) $errors[] = 'Le nom du contact ne doit contenir que des lettres.';
    if (empty($newPrenom)) $errors[] = 'Le prénom du contact est requis.';
    elseif (!validateLettersOnly($newPrenom)) $errors[] = 'Le prénom du contact ne doit contenir que des lettres.';
    if (empty($newEmail) || !validateEmail($newEmail)) $errors[] = 'Email invalide.';
    if (empty($newTelephone)) $errors[] = 'Le téléphone est requis.';
    elseif (!validateDigitsOnly($newTelephone)) $errors[] = 'Le téléphone ne doit contenir que des chiffres.';
    if ($pwError = passwordPolicyError($newPassword)) $errors[] = $pwError;
    if ($newPassword !== $newPasswordConfirm) $errors[] = 'Les mots de passe ne correspondent pas.';

    if (empty($errors)) {
        // Unicité de l'email vérifiée avant la transaction, pour un message clair.
        $stmt = $conn->prepare("SELECT idUtilisateur FROM utilisateur WHERE email = ?");
        $stmt->execute([$newEmail]);
        if ($stmt->fetch()) $errors[] = 'Cet email est déjà utilisé.';
    }

    if (empty($errors)) {
        try {
            // Transaction : l'utilisateur et le garage sont créés ensemble ou pas du tout.
            $conn->beginTransaction();
            $conn->prepare("INSERT INTO utilisateur (nom, prenom, email, telephone, motDePasse) VALUES (?, ?, ?, ?, ?)")
                ->execute([$newNom, $newPrenom, $newEmail, $newTelephone, hashPassword($newPassword)]);
            $newUserId = (int)$conn->lastInsertId();

            $conn->prepare("INSERT INTO garage (idUtilisateur, nomGarage, adresse, statutGarage) VALUES (?, ?, ?, 'VALIDE')")
                ->execute([$newUserId, $newNomGarage, $newAdresse ?: null]);
            $newGarageId = (int)$conn->lastInsertId();
            $conn->commit();

            log_activity($conn, 'Compte garage créé par l\'administrateur', [
                'idUtilisateur' => $_SESSION['user_id'] ?? null,
                'idGarage' => $newGarageId,
                'description' => 'Garage créé : ' . $newNomGarage,
                'categorie' => 'garage',
            ]);
            $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', 'Bienvenue sur SmartAutoTrack', ?)")
                ->execute([$newUserId, 'Votre compte garage "' . $newNomGarage . '" a été créé par un administrateur. Vous pouvez vous connecter avec les identifiants qui vous ont été transmis.']);

            header("Location: garages.php?success=created");
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Erreur lors de la création du garage.';
        }
    }
}

// Action : valider une inscription de garage.
if ($action === 'validate' && $garage_id && $postOk) {
    try {
        $conn->prepare("UPDATE garage SET statutGarage = 'VALIDE' WHERE idGarage = ?")->execute([$garage_id]);
        garage_notify_and_log($conn, $garage_id, 'Garage validé', 'Garage validé', 'Votre garage a été validé par un administrateur. Vous pouvez désormais recevoir des demandes d\'intervention.');
        header("Location: garages.php?success=validated");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la validation du garage.';
    }
}

// Action : rejeter une inscription de garage.
if ($action === 'reject' && $garage_id && $postOk) {
    try {
        $conn->prepare("UPDATE garage SET statutGarage = 'REJETE' WHERE idGarage = ?")->execute([$garage_id]);
        garage_notify_and_log($conn, $garage_id, 'Garage rejeté', 'Garage rejeté', 'Votre demande d\'inscription en tant que garage partenaire a été rejetée.');
        header("Location: garages.php?success=rejected");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors du rejet du garage.';
    }
}

// Suspendre un garage déjà validé : n'efface rien (techniciens, interventions,
// réparations, anomalies, journal restent intacts) — bloque seulement la
// connexion (isAccountUsable() n'autorise que 'VALIDE') et l'arrivée de
// nouvelles demandes tant qu'il n'est pas réactivé.
if ($action === 'suspend' && $garage_id && $postOk) {
    try {
        $conn->prepare("UPDATE garage SET statutGarage = 'SUSPENDU' WHERE idGarage = ? AND statutGarage = 'VALIDE'")->execute([$garage_id]);
        garage_notify_and_log($conn, $garage_id, 'Garage suspendu', 'Garage suspendu', 'Votre garage a été suspendu par un administrateur. Contactez le support pour plus d\'informations.');
        header("Location: garages.php?success=suspended");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la suspension du garage.';
    }
}

// Action : réactiver un garage suspendu (la condition SUSPENDU empêche de
// valider par ce biais un garage rejeté ou en attente).
if ($action === 'reactivate' && $garage_id && $postOk) {
    try {
        $conn->prepare("UPDATE garage SET statutGarage = 'VALIDE' WHERE idGarage = ? AND statutGarage = 'SUSPENDU'")->execute([$garage_id]);
        garage_notify_and_log($conn, $garage_id, 'Garage réactivé', 'Garage réactivé', 'Votre garage a été réactivé par un administrateur. Vous pouvez de nouveau recevoir des demandes d\'intervention.');
        header("Location: garages.php?success=reactivated");
        exit;
    } catch (Exception $e) {
        $errors[] = 'Erreur lors de la réactivation du garage.';
    }
}

// Filtres
$status_filter = $_GET['status'] ?? '';
// Correspondance entre les valeurs du filtre dans l'URL et l'ENUM statutGarage.
$statusToDb = ['actif' => 'VALIDE', 'pending' => 'EN_ATTENTE', 'rejete' => 'REJETE', 'suspendu' => 'SUSPENDU'];
$where = [];
$params = [];
if ($status_filter && isset($statusToDb[$status_filter])) {
    $where[] = 'g.statutGarage = ?';
    $params[] = $statusToDb[$status_filter];
}
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $conn->prepare("
    SELECT g.idGarage, g.nomGarage, g.adresse, g.statutGarage,
           u.idUtilisateur, u.nom, u.prenom, u.email, u.dateCreation AS created_at,
           (SELECT COUNT(*) FROM technicien t WHERE t.idGarage = g.idGarage) AS techniciens_count,
           (SELECT COUNT(*) FROM intervention i WHERE i.idGarage = g.idGarage) AS interventions_count
    FROM garage g
    LEFT JOIN utilisateur u ON u.idUtilisateur = g.idUtilisateur
    WHERE $whereSql
    ORDER BY g.idGarage DESC
");
$stmt->execute($params);
$garages = $stmt->fetchAll();

$stats = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN statutGarage = 'VALIDE' THEN 1 ELSE 0 END) AS actifs,
           SUM(CASE WHEN statutGarage = 'EN_ATTENTE' THEN 1 ELSE 0 END) AS en_attente,
           SUM(CASE WHEN statutGarage = 'SUSPENDU' THEN 1 ELSE 0 END) AS suspendus
    FROM garage
")->fetch();

$pageTitle = 'Garages';
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'garages'; $garagesEnAttenteBadge = (int)$stats['en_attente']; include 'includes/sidebar.php'; ?>

    <main class="av2-main">

        <?php if (isset($_GET['success'])): ?>
            <div class="av2-alert success">
                <?php
                $successMsg = ['created' => 'Compte garage créé avec succès.', 'validated' => 'Garage validé avec succès.', 'rejected' => 'Garage rejeté.', 'suspended' => 'Garage suspendu.', 'reactivated' => 'Garage réactivé.'];
                echo h($successMsg[$_GET['success']] ?? 'Action effectuée.');
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="av2-alert error"><?php echo h($err); ?></div><?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <h1 class="av2-h1">Garages</h1>
                <p class="av2-sub">Vision globale des garages partenaires — le partenariat se conclut hors plateforme, l'admin crée ensuite le compte d'accès du garage.</p>
            </div>
            <button type="button" class="av2-btn-primary" id="openNewGarage">+ Nouveau garage</button>
        </div>

        <div class="av2-stats">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 10L12 4L20 10V19C20 20.1 19.1 21 18 21H6C4.9 21 4 20.1 4 19V10Z" stroke="#1E7DBF" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['total']; ?></div><div class="av2-stat-label">Total garages</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E9F6EE;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 12.5L10.5 15L16 9" stroke="#1E8A4C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['actifs']; ?></div><div class="av2-stat-label">Actifs</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#FFF4E2;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C8871A" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['en_attente']; ?></div><div class="av2-stat-label">En attente</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#6D74A0" stroke-width="1.8"/><path d="M8 8L16 16M16 8L8 16" stroke="#6D74A0" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo (int)$stats['suspendus']; ?></div><div class="av2-stat-label">Suspendus</div></div>
            </div>
        </div>

        <form method="GET" class="av2-filterbar">
            <select name="status" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="actif" <?php echo $status_filter === 'actif' ? 'selected' : ''; ?>>Actif</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>En attente</option>
                <option value="suspendu" <?php echo $status_filter === 'suspendu' ? 'selected' : ''; ?>>Suspendu</option>
                <option value="rejete" <?php echo $status_filter === 'rejete' ? 'selected' : ''; ?>>Rejeté</option>
            </select>
            <?php if ($status_filter): ?><a href="garages.php" class="av2-btn-outline" style="text-decoration:none;">Effacer</a><?php endif; ?>
        </form>

        <div class="av2-card" style="padding:8px;">
            <?php if (empty($garages)): ?>
                <div class="av2-empty">Aucun garage ne correspond aux critères sélectionnés.</div>
            <?php else: ?>
                <div class="av2-table-wrap">
                    <table class="av2-table">
                        <thead>
                            <tr><th>Garage</th><th>Contact</th><th>Techniciens</th><th>Interventions</th><th>Statut</th><th>Inscription</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($garages as $g): ?>
                                <tr>
                                    <td>
                                        <div class="av2-table-entity">
                                            <div class="av2-table-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 10L12 4L20 10V19C20 20.1 19.1 21 18 21H6C4.9 21 4 20.1 4 19V10Z" stroke="#1E7DBF" stroke-width="1.6" stroke-linejoin="round"/></svg></div>
                                            <?php echo h($g['nomGarage']); ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#8B90B3; margin-top:2px;"><?php echo h($g['adresse'] ?: 'Adresse non renseignée'); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($g['idUtilisateur']): ?>
                                            <?php echo h($g['prenom'] . ' ' . $g['nom']); ?><div style="font-size:11.5px; color:#8B90B3;"><?php echo h($g['email']); ?></div>
                                        <?php else: ?>
                                            <span class="av2-badge warn">Aucun compte lié</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="av2-badge neutral"><?php echo (int)$g['techniciens_count']; ?></span></td>
                                    <td><span class="av2-badge neutral"><?php echo (int)$g['interventions_count']; ?></span></td>
                                    <td><span class="av2-badge <?php echo h(av2_status_badge($g['statutGarage'])); ?>"><?php echo h(av2_status_label($g['statutGarage'])); ?></span></td>
                                    <td><?php if ($g['created_at']): ?><?php echo h(date('d/m/Y', strtotime($g['created_at']))); ?><?php else: ?>—<?php endif; ?></td>
                                    <td>
                                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <a href="garage_detail.php?id=<?php echo (int)$g['idGarage']; ?>" class="av2-table-link">Voir</a>
                                            <?php if ($g['statutGarage'] === 'EN_ATTENTE'): ?>
                                                <form method="POST" action="garages.php?action=validate&id=<?php echo (int)$g['idGarage']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Valider ce garage ?">Valider</button>
                                                </form>
                                                <form method="POST" action="garages.php?action=reject&id=<?php echo (int)$g['idGarage']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Rejeter ce garage ?" style="color:#E5484D;">Rejeter</button>
                                                </form>
                                            <?php elseif ($g['statutGarage'] === 'VALIDE'): ?>
                                                <form method="POST" action="garages.php?action=suspend&id=<?php echo (int)$g['idGarage']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Suspendre ce garage ? Il ne pourra plus se connecter ni recevoir de nouvelles demandes, mais son historique est conservé." style="color:#C8871A;">Suspendre</button>
                                                </form>
                                            <?php elseif ($g['statutGarage'] === 'SUSPENDU'): ?>
                                                <form method="POST" action="garages.php?action=reactivate&id=<?php echo (int)$g['idGarage']; ?>" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Réactiver ce garage ?">Réactiver</button>
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

<!-- Modal nouveau garage -->
<div class="av2-modal-overlay" id="newGarageOverlay">
    <div class="av2-modal">
        <h3>Nouveau garage</h3>
        <p class="av2-modal-sub">À utiliser une fois le partenariat conclu hors plateforme — crée directement un compte garage actif.</p>
        <?php if (!empty($errors)): ?><div class="av2-alert error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div><?php endif; ?>
        <form method="POST" action="garages.php?action=create_garage">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="av2-form-group"><label>Nom du garage</label><input type="text" name="nom_garage" required placeholder="Ex. Garage Central Yaoundé"></div>
            <div class="av2-form-group"><label>Adresse</label><input type="text" name="adresse" placeholder="Ex. Rue 1.234, Bastos, Yaoundé"></div>
            <p class="av2-modal-sub" style="margin-top:18px;">Compte de connexion du garage</p>
            <div class="av2-form-group"><label>Nom du contact</label><input type="text" name="nom" data-only="letters" required placeholder="Ex. Mbarga"></div>
            <div class="av2-form-group"><label>Prénom du contact</label><input type="text" name="prenom" data-only="letters" required placeholder="Ex. Jean"></div>
            <div class="av2-form-group"><label>Email</label><input type="email" name="email" required placeholder="exemple@gmail.com"></div>
            <div class="av2-form-group"><label>Téléphone</label><input type="tel" name="telephone" data-only="digits" inputmode="numeric" maxlength="15" required placeholder="Ex. 677123456"></div>
            <div class="av2-form-group"><label>Mot de passe</label><input type="password" name="mot_de_passe" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" data-password-policy autocomplete="new-password" placeholder="<?php echo PASSWORD_MIN_LENGTH; ?> caractères minimum"></div>
            <div class="av2-form-group"><label>Confirmation</label><input type="password" name="confirmation" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" placeholder="Retapez le mot de passe"></div>
            <div class="av2-modal-actions">
                <button type="button" class="av2-btn-outline" id="closeNewGarage">Annuler</button>
                <button type="submit" class="av2-btn-primary">Créer le garage</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('newGarageOverlay');
    document.getElementById('openNewGarage').addEventListener('click', function () { overlay.classList.add('show'); });
    document.getElementById('closeNewGarage').addEventListener('click', function () { overlay.classList.remove('show'); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.classList.remove('show'); });
    <?php if (!empty($errors)): ?>overlay.classList.add('show');<?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
