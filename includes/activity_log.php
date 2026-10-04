<?php
/**
 * Journal d'activité — traçabilité centralisée des actions métier
 * importantes de la plateforme (jamais les simples clics/consultations).
 *
 * Deux fonctions, un seul point d'écriture et un seul point de lecture :
 *   - log_activity()      : enregistre une action (qui, quoi, quand, sur quel
 *                            élément, dans quel garage).
 *   - activity_log_fetch(): lit le journal selon le rôle du visiteur, en
 *                            appliquant STRICTEMENT le cloisonnement propre
 *                            à ce rôle. C'est le seul endroit du code qui
 *                            construit la clause WHERE de lecture du journal :
 *                            centraliser cette logique (plutôt que de la
 *                            dupliquer dans admin/garage/technicien/client)
 *                            évite qu'une page mal écrite ne fuite des
 *                            activités hors du périmètre de son rôle.
 *                            La clause elle-même est construite par
 *                            activity_log_scope_clause(), partagée avec le
 *                            comptage des pastilles ci-dessous.
 * Pastilles rouges « nouveautés » des onglets « Journal d'activité » et
 * « Interventions » des 4 sidebars (table onglet_vu, section 6 de
 * scripts/migrate_structure.php) : activity_log_mark_seen() (page ouverte),
 * activity_log_unread_mark() (repère NOW() + MAX(idActivite) de MySQL),
 * activity_log_unread_counts() (entrées nouvelles depuis la dernière visite,
 * hors actions du visiteur, même cloisonnement que le journal),
 * activity_log_sidebar_counts() (appel sûr depuis une sidebar),
 * activity_log_unread_ready() (migration appliquée ? sinon aucune pastille,
 * comportement inchangé) et les aides pures activity_log_unread_tab(),
 * activity_log_badge_label() (« 99+ ») et activity_log_unread_badge() (HTML).
 * Le technicien voit aussi les événements des interventions qui lui sont
 * affectées (i.idTechnicien), y compris ceux écrits avant son affectation
 * (ceux du garage de l'intervention ; tous pour un technicien SmartAutoTrack
 * INTERNE, dont les demandes n'ont pas de garage).
 * Plus des aides d'affichage pour les pages journal.php :
 * activity_log_role_label() / activity_log_perimetre_label() (libellés),
 * activity_log_is_report() (le rapport de fin d'intervention écrit par
 * includes/repair_report.php), et pour la fenêtre « Voir le rapport »
 * (assets/js/main.js) : activity_log_report_fields() (découpe « Libellé :
 * valeur »), activity_log_report_payload() (en-tête, paires, lien PDF,
 * lien de paiement), activity_log_report_pay_url() (lien « Payer cette
 * réparation », client propriétaire seulement) et activity_log_report_json()
 * (attribut data-report du bouton).
 */

if (!function_exists('log_activity')) {
    /**
     * @param PDO    $conn
     * @param string $nomActivite    Intitulé court de l'action (ex. "Intervention démarrée").
     * @param array  $data           Champs optionnels :
     *   idUtilisateur (int|null) — acteur ayant réalisé l'action (session courante en général).
     *   description   (string|null) — contexte lisible (motif, titre, résumé...).
     *   idIntervention, idGarage, idTechnicien, idReparation, idAnomalie (int|null) — éléments concernés.
     *   categorie (string) — 'intervention'|'reparation'|'anomalie'|'technicien'|'garage'|'compte'.
     *                         Par défaut déduite de idAnomalie/idReparation/idTechnicien/idIntervention.
     * Si idGarage n'est pas fourni mais idIntervention l'est, idGarage est
     * retrouvé automatiquement (garde les appelants simples) — permet au
     * cloisonnement du garage de fonctionner même quand l'appelant ne connaît
     * pas explicitement l'idGarage de l'intervention.
     * Passer explicitement `'idGarage' => false` force idGarage à NULL SANS
     * cette déduction automatique : nécessaire pour une action qui mentionne
     * un garage DIFFÉRENT de celui réellement affecté à l'intervention (ex. le
     * garage recommandé par le système quand le client a finalement choisi un
     * autre garage) — l'entrée ne doit alors fuiter ni dans le journal du
     * garage recommandé, ni dans celui du garage choisi, seulement chez
     * l'admin (vision globale) et le client (via son propre idClient).
     */
    function log_activity(PDO $conn, string $nomActivite, array $data = []): void {
        if ($nomActivite === '') return;

        $idUtilisateur = isset($data['idUtilisateur']) ? (int)$data['idUtilisateur'] : null;
        $idIntervention = isset($data['idIntervention']) ? (int)$data['idIntervention'] : null;
        $idTechnicien = isset($data['idTechnicien']) ? (int)$data['idTechnicien'] : null;
        $idReparation = isset($data['idReparation']) ? (int)$data['idReparation'] : null;
        $idAnomalie = isset($data['idAnomalie']) ? (int)$data['idAnomalie'] : null;
        $description = $data['description'] ?? null;

        if (array_key_exists('idGarage', $data) && $data['idGarage'] === false) {
            $idGarage = null;
        } elseif (isset($data['idGarage'])) {
            $idGarage = (int)$data['idGarage'];
        } elseif ($idIntervention !== null) {
            $stmt = $conn->prepare('SELECT idGarage FROM intervention WHERE idIntervention = ?');
            $stmt->execute([$idIntervention]);
            $found = $stmt->fetchColumn();
            $idGarage = $found !== false && $found !== null ? (int)$found : null;
        } else {
            $idGarage = null;
        }

        $categorie = $data['categorie'] ?? null;
        if ($categorie === null) {
            if ($idAnomalie !== null) $categorie = 'anomalie';
            elseif ($idReparation !== null) $categorie = 'reparation';
            else $categorie = 'intervention';
        }

        $stmt = $conn->prepare("
            INSERT INTO journalactivites (idUtilisateur, idGarage, idTechnicien, idIntervention, idReparation, idAnomalie, categorie, nomActivite, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$idUtilisateur, $idGarage, $idTechnicien, $idIntervention, $idReparation, $idAnomalie, $categorie, $nomActivite, $description]);
    }
}

