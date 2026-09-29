<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'config/roles.php';

requireAuth();

$db = new Database();
$conn = $db->getConnection();

// Récupérer les informations de l'utilisateur. Le statut/rôle est reconstitué
// depuis les nouvelles tables pour laisser le gabarit d'affichage inchangé :
// un technicien a un vrai statut de validation, client/admin sont toujours "actif".
$stmt = $conn->prepare("SELECT idUtilisateur AS id, nom, prenom, email, telephone, motDePasse AS mot_de_passe, dateCreation AS created_at FROM utilisateur WHERE idUtilisateur = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user) {
    $user['role'] = getUserRole($conn, (int)$user['id']);
    if ($user['role'] === ROLE_TECHNICIEN) {
        $stmt = $conn->prepare("SELECT statutValidation, competences FROM technicien WHERE idTechnicien = ?");
        $stmt->execute([$user['id']]);
        $t = $stmt->fetch();
        $statutMap = ['VALIDE' => 'actif', 'EN_ATTENTE' => 'pending', 'REJETE' => 'rejete'];
        $user['statut'] = $statutMap[$t['statutValidation'] ?? ''] ?? 'pending';
        $user['competences'] = $t['competences'] ?? null;
    } elseif ($user['role'] === ROLE_GARAGE) {
        $stmt = $conn->prepare("SELECT statutGarage FROM garage WHERE idUtilisateur = ?");
        $stmt->execute([$user['id']]);
        $g = $stmt->fetch();
        $statutMap = ['VALIDE' => 'actif', 'EN_ATTENTE' => 'pending', 'REJETE' => 'rejete'];
        $user['statut'] = $statutMap[$g['statutGarage'] ?? ''] ?? 'pending';
        $user['competences'] = null;
    } else {
        $user['statut'] = 'actif';
        $user['competences'] = null;
    }
}

if (!$user) {
    redirect('auth/login.php');
}

// Habillage v2 (sidebar + logo) pour les 4 rôles. $v2Role vaut 'client',
// 'garage', 'technicien', 'admin' ou null.
$isClientV2 = ($user['role'] === ROLE_CLIENT);
$isGarageV2 = ($user['role'] === ROLE_GARAGE);
$isTechnicienV2 = ($user['role'] === ROLE_TECHNICIEN);
$isAdminV2 = ($user['role'] === ROLE_ADMIN);
$v2Role = $isClientV2 ? 'client' : ($isGarageV2 ? 'garage' : ($isTechnicienV2 ? 'technicien' : ($isAdminV2 ? 'admin' : null)));
$clientRoleLabel = 'Client particulier';
$isEntreprise = false;
$interventionsActivesCount = 0;
$entrepriseInfo = null;
$garageInfo = null;
$technicienInfo = null;
if ($isClientV2) {
    $clientProfile = getUserProfile($conn, (int)$user['id']);
    $isEntreprise = (($clientProfile['typeClient'] ?? 'PARTICULIER') === 'ENTREPRISE');
    $clientRoleLabel = $isEntreprise ? 'Client entreprise' : 'Client particulier';
    $stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idClient = ? AND statut IN ('PLANIFIEE', 'EN_COURS')");
    $stmtBadge->execute([$user['id']]);
    $interventionsActivesCount = (int)$stmtBadge->fetchColumn();
    if ($isEntreprise) {
        $entrepriseInfo = [
            'raisonSociale' => $clientProfile['raisonSociale'] ?? null,
            'adresse' => $clientProfile['adresse'] ?? null,
        ];
    }
} elseif ($isGarageV2) {
    $garageProfile = getUserProfile($conn, (int)$user['id']);
    $garageIdForBadge = (int)($garageProfile['idGarage'] ?? 0);
    $stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
    $stmtBadge->execute([$garageIdForBadge]);
    $interventionsActivesCount = (int)$stmtBadge->fetchColumn();
    $garageInfo = [
        'nomGarage' => $garageProfile['nomGarage'] ?? null,
        'adresse' => $garageProfile['adresse'] ?? null,
    ];
} elseif ($isTechnicienV2) {
    $stmtBadge = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idTechnicien = ? AND statut = 'PLANIFIEE'");
    $stmtBadge->execute([$user['id']]);
    $interventionsActivesCount = (int)$stmtBadge->fetchColumn();
    $stmt = $conn->prepare('SELECT specialite, competences, experience FROM technicien WHERE idTechnicien = ?');
    $stmt->execute([$user['id']]);
    $technicienInfo = $stmt->fetch() ?: ['specialite' => null, 'competences' => null, 'experience' => null];
}

