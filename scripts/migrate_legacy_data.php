<?php
/**
 * Migration ponctuelle : reprend les données de l'export `charles (1).sql`
 * (ancien schéma users/vehicles/interventions/..., généré le 21/09/2026 17:06)
 * vers le nouveau schéma de `charles` (utilisateur/vehicule/intervention/...,
 * créé le 23/09/2026), et ajoute les 3 tables sans équivalent dans le nouveau
 * schéma (messages, notifications, technician_documents), rattachées à lui
 * par clé étrangère.
 *
 * Les identifiants numériques sont conservés à l'identique, pour que
 * messages/notifications/technician_documents restent cohérentes sans
 * changer leurs colonnes.
 *
 * Idempotent : peut être relancé sans dupliquer (vérifie l'existence par id
 * avant chaque insertion). N'écrit jamais dans les tables déjà migrées.
 *
 *   php scripts/migrate_legacy_data.php            simulation (aucune écriture)
 *   php scripts/migrate_legacy_data.php --apply    applique
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
function rowExists(PDO $c, string $t, string $pk, $id): bool {
    // En simulation, la table cible peut ne pas encore exister (elle serait créée à l'étape 2) :
    // dans ce cas, la ligne n'existe évidemment pas encore.
    if (!tableExists($c, $t)) return false;
    $s = $c->prepare("SELECT COUNT(*) FROM `$t` WHERE `$pk`=?");
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

$n = 0;
function announce(bool $apply, string $label): void {
    global $n;
    $n++;
    echo ($apply ? "  [fait] " : "  [à faire] ") . $label . "\n";
}

// ============================================================
// 1) Colonnes manquantes sur les tables du nouveau schéma
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
    // --- Découvertes en Phase 2 (admin) : colonnes nécessaires aux pages admin ---
    ['utilisateur', 'dateCreation', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    ['anomalie', 'type', 'VARCHAR(100) NULL'],
    ['anomalie', 'niveau', "ENUM('FAIBLE','MOYEN','CRITIQUE') NOT NULL DEFAULT 'MOYEN'"],
    // --- Découvertes en Phase 3 (client) : colonnes nécessaires aux pages client ---
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
// 2) Tables sans équivalent dans le nouveau schéma (reprises telles quelles)
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
// 3) Données historiques (source : charles (1).sql, export du 21/09/2026 17:06)
// ============================================================
echo "\n3. Données\n";
$before = $n;

// --- utilisateurs : id, nom, prenom, email, telephone, role, hash, competences, experience, statut_validation, theme, photo
$users = [
    [1, 'henga', 'Charles', 'admin@smartautotrack.com', '651797837', 'admin', '$2y$10$FZQqfFz4uPul3t1NiNLpp.Nlm/.VRGe2hG9owZ4UlliifrE5cU/JO', null, null, 'valide', 'light', null],
    [6, 'djoko', 'live', 'djoko@gmail.com', '671524727', 'client', '$2y$10$3Ci.9g09UYap9T1bAIQRCeV5YhFJbiICY3yiYdj84bQUG5uw4j3lu', null, null, 'valide', 'light', null],
    [8, 'henga', 'charles', 'hengacharles@gmail.com', '650251246', 'technicien', '$2y$10$eKeS07pnWlI7N78Eel179uzWjC9djS9y7iqGy3QaDD090j5fGO9/a', 'fhffhj', '3', 'valide', 'light', null],
    [9, 'HAMABOU', 'Emile', 'emilehamabou@gmail.com', '676470880', 'technicien', '$2y$10$HemDGu8Bj98maIVCkRGr2.S4scZHmI3NXzOWxBgoldVp70eScA3FW', 'tststyufhjjhjxf', '12', 'valide', 'light', null],
    [10, 'EKWALLA', 'Chris', 'ekwallachris@gmail.com', '657464231', 'technicien', '$2y$10$Z4Bl1hco/QzmYd6wvO8.8OJqS9b1hNkGnFed.b2oDog1/uQ4Kx3.a', 'Electricien Automobile', "3 ans d'experiences", 'en_attente', 'light', null],
    [11, 'NGANGOUP', 'Bernadette', 'ngangoupbernadette@gmail.com', '658039513', 'client', '$2y$10$MSGu5MoClhovq6yzyxk20uhIGUToo3MnbrzjYGQZXYrm3SfoJ6mbO', null, null, 'valide', 'light', null],
    [12, 'landry', 'land', 'landry@gmail.com', '658039513', 'technicien', '$2y$10$n1a2Z4w2hZ8AXcRJLiMQ0u7/.Yvjg0C.c5nBgKsUB8TdzlH.hJ./i', 'hjdvshvhjsdvj', 'djkvsdvdvbbn', 'en_attente', 'light', null],
    [13, 'charli', 'charlie', 'Charli@gmail.com', '651797837', 'technicien', '$2y$10$P86F/B3K6wK4Rw1vqh6cCuDmeUr7JtsFHjFi.DhBrRSfG9jGC4/Gi', 'jjdvbsnkjjkwlk,l', '.,l.,.[l', 'valide', 'light', null],
    [14, 'hwtettyetyeq', '157773', '11998@gmail.com', '658039513', 'client', '$2y$10$mISrVZvn3zvbnHkxx87/TOkUlIqJIRHGL289b19Q5NJw.wX8GfnsK', null, null, 'valide', 'light', null],
    [15, 'charles', 'hch', 'hch@gmail.com', '658039513', 'technicien', '$2y$10$/koB9YIWL8krruBWrUPiw.0I.ySNrHT5tfhmjoSzVPF2RFHCIXrn.', ',mvwjopwi0fpefpewqihciqoeh', '2p9ur93ur23', 'valide', 'light', null],
];
$statutValidationMap = ['valide' => 'VALIDE', 'en_attente' => 'EN_ATTENTE', 'rejete' => 'REJETE'];

foreach ($users as [$id, $nom, $prenom, $email, $tel, $role, $hash, $comp, $exp, $statutVal, $theme, $photo]) {
    if (rowExists($conn, 'utilisateur', 'idUtilisateur', $id)) continue;
    announce($apply, "utilisateur #$id ($role) : $prenom $nom");
    if (!$apply) continue;
    $conn->prepare("INSERT INTO utilisateur (idUtilisateur, nom, prenom, motDePasse, email, telephone, themePreference, photoProfil) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$id, $nom, $prenom, $hash, $email, $tel, $theme, $photo]);
    if ($role === 'admin') {
        $conn->prepare("INSERT INTO administrateur (idAdministrateur) VALUES (?)")->execute([$id]);
    } elseif ($role === 'client') {
        $conn->prepare("INSERT INTO client (idClient, typeClient) VALUES (?, 'PARTICULIER')")->execute([$id]);
        $conn->prepare("INSERT INTO particulier (idClient, adresse) VALUES (?, NULL)")->execute([$id]);
    } elseif ($role === 'technicien') {
        $conn->prepare("INSERT INTO technicien (idTechnicien, idGarage, specialite, statutValidation, competences, experience) VALUES (?, NULL, NULL, ?, ?, ?)")
            ->execute([$id, $statutValidationMap[$statutVal] ?? 'EN_ATTENTE', $comp, $exp]);
    }
}

// --- véhicules : id, client_id, marque, modele, immatriculation, kilometrage, statut
$vehicles = [
    [3, 6, 'Toyota Corolla', 'berline', '1234def', 0, 'actif'],
    [4, 11, 'Cadillac', 'escalade', 'CE-458-KL', 0, 'actif'],
    [5, 14, 'Cadillac e', 'escalade', '1155344343avvg', 0, 'actif'],
];
$etatMap = ['actif' => 'BON', 'en_panne' => 'EN_PANNE', 'en_entretien' => 'EN_ENTRETIEN', 'hors_service' => 'HORS_SERVICE'];

foreach ($vehicles as [$id, $clientId, $marque, $modele, $immat, $km, $statut]) {
    if (rowExists($conn, 'vehicule', 'idVehicule', $id)) continue;
    announce($apply, "véhicule #$id : $marque $modele ($immat)");
    if (!$apply) continue;
    $conn->prepare("INSERT INTO vehicule (idVehicule, idClient, idGarage, marque, modele, dateAcquisition, etat, numeroChassis, immatriculation, couleur, kilometrage) VALUES (?,?,NULL,?,?,NULL,?,NULL,?,NULL,?)")
        ->execute([$id, $clientId, $marque, $modele, $etatMap[$statut] ?? 'BON', $immat, $km]);
}

// --- interventions : id, vehicle_id, technicien_id, [admin_id sans équivalent, non repris], type, description, priorite, date_planifiee, statut
// idClient dérivé du véhicule (le nouveau schéma exige idClient directement sur l'intervention)
$interventions = [
    [4, 3, 8, 'dhggh', 'vjhvjh', 'moyenne', '2025-09-25 20:13:00', 'terminee'],
    [5, 3, 8, 'agshvfasdas', 'nklgnhlkg', 'moyenne', '2025-09-25 20:37:00', 'terminee'],
];
$statutIntervMap = ['planifiee' => 'PLANIFIEE', 'en_cours' => 'EN_COURS', 'terminee' => 'TERMINEE', 'annulee' => 'ANNULEE'];
$prioriteMap = ['basse' => 'BASSE', 'moyenne' => 'MOYENNE', 'haute' => 'HAUTE'];
$vehicleClient = array_column($vehicles, 1, 0); // vehicle_id => client_id

foreach ($interventions as [$id, $vehicleId, $techId, $type, $desc, $priorite, $date, $statut]) {
    if (rowExists($conn, 'intervention', 'idIntervention', $id)) continue;
    $clientId = $vehicleClient[$vehicleId] ?? null;
    announce($apply, "intervention #$id sur véhicule #$vehicleId (client #$clientId)");
    if (!$apply) continue;
    $conn->prepare("INSERT INTO intervention (idIntervention, idClient, idVehicule, idTechnicien, idGarage, type, dateIntervention, statut, description, priorite) VALUES (?,?,?,?,NULL,?,?,?,?,?)")
        ->execute([$id, $clientId, $vehicleId, $techId, $type, $date, $statutIntervMap[$statut] ?? 'PLANIFIEE', $desc, $prioriteMap[$priorite] ?? 'MOYENNE']);
}

// --- messages : id, expediteur_id, destinataire_id, sujet, contenu, lu, date_envoi
$messages = [
    [5, 1, 9, 'uryufuy', 'jiuihuh', 'oui', '2025-09-24 22:37:49'],
    [6, 6, 9, 'bjr', 'mon moteur', 'oui', '2025-09-24 22:41:53'],
    [7, 1, 6, 'Visite technique', "Salut M.DJOKO vous êtes priez de passer dans nos locaux au plus tard cette fin de semaine pour le Diagnostic de votre véhicule pour le compte de ce dernier trimestre\r\n\r\nCordialement SmartAutoTrack", 'non', '2026-06-26 12:47:38'],
    [8, 11, 1, 'Demande de Visite Automobile', 'jkgjkfguijbdvbruiuu8hiuhdjkhuifhjkkjnbcjhifws cm, xm,ndkjnfjkwhjkfiufhwshhiudfhwiuefiu', 'oui', '2026-06-26 13:18:24'],
    [9, 11, 1, 'Message', 'vjhkj', 'non', '2026-07-08 14:36:23'],
];
foreach ($messages as [$id, $exp, $dest, $sujet, $contenu, $lu, $date]) {
    if (rowExists($conn, 'messages', 'id', $id)) continue;
    announce($apply, "message #$id");
    if (!$apply) continue;
    $conn->prepare("INSERT INTO messages (id, expediteur_id, destinataire_id, sujet, contenu, lu, date_envoi) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id, $exp, $dest, $sujet, $contenu, $lu, $date]);
}

// --- notifications : id, user_id, type, titre, message, lu, date_creation
$notifications = [
    [6, 1, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Jean Durand', 'oui', '2025-09-24 15:29:39'],
    [9, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: henga charles", 'oui', '2025-09-24 16:11:09'],
    [10, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: charles henga", 'oui', '2025-09-24 16:15:12'],
    [11, 8, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'oui', '2025-09-24 16:18:01'],
    [12, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: Emile HAMABOU", 'oui', '2025-09-24 16:34:06'],
    [13, 9, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'oui', '2025-09-24 16:35:45'],
    [14, 9, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de SmartAutoTrack Admin', 'oui', '2025-09-24 22:37:49'],
    [15, 9, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de live djoko', 'oui', '2025-09-24 22:41:53'],
    [16, 9, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une nouvelle intervention: cghcgh', 'oui', '2025-09-25 18:54:29'],
    [18, 8, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une nouvelle intervention: dhggh', 'oui', '2025-09-25 19:13:42'],
    [19, 6, 'intervention', 'Intervention planifiée', 'Une intervention a été planifiée pour votre véhicule: dhggh', 'oui', '2025-09-25 19:13:42'],
    [20, 8, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une nouvelle intervention: agshvfasdas', 'oui', '2025-09-25 19:38:10'],
    [21, 6, 'intervention', 'Intervention planifiée', 'Une intervention a été planifiée pour votre véhicule: agshvfasdas', 'oui', '2025-09-25 19:38:10'],
    [22, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: Chris EKWALLA", 'oui', '2026-06-24 17:22:24'],
    [23, 6, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Charles henga', 'non', '2026-06-26 12:47:38'],
    [24, 1, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Bernadette NGANGOUP', 'oui', '2026-06-26 13:18:24'],
    [25, 1, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Bernadette NGANGOUP', 'non', '2026-07-08 14:36:23'],
    [26, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: land landry", 'non', '2026-08-24 10:46:26'],
    [27, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: charlie charli", 'non', '2026-08-24 10:49:11'],
    [28, 13, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'non', '2026-08-24 10:52:20'],
    [29, 1, 'validation', 'Nouvelle demande de technicien', "Nouvelle demande d'inscription de technicien: hch charles", 'oui', '2026-08-31 11:26:11'],
    [30, 15, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'non', '2026-08-31 11:27:20'],
];
foreach ($notifications as [$id, $uid, $type, $titre, $msg, $lu, $date]) {
    if (rowExists($conn, 'notifications', 'id', $id)) continue;
    announce($apply, "notification #$id");
    if (!$apply) continue;
    $conn->prepare("INSERT INTO notifications (id, user_id, type, titre, message, lu, date_creation) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id, $uid, $type, $titre, $msg, $lu, $date]);
}

// --- documents technicien : id, technicien_id, type_document, nom_fichier, chemin_fichier, taille_fichier, uploaded_at
$documents = [
    [1, 9, 'autre', 'reponses_soutenance.pdf', 'uploads/techniciens/9_1758731646_0.pdf', 7025, '2025-09-24 16:34:06'],
    [2, 10, 'autre', 'INTRO CHAP 1 ET 2.pdf', 'uploads/techniciens/10_1782321744_0.pdf', 208874, '2026-06-24 17:22:24'],
    [3, 12, 'autre', '_PDG.pdf', 'uploads/techniciens/12_1787568386_0.pdf', 23653, '2026-08-24 10:46:26'],
    [4, 13, 'autre', 'Bonne lettre.pdf', 'uploads/techniciens/13_1787568551_0.pdf', 706201, '2026-08-24 10:49:11'],
    [5, 15, 'autre', 'Bonne lettre.pdf', 'uploads/techniciens/15_1788175571_0.pdf', 706201, '2026-08-31 11:26:11'],
];
foreach ($documents as [$id, $techId, $type, $nom, $chemin, $taille, $date]) {
    if (rowExists($conn, 'technician_documents', 'id', $id)) continue;
    announce($apply, "document #$id (technicien #$techId) : $nom");
    if (!$apply) continue;
    $conn->prepare("INSERT INTO technician_documents (id, technicien_id, type_document, nom_fichier, chemin_fichier, taille_fichier, uploaded_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id, $techId, $type, $nom, $chemin, $taille, $date]);
}

if ($n === $before) echo "  ok, toutes les données sont déjà migrées\n";

// ============================================================
// 4) Rétro-remplissage de utilisateur.dateCreation (découvert en Phase 2)
//    depuis les dates d'inscription réelles de l'export charles (1).sql.
// ============================================================
echo "\n4. Dates d'inscription\n";
$before = $n;
$createdAt = [
    1 => '2025-09-24 15:12:40', 6 => '2025-09-24 16:06:00', 8 => '2025-09-24 16:15:12',
    9 => '2025-09-24 16:34:06', 10 => '2026-06-24 17:22:24', 11 => '2026-06-26 13:14:07',
    12 => '2026-08-24 10:46:26', 13 => '2026-08-24 10:49:11', 14 => '2026-08-31 11:22:27',
    15 => '2026-08-31 11:26:11',
];
if (colExists($conn, 'utilisateur', 'dateCreation')) {
    foreach ($createdAt as $id => $date) {
        $current = null;
        if (tableExists($conn, 'utilisateur')) {
            $s = $conn->prepare('SELECT dateCreation FROM utilisateur WHERE idUtilisateur = ?');
            $s->execute([$id]);
            $current = $s->fetchColumn();
        }
        if ($current === $date) continue; // déjà à jour
        if ($current === false) continue; // utilisateur pas encore migré (étape 3 pas encore passée)
        announce($apply, "dateCreation utilisateur #$id -> $date");
        if (!$apply) continue;
        $conn->prepare('UPDATE utilisateur SET dateCreation = ? WHERE idUtilisateur = ?')->execute([$date, $id]);
    }
} else {
    announce($apply, 'ajouter puis remplir utilisateur.dateCreation (relancer après --apply de l\'étape 1)');
}
if ($n === $before) echo "  ok, dates déjà à jour\n";

// ============================================================
// 5) Rétro-remplissage de vehicule.dateCreation (découvert en Phase 3)
// ============================================================
echo "\n5. Dates d'enregistrement des véhicules\n";
$before = $n;
$vehicleCreatedAt = [
    3 => '2025-09-24 16:06:00', 4 => '2026-06-26 13:14:07', 5 => '2026-08-31 11:22:27',
];
if (colExists($conn, 'vehicule', 'dateCreation')) {
    foreach ($vehicleCreatedAt as $id => $date) {
        $current = null;
        if (tableExists($conn, 'vehicule')) {
            $s = $conn->prepare('SELECT dateCreation FROM vehicule WHERE idVehicule = ?');
            $s->execute([$id]);
            $current = $s->fetchColumn();
        }
        if ($current === $date) continue;
        if ($current === false) continue; // véhicule pas encore migré
        announce($apply, "dateCreation véhicule #$id -> $date");
        if (!$apply) continue;
        $conn->prepare('UPDATE vehicule SET dateCreation = ? WHERE idVehicule = ?')->execute([$date, $id]);
    }
} else {
    announce($apply, 'ajouter puis remplir vehicule.dateCreation (relancer après --apply de l\'étape 1)');
}
if ($n === $before) echo "  ok, dates déjà à jour\n";

echo "\n" . ($n === 0 ? "Rien à faire, tout est déjà en place." :
    ($apply ? "$n action(s) appliquée(s)." : "$n action(s) à appliquer. Relancez avec --apply.")) . "\n";