if (!function_exists('activity_log_scope_clause')) {
    /**
     * Clause WHERE de cloisonnement du journal pour un rôle (fonction interne,
     * sans accès à la base). Partagée par activity_log_fetch() (lecture des
     * pages journal.php) et activity_log_unread_counts() (pastilles de la
     * sidebar) : les deux appliquent ainsi EXACTEMENT le même périmètre, et
     * le comptage des nouveautés ne peut pas voir plus que le journal.
     *
     * Les conditions supposent les alias de activity_log_fetch() :
     * `j` (journalactivites) et `i` (intervention, en LEFT JOIN sur
     * j.idIntervention).
     *
     * @param string $role          'admin'|'garage'|'technicien'|'client'
     * @param int    $idUtilisateur idUtilisateur du visiteur connecté.
     * @param array  $scope         ['idGarage' => int] pour le rôle garage (obligatoire).
     * @return array{0: string[], 1: array}|null [conditions, paramètres] (aucune
     *         condition pour l'admin) ; null si le visiteur ne doit rien voir
     *         (rôle inconnu, garage sans idGarage).
     */
    function activity_log_scope_clause(string $role, int $idUtilisateur, array $scope = []): ?array {
        $where = [];
        $params = [];

        switch ($role) {
            case ROLE_ADMIN:
                // Vision globale : aucune restriction de périmètre.
                break;
            case ROLE_GARAGE:
                $idGarage = (int)($scope['idGarage'] ?? 0);
                if (!$idGarage) return null;
                $where[] = 'j.idGarage = ?';
                $params[] = $idGarage;
                break;
            case ROLE_TECHNICIEN:
                // Uniquement ses propres actions, ses propres interventions/
                // réparations affectées, les anomalies constatées sur une
                // intervention où il est intervenu, et les événements des
                // interventions qui lui sont affectées (i.idTechnicien), même
                // écrits avant son affectation (ex. l'anomalie déclarée par
                // le client à la demande) — jamais le journal d'un autre
                // technicien hors de ses interventions. Pour ce dernier cas,
                // seules les entrées du garage de l'intervention comptent
                // (j.idGarage = i.idGarage) : une entrée écrite avec
                // idGarage => false (garage recommandé par SmartAutoTrack)
                // reste réservée à l'admin et au client.
                // Exception : un technicien SmartAutoTrack (typeTechnicien
                // INTERNE) voit tout l'historique de SES interventions. Une
                // demande adressée à SmartAutoTrack est écrite avec idGarage
                // NULL (NULL = NULL est faux en SQL) et le garage en appui
                // n'est posé qu'à l'affectation : sans cette exception, la
                // demande et l'anomalie déclarée par le client lui
                // échapperaient.
                $where[] = "(j.idUtilisateur = ? OR j.idTechnicien = ? OR (i.idTechnicien = ? AND (j.idGarage = i.idGarage
                    OR EXISTS (SELECT 1 FROM technicien tx WHERE tx.idTechnicien = ? AND tx.typeTechnicien = 'INTERNE'))))";
                $params[] = $idUtilisateur;
                $params[] = $idUtilisateur;
                $params[] = $idUtilisateur;
                $params[] = $idUtilisateur;
                break;
            case ROLE_CLIENT:
                // Uniquement les événements liés à ses propres véhicules /
                // interventions (particulier ou entreprise : un compte = un
                // seul parc, même portée de requête pour les deux).
                $where[] = 'i.idClient = ?';
                $params[] = $idUtilisateur;
                break;
            default:
                return null;
        }

        return [$where, $params];
    }
}

