<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Espace garage — Paramètres du compte et documents administratifs.
 *
 * Accès : rôle « garage ».
 * Actions (POST, jeton CSRF obligatoire) :
 *   - action=change_theme : thème clair/sombre (session + utilisateur.themePreference).
 *   - action=upload_document : dépôt d'un document (PDF, JPG ou PNG, 5 Mo max),
 *     enregistré EN_ATTENTE de vérification par l'admin.
 *   - action=delete_document : retrait d'un document encore EN_ATTENTE.
 * Tables : utilisateur, documentgarage (écriture), intervention (lecture),
 *          journalactivites (via log_activity()).
 * Fichiers : uploads/garages/ (jamais servi directement, téléchargement via
 *            ajax/download_garage_document.php) ; vérification côté admin
 *            dans admin/garage_detail.php.
 */

requireRole('garage');

$db = new Database();
$conn = $db->getConnection();

$profile = getUserProfile($conn, (int)$_SESSION['user_id']);
$garageId = (int)($profile['idGarage'] ?? 0);
$garageNom = $profile['nomGarage'] ?? 'Garage';

$success = '';
$errors = [];

// Documents administratifs du garage (assurance, Kbis, certification...) —
// symétrique de technician_documents (auth/register.php), mais avec un vrai
// statut de vérification (EN_ATTENTE/VALIDE/REJETE) traité par l'admin,
// cf. admin/garage_detail.php.
$docTypes = [
    'assurance' => 'Attestation d\'assurance',
    'kbis' => 'Extrait Kbis / immatriculation',
    'certification' => 'Certification professionnelle',
    'autre' => 'Autre document',
];

// Action : changer le thème d'affichage (session + préférence en base).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_theme') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $theme = sanitize($_POST['theme'] ?? '');
        if (in_array($theme, ['light', 'dark'], true)) {
            $_SESSION['theme'] = $theme;
            $conn->prepare("UPDATE utilisateur SET themePreference = ? WHERE idUtilisateur = ?")->execute([$theme, $_SESSION['user_id']]);
            $success = 'Thème mis à jour avec succès !';
        }
    }
}

// Action : déposer un document administratif (PDF, JPG ou PNG, 5 Mo max).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $docType = $_POST['type_document'] ?? '';
        if (!isset($docTypes[$docType])) {
            $errors[] = 'Type de document invalide.';
        } elseif (empty($_FILES['document']['name'])) {
            $errors[] = 'Merci de sélectionner un fichier.';
        } else {
            $file = $_FILES['document'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Erreur lors du téléversement du fichier.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Fichier trop volumineux. Taille max : 5 Mo.';
            } else {
                // Type réel vérifié depuis le contenu (jamais depuis le nom/l'extension).
                $allowedMimes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = is_uploaded_file($file['tmp_name']) ? $finfo->file($file['tmp_name']) : false;
                if (!$mime || !isset($allowedMimes[$mime])) {
                    $errors[] = 'Type de fichier non autorisé. Formats acceptés : PDF, JPG, PNG.';
                } else {
                    $uploadDir = UPLOAD_PATH . 'garages/';
                    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

                    // Nom de fichier aléatoire (non prédictible) : le fichier n'est de
                    // toute façon jamais servi directement (uploads/.htaccess bloque
                    // tout accès direct), seulement via ajax/download_garage_document.php.
                    $newFileName = $garageId . '_' . bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFileName)) {
                        $conn->prepare("INSERT INTO documentgarage (idGarage, typeDocument, statutVerification, fichier) VALUES (?, ?, 'EN_ATTENTE', ?)")
                            ->execute([$garageId, $docType, 'uploads/garages/' . $newFileName]);

                        log_activity($conn, 'Document déposé par le garage', [
                            'idUtilisateur' => $_SESSION['user_id'] ?? null,
                            'idGarage' => $garageId,
                            'description' => $docTypes[$docType] . ' déposé, en attente de vérification',
                            'categorie' => 'garage',
                        ]);

                        header("Location: parametres.php?success=uploaded");
                        exit;
                    } else {
                        $errors[] = 'Erreur lors de l\'enregistrement du fichier.';
                    }
                }
            }
        }
    }
}

// Retirer un document déposé par erreur — uniquement tant qu'il n'a pas
// encore été vérifié par un administrateur (au-delà, l'historique est
// conservé, comme partout ailleurs dans l'application).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_document') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, merci de réessayer.';
    } else {
        $docId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$docId) {
            $errors[] = 'Document invalide.';
        } else {
            $stmt = $conn->prepare("SELECT fichier FROM documentgarage WHERE idDocument = ? AND idGarage = ? AND statutVerification = 'EN_ATTENTE'");
            $stmt->execute([$docId, $garageId]);
            $doc = $stmt->fetch();
            if ($doc) {
                $conn->prepare("DELETE FROM documentgarage WHERE idDocument = ? AND idGarage = ?")->execute([$docId, $garageId]);
                // Le fichier n'est supprimé que s'il se trouve bien sous uploads/garages/ (protection contre un chemin détourné en base).
                $filePath = realpath(__DIR__ . '/../' . $doc['fichier']);
                $baseDir = realpath(UPLOAD_PATH . 'garages');
                if ($baseDir !== false && $filePath !== false && strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) === 0 && is_file($filePath)) {
                    @unlink($filePath);
                }
                header("Location: parametres.php?success=doc_deleted");
                exit;
            } else {
                $errors[] = 'Document introuvable, ou déjà vérifié par un administrateur.';
            }
        }
    }
}

