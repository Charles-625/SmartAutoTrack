<?php
/**
 * Étend `journalactivites` pour supporter un vrai journal d'activité
 * multi-rôles (admin / garage / technicien / client), au lieu du seul usage
 * "garage" initial :
 *
 *  - idUtilisateur (NULL, FK -> utilisateur) : l'acteur ayant réalisé l'action
 *    (garage connecté, technicien, admin, client...). Absent jusqu'ici : le
 *    journal ne savait donc pas dire "qui" avait agi, seulement "sur quel
 *    technicien concerné" (idTechnicien, conservé tel quel pour cet usage).
 *  - idGarage (NULL, FK -> garage) : périmètre garage de l'action, pour un
 *    cloisonnement direct et fiable même quand l'action n'est pas rattachée
 *    à une intervention (ex. validation du garage par l'administrateur).
 *  - idReparation / idAnomalie (NULL, FK) : élément précis concerné quand
 *    l'action porte sur une réparation ou une anomalie particulière.
 *  - categorie : classification grossière de l'action (intervention,
 *    reparation, anomalie, technicien, garage, compte), pour permettre aux
 *    vues (notamment celle de l'administrateur) de filtrer sans dépendre du
 *    texte exact de nomActivite.
 *  - idIntervention devient nullable : certaines actions importantes
 *    (validation d'un garage, validation/rejet d'un technicien, suppression
 *    d'un compte client...) ne sont rattachées à aucune intervention.
 *
 * Additif et non destructif : les 10 lignes existantes (toutes créées cette
 * session, rattachées à une intervention) restent valides — idUtilisateur/
 * idGarage/idReparation/idAnomalie NULL, categorie par défaut 'intervention'.
 */
require __DIR__ . '/../config/database.php';
$conn = (new Database())->getConnection();

/**
 * Indique si une colonne existe déjà (chaque étape n'est appliquée qu'une fois).
 *
 * @param PDO    $conn   Connexion à la base.
 * @param string $table  Table concernée.
 * @param string $column Colonne recherchée.
 * @return bool true si la colonne existe.
 */
function columnExists(PDO $conn, string $table, string $column): bool {
    // Noms de table/colonne toujours fournis par le code (jamais une entrée
    // utilisateur) : interpolation directe sûre, cohérente avec les autres
    // scripts migrate_*.php du projet.
    return (bool)$conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
}

if (!columnExists($conn, 'journalactivites', 'idUtilisateur')) {
    $conn->exec("ALTER TABLE journalactivites ADD COLUMN idUtilisateur INT NULL AFTER idActivite");
    $conn->exec("ALTER TABLE journalactivites ADD CONSTRAINT fk_journal_utilisateur FOREIGN KEY (idUtilisateur) REFERENCES utilisateur(idUtilisateur) ON DELETE SET NULL ON UPDATE CASCADE");
    echo "OK — idUtilisateur ajouté.\n";
} else {
    echo "idUtilisateur déjà présent, rien à faire.\n";
}

if (!columnExists($conn, 'journalactivites', 'idGarage')) {
    $conn->exec("ALTER TABLE journalactivites ADD COLUMN idGarage INT NULL AFTER idUtilisateur");
    $conn->exec("ALTER TABLE journalactivites ADD CONSTRAINT fk_journal_garage FOREIGN KEY (idGarage) REFERENCES garage(idGarage) ON DELETE SET NULL ON UPDATE CASCADE");
    echo "OK — idGarage ajouté.\n";
} else {
    echo "idGarage déjà présent, rien à faire.\n";
}

if (!columnExists($conn, 'journalactivites', 'idReparation')) {
    $conn->exec("ALTER TABLE journalactivites ADD COLUMN idReparation INT NULL AFTER idIntervention");
    $conn->exec("ALTER TABLE journalactivites ADD CONSTRAINT fk_journal_reparation FOREIGN KEY (idReparation) REFERENCES reparation(idReparation) ON DELETE SET NULL ON UPDATE CASCADE");
    echo "OK — idReparation ajouté.\n";
} else {
    echo "idReparation déjà présent, rien à faire.\n";
}

if (!columnExists($conn, 'journalactivites', 'idAnomalie')) {
    $conn->exec("ALTER TABLE journalactivites ADD COLUMN idAnomalie INT NULL AFTER idReparation");
    $conn->exec("ALTER TABLE journalactivites ADD CONSTRAINT fk_journal_anomalie FOREIGN KEY (idAnomalie) REFERENCES anomalie(idAnomalie) ON DELETE SET NULL ON UPDATE CASCADE");
    echo "OK — idAnomalie ajouté.\n";
} else {
    echo "idAnomalie déjà présent, rien à faire.\n";
}

if (!columnExists($conn, 'journalactivites', 'categorie')) {
    $conn->exec("ALTER TABLE journalactivites ADD COLUMN categorie ENUM('intervention','reparation','anomalie','technicien','garage','compte') NOT NULL DEFAULT 'intervention' AFTER idAnomalie");
    echo "OK — categorie ajoutée (rétro-remplie à 'intervention' pour les lignes existantes).\n";
} else {
    echo "categorie déjà présente, rien à faire.\n";
}

// idIntervention : le rendre nullable (certaines actions importantes n'en ont pas).
$col = $conn->query("SHOW COLUMNS FROM journalactivites LIKE 'idIntervention'")->fetch();
if ($col && stripos($col['Null'], 'NO') === 0) {
    $conn->exec("ALTER TABLE journalactivites MODIFY idIntervention INT NULL");
    echo "OK — idIntervention rendu nullable.\n";
} else {
    echo "idIntervention déjà nullable, rien à faire.\n";
}

// Rétro-remplissage : les lignes créées avant l'ajout de idGarage n'ont pas
// cette colonne renseignée alors qu'elles sont bien rattachées à une
// intervention elle-même rattachée à un garage. Sans ce rattrapage, ces
// lignes disparaîtraient du journal du garage (désormais filtré strictement
// sur idGarage) alors qu'elles lui appartiennent bien. Idempotent : ne
// touche que les lignes où idGarage est encore NULL.
$updated = $conn->exec("
    UPDATE journalactivites j
    JOIN intervention i ON i.idIntervention = j.idIntervention
    SET j.idGarage = i.idGarage
    WHERE j.idGarage IS NULL AND i.idGarage IS NOT NULL
");
echo "OK — idGarage rétro-rempli pour $updated ligne(s) existante(s) via leur intervention.\n";

echo $conn->query("SHOW CREATE TABLE journalactivites")->fetch()['Create Table'] . "\n";
