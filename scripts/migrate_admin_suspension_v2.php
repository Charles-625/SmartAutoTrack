<?php
/**
 * Ajoute la valeur 'SUSPENDU' aux ENUM de statut de `garage.statutGarage` et
 * `technicien.statutValidation`, pour permettre à l'administrateur de
 * suspendre un garage ou un technicien sans supprimer son compte ni son
 * historique (demande explicite du cahier des charges Admin).
 *
 * Additif et non destructif : élargit un ENUM existant (ajoute une valeur,
 * n'en retire ni n'en modifie aucune) — toutes les lignes existantes
 * ('EN_ATTENTE'/'VALIDE'/'REJETE') restent valides et inchangées.
 *
 * isAccountUsable() (config/roles.php) n'a besoin d'aucune modification :
 * elle n'autorise déjà la connexion que pour le statut 'VALIDE' — un compte
 * 'SUSPENDU' est donc automatiquement bloqué à la connexion, exactement
 * comme demandé, sans code supplémentaire.
 */
require __DIR__ . '/../config/database.php';
$conn = (new Database())->getConnection();

function enumHasValue(PDO $conn, string $table, string $column, string $value): bool {
    $col = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
    return $col && stripos($col['Type'], "'$value'") !== false;
}

if (!enumHasValue($conn, 'garage', 'statutGarage', 'SUSPENDU')) {
    $conn->exec("ALTER TABLE garage MODIFY statutGarage ENUM('EN_ATTENTE','VALIDE','REJETE','SUSPENDU') NOT NULL DEFAULT 'EN_ATTENTE'");
    echo "OK — garage.statutGarage : SUSPENDU ajouté.\n";
} else {
    echo "garage.statutGarage a déjà SUSPENDU, rien à faire.\n";
}

if (!enumHasValue($conn, 'technicien', 'statutValidation', 'SUSPENDU')) {
    $conn->exec("ALTER TABLE technicien MODIFY statutValidation ENUM('EN_ATTENTE','VALIDE','REJETE','SUSPENDU') NOT NULL DEFAULT 'EN_ATTENTE'");
    echo "OK — technicien.statutValidation : SUSPENDU ajouté.\n";
} else {
    echo "technicien.statutValidation a déjà SUSPENDU, rien à faire.\n";
}

echo $conn->query("SHOW COLUMNS FROM garage LIKE 'statutGarage'")->fetch()['Type'] . "\n";
echo $conn->query("SHOW COLUMNS FROM technicien LIKE 'statutValidation'")->fetch()['Type'] . "\n";