if (!function_exists('activity_log_fetch')) {
    /**
     * Lecture cloisonnée du journal. C'est la SEULE fonction de lecture de
     * `journalactivites` — toutes les pages journal.php (admin/garage/
     * technicien/client) doivent passer par elle. (activity_log_unread_counts()
     * ne fait que compter, avec la même clause activity_log_scope_clause().)
     *
     * @param string $role  'admin'|'garage'|'technicien'|'client'
     * @param int    $idUtilisateur idUtilisateur du visiteur connecté.
     * @param array  $scope  ['idGarage' => int] pour le rôle garage (obligatoire).
     * @param array  $filters filtres optionnels : categorie, idGarage (admin
     *                        seulement — filtrer la vue globale par garage),
     *                        idIntervention (toutes rôles — historique d'une
     *                        intervention précise, ne fait que RESTREINDRE le
     *                        périmètre déjà appliqué ci-dessus, jamais l'élargir),
     *                        date_from, date_to (format Y-m-d), limit.
     * @return array Lignes du journal, les plus récentes d'abord.
     */
    function activity_log_fetch(PDO $conn, string $role, int $idUtilisateur, array $scope = [], array $filters = []): array {
        $clause = activity_log_scope_clause($role, $idUtilisateur, $scope);
        if ($clause === null) return [];
        [$where, $params] = $clause;

        if (!empty($filters['categorie'])) {
            $where[] = 'j.categorie = ?';
            $params[] = $filters['categorie'];
        }
        if (!empty($filters['idIntervention'])) {
            $where[] = 'j.idIntervention = ?';
            $params[] = (int)$filters['idIntervention'];
        }
        if ($role === ROLE_ADMIN && !empty($filters['idGarage'])) {
            $where[] = 'j.idGarage = ?';
            $params[] = (int)$filters['idGarage'];
        }
        if ($role === ROLE_ADMIN && !empty($filters['role_acteur'])) {
            // Filtre "Rôle" de la vue globale admin : mêmes tables que celles
            // utilisées pour déduire acteur_role dans le SELECT ci-dessous,
            // via EXISTS (un alias calculé n'est pas utilisable dans WHERE).
            $roleExists = [
                'admin' => 'EXISTS (SELECT 1 FROM administrateur WHERE idAdministrateur = j.idUtilisateur)',
                'garage' => 'EXISTS (SELECT 1 FROM garage WHERE idUtilisateur = j.idUtilisateur)',
                'technicien' => 'EXISTS (SELECT 1 FROM technicien WHERE idTechnicien = j.idUtilisateur)',
                'client' => 'EXISTS (SELECT 1 FROM client WHERE idClient = j.idUtilisateur)',
            ];
            if (isset($roleExists[$filters['role_acteur']])) {
                $where[] = $roleExists[$filters['role_acteur']];
            }
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'j.dateHeure >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'j.dateHeure <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $limit = (int)($filters['limit'] ?? 150);
        if ($limit <= 0 || $limit > 500) $limit = 150;

        $stmt = $conn->prepare("
            SELECT j.idActivite, j.dateHeure, j.nomActivite, j.description, j.categorie,
                   j.idUtilisateur, ua.nom AS acteur_nom, ua.prenom AS acteur_prenom,
                   CASE WHEN radm.idAdministrateur IS NOT NULL THEN 'admin'
                        WHEN rgar.idGarage IS NOT NULL THEN 'garage'
                        WHEN rtech.idTechnicien IS NOT NULL THEN 'technicien'
                        WHEN rcli.idClient IS NOT NULL THEN 'client'
                        ELSE NULL END AS acteur_role,
                   j.idGarage, g.nomGarage,
                   j.idIntervention, i.type AS intervention_type, i.idClient AS intervention_client_id,
                   v.marque, v.modele, v.immatriculation,
                   j.idTechnicien, ut.nom AS technicien_nom, ut.prenom AS technicien_prenom,
                   j.idReparation, r.titre AS reparation_titre,
                   j.idAnomalie, an.description AS anomalie_description
            FROM journalactivites j
            LEFT JOIN utilisateur ua ON ua.idUtilisateur = j.idUtilisateur
            LEFT JOIN administrateur radm ON radm.idAdministrateur = j.idUtilisateur
            LEFT JOIN garage rgar ON rgar.idUtilisateur = j.idUtilisateur
            LEFT JOIN technicien rtech ON rtech.idTechnicien = j.idUtilisateur
            LEFT JOIN client rcli ON rcli.idClient = j.idUtilisateur
            LEFT JOIN garage g ON g.idGarage = j.idGarage
            LEFT JOIN intervention i ON i.idIntervention = j.idIntervention
            LEFT JOIN vehicule v ON v.idVehicule = i.idVehicule
            LEFT JOIN utilisateur ut ON ut.idUtilisateur = j.idTechnicien
            LEFT JOIN reparation r ON r.idReparation = j.idReparation
            LEFT JOIN anomalie an ON an.idAnomalie = j.idAnomalie
            $whereSql
            ORDER BY j.dateHeure DESC, j.idActivite DESC
            LIMIT $limit
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

/*
 * Pastilles « nouveautés » de la sidebar (onglets « Journal d'activité » et
 * « Interventions » des 4 espaces). Table `onglet_vu` (section 6 de
 * scripts/migrate_structure.php) : une ligne par utilisateur et par onglet,
 * dernierVu = dernière ouverture de la page. Est « nouvelle » une entrée du
 * journal visible par le visiteur (même cloisonnement que activity_log_fetch(),
 * via activity_log_scope_clause()), plus récente que dernierVu, et dont
 * l'acteur n'est pas lui (on ne se signale pas sa propre action).
 *
 * Fuseau horaire : dateHeure est rempli par le DEFAULT CURRENT_TIMESTAMP de
 * MySQL (log_activity() ne le fournit pas), et l'horloge MySQL de cette
 * machine diffère de celle de PHP (date_default_timezone_set de
 * config/config.php). dernierVu est donc écrit avec NOW() de MySQL et relu
 * tel quel pour la comparaison : les deux dates sont dans le même référentiel,
 * PHP ne calcule jamais lui-même ces instants (une date('Y-m-d H:i:s') PHP
 * décalerait la comparaison de l'écart entre les deux horloges). Même
 * principe que $dbNow (SELECT NOW()) des pages journal.php.
 * dateHeure étant à la seconde, dernierIdActivite (plus grand idActivite lu
 * dans la même instruction que NOW()) départage les entrées de la seconde de
 * la visite : nouvelle si dateHeure > dernierVu, ou dateHeure = dernierVu et
 * idActivite > dernierIdActivite. Une action faite juste après la visite
 * apparaît donc, et une entrée déjà vue ne revient pas.
 */

if (!defined('ACTIVITY_LOG_TABS')) {
    /** Onglets suivis par les pastilles (liste blanche, valeurs de onglet_vu.onglet). */
    define('ACTIVITY_LOG_TABS', ['journal', 'interventions']);
}

if (!function_exists('activity_log_unread_tab')) {
    /**
     * Valide un nom d'onglet contre la liste blanche ACTIVITY_LOG_TABS.
     * Fonction pure.
     *
     * @param string $onglet Nom reçu ('journal'|'interventions').
     * @return string|null L'onglet s'il est autorisé, sinon null.
     */
    function activity_log_unread_tab(string $onglet): ?string {
        return in_array($onglet, ACTIVITY_LOG_TABS, true) ? $onglet : null;
    }
}

if (!function_exists('activity_log_badge_label')) {
    /**
     * Texte affiché dans la pastille : le nombre, « 99+ » au-delà de 99,
     * chaîne vide si rien de nouveau. Fonction pure.
     *
     * @param int $count Nombre de nouveautés.
     * @return string
     */
    function activity_log_badge_label(int $count): string {
        if ($count <= 0) return '';
        return $count > 99 ? '99+' : (string)$count;
    }
}

if (!function_exists('activity_log_unread_badge')) {
    /**
     * HTML de la pastille rouge d'un onglet de la sidebar (classe commune
     * .nav-unread, assets/css/style.css), ou chaîne vide si rien de nouveau.
     * role="img" + aria-label (« 3 nouveautés ») : le lecteur d'écran annonce
     * une phrase plutôt qu'un chiffre isolé. Placée APRÈS le badge existant de
     * l'onglet (ex. interventions actives, orange) : les deux restent lisibles.
     * Fonction pure.
     *
     * @param int $count Nombre de nouveautés (activity_log_unread_counts()).
     * @return string
     */
    function activity_log_unread_badge(int $count): string {
        $label = activity_log_badge_label($count);
        if ($label === '') return '';
        $aria = $count > 99 ? 'Plus de 99 nouveautés' : ($count === 1 ? '1 nouveauté' : $count . ' nouveautés');
        $aria = htmlspecialchars($aria, ENT_QUOTES, 'UTF-8');
        return '<span class="nav-unread" role="img" aria-label="' . $aria . '" title="' . $aria . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('activity_log_unread_ready')) {
    /**
     * La table onglet_vu existe (scripts/migrate_structure.php appliqué).
     * Tant qu'elle manque, aucune pastille n'est affichée et rien n'est écrit :
     * le site se comporte exactement comme avant. Résultat mémorisé pour la
     * requête HTTP courante.
     *
     * @param PDO $conn
     * @return bool
     */
    function activity_log_unread_ready(PDO $conn): bool {
        static $ready = null;
        if ($ready === null) {
            $stmt = $conn->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onglet_vu'
            ");
            $ready = (int)$stmt->fetchColumn() === 1;
        }
        return $ready;
    }
}

if (!function_exists('activity_log_unread_memo')) {
    /**
     * Mémoire des comptages de la requête HTTP courante (clé « rôle:id:garage ») :
     * la sidebar ne recalcule pas ; activity_log_mark_seen() la vide.
     *
     * @return array Référence vers le tableau statique.
     */
    function &activity_log_unread_memo(): array {
        static $memo = [];
        return $memo;
    }
}

if (!function_exists('activity_log_unread_mark')) {
    /**
     * Repère « maintenant » des pastilles : NOW() de MySQL et plus grand
     * idActivite du journal, lus dans la même instruction (voir « Fuseau
     * horaire » plus haut). Lecture simple, sans verrou sur journalactivites.
     *
     * @param PDO $conn
     * @return array{0: string, 1: int} [dernierVu, dernierIdActivite]
     */
    function activity_log_unread_mark(PDO $conn): array {
        $row = $conn->query('SELECT NOW(), COALESCE(MAX(idActivite), 0) FROM journalactivites')->fetch(PDO::FETCH_NUM);
        return [(string)$row[0], (int)$row[1]];
    }
}

if (!function_exists('activity_log_mark_seen')) {
    /**
     * Marque un onglet comme vu maintenant (UPSERT de dernierVu = NOW() de
     * MySQL et dernierIdActivite = plus grand idActivite, voir « Fuseau
     * horaire » plus haut). Appelée en haut des pages
     * Journal et Interventions de chaque espace, AVANT le rendu de la
     * sidebar : la pastille de l'onglet courant disparaît immédiatement.
     * Sans effet si l'onglet n'est pas dans la liste blanche ou si la
     * migration n'est pas appliquée ; une erreur est seulement journalisée
     * (error_log), jamais remontée à la page.
     *
     * @param PDO    $conn
     * @param int    $userId idUtilisateur du visiteur connecté.
     * @param string $onglet 'journal'|'interventions'.
     * @return void
     */
    function activity_log_mark_seen(PDO $conn, int $userId, string $onglet): void {
        $onglet = activity_log_unread_tab($onglet);
        if ($onglet === null || $userId <= 0) return;
        try {
            if (!activity_log_unread_ready($conn)) return;
            // NOW() et MAX(idActivite) lus dans la même instruction (lecture
            // simple, sans verrou sur journalactivites), puis écrits tels quels.
            [$now, $maxId] = activity_log_unread_mark($conn);
            $stmt = $conn->prepare("
                INSERT INTO onglet_vu (idUtilisateur, onglet, dernierVu, dernierIdActivite) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE dernierVu = VALUES(dernierVu), dernierIdActivite = VALUES(dernierIdActivite)
            ");
            $stmt->execute([$userId, $onglet, $now, $maxId]);
            $memo = &activity_log_unread_memo();
            $memo = [];
        } catch (Throwable $e) {
            error_log('[SmartAutoTrack] onglet vu : ' . $e->getMessage());
        }
    }
}

if (!function_exists('activity_log_unread_counts')) {
    /**
     * Nombre de nouveautés depuis la dernière visite de chaque onglet :
     *   - journal       : entrées visibles, plus récentes que dernierVu de
     *                     l'onglet journal, dont l'acteur n'est pas le visiteur ;
     *   - interventions : interventions DISTINCTES (j.idIntervention non NULL)
     *                     ayant au moins une telle entrée depuis dernierVu de
     *                     l'onglet interventions.
     * Même périmètre que activity_log_fetch() (activity_log_scope_clause()).
     * Un onglet jamais ouvert reçoit sa ligne onglet_vu à maintenant et compte
     * 0 : la première fois, tout l'historique n'apparaît pas comme nouveau.
     * Deux requêtes (lecture des repères dernierVu/dernierIdActivite,
     * comptage des deux onglets en une fois, sur l'index dateHeure), plus
     * activity_log_unread_mark() et une écriture à la première visite.
     * Résultat mémorisé pour la requête HTTP courante.
     * Lève une exception en cas d'erreur SQL : la sidebar passe par
     * activity_log_sidebar_counts(), qui l'intercepte.
     *
     * @param PDO    $conn
     * @param string $role   'admin'|'garage'|'technicien'|'client'
     * @param int    $userId idUtilisateur du visiteur connecté.
     * @param array  $scope  ['idGarage' => int] pour le rôle garage (obligatoire).
     * @return array{journal: int, interventions: int}
     */
    function activity_log_unread_counts(PDO $conn, string $role, int $userId, array $scope = []): array {
        $counts = ['journal' => 0, 'interventions' => 0];
        if ($userId <= 0 || !activity_log_unread_ready($conn)) return $counts;

        $memo = &activity_log_unread_memo();
        $key = $role . ':' . $userId . ':' . (int)($scope['idGarage'] ?? 0);
        if (isset($memo[$key])) return $memo[$key];

        $clause = activity_log_scope_clause($role, $userId, $scope);
        if ($clause === null) return $memo[$key] = $counts;
        [$where, $params] = $clause;

        // Repères de dernière visite, tels que MySQL les a écrits (NOW() et
        // MAX(idActivite), activity_log_unread_mark()).
        $stmt = $conn->prepare('SELECT onglet, dernierVu, dernierIdActivite FROM onglet_vu WHERE idUtilisateur = ?');
        $stmt->execute([$userId]);
        $since = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vu) {
            if (activity_log_unread_tab((string)$vu['onglet']) !== null) {
                $since[$vu['onglet']] = [(string)$vu['dernierVu'], (int)$vu['dernierIdActivite']];
            }
        }
        $missing = array_diff(ACTIVITY_LOG_TABS, array_keys($since));
        if ($missing) {
            // Première visite : point de départ à maintenant, rien de nouveau.
            [$now, $maxId] = activity_log_unread_mark($conn);
            $insert = $conn->prepare('INSERT IGNORE INTO onglet_vu (idUtilisateur, onglet, dernierVu, dernierIdActivite) VALUES (?, ?, ?, ?)');
            foreach ($missing as $onglet) {
                $insert->execute([$userId, $onglet, $now, $maxId]);
            }
        }
        if (!$since) return $memo[$key] = $counts;

        // Une seule lecture du journal pour les deux onglets, bornée par la
        // plus ancienne des deux dates (plage sur l'index dateHeure). Un
        // onglet sans date (première visite) vaut 0. Nouvelle = postérieure à
        // dernierVu, ou de la même seconde mais écrite après (idActivite).
        $newer = '(j.dateHeure > ? OR (j.dateHeure = ? AND j.idActivite > ?))';
        $select = [];
        $selectParams = [];
        if (isset($since['journal'])) {
            $select[] = "COALESCE(SUM($newer), 0) AS journal";
            array_push($selectParams, $since['journal'][0], $since['journal'][0], $since['journal'][1]);
        } else {
            $select[] = '0 AS journal';
        }
        if (isset($since['interventions'])) {
            $select[] = "COUNT(DISTINCT CASE WHEN $newer THEN j.idIntervention END) AS interventions";
            array_push($selectParams, $since['interventions'][0], $since['interventions'][0], $since['interventions'][1]);
        } else {
            $select[] = '0 AS interventions';
        }
        $where[] = '(j.idUtilisateur IS NULL OR j.idUtilisateur <> ?)';
        $params[] = $userId;
        $where[] = 'j.dateHeure >= ?';
        $params[] = min(array_column($since, 0));

        // Les ? du SELECT précèdent ceux du WHERE dans le texte : même ordre ici.
        $stmt = $conn->prepare('
            SELECT ' . implode(', ', $select) . '
            FROM journalactivites j
            LEFT JOIN intervention i ON i.idIntervention = j.idIntervention
            WHERE ' . implode(' AND ', $where));
        $stmt->execute(array_merge($selectParams, $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts['journal'] = (int)($row['journal'] ?? 0);
        $counts['interventions'] = (int)($row['interventions'] ?? 0);
        return $memo[$key] = $counts;
    }
}

if (!function_exists('activity_log_sidebar_counts')) {
    /**
     * Pastilles d'une sidebar : activity_log_unread_counts() avec le $scope
     * propre au rôle (idGarage du compte garage, relu en base depuis
     * l'utilisateur connecté, comme getUserProfile()), sans jamais casser la
     * navigation : pas de $conn (page sans base), migration non appliquée ou
     * erreur SQL (journalisée) donnent 0 partout, donc aucune pastille.
     *
     * @param mixed  $conn   Connexion PDO de la page, ou null si elle n'en a pas.
     * @param string $role   'admin'|'garage'|'technicien'|'client'
     * @param int    $userId idUtilisateur du visiteur connecté.
     * @return array{journal: int, interventions: int}
     */
    function activity_log_sidebar_counts($conn, string $role, int $userId): array {
        $counts = ['journal' => 0, 'interventions' => 0];
        if (!$conn instanceof PDO || $userId <= 0) return $counts;
        try {
            if (!activity_log_unread_ready($conn)) return $counts;
            $scope = [];
            if ($role === ROLE_GARAGE) {
                $stmt = $conn->prepare('SELECT idGarage FROM garage WHERE idUtilisateur = ?');
                $stmt->execute([$userId]);
                $scope['idGarage'] = (int)$stmt->fetchColumn();
            }
            return activity_log_unread_counts($conn, $role, $userId, $scope);
        } catch (Throwable $e) {
            error_log('[SmartAutoTrack] pastilles sidebar : ' . $e->getMessage());
            return $counts;
        }
    }
}

if (!function_exists('activity_log_role_label')) {
    /** Libellé FR d'un rôle d'acteur ('admin'|'garage'|'technicien'|'client'), pour l'affichage. */
    function activity_log_role_label(?string $role): string {
        $labels = ['admin' => 'Administrateur', 'garage' => 'Garage', 'technicien' => 'Technicien', 'client' => 'Client'];
        return $labels[$role] ?? '—';
    }
}

/**
 * Nom de l'activité « rapport de fin d'intervention », écrite par
 * includes/repair_report.php (repairReportClose()). Défini ici pour que
 * activity_log_is_report() le reconnaisse sans charger repair_report.php.
 */
if (!defined('REPAIR_REPORT_ACTIVITY')) {
    define('REPAIR_REPORT_ACTIVITY', 'Rapport de fin d\'intervention');
}

if (!function_exists('activity_log_is_report')) {
    /**
     * Indique si une entrée est le rapport de fin d'intervention
     * (REPAIR_REPORT_ACTIVITY, « Libellé : valeur » par ligne). Les pages
     * journal affichent alors un bouton « Voir le rapport » qui l'ouvre dans
     * une fenêtre (activity_log_report_json(), assets/js/main.js) ; les
     * autres descriptions, même multi-lignes (ex. une anomalie
     * constatée), gardent leur affichage habituel — et restent masquées côté
     * client.
     *
     * @param array $j Ligne renvoyée par activity_log_fetch().
     * @return bool
     */
    function activity_log_is_report(array $j): bool {
        return ($j['nomActivite'] ?? '') === REPAIR_REPORT_ACTIVITY && trim((string)($j['description'] ?? '')) !== '';
    }
}

if (!function_exists('activity_log_perimetre_label')) {
    /**
     * Périmètre de l'action, dérivé du rôle de l'acteur : c'est directement
     * l'échelle à laquelle l'action a été décidée (le garage a planifié, le
     * technicien a agi sur sa tâche, l'admin a agi au niveau plateforme...),
     * pas une nouvelle donnée stockée — juste une lecture plus explicite du
     * même rôle que celui déjà utilisé pour le cloisonnement.
     */
    function activity_log_perimetre_label(?string $role): string {
        $labels = ['admin' => 'Plateforme', 'garage' => 'Garage', 'technicien' => 'Technicien', 'client' => 'Client'];
        return $labels[$role] ?? '—';
    }
}

if (!function_exists('activity_log_report_fields')) {
    /**
     * Découpe la description d'un rapport de fin d'intervention
     * (« Libellé : valeur » par ligne, voir repairReportCompose()) en paires
     * affichables dans la fenêtre « Voir le rapport ». Fonction pure.
     *
     * Une ligne sans « : » est rattachée à la valeur précédente (saisie
     * multi-lignes, ex. un diagnostic sur plusieurs lignes). Quand la
     * première ligne porte un libellé connu du rapport (Titre, Diagnostic...),
     * seuls ces libellés ouvrent une nouvelle paire : une ligne de la saisie
     * contenant elle-même « : » (« Attention : ... ») reste dans la valeur.
     * Les valeurs ne sont ni décodées ni échappées (à faire à l'affichage).
     *
     * @param string $description Description de l'entrée du journal.
     * @return array<int, array{label: string, value: string}> Paires dans
     *         l'ordre du texte ; libellé vide si le texte commence sans libellé.
     */
    function activity_log_report_fields(string $description): array {
        $known = ['Titre', 'Diagnostic', 'Travaux effectués', 'Pièces utilisées', 'Durée', 'Coût',
            'Kilométrage relevé', 'État du véhicule', 'Recommandations'];
        $lines = preg_split('/\r\n|\r|\n/', trim($description));

        // Mode strict : le texte suit le format de repairReportCompose().
        $strict = false;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $strict = preg_match('/^\s*([^:]{1,40}?)\s*:/u', $line, $m) === 1 && in_array($m[1], $known, true);
            break;
        }

        $fields = [];
        foreach ($lines as $line) {
            $isField = preg_match('/^\s*([^:]{1,40}?)\s*:\s?(.*)$/u', $line, $m) === 1
                && (!$strict || in_array($m[1], $known, true));
            if ($isField) {
                $fields[] = ['label' => $m[1], 'value' => $m[2]];
            } elseif ($fields) {
                $fields[count($fields) - 1]['value'] .= "\n" . $line;
            } elseif (trim($line) !== '') {
                $fields[] = ['label' => '', 'value' => $line];
            }
        }
        foreach ($fields as &$field) {
            $field['value'] = trim($field['value']);
        }
        unset($field);
        return $fields;
    }
}

if (!function_exists('activity_log_report_payload')) {
    /**
     * Données de la fenêtre « Voir le rapport » (assets/js/main.js) pour une
     * entrée de rapport : uniquement des informations déjà présentes dans la
     * ligne du journal, donc déjà autorisées pour le visiteur. Fonction pure.
     *
     * Les textes stockés passent par sanitize() (entités HTML) : ils sont
     * décodés ici car le JavaScript les insère avec textContent, sans
     * interprétation HTML.
     *
     * @param array       $j       Ligne renvoyée par activity_log_fetch().
     * @param string      $siteUrl Racine du site (SITE_URL), pour le lien PDF.
     * @param string|null $pay     Lien de paiement déjà calculé par
     *                             activity_log_report_pay_url() (null : aucun).
     * @return array{meta: array, fields: array, pdf: string|null, pay: string|null}
     *         meta : paires libellé/valeur de l'en-tête (intervention,
     *         garage ou SmartAutoTrack, rempli par, date) ; fields :
     *         activity_log_report_fields() ; pdf : URL de
     *         ajax/download_report.php?format=pdf, null sans idReparation ;
     *         pay : $pay, null sans idReparation.
     */
    function activity_log_report_payload(array $j, string $siteUrl, ?string $pay = null): array {
        $txt = function ($v): string {
            return html_entity_decode(trim((string)($v ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };

        $meta = [];
        if (!empty($j['idIntervention'])) {
            $intervention = ($txt($j['intervention_type'] ?? '') ?: 'Intervention') . ' n° ' . (int)$j['idIntervention'];
            $meta[] = ['label' => 'Intervention', 'value' => $intervention];
        }
        if ($txt($j['marque'] ?? '') !== '') {
            $vehicule = trim($txt($j['marque']) . ' ' . $txt($j['modele'] ?? ''));
            if ($txt($j['immatriculation'] ?? '') !== '') $vehicule .= ' (' . $txt($j['immatriculation']) . ')';
            $meta[] = ['label' => 'Véhicule', 'value' => $vehicule];
        }
        if ($txt($j['nomGarage'] ?? '') !== '') {
            $meta[] = ['label' => 'Garage', 'value' => $txt($j['nomGarage'])];
        } elseif (!empty($j['idIntervention'])) {
            // Intervention confiée à SmartAutoTrack (technicien INTERNE sans garage).
            $meta[] = ['label' => 'Garage', 'value' => 'SmartAutoTrack'];
        }
        if ($txt($j['acteur_nom'] ?? '') !== '') {
            $acteur = trim($txt($j['acteur_prenom'] ?? '') . ' ' . $txt($j['acteur_nom']));
            if (!empty($j['acteur_role'])) $acteur .= ' (' . activity_log_role_label($j['acteur_role']) . ')';
            $meta[] = ['label' => 'Rempli par', 'value' => $acteur];
        }
        if (!empty($j['dateHeure']) && strtotime($j['dateHeure']) !== false) {
            $meta[] = ['label' => 'Date', 'value' => date('d/m/Y à H:i', strtotime($j['dateHeure']))];
        }

        $fields = [];
        foreach (activity_log_report_fields((string)($j['description'] ?? '')) as $f) {
            $fields[] = ['label' => $txt($f['label']), 'value' => $txt($f['value'])];
        }

        $pdf = !empty($j['idReparation'])
            ? $siteUrl . 'ajax/download_report.php?id=' . (int)$j['idReparation'] . '&format=pdf'
            : null;

        return ['meta' => $meta, 'fields' => $fields, 'pdf' => $pdf, 'pay' => !empty($j['idReparation']) ? $pay : null];
    }
}

if (!function_exists('activity_log_report_pay_url')) {
    /**
     * Lien « Payer cette réparation » de la fenêtre du rapport :
     * client/reparations.php?pay=<idReparation>, uniquement pour un visiteur
     * de rôle client propriétaire de la réparation (paymentPayableRepair() :
     * TERMINEE, coût > 0, intervention de ce client), quand le paiement en
     * ligne est disponible (paymentsReady(), campayIsConfigured()) et que la
     * réparation n'est pas déjà payée (paymentStatesForRepairs()). Lecture
     * seule ; résultat mémorisé par réparation pour la page.
     *
     * Les autres rôles (admin, garage, technicien) et les pages hors session
     * reçoivent null sans aucune requête.
     *
     * @param array     $j       Ligne renvoyée par activity_log_fetch().
     * @param string    $siteUrl Racine du site (SITE_URL).
     * @param PDO|null  $conn    Connexion ; ouverte via Database si absente.
     * @return string|null URL, ou null si le paiement n'est pas proposé.
     */
    function activity_log_report_pay_url(array $j, string $siteUrl, ?PDO $conn = null): ?string {
        static $cache = [];
        $repairId = (int)($j['idReparation'] ?? 0);
        $clientId = (int)($_SESSION['user_id'] ?? 0);
        if ($repairId <= 0 || $clientId <= 0 || ($_SESSION['role'] ?? null) !== 'client') {
            return null;
        }
        if (array_key_exists($repairId, $cache)) {
            return $cache[$repairId];
        }
        $url = null;
        try {
            require_once __DIR__ . '/payments.php';
            if ($conn === null && class_exists('Database')) {
                $conn = (new Database())->getConnection();
            }
            if ($conn instanceof PDO && campayIsConfigured() && paymentsReady($conn)
                && paymentPayableRepair($conn, $clientId, $repairId) !== null
                && (paymentStatesForRepairs($conn, [$repairId])[$repairId] ?? null) !== 'PAYE') {
                $url = $siteUrl . 'client/reparations.php?pay=' . $repairId;
            }
        } catch (Throwable $e) {
            // Le bouton de paiement est un raccourci : son absence ne doit
            // jamais empêcher l'affichage du journal.
            error_log('[SmartAutoTrack] lien de paiement du rapport : ' . $e->getMessage());
        }
        return $cache[$repairId] = $url;
    }
}

if (!function_exists('activity_log_report_json')) {
    /**
     * JSON de activity_log_report_payload() pour l'attribut data-report du
     * bouton « Voir le rapport » (classe report-modal-trigger). Les options
     * JSON_HEX_* remplacent < > & ' " par des séquences \uXXXX : la page
     * l'affiche ensuite avec h(), sans risque de sortir de l'attribut.
     * La clé pay vient de activity_log_report_pay_url() (client propriétaire
     * seulement ; null pour les autres rôles).
     *
     * @param array $j Ligne renvoyée par activity_log_fetch().
     * @return string JSON, '{}' si l'encodage échoue.
     */
    function activity_log_report_json(array $j): string {
        $siteUrl = defined('SITE_URL') ? SITE_URL : '';
        $json = json_encode(activity_log_report_payload($j, $siteUrl, activity_log_report_pay_url($j, $siteUrl)),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? '{}' : $json;
    }
}
