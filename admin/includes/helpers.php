<?php
/**
 * Helpers du dashboard "v2" Administrateur. Réutilise les helpers génériques
 * déjà écrits pour le client (v2_relative, v2_today_fr — purs utilitaires
 * d'affichage, aucun couplage au rôle) plutôt que de les dupliquer.
 *
 * Contient aussi la logique d'affectation des demandes, partagée entre
 * admin/interventions.php et admin/intervention_detail.php :
 * admin_assign() (point d'entrée unique : technicien SmartAutoTrack ou de
 * garage, garage seul, ou technicien SmartAutoTrack avec un garage ;
 * affectation et réaffectation), sa règle pure admin_assign_plan(), les
 * choix proposés avec leur charge (admin_assign_choices(), admin_assign_fields())
 * et les anciennes entrées admin_reassign_garage() / admin_assign_internal(),
 * qui lui délèguent.
 */
require_once __DIR__ . '/../../client/includes/helpers.php';

if (!function_exists('av2_status_badge')) {
    /** Classe de badge (.av2-badge.*) pour un statut garage/technicien EN_ATTENTE/VALIDE/REJETE/SUSPENDU. */
    function av2_status_badge(string $statut): string {
        return ['VALIDE' => 'ok', 'EN_ATTENTE' => 'warn', 'REJETE' => 'bad', 'SUSPENDU' => 'neutral'][$statut] ?? 'neutral';
    }
    /** Libellé français d'un statut garage/technicien (repli : le code mis en forme). */
    function av2_status_label(string $statut): string {
        return ['VALIDE' => 'Validé', 'EN_ATTENTE' => 'En attente', 'REJETE' => 'Rejeté', 'SUSPENDU' => 'Suspendu'][$statut] ?? ucfirst(strtolower($statut));
    }
}

