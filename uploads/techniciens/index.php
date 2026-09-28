<?php
// Interdire l'accès direct au dossier uploads/techniciens
header('HTTP/1.0 403 Forbidden');
exit('Accès interdit');
?>
