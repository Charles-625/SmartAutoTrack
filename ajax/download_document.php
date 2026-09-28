<?php
/**
 * Téléchargement / affichage sécurisé d'un document de technicien.
 * Les fichiers de uploads/ ne sont plus servis directement par Apache :
 * ils passent par ce script qui vérifie la session et les droits.
 *
 * Usage : ajax/download_document.php?id=<id document>[&download=1]
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

    $stmt = $conn->prepare("SELECT id, technicien_id, nom_fichier, chemin_fichier FROM technician_documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();
} catch (Exception $e) {
    error_log('[SmartAutoTrack] download_document : ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur serveur');
}

// Droits : un admin, ou le technicien propriétaire du document. Réponse 404 dans tous
// les autres cas pour ne pas révéler l'existence du document.
$isAdmin = ($_SESSION['role'] === ROLE_ADMIN);
$isOwner = ($_SESSION['role'] === ROLE_TECHNICIEN && $doc && (int)$doc['technicien_id'] === (int)$_SESSION['user_id']);
if (!$doc || !($isAdmin || $isOwner)) {
    http_response_code(404);
    exit('Document introuvable');
}

// Le chemin en base est relatif ; on ne sert que les fichiers réellement situés dans uploads/techniciens/
$baseDir = realpath(UPLOAD_PATH . 'techniciens');
$file = realpath(__DIR__ . '/../' . $doc['chemin_fichier']);
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

$download = !empty($_GET['download']);
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($doc['nom_fichier'], PATHINFO_FILENAME));
$safeName = trim($safeName, '._-') ?: 'document';
$safeName .= '.' . $allowedMime[$mime];

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');

readfile($file);
exit;
