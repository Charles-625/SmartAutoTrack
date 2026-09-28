<?php
/**
 * Téléchargement / affichage sécurisé d'un document de garage.
 * Les fichiers de uploads/ ne sont jamais servis directement par Apache
 * (uploads/.htaccess bloque tout accès direct) : ils passent par ce script,
 * symétrique de ajax/download_document.php (documents technicien).
 *
 * Usage : ajax/download_garage_document.php?id=<id document>[&download=1]
 */
require_once '../config/config.php';
require_once '../config/database.php';

requireAuth();

$doc_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$doc_id) {
    http_response_code(400);
    exit('Document invalide');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT d.idDocument, d.idGarage, d.typeDocument, d.fichier, g.idUtilisateur
        FROM documentgarage d
        JOIN garage g ON g.idGarage = d.idGarage
        WHERE d.idDocument = ?
    ");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();
} catch (Exception $e) {
    error_log('[SmartAutoTrack] download_garage_document : ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur serveur');
}

// Droits : un admin, ou le garage propriétaire du document. Réponse 404 dans
// tous les autres cas pour ne pas révéler l'existence du document.
$isAdmin = ($_SESSION['role'] === ROLE_ADMIN);
$isOwner = ($_SESSION['role'] === ROLE_GARAGE && $doc && (int)$doc['idUtilisateur'] === (int)$_SESSION['user_id']);
if (!$doc || !($isAdmin || $isOwner)) {
    http_response_code(404);
    exit('Document introuvable');
}

// Le chemin en base est relatif ; on ne sert que les fichiers réellement situés dans uploads/garages/
$baseDir = realpath(UPLOAD_PATH . 'garages');
$file = realpath(__DIR__ . '/../' . $doc['fichier']);
if ($baseDir === false || $file === false || strpos($file, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Fichier introuvable');
}

// Type MIME déterminé à partir du contenu (jamais depuis le nom de fichier ni la base)
$allowedMime = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file);
if (!isset($allowedMime[$mime])) {
    http_response_code(415);
    exit('Type de fichier non autorisé');
}

// documentgarage ne conserve pas le nom de fichier d'origine (schéma minimal,
// symétrique mais plus simple que technician_documents) : le nom de
// téléchargement est dérivé du type de document.
$docTypeSlugs = ['assurance' => 'assurance', 'kbis' => 'kbis', 'certification' => 'certification', 'autre' => 'document'];
$download = !empty($_GET['download']);
$safeName = ($docTypeSlugs[$doc['typeDocument']] ?? 'document') . '.' . $allowedMime[$mime];

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');

readfile($file);
exit;