$errors = [];
$success = '';

// Traitement du formulaire de modification (infos personnelles + mot de passe).
// Le formulaire entreprise ci-dessous envoie form=update_entreprise ; celui-ci
// n'a pas de champ "form" (compatibilité avec le formulaire déjà en place).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['form'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $nom = sanitize($_POST['nom'] ?? '');
        $prenom = sanitize($_POST['prenom'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $telephone = sanitize($_POST['telephone'] ?? '');
        $mot_de_passe_actuel = $_POST['mot_de_passe_actuel'] ?? '';
        $nouveau_mot_de_passe = $_POST['nouveau_mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';
        
        // Validation des champs
        if (empty($nom)) $errors[] = 'Le nom est requis.';
        elseif (!validateLettersOnly($nom)) $errors[] = 'Le nom ne doit contenir que des lettres.';
        if (empty($prenom)) $errors[] = 'Le prénom est requis.';
        elseif (!validateLettersOnly($prenom)) $errors[] = 'Le prénom ne doit contenir que des lettres.';
        if (empty($email)) $errors[] = 'L\'email est requis.';
        if (!validateEmail($email)) $errors[] = 'L\'email n\'est pas valide.';
        if (empty($telephone)) $errors[] = 'Le téléphone est requis.';
        elseif (!validateDigitsOnly($telephone)) $errors[] = 'Le téléphone ne doit contenir que des chiffres.';
        
        // Vérifier l'unicité de l'email (sauf pour cet utilisateur)
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT idUtilisateur FROM utilisateur WHERE email = ? AND idUtilisateur != ?");
            $stmt->execute([$email, $_SESSION['user_id']]);

            if ($stmt->fetch()) {
                $errors[] = 'Cet email est déjà utilisé.';
            }
        }
        
        // Changement de mot de passe si fourni
        if (!empty($nouveau_mot_de_passe)) {
            if (empty($mot_de_passe_actuel)) {
                $errors[] = 'Le mot de passe actuel est requis pour le changement.';
            } elseif (!verifyPassword($mot_de_passe_actuel, $user['mot_de_passe'])) {
                $errors[] = 'Le mot de passe actuel est incorrect.';
            } elseif (strlen($nouveau_mot_de_passe) < PASSWORD_MIN_LENGTH) {
                $errors[] = 'Le nouveau mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caractères.';
            } elseif ($nouveau_mot_de_passe !== $confirmation) {
                $errors[] = 'Les nouveaux mots de passe ne correspondent pas.';
            }
        }
        
        // Mise à jour en base
        if (empty($errors)) {
            try {
                $sql = "UPDATE utilisateur SET nom = ?, prenom = ?, email = ?, telephone = ?";
                $params = [$nom, $prenom, $email, $telephone];

                if (!empty($nouveau_mot_de_passe)) {
                    $sql .= ", motDePasse = ?";
                    $params[] = hashPassword($nouveau_mot_de_passe);
                }

                $sql .= " WHERE idUtilisateur = ?";
                $params[] = $_SESSION['user_id'];
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                
                // Mettre à jour la session
                $_SESSION['nom'] = $nom;
                $_SESSION['prenom'] = $prenom;
                $_SESSION['email'] = $email;
                
                $success = 'Profil mis à jour avec succès !';
                
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la mise à jour du profil.';
            }
        }
    }
}

// Traitement du formulaire "informations entreprise" (raison sociale, adresse).
// Réutilise la table `entreprise` existante (aucune migration) : upsert via
// ON DUPLICATE KEY UPDATE puisque idClient en est la clé primaire.
if ($isEntreprise && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_entreprise') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $raisonSociale = sanitize($_POST['raison_sociale'] ?? '');
        $adresseEntreprise = sanitize($_POST['adresse_entreprise'] ?? '');

        if (empty($raisonSociale)) {
            $errors[] = 'La raison sociale est requise.';
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO entreprise (idClient, raisonSociale, adresse) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE raisonSociale = VALUES(raisonSociale), adresse = VALUES(adresse)
                ");
                $stmt->execute([$user['id'], $raisonSociale, $adresseEntreprise ?: null]);
                $entrepriseInfo = ['raisonSociale' => $raisonSociale, 'adresse' => $adresseEntreprise ?: null];
                $success = 'Informations de l\'entreprise mises à jour avec succès !';
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la mise à jour des informations de l\'entreprise.';
            }
        }
    }
}