if (!function_exists('admin_assign_plan')) {
    /**
     * Règle d'affectation d'une intervention par l'administrateur — fonction
     * PURE (aucun accès à la base), testée par tests/Unit/AdminAssignTest.php
     * et appelée par admin_assign() sur des données relues en base sous verrou.
     *
     * L'administrateur choisit librement : un technicien SmartAutoTrack
     * (INTERNE), seul ou avec un garage ; un technicien de garage (GARAGE),
     * toujours affecté avec SON garage ; ou un garage seul, qui affectera
     * lui-même son technicien depuis garage/demandes.php. Le technicien et le
     * garage sont chacun facultatifs, mais au moins l'un des deux est requis.
     *
     * Contrôles, dans l'ordre :
     *   - ni technicien ni garage → « Choisissez un technicien ou un garage. » ;
     *   - intervention ni PLANIFIEE ni ANNULEE (refusée) → plus réaffectable ;
     *   - garage choisi non validé, technicien non validé ;
     *   - technicien GARAGE : un autre garage choisi est refusé (« Ce technicien
     *     appartient au garage X. ») et son garage doit être validé ;
     *   - même affectation que l'actuelle → refusée.
     * En cas de succès, l'appelant repasse le statut à PLANIFIEE (une demande
     * refusée, ANNULEE, redevient active).
     *
     * @param array      $iv     Intervention : statut, idTechnicien, idGarage.
     * @param array|null $tech   Technicien choisi, ou null : idTechnicien,
     *                           typeTechnicien, idGarage, statutValidation,
     *                           nomGarage et garageStatut (garage du technicien).
     * @param array|null $garage Garage choisi, ou null : idGarage, nomGarage, statutGarage.
     * @return array{ok: bool, error: ?string, idTechnicien: ?int, idGarage: ?int}
     *               Affectation à écrire (ok = true), ou le message d'erreur.
     */
    function admin_assign_plan(array $iv, ?array $tech, ?array $garage): array {
        $fail = fn(string $error): array => ['ok' => false, 'error' => $error, 'idTechnicien' => null, 'idGarage' => null];

        if ($tech === null && $garage === null) {
            return $fail('Choisissez un technicien ou un garage.');
        }
        if (!in_array($iv['statut'] ?? null, ['PLANIFIEE', 'ANNULEE'], true)) {
            return $fail('Cette intervention a déjà démarré / est terminée : elle ne peut plus être réaffectée.');
        }
        if ($garage !== null && ($garage['statutGarage'] ?? null) !== 'VALIDE') {
            return $fail('Ce garage n\'est pas validé.');
        }

        $idTechnicien = null;
        $idGarage = $garage !== null ? (int)$garage['idGarage'] : null;
        if ($tech !== null) {
            if (($tech['statutValidation'] ?? null) !== 'VALIDE') {
                return $fail('Ce technicien n\'est pas validé.');
            }
            $idTechnicien = (int)$tech['idTechnicien'];
            if (($tech['typeTechnicien'] ?? null) === 'GARAGE') {
                // Un technicien de garage travaille toujours pour SON garage :
                // l'intervention part dans ce garage, jamais dans un autre.
                if (empty($tech['idGarage'])) {
                    return $fail('Ce technicien de garage n\'est rattaché à aucun garage.');
                }
                $techGarageId = (int)$tech['idGarage'];
                if ($idGarage !== null && $idGarage !== $techGarageId) {
                    return $fail('Ce technicien appartient au garage ' . ($tech['nomGarage'] ?? ('#' . $techGarageId)) . '.');
                }
                if (($tech['garageStatut'] ?? null) !== 'VALIDE') {
                    return $fail('Le garage de ce technicien n\'est pas validé.');
                }
                $idGarage = $techGarageId;
            } elseif (($tech['typeTechnicien'] ?? null) !== 'INTERNE') {
                return $fail('Type de technicien inconnu.');
            }
        }

        $currentTech = isset($iv['idTechnicien']) ? (int)$iv['idTechnicien'] : null;
        $currentGarage = isset($iv['idGarage']) ? (int)$iv['idGarage'] : null;
        if ($currentTech === $idTechnicien && $currentGarage === $idGarage) {
            return $fail('Cette intervention est déjà affectée ainsi.');
        }

        return ['ok' => true, 'error' => null, 'idTechnicien' => $idTechnicien, 'idGarage' => $idGarage];
    }
}

if (!function_exists('admin_assign_label')) {
    /**
     * Libellé lisible d'une affectation (journal, notifications) — fonction pure.
     * Ex. « Jean Dupont (SmartAutoTrack) », « Jean Dupont (SmartAutoTrack),
     * garage Auto+ », « Jean Dupont (garage Auto+) », « le garage Auto+ ».
     *
     * @param string|null $technicienNom  Prénom et nom du technicien, ou null.
     * @param string|null $typeTechnicien 'INTERNE' | 'GARAGE' | null.
     * @param string|null $garageNom      Nom du garage affecté, ou null.
     * @return string « aucune affectation » si ni technicien ni garage.
     */
    function admin_assign_label(?string $technicienNom, ?string $typeTechnicien, ?string $garageNom): string {
        if ($technicienNom !== null && $technicienNom !== '') {
            if ($typeTechnicien === 'GARAGE') {
                return $technicienNom . ($garageNom ? ' (garage ' . $garageNom . ')' : '');
            }
            return $technicienNom . ' (SmartAutoTrack)' . ($garageNom ? ', garage ' . $garageNom : '');
        }
        return $garageNom ? 'le garage ' . $garageNom : 'aucune affectation';
    }
}

if (!function_exists('admin_load_label')) {
    /**
     * Charge d'un technicien ou d'un garage affichée dans les listes
     * d'affectation (nombre d'interventions PLANIFIEE et EN_COURS) — pure.
     *
     * @param int $count Nombre d'interventions planifiées ou en cours.
     * @return string Ex. « — disponible », « — 1 active », « — 2 actives » (libellé court :
     *                la colonne « Affectation » de la fiche est étroite).
     */
    function admin_load_label(int $count): string {
        if ($count <= 0) return '— disponible';
        return '— ' . $count . ' active' . ($count > 1 ? 's' : '');
    }
}

