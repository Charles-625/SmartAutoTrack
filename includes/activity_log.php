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

if (!function_exists('activity_log_fetch')) {
    /**
     * Lecture cloisonnée du journal. C'est la SEULE fonction de lecture de
     * `journalactivites` — toutes les pages journal.php (admin/garage/
     * technicien/client) doivent passer par elle.
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
        $where = [];
        $params = [];

        switch ($role) {
            case ROLE_ADMIN:
                // Vision globale : aucune restriction de périmètre.
                break;
            case ROLE_GARAGE:
                $idGarage = (int)($scope['idGarage'] ?? 0);
                if (!$idGarage) return [];
                $where[] = 'j.idGarage = ?';
                $params[] = $idGarage;
                break;
            case ROLE_TECHNICIEN:
                // Uniquement ses propres actions, ses propres interventions/
                // réparations affectées, et les anomalies constatées sur une
                // intervention où il est intervenu — jamais le journal d'un
                // autre technicien.
                $where[] = '(j.idUtilisateur = ? OR j.idTechnicien = ?)';
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
                return [];
        }

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

if (!function_exists('activity_log_role_label')) {
    /** Libellé FR d'un rôle d'acteur ('admin'|'garage'|'technicien'|'client'), pour l'affichage. */
    function activity_log_role_label(?string $role): string {
        $labels = ['admin' => 'Administrateur', 'garage' => 'Garage', 'technicien' => 'Technicien', 'client' => 'Client'];
        return $labels[$role] ?? '—';
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
