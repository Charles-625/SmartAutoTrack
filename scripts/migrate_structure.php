<?php
/**
 * Complète le schéma de `charles` (utilisateur/vehicule/intervention/...,
 * créé le 23/09/2026) avec les colonnes dont le site PHP a besoin, et crée
 * les 3 tables sans équivalent dans ce schéma (messages, notifications,
 * technician_documents), rattachées à lui par clé étrangère. Prépare aussi
 * la table `paiement` pour le paiement Mobile Money CamPay, puis les
 * abonnements Premium (includes/subscription.php) : tables `abonnement` et
 * `ia_usage`, paiement.idIntervention rendu facultatif (clé étrangère
 * conservée) et colonne paiement.idAbonnement. Enfin, le rapport de fin
 * d'intervention (includes/repair_report.php) : colonnes
 * reparation.kilometrage et reparation.etatVehicule. Puis les pastilles
 * « nouveautés » de la sidebar (includes/activity_log.php) : table
 * `onglet_vu` (dernière ouverture des onglets Journal et Interventions par
 * utilisateur) et index sur journalactivites.dateHeure. Et le mot de passe
 * oublié (Brevo) et la connexion Google : table `password_resets`, colonne
 * utilisateur.googleId et son index. Enfin, les échéances et rappels
 * d'entretien (includes/maintenance.php) : colonnes
 * vehicule.dateExpirationAssurance et vehicule.dateProchaineVisiteTechnique,
 * tables `entretien`, `entretien_regle` (avec ses 3 règles par défaut) et
 * `rappel_envoye`.
 *
 * Idempotent : peut être relancé sans effet si tout est déjà en place.
 * Ne touche à aucune donnée existante (seule écriture de données : les
 * règles d'entretien par défaut, insérées si absentes).
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

/**
 * Indique si une colonne existe dans la base courante (via information_schema).
 *
 * @param PDO    $c   Connexion à la base.
 * @param string $t   Table.
 * @param string $col Colonne.
 * @return bool
 */
function colExists(PDO $c, string $t, string $col): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $col]);
    return (bool)$s->fetchColumn();
}
/**
 * Indique si une table existe dans la base courante.
 *
 * @param PDO    $c Connexion à la base.
 * @param string $t Table.
 * @return bool
 */
function tableExists(PDO $c, string $t): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$t]);
    return (bool)$s->fetchColumn();
}

// Compteur global des actions (faites ou à faire), utilisé pour le bilan final.
$n = 0;
/**
 * Affiche une action ([fait] en --apply, [à faire] en simulation) et
 * incrémente le compteur global $n.
 *
 * @param bool   $apply Mode application (true) ou simulation (false).
 * @param string $label Description lisible de l'action.
 * @return void
 */
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
    ['utilisateur', 'googleId', 'VARCHAR(100) NULL'],
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

// ============================================================
// 3) Paiement Mobile Money (CamPay)
// ============================================================
echo "\n3. Paiement CamPay\n";
$before = $n;

/**
 * Renvoie la description d'une colonne (type, nullabilité, défaut, longueur),
 * nécessaire pour modifier un ENUM sans perdre ses autres attributs.
 *
 * @param PDO    $c   Connexion à la base.
 * @param string $t   Table.
 * @param string $col Colonne.
 * @return array|null Ligne information_schema.COLUMNS, ou null si absente.
 */
