<?php
/**
 * ============================================================================
 * OBSOLÈTE depuis le 23/09/2026 : la base `charles` a été remplacée par un
 * nouveau schéma (utilisateur/client/technicien/vehicule/intervention/...,
 * camelCase). Ce script visait l'ANCIEN schéma (users/vehicles/interventions,
 * snake_case) et n'a plus d'effet utile — NE PLUS L'EXÉCUTER.
 * Pour la base actuelle, voir scripts/migrate_legacy_data.php.
 * Conservé uniquement à titre d'historique.
 * ============================================================================
 *
 * Met à niveau une base existante vers le schéma final (sql/schema.sql).
 * Idempotent : on peut le relancer sans risque, quel que soit l'état de la base
 * (base d'origine db_init.sql, partiellement migrée, ou déjà à jour).
 * Ne supprime aucune colonne, table ni donnée.
 *
 *   php scripts/migrate_schema.php            simulation (n'écrit rien)
 *   php scripts/migrate_schema.php --apply    applique les changements
 *
 * Base ciblée : celle de config/local.php, ou HCH_DB_NAME.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';

$apply = in_array('--apply', $argv, true);
$conn = (new Database())->getConnection();
if (!$conn) {
    fwrite(STDERR, "Connexion à la base impossible (voir les logs PHP).\n");
    exit(1);
}
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
echo ($apply ? "APPLICATION" : "SIMULATION (aucune modification)") . " sur la base « $dbName »\n\n";

$changes = 0;
function step(PDO $conn, bool $apply, string $label, string $sql): void {
    global $changes;
    $changes++;
    echo ($apply ? "  [fait] " : "  [à faire] ") . $label . "\n";
    if ($apply) {
        $conn->exec($sql);
    }
}

function tableExists(PDO $c, string $t): bool {
    $s = $c->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$t]);
    return (bool)$s->fetchColumn();
}

function columnType(PDO $c, string $t, string $col): ?string {
    $s = $c->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $col]);
    $r = $s->fetchColumn();
    return $r === false ? null : $r;
}

function indexExists(PDO $c, string $t, string $idx): bool {
    $s = $c->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $s->execute([$t, $idx]);
    return (bool)$s->fetchColumn();
}

function fkExists(PDO $c, string $t, string $name): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $s->execute([$t, $name]);
    return (bool)$s->fetchColumn();
}

// 1) Tables manquantes : création via sql/schema.sql (CREATE TABLE IF NOT EXISTS)
echo "1. Tables\n";
$sqlText = file_get_contents(__DIR__ . '/../sql/schema.sql');
$sqlText = preg_replace('/--[^\r\n]*/', '', $sqlText);
$statements = array_filter(array_map('trim', explode(';', $sqlText)));
foreach ($statements as $stmt) {
    if (preg_match('/^CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $stmt, $m)) {
        if (!tableExists($conn, $m[1])) {
            step($conn, $apply, "créer la table {$m[1]}", $stmt);
        }
    }
}
if ($changes === 0) echo "  ok, toutes les tables existent\n";

// 2) Colonnes manquantes (ajout uniquement)
$columns = [
    // table, colonne, définition, position
    ['users',         'theme_preference',   "VARCHAR(20) DEFAULT 'light'",                                          'AFTER statut_validation'],
    ['interventions', 'priorite',           "ENUM('basse','moyenne','haute') NOT NULL DEFAULT 'moyenne'",           'AFTER description'],
    ['reparations',   'intervention_id',    'INT NULL',                                                             'AFTER technicien_id'],
    ['reparations',   'titre',              'VARCHAR(255)',                                                         'AFTER description'],
    ['reparations',   'diagnostic',         'TEXT',                                                                 'AFTER titre'],
    ['reparations',   'travaux_effectues',  'TEXT',                                                                 'AFTER diagnostic'],
    ['reparations',   'pieces_utilisees',   'TEXT',                                                                 'AFTER travaux_effectues'],
    ['reparations',   'recommandations',    'TEXT',                                                                 'AFTER pieces_utilisees'],
    ['reparations',   'duree_intervention', 'DECIMAL(5,2) DEFAULT 0',                                               'AFTER cout'],
    ['reparations',   'updated_at',         'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',      'AFTER created_at'],
];
echo "2. Colonnes\n";
$before = $changes;
foreach ($columns as [$table, $col, $def, $pos]) {
    if (!tableExists($conn, $table)) continue; // sera créée complète à l'étape 1
    if (columnType($conn, $table, $col) === null) {
        step($conn, $apply, "ajouter $table.$col", "ALTER TABLE `$table` ADD COLUMN `$col` $def $pos");
    }
}
if ($changes === $before) echo "  ok, aucune colonne manquante\n";

// 3) Valeurs d'ENUM : reparations.statut doit accepter tout ce que le code écrit/lit
echo "3. Valeurs de reparations.statut\n";
$wanted = "enum('planifiee','en_cours','terminee','validee','valide','en_attente')";
$current = tableExists($conn, 'reparations') ? columnType($conn, 'reparations', 'statut') : $wanted;
if ($current !== null && strtolower($current) !== $wanted) {
    step($conn, $apply, "élargir reparations.statut : $current -> $wanted",
        "ALTER TABLE reparations MODIFY COLUMN statut ENUM('planifiee','en_cours','terminee','validee','valide','en_attente') DEFAULT 'planifiee'");
} else {
    echo "  ok\n";
}

// 4) Index et clés étrangères
echo "4. Index et clés étrangères\n";
$before = $changes;
if (tableExists($conn, 'users') && !indexExists($conn, 'users', 'idx_users_theme') && columnType($conn, 'users', 'theme_preference') !== null) {
    step($conn, $apply, 'index users.idx_users_theme', 'CREATE INDEX idx_users_theme ON users(theme_preference)');
}
// (en simulation la colonne peut ne pas encore exister : elle sera ajoutée à l'étape 2)
$fkColumnReady = $apply ? columnType($conn, 'reparations', 'intervention_id') !== null : true;
if (tableExists($conn, 'reparations') && $fkColumnReady && !fkExists($conn, 'reparations', 'fk_reparations_intervention')) {
    step($conn, $apply, 'clé étrangère reparations.intervention_id -> interventions.id',
        'ALTER TABLE reparations ADD CONSTRAINT fk_reparations_intervention FOREIGN KEY (intervention_id) REFERENCES interventions(id) ON DELETE SET NULL');
}
if ($changes === $before) echo "  ok\n";

echo "\n" . ($changes === 0 ? "Base déjà conforme au schéma final." :
    ($apply ? "$changes modification(s) appliquée(s)." : "$changes modification(s) à appliquer. Relancez avec --apply.")) . "\n";
