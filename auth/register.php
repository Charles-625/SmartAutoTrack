<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';

/**
 * Page d'inscription publique (clients et techniciens uniquement).
 *
 * Les comptes garage et admin ne se créent pas ici. Un client est actif
 * immédiatement ; un technicien est créé EN_ATTENTE et doit être validé
 * par un administrateur (une notification est créée à cet effet).
 *
 * POST : champs communs (nom, prénom, email, téléphone, mot de passe soumis
 * à includes/password_policy.php, rôle) puis, selon le rôle :
 *  - client : type PARTICULIER (avec un premier véhicule) ou ENTREPRISE
 *    (raison sociale, sans véhicule) ;
 *  - technicien : compétences, expérience et documents justificatifs
 *    (PDF/JPG/PNG, 5 Mo max, type vérifié sur le contenu).
 *
 * Tables écrites (dans une seule transaction) : utilisateur, client,
 * particulier / entreprise, vehicule, technicien, technician_documents,
 * notifications. Fichiers stockés dans uploads/techniciens/ et servis
 * uniquement par ajax/download_document.php.
 */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification du token CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $role = sanitize($_POST['role'] ?? '');
        $nom = sanitize($_POST['nom'] ?? '');
        $prenom = sanitize($_POST['prenom'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $telephone = sanitize($_POST['telephone'] ?? '');
        $mot_de_passe = trim($_POST['mot_de_passe'] ?? '');
        $confirmation = trim($_POST['confirmation'] ?? '');
        
        // Validation des champs communs
        if (empty($nom)) $errors[] = 'Le nom est requis.';
        elseif (!validateLettersOnly($nom)) $errors[] = 'Le nom ne doit contenir que des lettres.';
        if (empty($prenom)) $errors[] = 'Le prénom est requis.';
        elseif (!validateLettersOnly($prenom)) $errors[] = 'Le prénom ne doit contenir que des lettres.';
        if (empty($email)) $errors[] = 'L\'email est requis.';
        if (!validateEmail($email)) $errors[] = 'L\'email n\'est pas valide.';
        if (empty($telephone)) $errors[] = 'Le téléphone est requis.';
        elseif (!validateDigitsOnly($telephone)) $errors[] = 'Le téléphone ne doit contenir que des chiffres.';
        if (empty($mot_de_passe)) $errors[] = 'Le mot de passe est requis.';
        elseif ($pwError = passwordPolicyError($mot_de_passe)) $errors[] = $pwError;
        if ($mot_de_passe !== $confirmation) $errors[] = 'Les mots de passe ne correspondent pas.';
        if (!in_array($role, ['client', 'technicien'])) $errors[] = 'Rôle invalide.';
        
        // Validation spécifique aux clients
        if ($role === 'client') {
            $type_client = ($_POST['type_client'] ?? 'PARTICULIER') === 'ENTREPRISE' ? 'ENTREPRISE' : 'PARTICULIER';
            $raison_sociale = sanitize($_POST['raison_sociale'] ?? '');
            $adresse_entreprise = sanitize($_POST['adresse_entreprise'] ?? '');

            $marque = sanitize($_POST['marque'] ?? '');
            $modele = sanitize($_POST['modele'] ?? '');
            $immatriculation = normalizePlate(sanitize($_POST['immatriculation'] ?? ''));

            // Le véhicule n'est demandé qu'aux particuliers : une entreprise
            // ajoute sa flotte depuis son dashboard après inscription.
            if ($type_client === 'PARTICULIER') {
                if (empty($marque)) $errors[] = 'La marque du véhicule est requise.';
                elseif (!validateLettersOnly($marque)) $errors[] = 'La marque du véhicule ne doit contenir que des lettres.';
                if (empty($modele)) $errors[] = 'Le modèle du véhicule est requis.';
                elseif (!validateModel($modele)) $errors[] = 'Le modèle ne peut contenir que des lettres, des chiffres, des espaces et les signes - . + ! /';
                if (empty($immatriculation)) $errors[] = 'L\'immatriculation est requise.';
                elseif (!validatePlate($immatriculation)) $errors[] = 'L\'immatriculation ne doit contenir que des lettres et des chiffres (espaces et tirets permis).';
            }

            if ($type_client === 'ENTREPRISE' && empty($raison_sociale)) {
                $errors[] = 'La raison sociale est requise pour un compte entreprise.';
            }
        }
        
        // Validation spécifique aux techniciens
        if ($role === 'technicien') {
            $competences = sanitize($_POST['competences'] ?? '');
            $experience = sanitize($_POST['experience'] ?? '');
            
            if (empty($competences)) $errors[] = 'Les compétences sont requises.';
            if (empty($experience)) $errors[] = 'L\'expérience est requise.';
        }
        
        // Vérification de l'unicité de l'email
        if (empty($errors)) {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("SELECT idUtilisateur FROM utilisateur WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $errors[] = 'Cet email est déjà utilisé.';
            }
        }

        // Insertion en base de données
        if (empty($errors)) {
            try {
                // Tout ou rien : l'utilisateur et ses fiches liées sont créés
                // ensemble ; une erreur annule l'ensemble (rollBack plus bas).
                $conn->beginTransaction();

                // Insertion de l'utilisateur (le rôle n'est plus une colonne : il est
                // matérialisé par la ligne créée dans la table client ou technicien ci-dessous)
                $hashedPassword = hashPassword($mot_de_passe);

                $stmt = $conn->prepare("
                    INSERT INTO utilisateur (nom, prenom, email, telephone, motDePasse)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nom, $prenom, $email, $telephone, $hashedPassword]);

                $user_id = $conn->lastInsertId();

                // Si c'est un client, créer sa fiche client (+ particulier ou entreprise) et son véhicule
                if ($role === 'client') {
                    $conn->prepare("INSERT INTO client (idClient, typeClient) VALUES (?, ?)")->execute([$user_id, $type_client]);

                    if ($type_client === 'ENTREPRISE') {
                        $conn->prepare("INSERT INTO entreprise (idClient, raisonSociale, adresse) VALUES (?, ?, ?)")
                            ->execute([$user_id, $raison_sociale, $adresse_entreprise ?: null]);
                        // Pas de véhicule à l'inscription pour une entreprise : elle
                        // ajoutera sa flotte depuis son dashboard.
                    } else {
                        $conn->prepare("INSERT INTO particulier (idClient, adresse) VALUES (?, NULL)")->execute([$user_id]);

                        $stmt = $conn->prepare("
                            INSERT INTO vehicule (idClient, marque, modele, immatriculation)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$user_id, $marque, $modele, $immatriculation]);
                    }
                }

                // Si c'est un technicien, créer sa fiche technicien (en attente de validation)
                if ($role === 'technicien') {
                    $conn->prepare("
                        INSERT INTO technicien (idTechnicien, idGarage, specialite, statutValidation, competences, experience)
                        VALUES (?, NULL, NULL, 'EN_ATTENTE', ?, ?)
                    ")->execute([$user_id, $competences, $experience]);
                }
                
                // Si c'est un technicien, traiter l'upload des documents
                if ($role === 'technicien' && isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                    // Un fichier refusé n'annule pas l'inscription : il est
                    // simplement signalé dans $errors et ignoré.
                    $upload_dir = __DIR__ . '/../uploads/techniciens/';
                    
                    // Créer le dossier s'il n'existe pas
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Types autorisés : le contenu réel du fichier est vérifié (pas seulement l'extension)
                    $allowed_mimes = [
                        'application/pdf' => 'pdf',
                        'image/jpeg'      => 'jpg',
                        'image/png'       => 'png',
                    ];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $max_size = 5 * 1024 * 1024; // 5MB

                    for ($i = 0; $i < count($_FILES['documents']['name']); $i++) {
                        if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_OK) {
                            $file_name = sanitize(basename($_FILES['documents']['name'][$i]));
                            $file_size = $_FILES['documents']['size'][$i];
                            $file_tmp = $_FILES['documents']['tmp_name'][$i];

                            // Vérifier le type de fichier d'après son contenu
                            $file_mime = is_uploaded_file($file_tmp) ? $finfo->file($file_tmp) : false;
                            if (!$file_mime || !isset($allowed_mimes[$file_mime])) {
                                $errors[] = "Type de fichier non autorisé pour $file_name. Types autorisés: PDF, JPG, PNG";
                                continue;
                            }
                            $file_ext = $allowed_mimes[$file_mime];

                            // Vérifier la taille
                            if ($file_size > $max_size) {
                                $errors[] = "Fichier $file_name trop volumineux. Taille max: 5MB";
                                continue;
                            }

                            // Nom de fichier aléatoire (non prédictible), extension issue du type réel
                            $new_file_name = $user_id . '_' . bin2hex(random_bytes(16)) . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;
                            
                            // Déplacer le fichier
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                // Déterminer le type de document
                                // Classement indicatif d'après le nom du fichier, pour
                                // aider l'administrateur lors de la validation.
                                $document_type = 'autre';
                                if (strpos(strtolower($file_name), 'diplome') !== false || strpos(strtolower($file_name), 'diplôme') !== false) {
                                    $document_type = 'diplome';
                                } elseif (strpos(strtolower($file_name), 'certificat') !== false || strpos(strtolower($file_name), 'certificate') !== false) {
                                    $document_type = 'certificat';
                                } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png'])) {
                                    $document_type = 'photo';
                                }
                                
                                // Enregistrer en base de données
                                $stmt = $conn->prepare("
                                    INSERT INTO technician_documents (technicien_id, type_document, nom_fichier, chemin_fichier, taille_fichier) 
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $user_id, 
                                    $document_type, 
                                    $file_name, 
                                    'uploads/techniciens/' . $new_file_name, 
                                    $file_size
                                ]);
                            } else {
                                $errors[] = "Erreur lors de l'upload du fichier $file_name";
                            }
                        }
                    }
                }
                
                // Créer une notification pour l'admin si c'est un technicien
                if ($role === 'technicien') {
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, titre, message) 
                        VALUES (1, 'validation', 'Nouvelle demande de technicien', ?)
                    ");
                    $stmt->execute(["Nouvelle demande d'inscription de technicien: " . $prenom . " " . $nom]);
                }
                
                $conn->commit();
                
                $success = $role === 'client' 
                    ? 'Inscription réussie ! Vous pouvez maintenant vous connecter.' 
                    : 'Demande d\'inscription envoyée ! Votre compte sera activé après validation par un administrateur.';
                
                // Ajouter un message si des documents ont été uploadés
                if ($role === 'technicien' && isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                    $uploaded_count = 0;
                    for ($i = 0; $i < count($_FILES['documents']['name']); $i++) {
                        if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_OK) {
                            $uploaded_count++;
                        }
                    }
                    if ($uploaded_count > 0) {
                        $success .= " $uploaded_count document(s) uploadé(s) avec succès.";
                    }
                }
                
                // Redirection vers la page de connexion
                header("refresh:3;url=login.php");
                
            } catch (Exception $e) {
                $conn->rollBack();
                $errors[] = 'Erreur lors de l\'inscription. Veuillez réessayer.';
            }
        }
    }
}

