<?php
/**
 * Complète le schéma de `charles` (utilisateur/vehicule/intervention/...,
 * créé le 23/09/2026) avec les colonnes dont le site PHP a besoin, et crée
 * les 3 tables sans équivalent dans ce schéma (messages, notifications,
 * technician_documents), rattachées à lui par clé étrangère.
 *
 * Idempotent : peut être relancé sans effet si tout est déjà en place.
 * Ne touche à aucune donnée.
 *
 *   php scripts/migrate_structure.php            simulation (aucune écriture)
 *   php scripts/migrate_structure.php --apply    applique
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv, true);
$conn = (new Database())->getConnection();
if (!$conn) {
    fwrite(STDERR, "Connexion impossible (voir les logs PHP).\n");
    exit(1);
}
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
echo ($apply ? "APPLICATION" : "SIMULATION (aucune écriture)") . " sur « $dbName »\n\n";

function colExists(PDO $c, string $t, string $col): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $col]);
    return (bool)$s->fetchColumn();
}
function tableExists(PDO $c, string $t): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$t]);
    return (bool)$s->fetchColumn();
}

$n = 0;
function announce(bool $apply, string $label): void {
    global $n;
    $n++;
    echo ($apply ? "  [fait] " : "  [à faire] ") . $label . "\n";
}

// ============================================================
// 1) Colonnes manquantes sur les tables du schéma
// ============================================================
echo "1. Colonnes\n";
$before = $n;
$columns = [
    ['technicien', 'statutValidation', "ENUM('EN_ATTENTE','VALIDE','REJETE') NOT NULL DEFAULT 'EN_ATTENTE'"],
    ['technicien', 'competences', 'TEXT NULL'],
    ['technicien', 'experience', 'TEXT NULL'],
    ['utilisateur', 'themePreference', "VARCHAR(20) NOT NULL DEFAULT 'light'"],
    ['utilisateur', 'photoProfil', 'VARCHAR(255) NULL'],
    ['vehicule', 'kilometrage', 'INT NOT NULL DEFAULT 0'],
    ['intervention', 'priorite', "ENUM('BASSE','MOYENNE','HAUTE') NOT NULL DEFAULT 'MOYENNE'"],
    ['reparation', 'titre', 'VARCHAR(255) NULL'],
    ['reparation', 'diagnostic', 'TEXT NULL'],
    ['reparation', 'travauxEffectues', 'TEXT NULL'],
    ['reparation', 'piecesUtilisees', 'TEXT NULL'],
    ['reparation', 'recommandations', 'TEXT NULL'],
    ['reparation', 'dureeIntervention', 'DECIMAL(5,2) NOT NULL DEFAULT 0'],
    ['reparation', 'dateDebut', 'DATETIME NULL'],
    ['reparation', 'dateFin', 'DATETIME NULL'],
    ['utilisateur', 'dateCreation', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    ['anomalie', 'type', 'VARCHAR(100) NULL'],
    ['anomalie', 'niveau', "ENUM('FAIBLE','MOYEN','CRITIQUE') NOT NULL DEFAULT 'MOYEN'"],
    ['anomalie', 'dateResolution', 'DATETIME NULL'],
    ['vehicule', 'dateCreation', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    // dateAcquisition existe déjà mais a un autre sens (date d'achat) ; annee (millésime)
    // est utilisée telle quelle par le formulaire véhicule existant.
    ['vehicule', 'annee', 'INT NULL'],
];
foreach ($columns as [$t, $c, $def]) {
    if (!colExists($conn, $t, $c)) {
        announce($apply, "ajouter $t.$c");
        if ($apply) $conn->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
}
if ($n === $before) echo "  ok, aucune colonne manquante\n";

// ============================================================
// 2) Tables sans équivalent dans le schéma (reprises de l'ancien)
// ============================================================
echo "\n2. Tables\n";
$before = $n;
if (!tableExists($conn, 'messages')) {
    announce($apply, 'créer la table messages');
    if ($apply) $conn->exec("CREATE TABLE messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expediteur_id INT NOT NULL,
        destinataire_id INT NOT NULL,
        sujet VARCHAR(255) NOT NULL,
        contenu TEXT NOT NULL,
        lu ENUM('oui','non') NOT NULL DEFAULT 'non',
        date_envoi TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_messages_expediteur FOREIGN KEY (expediteur_id) REFERENCES utilisateur(idUtilisateur) ON DELETE CASCADE,
        CONSTRAINT fk_messages_destinataire FOREIGN KEY (destinataire_id) REFERENCES utilisateur(idUtilisateur) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if (!tableExists($conn, 'notifications')) {
    announce($apply, 'créer la table notifications');
    if ($apply) $conn->exec("CREATE TABLE notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('anomalie','intervention','message','rapport','validation') NOT NULL,
        titre VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        lu ENUM('oui','non') NOT NULL DEFAULT 'non',
        date_creation TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES utilisateur(idUtilisateur) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if (!tableExists($conn, 'technician_documents')) {
    announce($apply, 'créer la table technician_documents');
    if ($apply) $conn->exec("CREATE TABLE technician_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        technicien_id INT NOT NULL,
        type_document ENUM('diplome','certificat','photo','autre') NOT NULL,
        nom_fichier VARCHAR(255) NOT NULL,
        chemin_fichier VARCHAR(500) NOT NULL,
        taille_fichier INT NULL,
        uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_documents_technicien FOREIGN KEY (technicien_id) REFERENCES technicien(idTechnicien) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if ($n === $before) echo "  ok, les 3 tables existent déjà\n";

echo "\n" . ($n === 0 ? "Rien à faire, tout est déjà en place." :
    ($apply ? "$n action(s) appliquée(s)." : "$n action(s) à appliquer. Relancez avec --apply.")) . "\n";
