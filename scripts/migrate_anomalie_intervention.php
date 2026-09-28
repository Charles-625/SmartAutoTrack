<?php
/**
 * Ajoute anomalie.idIntervention (nullable, FK vers intervention).
 *
 * Constat en testant le dashboard Garage : sans cette colonne, l'affichage
 * "intervention concernée" d'une anomalie ne pouvait être qu'un rattachement
 * "au mieux" par proximité de date (la plus récente intervention du même
 * véhicule à ou avant la date de détection) — et ce rattachement s'est avéré
 * concrètement FAUX dès que deux interventions proches dans le temps
 * existent sur le même véhicule (cas réel constaté en test). Le formulaire
 * de constat d'anomalie du garage capture déjà explicitement l'intervention
 * choisie ; cette colonne permet de la stocker et de l'afficher correctement
 * au lieu de la redeviner.
 *
 * Additif, non destructif : 0 ligne existante dans `anomalie` au moment de
 * ce script (vérifié), aucune contrainte existante touchée, ON DELETE SET
 * NULL (si l'intervention est supprimée, l'anomalie reste, orpheline).
 */
require __DIR__ . '/../config/database.php';
$conn = (new Database())->getConnection();

$col = $conn->query("SHOW COLUMNS FROM anomalie LIKE 'idIntervention'")->fetch();
if ($col) {
    echo "Colonne idIntervention déjà présente sur anomalie, rien à faire.\n";
    exit;
}

$conn->exec("ALTER TABLE anomalie ADD COLUMN idIntervention INT NULL AFTER idVehicule");
$conn->exec("ALTER TABLE anomalie ADD CONSTRAINT fk_anomalie_intervention FOREIGN KEY (idIntervention) REFERENCES intervention(idIntervention) ON DELETE SET NULL ON UPDATE CASCADE");

echo "OK — anomalie.idIntervention ajouté (nullable, FK vers intervention).\n";
echo $conn->query("SHOW CREATE TABLE anomalie")->fetch()['Create Table'] . "\n";