$stmt = $conn->prepare("SELECT themePreference FROM utilisateur WHERE idUtilisateur = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentTheme = $stmt->fetchColumn() ?: 'light';

// Compteur du badge « Demandes d'intervention » de la sidebar.
$stmt = $conn->prepare("SELECT COUNT(*) FROM intervention WHERE idGarage = ? AND idTechnicien IS NULL AND statut = 'PLANIFIEE'");
$stmt->execute([$garageId]);
$demandesEnAttente = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("SELECT idDocument, typeDocument, statutVerification FROM documentgarage WHERE idGarage = ? ORDER BY idDocument DESC");
$stmt->execute([$garageId]);
$documents = $stmt->fetchAll();
$docStatusLabels = ['EN_ATTENTE' => ['En attente', 'warn'], 'VALIDE' => ['Validé', 'ok'], 'REJETE' => ['Rejeté', 'bad']];

$pageTitle = 'Paramètres';
$hideNavbar = true;
$bodyClass = 'gv2';
$extraStylesheets = ['assets/css/garage_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="gv2-shell">
    <?php $activeNav = 'parametres'; $pendingBadge = $demandesEnAttente; include 'includes/sidebar.php'; ?>

    <main class="gv2-main">
        <div class="gv2-page-head">
            <div>
                <h1 class="gv2-h1">Paramètres</h1>
                <p class="gv2-sub">Préférences d'affichage de votre espace garage.</p>
            </div>
        </div>

        <?php if ($success): ?><div class="gv2-alert success"><?php echo h($success); ?></div><?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="gv2-alert success">
                <?php
                $successMsg = ['uploaded' => 'Document envoyé avec succès, en attente de vérification.', 'doc_deleted' => 'Document retiré.'];
                echo h($successMsg[$_GET['success']] ?? 'Action effectuée.');
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="gv2-alert error"><?php echo h($e); ?></div><?php endforeach; ?>

        <div class="gv2-card gv2-panel" style="max-width:520px;">
            <div class="gv2-panel-head"><h2>Apparence</h2></div>
            <form method="POST">
                <input type="hidden" name="action" value="change_theme">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="gv2-form-group">
                    <label for="themeSelect">Thème</label>
                    <select name="theme" id="themeSelect">
                        <option value="light" <?php echo $currentTheme === 'light' ? 'selected' : ''; ?>>Clair</option>
                        <option value="dark" <?php echo $currentTheme === 'dark' ? 'selected' : ''; ?>>Sombre</option>
                    </select>
                </div>
                <button type="submit" class="gv2-btn-primary">Appliquer</button>
            </form>
        </div>

        <div class="gv2-card gv2-panel" style="max-width:520px;">
            <div class="gv2-panel-head"><h2>Compte</h2></div>
            <p style="color:#666C8E; font-size:14px; line-height:1.6; margin:0 0 14px;">
                Pour modifier vos informations de contact ou votre mot de passe, rendez-vous sur votre
                <a href="<?php echo SITE_URL; ?>profile.php" style="color:#3956E8; font-weight:600;">page de profil</a>.
            </p>
            <a href="<?php echo SITE_URL; ?>profile.php" class="gv2-btn-outline" style="text-decoration:none; display:inline-block;">Aller à mon profil</a>
        </div>

        <div class="gv2-card gv2-panel" style="max-width:520px;">
            <div class="gv2-panel-head"><h2>Documents du garage</h2></div>
            <p style="color:#666C8E; font-size:14px; line-height:1.6; margin:0 0 14px;">
                Attestation d'assurance, extrait Kbis, certifications... Chaque document est vérifié par un administrateur après envoi.
            </p>

            <?php if (empty($documents)): ?>
                <div class="gv2-empty">Aucun document envoyé pour l'instant.</div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:18px;">
                    <?php foreach ($documents as $doc): $dst = $docStatusLabels[$doc['statutVerification']] ?? ['—', 'neutral']; ?>
                        <div class="gv2-row">
                            <div style="flex-grow:1; min-width:0;">
                                <div class="gv2-row-title"><?php echo h($docTypes[$doc['typeDocument']] ?? $doc['typeDocument']); ?></div>
                            </div>
                            <span class="gv2-badge <?php echo h($dst[1]); ?>"><?php echo h($dst[0]); ?></span>
                            <a href="<?php echo SITE_URL; ?>ajax/download_garage_document.php?id=<?php echo (int)$doc['idDocument']; ?>" target="_blank" class="gv2-table-link" style="margin-left:12px;">Voir</a>
                            <?php if ($doc['statutVerification'] === 'EN_ATTENTE'): ?>
                                <form method="POST" action="parametres.php" style="display:inline; margin-left:12px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="delete_document">
                                    <input type="hidden" name="document_id" value="<?php echo (int)$doc['idDocument']; ?>">
                                    <button type="submit" class="gv2-table-link gv2-link-btn btn-confirm" data-confirm="Retirer ce document ?" style="color:#C0392B;">Retirer</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_document">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="gv2-form-group">
                    <label for="typeDocumentSelect">Type de document</label>
                    <select name="type_document" id="typeDocumentSelect">
                        <?php foreach ($docTypes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="gv2-form-group">
                    <label for="documentFile">Fichier (PDF, JPG ou PNG — 5 Mo max)</label>
                    <input type="file" name="document" id="documentFile" accept=".pdf,.jpg,.jpeg,.png" required>
                </div>
                <button type="submit" class="gv2-btn-primary">Envoyer le document</button>
            </form>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
