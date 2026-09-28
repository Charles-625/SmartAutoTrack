<?php
/**
 * Ajoute le lien manquant entre `garage` et `utilisateur`.
 *
 * Constat : la table `garage` (idGarage, nomGarage, adresse, statutGarage)
 * n'a aucune colonne de connexion — impossible aujourd'hui pour "un garage"
 * de se connecter en tant que tel (seuls ses techniciens ont un compte).
 * Le dashboard Garage demandé nécessite un acteur qui se connecte et gère
 * SON garage : on ajoute donc idUtilisateur (nullable, UNIQUE) sur `garage`,
 * exactement le même schéma de lien que `entreprise.idClient` → `utilisateur`.
 *
 * Additif, non destructif : 0 ligne existante dans `garage` au moment de ce
 * script, aucune contrainte existante touchée, ON DELETE SET NULL (si le
 * compte de connexion est supprimé, la fiche garage métier reste).
 */
require __DIR__ . '/../config/database.php';
$conn = (new Database())->getConnection();

$col = $conn->query("SHOW COLUMNS FROM garage LIKE 'idUtilisateur'")->fetch();
if ($col) {
    echo "Colonne idUtilisateur déjà présente sur garage, rien à faire.\n";
    exit;
}

$conn->exec("ALTER TABLE garage ADD COLUMN idUtilisateur INT NULL UNIQUE AFTER idGarage");
$conn->exec("ALTER TABLE garage ADD CONSTRAINT fk_garage_utilisateur FOREIGN KEY (idUtilisateur) REFERENCES utilisateur(idUtilisateur) ON DELETE SET NULL ON UPDATE CASCADE");

echo "OK — garage.idUtilisateur ajouté (nullable, UNIQUE, FK vers utilisateur).\n";
echo $conn->query("SHOW CREATE TABLE garage")->fetch()['Create Table'] . "\n";