// Traitement du formulaire "informations du garage" (nom, adresse). La ligne
// `garage` existe déjà (créée avec le compte de connexion) : simple UPDATE,
// pas d'upsert nécessaire contrairement à `entreprise`.
if ($isGarageV2 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_garage') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $nomGarage = sanitize($_POST['nom_garage'] ?? '');
        $adresseGarage = sanitize($_POST['adresse_garage'] ?? '');

        if (empty($nomGarage)) {
            $errors[] = 'Le nom du garage est requis.';
        } else {
            try {
                $conn->prepare("UPDATE garage SET nomGarage = ?, adresse = ? WHERE idUtilisateur = ?")
                    ->execute([$nomGarage, $adresseGarage ?: null, $user['id']]);
                $garageInfo = ['nomGarage' => $nomGarage, 'adresse' => $adresseGarage ?: null];
                $success = 'Informations du garage mises à jour avec succès !';
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la mise à jour des informations du garage.';
            }
        }
    }
}

// Traitement du formulaire "informations professionnelles" du technicien
// (spécialité, compétences, expérience). Simple UPDATE : la ligne
// `technicien` existe déjà (créée avec le compte).
if ($isTechnicienV2 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_technicien') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $specialite = sanitize($_POST['specialite'] ?? '');
        $competences = sanitize($_POST['competences'] ?? '');
        $experience = sanitize($_POST['experience'] ?? '');
        try {
            $conn->prepare("UPDATE technicien SET specialite = ?, competences = ?, experience = ? WHERE idTechnicien = ?")
                ->execute([$specialite ?: null, $competences ?: null, $experience ?: null, $user['id']]);
            $technicienInfo = ['specialite' => $specialite ?: null, 'competences' => $competences ?: null, 'experience' => $experience ?: null];
            $user['competences'] = $competences ?: null;
            $success = 'Informations professionnelles mises à jour avec succès !';
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la mise à jour des informations professionnelles.';
        }
    }
}