if (!function_exists('admin_assign_parse_id')) {
    /**
     * Lit un identifiant facultatif d'un formulaire d'affectation — pure.
     *
     * @param mixed $raw Valeur brute ($_POST[...] ?? null).
     * @return int|null|false null si absent ou vide (aucun choix), l'id s'il est valide (> 0), false sinon (texte, 0, tableau…).
     */
    function admin_assign_parse_id($raw) {
        if ($raw === null) return null;
        if (!is_string($raw) && !is_int($raw)) return false; // ex. tableau forgé
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? false : $id;
    }
}

if (!function_exists('admin_assign_choices')) {
    /**
     * Choix proposés à l'affectation, avec la charge de chacun (interventions
     * PLANIFIEE et EN_COURS) pour aider à confier la demande à quelqu'un de
     * disponible. Partagé par admin/interventions.php et intervention_detail.php.
     *
     * Techniciens : statutValidation VALIDE ; ceux d'un garage seulement si ce
     * garage est VALIDE (admin_assign() le revérifie). Garages : VALIDE.
     *
     * @param PDO $conn Connexion à la base (lecture seule).
     * @return array{internes: array, parGarage: array<string, array>, garages: array}
     *         internes : techniciens SmartAutoTrack ; parGarage : techniciens
     *         de garage groupés par nom de garage ; garages : idGarage,
     *         nomGarage, charge. Chaque technicien : id, prenom, nom,
     *         specialite, idGarage (null pour un interne), charge.
     */
    function admin_assign_choices(PDO $conn): array {
        $rows = $conn->query("
            SELECT t.idTechnicien AS id, u.prenom, u.nom, t.specialite, t.typeTechnicien, t.idGarage, g.nomGarage,
                   (SELECT COUNT(*) FROM intervention i WHERE i.idTechnicien = t.idTechnicien AND i.statut IN ('PLANIFIEE', 'EN_COURS')) AS charge
            FROM technicien t
            JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
            LEFT JOIN garage g ON g.idGarage = t.idGarage
            WHERE t.statutValidation = 'VALIDE'
              AND (t.typeTechnicien = 'INTERNE' OR (t.typeTechnicien = 'GARAGE' AND g.statutGarage = 'VALIDE'))
            ORDER BY g.nomGarage, u.nom, u.prenom
        ")->fetchAll();

        $internes = [];
        $parGarage = [];
        foreach ($rows as $r) {
            if ($r['typeTechnicien'] === 'INTERNE') {
                $r['idGarage'] = null; // un technicien interne n'emmène aucun garage
                $internes[] = $r;
            } else {
                $parGarage[$r['nomGarage']][] = $r;
            }
        }

        $garages = $conn->query("
            SELECT g.idGarage, g.nomGarage,
                   (SELECT COUNT(*) FROM intervention i WHERE i.idGarage = g.idGarage AND i.statut IN ('PLANIFIEE', 'EN_COURS')) AS charge
            FROM garage g WHERE g.statutGarage = 'VALIDE' ORDER BY g.nomGarage
        ")->fetchAll();

        return ['internes' => $internes, 'parGarage' => $parGarage, 'garages' => $garages];
    }
}

if (!function_exists('admin_assign_fields')) {
    /**
     * Affiche (echo) les champs du formulaire d'affectation, communs à la fenêtre
     * de admin/interventions.php et à la fiche intervention_detail.php : la
     * phrase d'aide, puis deux listes FACULTATIVES « Technicien » (optgroup
     * « SmartAutoTrack » puis un optgroup par garage) et « Garage », avec la
     * charge de chacun (admin_load_label()), placée juste après le nom (avant la
     * spécialité) pour rester visible dans la colonne étroite de la fiche. Chaque technicien de garage porte
     * data-garage (son garage) : le script de la page présélectionne ce garage.
     * Le paragraphe [data-assign-error] est affiché par ce script si l'envoi
     * est tenté sans aucun choix (la vraie validation reste serveur).
     *
     * @param array    $choices        Résultat de admin_assign_choices().
     * @param string   $idPrefix       Préfixe des id HTML (unicité dans la page).
     * @param int|null $selectedTech   Technicien présélectionné, ou null.
     * @param int|null $selectedGarage Garage présélectionné, ou null.
     * Chaque libellé passe par h() : le HTML produit est sûr à afficher tel quel.
     *
     * @return void HTML de l'aide, des deux listes et du message d'erreur, affiché directement.
     */
    function admin_assign_fields(array $choices, string $idPrefix, ?int $selectedTech = null, ?int $selectedGarage = null): void {
        $sel = fn(?int $selected, $id): string => ($selected !== null && $selected === (int)$id) ? ' selected' : '';
        $techOption = function (array $t) use ($sel, $selectedTech): string {
            return '<option value="' . (int)$t['id'] . '"' . ($t['idGarage'] ? ' data-garage="' . (int)$t['idGarage'] . '"' : '') . $sel($selectedTech, $t['id']) . '>'
                . h($t['prenom'] . ' ' . $t['nom'] . ' ' . admin_load_label((int)$t['charge']) . ($t['specialite'] ? ' (' . $t['specialite'] . ')' : '')) . '</option>';
        };
        $prefix = h($idPrefix);

        $html = '<p class="av2-form-help">Choisissez un technicien, un garage, ou les deux. Un technicien de garage est affecté avec son garage. « Actives » = interventions planifiées ou en cours.</p>';
        $html .= '<div class="av2-form-group"><label for="' . $prefix . 'Technicien">Technicien (facultatif)</label>'
            . '<select name="technicien_id" id="' . $prefix . 'Technicien" data-assign-tech>'
            . '<option value="">Aucun technicien</option>';
        if (!empty($choices['internes'])) {
            $html .= '<optgroup label="SmartAutoTrack">';
            foreach ($choices['internes'] as $t) $html .= $techOption($t);
            $html .= '</optgroup>';
        }
        foreach ($choices['parGarage'] as $nomGarage => $techs) {
            $html .= '<optgroup label="' . h((string)$nomGarage) . '">';
            foreach ($techs as $t) $html .= $techOption($t);
            $html .= '</optgroup>';
        }
        $html .= '</select></div>';

        $html .= '<div class="av2-form-group"><label for="' . $prefix . 'Garage">Garage (facultatif)</label>'
            . '<select name="garage_id" id="' . $prefix . 'Garage" data-assign-garage>'
            . '<option value="">Aucun garage</option>';
        foreach ($choices['garages'] as $g) {
            $html .= '<option value="' . (int)$g['idGarage'] . '"' . $sel($selectedGarage, $g['idGarage']) . '>'
                . h($g['nomGarage'] . ' ' . admin_load_label((int)$g['charge'])) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<p class="av2-alert error" data-assign-error hidden>Choisissez un technicien ou un garage.</p>';
        echo $html;
    }
}

if (!function_exists('admin_assign')) {
    /**
     * Affecte ou réaffecte une intervention — point d'entrée UNIQUE de
     * l'administrateur, partagé par admin/interventions.php (fenêtre
     * « Affecter la demande ») et admin/intervention_detail.php. Chaque
     * appelant gère le CSRF et son formulaire ; cette fonction fait la
     * validation métier (admin_assign_plan()) et l'écriture.
     *
     * Dans une transaction : l'intervention est verrouillée (SELECT … FOR
     * UPDATE), le technicien (statutValidation, type, garage et statut de ce
     * garage) et le garage (statutGarage) sont relus en base, puis l'UPDATE
     * reprend l'état lu (statut, technicien, garage) et doit toucher
     * exactement une ligne : deux administrateurs ne peuvent pas affecter la
     * même demande en même temps, et une intervention démarrée entre-temps
     * n'est jamais réaffectée. Le statut repasse à PLANIFIEE (une demande
     * refusée redevient active).
     *
     * Notifications (dans la transaction) : ancien technicien retiré, ancien
     * garage s'il change (et a un compte), nouveau technicien (préfixe
     * « URGENT » si une anomalie CRITIQUE ouverte est liée), nouveau garage
     * s'il a un compte, et le client. Journal : log_activity (catégorie
     * 'intervention', description « avant → après » ; 'idGarage' => false
     * quand aucun garage n'est affecté, pour que l'entrée n'aille pas dans le
     * journal d'un garage qui n'est plus concerné).
     *
     * @param PDO      $conn           Connexion à la base.
     * @param int      $interventionId Intervention à (ré)affecter.
     * @param int|null $technicienId   Technicien choisi (INTERNE ou GARAGE), ou null.
     * @param int|null $garageId       Garage choisi, ou null.
     * @param int      $adminId        Administrateur auteur (journal).
     * @return string|null null si succès, sinon un message d'erreur à afficher.
     */
    function admin_assign(PDO $conn, int $interventionId, ?int $technicienId, ?int $garageId, int $adminId): ?string {
        if ($technicienId === null && $garageId === null) {
            return 'Choisissez un technicien ou un garage.';
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT idClient, idVehicule, idTechnicien, idGarage, statut, type FROM intervention WHERE idIntervention = ? FOR UPDATE");
            $stmt->execute([$interventionId]);
            $iv = $stmt->fetch();
            if (!$iv) {
                $conn->rollBack();
                return 'Intervention introuvable.';
            }

            // Relecture en base de l'affectation demandée : du formulaire, on ne
            // garde que les identifiants.
            $tech = null;
            if ($technicienId !== null) {
                $stmt = $conn->prepare("
                    SELECT t.idTechnicien, t.typeTechnicien, t.idGarage, t.statutValidation, u.prenom, u.nom,
                           g.nomGarage, g.statutGarage AS garageStatut
                    FROM technicien t JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
                    LEFT JOIN garage g ON g.idGarage = t.idGarage
                    WHERE t.idTechnicien = ?
                ");
                $stmt->execute([$technicienId]);
                $tech = $stmt->fetch() ?: null;
                if ($tech === null) {
                    $conn->rollBack();
                    return 'Technicien introuvable.';
                }
            }
            $garage = null;
            if ($garageId !== null) {
                $stmt = $conn->prepare("SELECT idGarage, nomGarage, statutGarage FROM garage WHERE idGarage = ?");
                $stmt->execute([$garageId]);
                $garage = $stmt->fetch() ?: null;
                if ($garage === null) {
                    $conn->rollBack();
                    return 'Garage introuvable.';
                }
            }

            $plan = admin_assign_plan($iv, $tech, $garage);
            if (!$plan['ok']) {
                $conn->rollBack();
                return $plan['error'];
            }
            $newTechId = $plan['idTechnicien'];
            $newGarageId = $plan['idGarage'];

            // UPDATE conditionnel sur l'état lu sous verrou (<=> : égalité qui
            // accepte NULL) : rowCount = 0 signifie que l'intervention a changé.
            $stmt = $conn->prepare("
                UPDATE intervention SET idTechnicien = ?, idGarage = ?, statut = 'PLANIFIEE'
                WHERE idIntervention = ? AND statut = ? AND idTechnicien <=> ? AND idGarage <=> ?
            ");
            $stmt->execute([$newTechId, $newGarageId, $interventionId, $iv['statut'], $iv['idTechnicien'], $iv['idGarage']]);
            if ($stmt->rowCount() !== 1) {
                $conn->rollBack();
                return 'Cette intervention vient d\'être modifiée, merci de recharger la page.';
            }

            // Noms pour le journal et les notifications : ancienne affectation,
            // garage finalement affecté (choisi, ou celui du technicien de
            // garage), véhicule.
            $oldTechId = $iv['idTechnicien'] !== null ? (int)$iv['idTechnicien'] : null;
            $oldGarageId = $iv['idGarage'] !== null ? (int)$iv['idGarage'] : null;
            $oldTech = null;
            if ($oldTechId !== null) {
                $stmt = $conn->prepare("SELECT u.prenom, u.nom, t.typeTechnicien FROM utilisateur u LEFT JOIN technicien t ON t.idTechnicien = u.idUtilisateur WHERE u.idUtilisateur = ?");
                $stmt->execute([$oldTechId]);
                $oldTech = $stmt->fetch() ?: null;
            }
            $garageStmt = $conn->prepare("SELECT nomGarage, idUtilisateur FROM garage WHERE idGarage = ?");
            $oldGarage = null;
            if ($oldGarageId !== null) {
                $garageStmt->execute([$oldGarageId]);
                $oldGarage = $garageStmt->fetch() ?: null;
            }
            $newGarage = null;
            if ($newGarageId !== null) {
                $garageStmt->execute([$newGarageId]);
                $newGarage = $garageStmt->fetch() ?: null;
            }
            $stmt = $conn->prepare("SELECT marque, modele, immatriculation FROM vehicule WHERE idVehicule = ?");
            $stmt->execute([$iv['idVehicule']]);
            $v = $stmt->fetch();
            $vehicule = $v ? $v['marque'] . ' ' . $v['modele'] . ' (' . $v['immatriculation'] . ')' : 'véhicule';

            // Anomalie CRITIQUE encore ouverte liée à l'intervention : le
            // technicien la voit en tête de sa notification.
            $stmt = $conn->prepare("SELECT 1 FROM anomalie WHERE idIntervention = ? AND niveau = 'CRITIQUE' AND statut IN ('NOUVELLE', 'EN_COURS') LIMIT 1");
            $stmt->execute([$interventionId]);
            $isCritical = (bool)$stmt->fetchColumn();

            $motif = $iv['type'] ?: 'Intervention';
            $subject = $motif . ' — ' . $vehicule;
            $isReassignment = ($oldTechId !== null || $oldGarageId !== null);
            $techNom = $tech ? $tech['prenom'] . ' ' . $tech['nom'] : null;
            $before = admin_assign_label($oldTech ? $oldTech['prenom'] . ' ' . $oldTech['nom'] : null, $oldTech['typeTechnicien'] ?? null, $oldGarage['nomGarage'] ?? null);
            $after = admin_assign_label($techNom, $tech['typeTechnicien'] ?? null, $newGarage['nomGarage'] ?? null);

            log_activity($conn, $isReassignment ? 'Intervention réaffectée par l\'administrateur' : 'Intervention affectée par l\'administrateur', [
                'idUtilisateur' => $adminId,
                'idIntervention' => $interventionId,
                'idTechnicien' => $newTechId,
                'idGarage' => $newGarageId ?? false,
                'description' => $motif . ' — ' . ($isReassignment ? $before . ' → ' . $after : $after),
                'categorie' => 'intervention',
            ]);

            $notif = $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', ?, ?)");

            // Ancien technicien retiré : la tâche disparaît de son espace.
            if ($oldTechId !== null && $oldTechId !== $newTechId) {
                $notif->execute([$oldTechId, 'Intervention retirée',
                    'L\'intervention (' . $subject . ') qui vous était affectée a été confiée par l\'administrateur à ' . $after . '.']);
            }
            // Ancien garage, s'il change et a un compte.
            if ($oldGarageId !== null && $oldGarageId !== $newGarageId && $oldGarage && $oldGarage['idUtilisateur']) {
                $notif->execute([$oldGarage['idUtilisateur'], 'Intervention retirée',
                    'L\'intervention (' . $subject . ') a été confiée par l\'administrateur à ' . $after . ' : elle ne fait plus partie de vos interventions.']);
            }
            // Nouveau technicien, ou même technicien dont le garage change.
            if ($newTechId !== null) {
                $urgent = $isCritical ? 'URGENT — ' : '';
                if ($newTechId !== $oldTechId) {
                    $notif->execute([$newTechId, $urgent . 'Nouvelle intervention assignée',
                        ($isCritical ? 'URGENT (anomalie critique) — ' : '') . 'Vous avez été assigné à une intervention : ' . $subject
                            . ($newGarage ? '. Garage : ' . $newGarage['nomGarage'] . '.' : '.')]);
                } else {
                    $notif->execute([$newTechId, $urgent . 'Intervention mise à jour',
                        'Le garage de votre intervention (' . $subject . ') a changé : ' . ($newGarage ? $newGarage['nomGarage'] : 'aucun garage') . '.']);
                }
            }
            // Nouveau garage, s'il a un compte (garage changé, ou technicien changé).
            if ($newGarage && $newGarage['idUtilisateur'] && ($newGarageId !== $oldGarageId || $newTechId !== $oldTechId)) {
                if ($newTechId === null) {
                    $garageMsg = 'L\'administrateur vous a transmis une demande d\'intervention (' . $subject . ') : affectez-la à l\'un de vos techniciens.';
                } elseif ($tech['typeTechnicien'] === 'GARAGE') {
                    $garageMsg = 'L\'administrateur a confié une intervention (' . $subject . ') à votre technicien ' . $techNom . '.';
                } else {
                    $garageMsg = 'SmartAutoTrack vous a confié une intervention (' . $subject . '), avec son technicien ' . $techNom . '.';
                }
                $notif->execute([$newGarage['idUtilisateur'],
                    $newGarageId !== $oldGarageId ? 'Nouvelle demande d\'intervention' : 'Intervention mise à jour', $garageMsg]);
            }
            // Client, dans tous les cas.
            $notif->execute([$iv['idClient'], $isReassignment ? 'Intervention réaffectée' : 'Intervention affectée',
                'Votre intervention (' . $motif . ') a été ' . ($isReassignment ? 'réaffectée' : 'affectée') . ' à ' . $after . '.']);

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return 'Erreur lors de l\'affectation de l\'intervention.';
        }

        return null;
    }
}

if (!function_exists('admin_reassign_garage')) {
    /**
     * Ancienne entrée « affecter à un garage », conservée pour compatibilité
     * (plus appelée par les pages) : délègue à admin_assign() avec un garage
     * seul (le technicien éventuel est retiré).
     *
     * @param PDO $conn           Connexion à la base.
     * @param int $interventionId Intervention à (ré)affecter.
     * @param int $newGarageId    Garage cible, qui doit être VALIDE.
     * @return string|null null si succès, sinon un message d'erreur à afficher.
     */
    function admin_reassign_garage(PDO $conn, int $interventionId, int $newGarageId): ?string {
        return admin_assign($conn, $interventionId, null, $newGarageId, (int)($_SESSION['user_id'] ?? 0));
    }
}

if (!function_exists('admin_assign_internal')) {
    /**
     * Ancienne entrée « affecter à un technicien », conservée pour
     * compatibilité (plus appelée par les pages) : délègue à admin_assign()
     * (technicien, garage facultatif).
     *
     * @param PDO      $conn           Connexion à la base.
     * @param int      $interventionId Intervention à affecter.
     * @param int      $technicienId   Technicien validé.
     * @param int|null $garageId       Garage VALIDE, ou null.
     * @return string|null null si succès, sinon un message d'erreur à afficher.
     */
    function admin_assign_internal(PDO $conn, int $interventionId, int $technicienId, ?int $garageId = null): ?string {
        return admin_assign($conn, $interventionId, $technicienId, $garageId, (int)($_SESSION['user_id'] ?? 0));
    }
}

if (!function_exists('admin_anomaly_badge')) {
    /** Classe de badge (.av2-badge.*) d'un niveau d'anomalie : CRITIQUE en rouge. */
    function admin_anomaly_badge(?string $niveau): string {
        return ['CRITIQUE' => 'bad', 'MOYEN' => 'warn', 'FAIBLE' => 'neutral'][$niveau ?? ''] ?? 'neutral';
    }
}

if (!function_exists('av2_donut_svg')) {
    /** Donut chart SVG pur — même principe que gv2_donut_svg (garage) / tv2_donut_svg (technicien). */
    function av2_donut_svg(array $segments, int $size = 140, int $stroke = 18): string {
        $total = array_sum(array_column($segments, 'value'));
        $r = ($size - $stroke) / 2;
        $cx = $size / 2;
        $cy = $size / 2;
        $circumference = 2 * M_PI * $r;
        $svg = '<svg class="av2-donut" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#EFF0F6" stroke-width="' . $stroke . '"/>';
        // Chaque segment est un cercle en pointillé (stroke-dasharray) tourné pour
        // démarrer là où le précédent s'arrête ; départ en haut (-90°).
        if ($total > 0) {
            $offset = 0;
            foreach ($segments as $seg) {
                if ($seg['value'] <= 0) continue;
                $fraction = $seg['value'] / $total;
                $dash = $fraction * $circumference;
                $gap = $circumference - $dash;
                $rotation = -90 + ($offset / $total) * 360;
                $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . htmlspecialchars($seg['color']) . '" stroke-width="' . $stroke . '" '
                      . 'stroke-dasharray="' . round($dash, 2) . ' ' . round($gap, 2) . '" '
                      . 'transform="rotate(' . round($rotation, 2) . ' ' . $cx . ' ' . $cy . ')" stroke-linecap="butt"/>';
                $offset += $seg['value'];
            }
        }
        $svg .= '</svg>';
        return $svg;
    }
}

if (!function_exists('av2_trend_svg')) {
    /** Courbe (aire) SVG pure — même principe que gv2_trend_svg (garage) / tv2_trend_svg (technicien). */
    function av2_trend_svg(array $values, int $width = 320, int $height = 90, string $color = '#3956E8'): string {
        $count = count($values);
        if ($count === 0) return '';
        // max(1, …) évite une division par zéro quand toutes les valeurs sont nulles.
        $max = max(1, max($values));
        $stepX = $count > 1 ? $width / ($count - 1) : $width;
        $points = [];
        foreach ($values as $i => $v) {
            $x = round($i * $stepX, 2);
            $y = round($height - ($v / $max) * ($height - 10) - 4, 2);
            $points[] = [$x, $y];
        }
        $linePath = 'M ' . implode(' L ', array_map(fn($p) => $p[0] . ' ' . $p[1], $points));
        $areaPath = $linePath . " L {$width} {$height} L 0 {$height} Z";
        // Identifiant de dégradé dérivé des valeurs, pour éviter une collision si
        // plusieurs courbes sont affichées sur la même page.
        $id = 'av2trend' . substr(md5(json_encode($values)), 0, 6);
        $svg = '<svg class="av2-trend-svg" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">';
        $svg .= '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0%" stop-color="' . $color . '" stop-opacity="0.35"/>'
              . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0.02"/></linearGradient></defs>';
        $svg .= '<path d="' . $areaPath . '" fill="url(#' . $id . ')" stroke="none"/>';
        $svg .= '<path d="' . $linePath . '" fill="none" stroke="' . $color . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>';
        foreach ($points as $p) {
            $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="3" fill="#FFFFFF" stroke="' . $color . '" stroke-width="2"/>';
        }
        $svg .= '</svg>';
        return $svg;
    }
}