$pageTitle = 'Inscription';
$bodyClass = 'auth-page';
$hideNavbar = true;
include '../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <img src="<?php echo SITE_URL; ?>assets/img/logo.png" alt="<?php echo SITE_NAME; ?>" style="width:64px; height:64px; border-radius:16px; margin-bottom:1rem;">
            <h1>SmartAutoTrack</h1>
            <p>Rejoignez notre plateforme de suivi véhicules</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8', false); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo h($success); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

            <p class="required-legend"><span class="required-star" aria-hidden="true">*</span> Champs obligatoires</p>

            <!-- Choix du rôle -->
            <div class="form-group">
                <label class="form-label">Je suis <span class="required-star" aria-hidden="true">*</span></label>
                <div class="role-selection">
                    <label class="role-option">
                        <input type="radio" name="role" value="client" required>
                        <div class="role-card">
                            <i class="fas fa-user"></i>
                            <span>Client</span>
                            <small>Propriétaire de véhicule</small>
                        </div>
                    </label>
                    <label class="role-option">
                        <input type="radio" name="role" value="technicien" required>
                        <div class="role-card">
                            <i class="fas fa-wrench"></i>
                            <span>Technicien</span>
                            <small>Spécialiste automobile</small>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Informations personnelles -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nom <span class="required-star" aria-hidden="true">*</span></label>
                    <input type="text" name="nom" data-only="letters" class="form-control" required placeholder="Ex. Mbarga" value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom <span class="required-star" aria-hidden="true">*</span></label>
                    <input type="text" name="prenom" data-only="letters" class="form-control" required placeholder="Ex. Jean" value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Email <span class="required-star" aria-hidden="true">*</span></label>
                <input type="email" name="email" class="form-control" required placeholder="exemple@gmail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Téléphone <span class="required-star" aria-hidden="true">*</span></label>
                <input type="tel" name="telephone" data-only="digits" inputmode="numeric" maxlength="15" class="form-control" required placeholder="Ex. 677123456" value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>">
            </div>
            
            <!-- Type de client (pour les clients) -->
            <div id="client-type-section" class="vehicle-section" style="display: none;">
                <h3>Type de compte</h3>
                <div class="role-selection" style="margin-bottom: 0;">
                    <label class="role-option">
                        <input type="radio" name="type_client" value="PARTICULIER" checked>
                        <div class="role-card">
                            <i class="fas fa-user"></i>
                            <span>Particulier</span>
                            <small>Véhicule personnel</small>
                        </div>
                    </label>
                    <label class="role-option">
                        <input type="radio" name="type_client" value="ENTREPRISE">
                        <div class="role-card">
                            <i class="fas fa-building"></i>
                            <span>Entreprise</span>
                            <small>Flotte de véhicules</small>
                        </div>
                    </label>
                </div>
                <div id="entreprise-info" style="display: none; margin-top: 1.5rem;">
                    <div class="form-group">
                        <label class="form-label">Raison sociale <span class="required-star" aria-hidden="true">*</span></label>
                        <input type="text" name="raison_sociale" class="form-control" placeholder="Ex. Transports Express SARL" value="<?php echo htmlspecialchars($_POST['raison_sociale'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Adresse de l'entreprise</label>
                        <input type="text" name="adresse_entreprise" class="form-control" placeholder="Ex. Rue 1.234, Bastos, Yaoundé" value="<?php echo htmlspecialchars($_POST['adresse_entreprise'] ?? ''); ?>">
                    </div>
                    <p class="form-text">Vous pourrez ajouter les véhicules de votre flotte depuis votre tableau de bord une fois inscrit.</p>
                </div>
            </div>

            <!-- Informations véhicule (particulier uniquement : une entreprise
                 ajoute sa flotte depuis son dashboard, voir #entreprise-info) -->
            <div id="vehicle-info" class="vehicle-section" style="display: none;">
                <h3>Informations du véhicule</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Marque <span class="required-star" aria-hidden="true">*</span></label>
                        <input type="text" name="marque" data-only="letters" class="form-control" placeholder="Ex. Toyota" value="<?php echo htmlspecialchars($_POST['marque'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Modèle <span class="required-star" aria-hidden="true">*</span></label>
                        <input type="text" name="modele" data-only="model" maxlength="50" class="form-control" placeholder="Ex. Corolla" value="<?php echo htmlspecialchars($_POST['modele'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Immatriculation <span class="required-star" aria-hidden="true">*</span></label>
                    <input type="text" name="immatriculation" data-only="plate" maxlength="15" autocapitalize="characters" class="form-control" placeholder="Ex. LT 123 AB" value="<?php echo htmlspecialchars($_POST['immatriculation'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Informations technicien -->
            <div id="technician-info" class="technician-section" style="display: none;">
                <h3>Informations professionnelles</h3>
                <div class="form-group">
                    <label class="form-label">Compétences <span class="required-star" aria-hidden="true">*</span></label>
                    <textarea name="competences" class="form-control" rows="3" placeholder="Ex. Mécanique générale, diagnostic électronique, climatisation"><?php echo htmlspecialchars($_POST['competences'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Expérience <span class="required-star" aria-hidden="true">*</span></label>
                    <textarea name="experience" class="form-control" rows="3" placeholder="Ex. 5 ans en garage agréé Toyota"><?php echo htmlspecialchars($_POST['experience'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Documents (optionnel)</label>
                    <input type="file" name="documents[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png" id="documentsInput">
                    <div class="form-text">Diplômes, certificats, photo de profil (PDF, JPG, PNG) - Max 5MB par fichier</div>
                    <div id="filePreview" class="file-preview"></div>
                </div>
            </div>
            
            <!-- Mot de passe -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Mot de passe <span class="required-star" aria-hidden="true">*</span></label>
                    <input type="password" name="mot_de_passe" class="form-control" required data-password-policy autocomplete="new-password" placeholder="<?php echo PASSWORD_MIN_LENGTH; ?> caractères minimum">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirmation <span class="required-star" aria-hidden="true">*</span></label>
                    <input type="password" name="confirmation" class="form-control" required placeholder="Retapez le mot de passe">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                <i class="fas fa-user-plus"></i>
                S'inscrire
            </button>
        </form>
        
        <div class="auth-footer">
            <p>Déjà inscrit ? <a href="login.php">Se connecter</a></p>
        </div>
    </div>
</div>

<style>
.auth-page {
    background: #D9DCE3;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}

.auth-container {
    width: 100%;
    max-width: 600px;
}

.auth-card {
    background: #EEF0F5;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-hover);
    padding: 2rem;
}

.auth-header {
    text-align: center;
    margin-bottom: 2rem;
}

.auth-header i {
    font-size: 3rem;
    color: var(--primary-color);
    margin-bottom: 1rem;
}

.auth-header h1 {
    margin-bottom: 0.5rem;
    color: var(--text-color);
}

.auth-header p {
    color: var(--text-light);
    margin-bottom: 0;
}

.role-selection {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.role-option {
    cursor: pointer;
}

.role-option input {
    display: none;
}

.role-card {
    border: 2px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    text-align: center;
    transition: var(--transition);
    background: var(--background-color);
}

.role-option input:checked + .role-card {
    border-color: var(--primary-color);
    background: rgba(30, 144, 255, 0.05);
}

.role-card i {
    font-size: 2rem;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
    display: block;
}

.role-card span {
    font-weight: 600;
    display: block;
    margin-bottom: 0.25rem;
}

.role-card small {
    color: var(--text-light);
    font-size: 0.875rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.vehicle-section,
.technician-section {
    background: var(--background-color);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    margin: 1.5rem 0;
}

.vehicle-section h3,
.technician-section h3 {
    margin-bottom: 1rem;
    color: var(--primary-color);
    font-size: 1.25rem;
}

.required-star {
    color: #E03131;
    font-weight: 700;
    margin-left: 2px;
}

.required-legend {
    font-size: 0.85rem;
    color: var(--text-light);
    margin-bottom: 1rem;
}

.btn-block {
    width: 100%;
    margin-top: 1rem;
}

.auth-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 1px solid var(--border-color);
}

.auth-footer a {
    color: var(--primary-color);
    text-decoration: none;
    font-weight: 500;
}

.auth-footer a:hover {
    text-decoration: underline;
}

.alert {
    padding: 1rem;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
}

.alert-danger {
    background-color: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.2);
    color: var(--danger-color);
}

.alert-success {
    background-color: rgba(40, 167, 69, 0.1);
    border: 1px solid rgba(40, 167, 69, 0.2);
    color: var(--success-color);
}

.alert ul {
    margin: 0;
    padding-left: 1rem;
}

@media (max-width: 768px) {
    .auth-page {
        padding: 1rem;
    }
    
    .auth-card {
        padding: 1.5rem;
    }
    
    .role-selection {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
}

.file-preview {
    margin-top: 1rem;
}

.file-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    background: var(--background-color);
    border-radius: var(--border-radius);
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.file-item i {
    color: var(--primary-color);
}

.file-name {
    flex: 1;
    color: var(--text-color);
}

.file-size {
    color: var(--text-light);
    font-size: 0.8rem;
}

.file-error {
    color: var(--danger-color);
    font-size: 0.8rem;
}
</style>

<script>
$(document).ready(function() {
    // Bascule Particulier / Entreprise : la raison sociale n'est requise que
    // pour une entreprise, et à l'inverse le véhicule n'est demandé qu'à un
    // particulier (une entreprise l'ajoutera depuis son dashboard).
    function updateClientTypeSections() {
        const isEntreprise = $('input[name="type_client"]:checked').val() === 'ENTREPRISE';
        if (isEntreprise) {
            $('#entreprise-info').slideDown();
            $('#vehicle-info').slideUp();
        } else {
            $('#entreprise-info').slideUp();
            $('#vehicle-info').slideDown();
        }
        $('input[name="raison_sociale"]').prop('required', isEntreprise);
        $('input[name="marque"], input[name="modele"], input[name="immatriculation"]').prop('required', !isEntreprise);
    }

    $('input[name="role"]').change(function() {
        const role = $(this).val();

        if (role === 'client') {
            $('#client-type-section').slideDown();
            $('#technician-info').slideUp();
            $('textarea[name="competences"], textarea[name="experience"]').prop('required', false);
            updateClientTypeSections();
        } else if (role === 'technicien') {
            $('#client-type-section').slideUp();
            $('#vehicle-info').slideUp();
            $('#entreprise-info').slideUp();
            $('#technician-info').slideDown();
            $('input[name="marque"], input[name="modele"], input[name="immatriculation"]').prop('required', false);
            $('textarea[name="competences"], textarea[name="experience"]').prop('required', true);
            $('input[name="raison_sociale"]').prop('required', false);
        }
    });

    $('input[name="type_client"]').change(updateClientTypeSections);

    // Validation en temps réel
    $('form').on('submit', function(e) {
        const role = $('input[name="role"]:checked').val();
        
        if (!role) {
            e.preventDefault();
            showToast('Veuillez sélectionner un rôle', 'error');
            return false;
        }
        
        if (role === 'client') {
            const required = ['nom', 'prenom', 'email', 'telephone', 'mot_de_passe', 'confirmation'];
            if ($('input[name="type_client"]:checked').val() === 'ENTREPRISE') {
                required.push('raison_sociale');
            } else {
                required.push('marque', 'modele', 'immatriculation');
            }
            for (let field of required) {
                if (!$(`[name="${field}"]`).val().trim()) {
                    e.preventDefault();
                    showToast(`Le champ ${field} est requis`, 'error');
                    return false;
                }
            }
        }
        
        if (role === 'technicien') {
            const required = ['nom', 'prenom', 'email', 'telephone', 'competences', 'experience', 'mot_de_passe', 'confirmation'];
            for (let field of required) {
                if (!$(`[name="${field}"]`).val().trim()) {
                    e.preventDefault();
                    showToast(`Le champ ${field} est requis`, 'error');
                    return false;
                }
            }
        }
    });
    
    // Gestion de l'aperçu des fichiers
    $('#documentsInput').change(function() {
        const files = this.files;
        const preview = $('#filePreview');
        preview.empty();
        
        const allowedTypes = ['pdf', 'jpg', 'jpeg', 'png'];
        const maxSize = 5 * 1024 * 1024; // 5MB
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const fileSize = file.size;
            const fileName = file.name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            const fileItem = $(`
                <div class="file-item">
                    <i class="fas fa-${getFileIcon(fileExt)}"></i>
                    <div class="file-name">${fileName}</div>
                    <div class="file-size">${formatFileSize(fileSize)}</div>
                </div>
            `);
            
            // Vérifier le type
            if (!allowedTypes.includes(fileExt)) {
                fileItem.append('<div class="file-error">Type non autorisé</div>');
                fileItem.find('.file-item').css('border-left', '3px solid var(--danger-color)');
            }
            
            // Vérifier la taille
            if (fileSize > maxSize) {
                fileItem.append('<div class="file-error">Fichier trop volumineux</div>');
                fileItem.find('.file-item').css('border-left', '3px solid var(--danger-color)');
            }
            
            preview.append(fileItem);
        }
    });
    
    function getFileIcon(ext) {
        switch(ext) {
            case 'pdf': return 'file-pdf';
            case 'jpg':
            case 'jpeg':
            case 'png': return 'file-image';
            default: return 'file';
        }
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
});
</script>

<?php include '../includes/footer.php'; ?>