function columnInfo(PDO $c, string $t, string $col): ?array {
    $s = $c->prepare("SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $col]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
/**
 * Indique si un index (y compris UNIQUE) existe sur une table.
 *
 * @param PDO    $c     Connexion à la base.
 * @param string $t     Table.
 * @param string $index Nom de l'index.
 * @return bool
 */
function indexExists(PDO $c, string $t, string $index): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->execute([$t, $index]);
    return (bool)$s->fetchColumn();
}
/**
 * Ajoute des valeurs à une colonne ENUM existante, en conservant nullabilité et défaut.
 *
 * Les valeurs déjà présentes sont gardées dans leur ordre d'origine et les
 * nouvelles ajoutées à la fin : les lignes existantes restent valides. Ne fait
 * rien si la colonne n'existe pas ou n'est pas un ENUM (ex. typePaiement en
 * VARCHAR, traité à part plus bas).
 *
 * @param PDO      $c      Connexion à la base.
 * @param bool     $apply  Mode application (true) ou simulation (false).
 * @param string   $t      Table.
 * @param string   $col    Colonne ENUM.
 * @param string[] $values Valeurs qui doivent être acceptées.
 * @return void Annonce l'action (compteur $n) et, en --apply, exécute l'ALTER TABLE.
 */
function ensureEnumValues(PDO $c, bool $apply, string $t, string $col, array $values): void {
    $info = columnInfo($c, $t, $col);
    if (!$info || strtolower($info['DATA_TYPE']) !== 'enum') return;
    preg_match_all("/'((?:[^']|'')*)'/", $info['COLUMN_TYPE'], $m);
    $existing = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
    $missing = array_values(array_diff($values, $existing));
    if (!$missing) return;
    announce($apply, "ajouter " . implode(', ', $missing) . " aux valeurs de $t.$col");
    if (!$apply) return;
    $all = array_merge($existing, $missing);
    $enum = "ENUM(" . implode(',', array_map(fn($v) => $c->quote($v), $all)) . ")";
    $default = $info['COLUMN_DEFAULT'];
    $nullable = $info['IS_NULLABLE'] === 'YES';
    $sql = "ALTER TABLE `$t` MODIFY `$col` $enum " . ($nullable ? 'NULL' : 'NOT NULL');
    if ($default !== null && strtoupper($default) !== 'NULL') {
        $sql .= ' DEFAULT ' . $c->quote(trim($default, "'"));
    } elseif ($nullable) {
        $sql .= ' DEFAULT NULL';
    }
    $c->exec($sql);
}

if (!tableExists($conn, 'paiement')) {
    announce($apply, 'créer la table paiement');
    if ($apply) $conn->exec("CREATE TABLE paiement (
        idPaiement INT AUTO_INCREMENT PRIMARY KEY,
        idClient INT NOT NULL,
        idIntervention INT NULL,
        montant DECIMAL(10,2) NOT NULL,
        datePaiement DATETIME NULL,
        typePaiement VARCHAR(30) NULL,
        statut ENUM('EN_ATTENTE','PAYE','ECHOUE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE',
        CONSTRAINT fk_paiement_client FOREIGN KEY (idClient) REFERENCES client(idClient) ON DELETE RESTRICT,
        CONSTRAINT fk_paiement_intervention FOREIGN KEY (idIntervention) REFERENCES intervention(idIntervention) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Colonnes, valeurs d'ENUM et index propres au flux CamPay (références
// externe et CamPay, téléphone, opérateur), utilisés par includes/payments.php
// et webhooks/campay.php.
if (tableExists($conn, 'paiement')) {
    $paiementColumns = [
        ['idReparation', 'INT NULL'],
        ['referenceExterne', 'VARCHAR(64) NULL'],
        ['referenceCampay', 'VARCHAR(100) NULL'],
        ['telephone', 'VARCHAR(20) NULL'],
        ['operateur', 'VARCHAR(30) NULL'],
        ['messageErreur', 'VARCHAR(255) NULL'],
    ];
    foreach ($paiementColumns as [$c, $def]) {
        if (!colExists($conn, 'paiement', $c)) {
            announce($apply, "ajouter paiement.$c");
            if ($apply) $conn->exec("ALTER TABLE paiement ADD COLUMN `$c` $def");
        }
    }

    ensureEnumValues($conn, $apply, 'paiement', 'statut', ['EN_ATTENTE', 'PAYE', 'ECHOUE', 'ANNULE']);
    ensureEnumValues($conn, $apply, 'paiement', 'typePaiement', ['MOBILE_MONEY']);
    $type = columnInfo($conn, 'paiement', 'typePaiement');
    if ($type && in_array(strtolower($type['DATA_TYPE']), ['varchar', 'char'], true) && (int)$type['CHARACTER_MAXIMUM_LENGTH'] < 12) {
        announce($apply, 'agrandir paiement.typePaiement à 30 caractères (pour MOBILE_MONEY)');
        if ($apply) $conn->exec("ALTER TABLE paiement MODIFY typePaiement VARCHAR(30) " . ($type['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL'));
    }

    $indexes = [
        ['uq_paiement_reference_externe', 'ADD UNIQUE INDEX uq_paiement_reference_externe (referenceExterne)'],
        ['idx_paiement_reference_campay', 'ADD INDEX idx_paiement_reference_campay (referenceCampay)'],
        ['idx_paiement_reparation', 'ADD INDEX idx_paiement_reparation (idReparation)'],
    ];
    foreach ($indexes as [$name, $ddl]) {
        if (!indexExists($conn, 'paiement', $name)) {
            announce($apply, "créer l'index $name");
            if ($apply) $conn->exec("ALTER TABLE paiement $ddl");
        }
    }
}
if ($n === $before) echo "  ok, la table paiement est prête\n";

// ============================================================
// 4) Abonnements Premium
// ============================================================
// Après la section 3 : paiement a déjà ses colonnes CamPay. Un paiement
// d'abonnement n'a pas d'intervention (idIntervention NULL) et pointe vers
// sa ligne `abonnement` (idAbonnement).
echo "\n4. Abonnements\n";
$before = $n;

/**
 * Indique si une contrainte de clé étrangère existe sur une table.
 *
 * @param PDO    $c    Connexion à la base.
 * @param string $t    Table.
 * @param string $name Nom de la contrainte.
 * @return bool
 */
function foreignKeyExists(PDO $c, string $t, string $name): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $s->execute([$t, $name]);
    return (bool)$s->fetchColumn();
}

if (!tableExists($conn, 'abonnement')) {
    announce($apply, 'créer la table abonnement');
    if ($apply) $conn->exec("CREATE TABLE abonnement (
        idAbonnement INT AUTO_INCREMENT PRIMARY KEY,
        idClient INT NOT NULL,
        formule ENUM('PREMIUM') NOT NULL DEFAULT 'PREMIUM',
        periodicite ENUM('ESSAI','OFFERT','MENSUEL','ANNUEL') NOT NULL,
        nbVehicules INT NULL,
        montant DECIMAL(10,2) NOT NULL DEFAULT 0,
        statut ENUM('EN_ATTENTE','ACTIF','ECHOUE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE',
        dateCreation DATETIME NOT NULL,
        dateDebut DATETIME NULL,
        dateFin DATETIME NULL,
        idAdministrateur INT NULL,
        INDEX idx_abonnement_client_statut_fin (idClient, statut, dateFin),
        CONSTRAINT fk_abonnement_client FOREIGN KEY (idClient) REFERENCES client(idClient) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if (!tableExists($conn, 'ia_usage')) {
    announce($apply, 'créer la table ia_usage');
    if ($apply) $conn->exec("CREATE TABLE ia_usage (
        idUtilisateur INT NOT NULL,
        jour DATE NOT NULL,
        nb INT NOT NULL DEFAULT 0,
        PRIMARY KEY (idUtilisateur, jour),
        CONSTRAINT fk_ia_usage_utilisateur FOREIGN KEY (idUtilisateur) REFERENCES utilisateur(idUtilisateur) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

if (tableExists($conn, 'paiement')) {
    // MODIFY ne change que la nullabilité : fk_paiement_intervention est
    // conservée (une valeur NULL n'est simplement pas contrôlée).
    $intervention = columnInfo($conn, 'paiement', 'idIntervention');
    if ($intervention && $intervention['IS_NULLABLE'] === 'NO') {
        announce($apply, 'rendre paiement.idIntervention facultatif (NULL pour un abonnement)');
        if ($apply) $conn->exec("ALTER TABLE paiement MODIFY idIntervention INT NULL");
    }
    if (!colExists($conn, 'paiement', 'idAbonnement')) {
        announce($apply, 'ajouter paiement.idAbonnement');
        if ($apply) $conn->exec("ALTER TABLE paiement ADD COLUMN idAbonnement INT NULL");
    }
    if (!indexExists($conn, 'paiement', 'idx_paiement_abonnement')) {
        announce($apply, "créer l'index idx_paiement_abonnement");
        if ($apply) $conn->exec("ALTER TABLE paiement ADD INDEX idx_paiement_abonnement (idAbonnement)");
    }
    if (!foreignKeyExists($conn, 'paiement', 'fk_paiement_abonnement')) {
        announce($apply, 'créer la clé étrangère fk_paiement_abonnement (paiement.idAbonnement -> abonnement)');
        if ($apply) $conn->exec("ALTER TABLE paiement ADD CONSTRAINT fk_paiement_abonnement FOREIGN KEY (idAbonnement) REFERENCES abonnement(idAbonnement) ON DELETE SET NULL");
    }
}
if ($n === $before) echo "  ok, les abonnements sont prêts\n";

// ============================================================
// 5) Rapport de fin d'intervention
// ============================================================
// Kilométrage relevé et état du véhicule à la sortie, saisis à la clôture
// d'une réparation (includes/repair_report.php). Facultatives (NULL) : les
// réparations enregistrées avant cette section n'ont pas ces valeurs. Tant
// qu'elles manquent, repairReportReady() renvoie false et le site enregistre
// la réparation sans elles.
echo "\n5. Rapport de fin d'intervention\n";
$before = $n;
$reparationColumns = [
    ['kilometrage', 'INT NULL'],
    ['etatVehicule', 'VARCHAR(20) NULL'],
];
foreach ($reparationColumns as [$c, $def]) {
    if (!colExists($conn, 'reparation', $c)) {
        announce($apply, "ajouter reparation.$c");
        if ($apply) $conn->exec("ALTER TABLE reparation ADD COLUMN `$c` $def");
    }
}
if ($n === $before) echo "  ok, le rapport de fin d'intervention est prêt\n";

// ============================================================
// 6) Pastilles « nouveautés » de la sidebar
// ============================================================
// Dernière ouverture des onglets « Journal d'activité » et « Interventions »
// par utilisateur (includes/activity_log.php : activity_log_mark_seen(),
// activity_log_unread_counts()). dernierVu est écrit avec NOW() de MySQL, dans
// le même référentiel que journalactivites.dateHeure ; dernierIdActivite
// (plus grand idActivite au même instant) départage les entrées écrites dans
// la même seconde que la visite (dateHeure est à la seconde). Tant que la table
// manque, activity_log_unread_ready() renvoie false et aucune pastille n'est
// affichée. L'index sur dateHeure sert le comptage des entrées récentes.
echo "\n6. Pastilles de nouveautés\n";
$before = $n;

/**
 * Indique si un index de la table commence par une colonne donnée (quel que
 * soit son nom) : un tel index sert déjà les recherches par plage sur elle.
 *
 * @param PDO    $c   Connexion à la base.
 * @param string $t   Table.
 * @param string $col Première colonne recherchée.
 * @return bool
 */
function indexStartsWith(PDO $c, string $t, string $col): bool {
    $s = $c->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND SEQ_IN_INDEX=1");
    $s->execute([$t, $col]);
    return (bool)$s->fetchColumn();
}

if (!tableExists($conn, 'onglet_vu')) {
    announce($apply, 'créer la table onglet_vu');
    if ($apply) $conn->exec("CREATE TABLE onglet_vu (
        idUtilisateur INT NOT NULL,
        onglet ENUM('journal','interventions') NOT NULL,
        dernierVu DATETIME NOT NULL,
        dernierIdActivite INT NOT NULL DEFAULT 0,
        PRIMARY KEY (idUtilisateur, onglet),
        CONSTRAINT fk_onglet_vu_utilisateur FOREIGN KEY (idUtilisateur) REFERENCES utilisateur(idUtilisateur) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if (tableExists($conn, 'onglet_vu') && !colExists($conn, 'onglet_vu', 'dernierIdActivite')) {
    announce($apply, 'ajouter onglet_vu.dernierIdActivite');
    if ($apply) $conn->exec("ALTER TABLE onglet_vu ADD COLUMN dernierIdActivite INT NOT NULL DEFAULT 0 AFTER dernierVu");
}
if (tableExists($conn, 'journalactivites') && !indexStartsWith($conn, 'journalactivites', 'dateHeure')) {
    announce($apply, "créer l'index idx_journal_dateheure (journalactivites.dateHeure)");
    if ($apply) $conn->exec("ALTER TABLE journalactivites ADD INDEX idx_journal_dateheure (dateHeure)");
}
if ($n === $before) echo "  ok, les pastilles de nouveautés sont prêtes\n";

// ============================================================
// 7) Mot de passe oublié (Brevo) & Google OAuth 2.0
// ============================================================
echo "\n7. Mot de passe oublié et Google OAuth\n";
$before = $n;
if (!tableExists($conn, 'password_resets')) {
    announce($apply, 'créer la table password_resets');
    if ($apply) $conn->exec("CREATE TABLE password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idUtilisateur INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pwd_resets_token (token_hash),
        INDEX idx_pwd_resets_user (idUtilisateur),
        CONSTRAINT fk_pwd_resets_user FOREIGN KEY (idUtilisateur) REFERENCES utilisateur(idUtilisateur) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

if (!indexExists($conn, 'utilisateur', 'idx_utilisateur_google_id')) {
    if (colExists($conn, 'utilisateur', 'googleId')) {
        announce($apply, "créer l'index idx_utilisateur_google_id sur utilisateur(googleId)");
        if ($apply) $conn->exec("ALTER TABLE utilisateur ADD INDEX idx_utilisateur_google_id (googleId)");
    }
}
if ($n === $before) echo "  ok, réinitialisation de mot de passe et OAuth sont prêts\n";

// ============================================================
// 8) Échéances et rappels d'entretien
// ============================================================
// Assurance et visite technique : dates saisies sur le véhicule. Vidange,
// freins et pneus : historique `entretien` (clôture d'une réparation ou
// déclaration du client) et règles `entretien_regle` (modifiables par
// l'admin). `rappel_envoye` garantit un seul rappel par véhicule, type,
// échéance, palier et canal (scripts/send_reminders.php). Tant qu'un de ces
// éléments manque, maintenanceReady() renvoie false et le site se comporte
// comme avant.
echo "\n8. Échéances et rappels d'entretien\n";
$before = $n;
$vehiculeDateColumns = [
    ['dateExpirationAssurance', 'DATE NULL'],
    ['dateProchaineVisiteTechnique', 'DATE NULL'],
];
foreach ($vehiculeDateColumns as [$c, $def]) {
    if (!colExists($conn, 'vehicule', $c)) {
        announce($apply, "ajouter vehicule.$c");
        if ($apply) $conn->exec("ALTER TABLE vehicule ADD COLUMN `$c` $def");
    }
}
if (!tableExists($conn, 'entretien')) {
    announce($apply, 'créer la table entretien');
    if ($apply) $conn->exec("CREATE TABLE entretien (
        idEntretien INT AUTO_INCREMENT PRIMARY KEY,
        idVehicule INT NOT NULL,
        type ENUM('VIDANGE','FREINS','PNEUS') NOT NULL,
        dateEntretien DATE NOT NULL,
        kilometrage INT NULL,
        idReparation INT NULL,
        source ENUM('REPARATION','CLIENT') NOT NULL,
        dateCreation DATETIME NOT NULL,
        INDEX idx_entretien_vehicule_type_date (idVehicule, type, dateEntretien),
        CONSTRAINT fk_entretien_vehicule FOREIGN KEY (idVehicule) REFERENCES vehicule(idVehicule) ON DELETE CASCADE,
        CONSTRAINT fk_entretien_reparation FOREIGN KEY (idReparation) REFERENCES reparation(idReparation) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if (!tableExists($conn, 'entretien_regle')) {
    announce($apply, 'créer la table entretien_regle');
    if ($apply) $conn->exec("CREATE TABLE entretien_regle (
        type ENUM('VIDANGE','FREINS','PNEUS') NOT NULL PRIMARY KEY,
        intervalleMois INT NOT NULL,
        intervalleKm INT NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
// Règles par défaut (conditions sévères du Cameroun), recopiées ici plutôt
// que lues dans MAINTENANCE_DEFAULT_RULES : une migration reste figée même si
// les valeurs par défaut du code changent plus tard. INSERT IGNORE ne
// remplace jamais une règle déjà modifiée par l'admin.
$defaultRules = [
    ['VIDANGE', 6, 5000],
    ['FREINS', 6, 10000],
    ['PNEUS', 24, 40000],
];
$existingRules = tableExists($conn, 'entretien_regle')
    ? $conn->query('SELECT type FROM entretien_regle')->fetchAll(PDO::FETCH_COLUMN)
    : [];
foreach ($defaultRules as [$type, $mois, $km]) {
    if (!in_array($type, $existingRules, true)) {
        announce($apply, "insérer la règle par défaut $type ($km km ou $mois mois)");
        if ($apply) $conn->prepare("INSERT IGNORE INTO entretien_regle (type, intervalleMois, intervalleKm, actif) VALUES (?, ?, ?, 1)")->execute([$type, $mois, $km]);
    }
}
if (!tableExists($conn, 'rappel_envoye')) {
    announce($apply, 'créer la table rappel_envoye');
    if ($apply) $conn->exec("CREATE TABLE rappel_envoye (
        idVehicule INT NOT NULL,
        type VARCHAR(20) NOT NULL,
        echeance DATE NOT NULL,
        palier ENUM('J30','J7','J0','RETARD') NOT NULL,
        canal ENUM('NOTIF','EMAIL') NOT NULL,
        dateEnvoi DATETIME NOT NULL,
        PRIMARY KEY (idVehicule, type, echeance, palier, canal),
        CONSTRAINT fk_rappel_envoye_vehicule FOREIGN KEY (idVehicule) REFERENCES vehicule(idVehicule) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if ($n === $before) echo "  ok, les échéances et rappels d'entretien sont prêts\n";

echo "\n" . ($n === 0 ? "Rien à faire, tout est déjà en place." :
    ($apply ? "$n action(s) appliquée(s)." : "$n action(s) à appliquer. Relancez avec --apply.")) . "\n";
