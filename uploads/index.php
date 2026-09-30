<?php
/**
 * Garde-fou du dossier de téléversement : une requête directe sur le dossier
 * reçoit un 403 au lieu d'un listing des fichiers. Les documents ne sont
 * servis qu'à travers des scripts qui contrôlent les droits
 * (ajax/download_document.php, ajax/download_garage_document.php).
 */
// Interdire l'accès direct au dossier uploads
header('HTTP/1.0 403 Forbidden');
exit('Accès interdit');
?>