$pageTitle = 'Mon Profil';
if ($v2Role === 'client') {
    $hideNavbar = true;
    $bodyClass = 'v2';
    $extraStylesheets = ['assets/css/client_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
} elseif ($v2Role === 'garage') {
    $hideNavbar = true;
    $bodyClass = 'gv2';
    $extraStylesheets = ['assets/css/garage_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
} elseif ($v2Role === 'technicien') {
    $hideNavbar = true;
    $bodyClass = 'tv2';
    $extraStylesheets = ['assets/css/technicien_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
} elseif ($v2Role === 'admin') {
    require_once 'admin/includes/helpers.php';
    $hideNavbar = true;
    $bodyClass = 'av2';
    $extraStylesheets = ['assets/css/admin_v2.css'];
    $extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
}
include 'includes/header.php';
?>
<?php if ($v2Role === 'client'): ?>
<div class="v2-shell">
    <?php $activeNav = 'profil'; $interventionsBadge = $interventionsActivesCount; include 'client/includes/sidebar.php'; ?>
    <main class="v2-main">
        <div class="v2-page-head">
            <div>
                <div class="v2-kicker"><?php echo h($clientRoleLabel); ?></div>
                <h1 class="v2-h1">Mon profil</h1>
                <p class="v2-sub">Vos informations personnelles et votre mot de passe</p>
            </div>
            <a href="client/dashboard.php" class="v2-btn-outline" style="text-decoration:none; display:inline-block;">← Retour au tableau de bord</a>
        </div>
<?php elseif ($v2Role === 'garage'): ?>
<div class="gv2-shell">
    <?php $activeNav = 'profil'; $pendingBadge = $interventionsActivesCount; $garageNom = $garageInfo['nomGarage'] ?? 'Garage'; include 'garage/includes/sidebar.php'; ?>
    <main class="gv2-main">
        <div class="gv2-page-head">
            <div>
                <div class="gv2-kicker">Garage partenaire</div>
                <h1 class="gv2-h1">Mon profil</h1>
                <p class="gv2-sub">Vos informations personnelles et votre mot de passe</p>
            </div>
            <a href="garage/dashboard.php" class="gv2-btn-outline" style="text-decoration:none; display:inline-block;">← Retour au tableau de bord</a>
        </div>
<?php elseif ($v2Role === 'technicien'): ?>
<div class="tv2-shell">
    <?php $activeNav = 'profil'; $tachesBadge = $interventionsActivesCount; include 'technicien/includes/sidebar.php'; ?>
    <main class="tv2-main">
        <div class="tv2-page-head">
            <div>
                <div class="tv2-kicker">Technicien</div>
                <h1 class="tv2-h1">Mon profil</h1>
                <p class="tv2-sub">Vos informations personnelles et votre mot de passe</p>
            </div>
            <a href="technicien/dashboard.php" class="tv2-btn-outline" style="text-decoration:none; display:inline-block;">← Retour au tableau de bord</a>
        </div>
<?php elseif ($v2Role === 'admin'): ?>
<div class="av2-shell">
    <?php $activeNav = 'profil'; include 'admin/includes/sidebar.php'; ?>
    <main class="av2-main">
        <div class="av2-page-head">
            <div>
                <div class="av2-kicker">Administrateur</div>
                <h1 class="av2-h1">Mon profil</h1>
                <p class="av2-sub">Vos informations personnelles et votre mot de passe</p>
            </div>
            <a href="admin/dashboard.php" class="av2-btn-outline" style="text-decoration:none; display:inline-block;">← Retour au tableau de bord</a>
        </div>
<?php else: ?>
<div class="page-header">
    <h1><i class="fas fa-user"></i> Mon Profil</h1>
    <div class="header-actions">
        <a href="dashboard.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?php echo h($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo h($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-8">
        <?php if ($isEntreprise): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-building"></i> Informations de l'entreprise</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="form" value="update_entreprise">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label class="form-label">Raison sociale *</label>
                        <input type="text" name="raison_sociale" class="form-control" required value="<?php echo h($entrepriseInfo['raisonSociale'] ?? ''); ?>" placeholder="Ex. : SmartAutoTrack Flotte SARL">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Adresse</label>
                        <input type="text" name="adresse_entreprise" class="form-control" value="<?php echo h($entrepriseInfo['adresse'] ?? ''); ?>" placeholder="Adresse du siège">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </form>
                <?php if (empty($entrepriseInfo['raisonSociale'])): ?>
                    <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.75rem;">
                        Renseignez la raison sociale pour qu'elle apparaisse dans votre tableau de bord (à la place de votre prénom).
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($isGarageV2): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-warehouse"></i> Informations du garage</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="form" value="update_garage">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label class="form-label">Nom du garage *</label>
                        <input type="text" name="nom_garage" class="form-control" required value="<?php echo h($garageInfo['nomGarage'] ?? ''); ?>" placeholder="Ex. : Garage Nord Auto">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Adresse</label>
                        <input type="text" name="adresse_garage" class="form-control" value="<?php echo h($garageInfo['adresse'] ?? ''); ?>" placeholder="Adresse du garage">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </form>
                <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.75rem;">
                    Le téléphone et l'email ci-dessous servent aussi de contact pour votre garage. Le statut de validation est géré par un administrateur.
                </p>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($isTechnicienV2): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-wrench"></i> Informations professionnelles</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="form" value="update_technicien">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label class="form-label">Spécialité</label>
                        <input type="text" name="specialite" class="form-control" value="<?php echo h($technicienInfo['specialite'] ?? ''); ?>" placeholder="Ex. : Freinage, Moteur & diagnostic...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Compétences</label>
                        <textarea name="competences" class="form-control" rows="3" placeholder="Vos compétences principales..."><?php echo h($technicienInfo['competences'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expérience</label>
                        <textarea name="experience" class="form-control" rows="3" placeholder="Votre parcours et expérience..."><?php echo h($technicienInfo['experience'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                    </div>
                </form>
                <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.75rem;">
                    Le garage auquel vous êtes rattaché et le statut de validation de votre compte sont gérés par un administrateur.
                </p>
            </div>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Informations personnelles</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nom *</label>
                            <input type="text" name="nom" class="form-control" required value="<?php echo htmlspecialchars($user['nom']); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prénom *</label>
                            <input type="text" name="prenom" class="form-control" required value="<?php echo htmlspecialchars($user['prenom']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($user['email']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Téléphone *</label>
                        <input type="tel" name="telephone" class="form-control" required value="<?php echo htmlspecialchars($user['telephone']); ?>">
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-lock"></i> Changer le mot de passe</h3>
            </div>
            <div class="card-body">
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Mot de passe actuel *</label>
                        <input type="password" name="mot_de_passe_actuel" class="form-control" id="currentPassword">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nouveau mot de passe *</label>
                            <input type="password" name="nouveau_mot_de_passe" class="form-control" id="newPassword">
                            <div class="form-text">Minimum <?php echo PASSWORD_MIN_LENGTH; ?> caractères</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirmation *</label>
                            <input type="password" name="confirmation" class="form-control" id="confirmPassword">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-key"></i> Changer le mot de passe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-4">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> Informations du compte</h3>
            </div>
            <div class="card-body">
                <div class="account-info">
                    <div class="info-item">
                        <label>Rôle :</label>
                        <span class="badge badge-primary"><?php echo h(ucfirst($user['role'])); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <label>Statut :</label>
                        <span class="badge badge-<?php echo h($user['statut'] === 'actif' ? 'success' : 
                                ($user['statut'] === 'pending' ? 'warning' : 'danger')); ?>">
                            <?php echo h(ucfirst($user['statut'])); ?>
                        </span>
                    </div>
                    
                    <div class="info-item">
                        <label>Membre depuis :</label>
                        <span><?php echo formatDate($user['created_at']); ?></span>
                    </div>
                    
                    <?php if ($user['role'] === 'technicien' && $user['competences']): ?>
                        <div class="info-item">
                            <label>Compétences :</label>
                            <div class="competences-preview">
                                <?php echo h(substr($user['competences'], 0, 100)) . (strlen($user['competences']) > 100 ? '...' : ''); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($user['role'] === 'technicien' && $user['statut'] === 'pending'): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Validation en cours</h3>
            </div>
            <div class="card-body">
                <div class="pending-info">
                    <i class="fas fa-hourglass-half"></i>
                    <p>Votre compte technicien est en cours de validation par un administrateur.</p>
                    <p>Vous recevrez une notification dès que votre compte sera activé.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.account-info {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.info-item label {
    font-weight: 600;
    color: var(--text-color);
    font-size: 0.9rem;
}

.info-item span {
    color: var(--text-light);
}

.competences-preview {
    background: var(--background-color);
    padding: 0.75rem;
    border-radius: var(--border-radius);
    font-size: 0.9rem;
    line-height: 1.5;
    color: var(--text-light);
}

.pending-info {
    text-align: center;
    padding: 1rem;
}

.pending-info i {
    font-size: 2rem;
    color: var(--warning-color);
    margin-bottom: 1rem;
}

.pending-info p {
    color: var(--text-light);
    margin-bottom: 0.5rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
}

@media (max-width: 768px) {
    .row {
        flex-direction: column;
    }
    
    .form-actions {
        justify-content: stretch;
    }
    
    .form-actions .btn {
        flex: 1;
    }
}
</style>

<script>
$(document).ready(function() {
    // Validation du formulaire de changement de mot de passe
    $('#passwordForm').submit(function(e) {
        const currentPassword = $('#currentPassword').val().trim();
        const newPassword = $('#newPassword').val().trim();
        const confirmPassword = $('#confirmPassword').val().trim();
        
        if (!currentPassword || !newPassword || !confirmPassword) {
            e.preventDefault();
            showToast('Tous les champs sont requis pour changer le mot de passe', 'error');
            return false;
        }
        
        if (newPassword.length < <?php echo PASSWORD_MIN_LENGTH; ?>) {
            e.preventDefault();
            showToast('Le nouveau mot de passe doit contenir au moins <?php echo PASSWORD_MIN_LENGTH; ?> caractères', 'error');
            return false;
        }
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            showToast('Les nouveaux mots de passe ne correspondent pas', 'error');
            return false;
        }
        
        if (!confirm('Êtes-vous sûr de vouloir changer votre mot de passe ?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Validation du formulaire principal
    $('form').not('#passwordForm').submit(function(e) {
        const nom = $('input[name="nom"]').val().trim();
        const prenom = $('input[name="prenom"]').val().trim();
        const email = $('input[name="email"]').val().trim();
        const telephone = $('input[name="telephone"]').val().trim();
        
        if (!nom || !prenom || !email || !telephone) {
            e.preventDefault();
            showToast('Tous les champs sont requis', 'error');
            return false;
        }
        
        if (!SmartAutoTrack.utils.validateEmail(email)) {
            e.preventDefault();
            showToast('Format d\'email invalide', 'error');
            return false;
        }
    });
});
</script>

<?php if ($v2Role !== null): ?>
    </main>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
