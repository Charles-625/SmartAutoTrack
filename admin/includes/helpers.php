<?php
/**
 * Helpers du dashboard "v2" Administrateur. Réutilise les helpers génériques
 * déjà écrits pour le client (v2_relative, v2_today_fr — purs utilitaires
 * d'affichage, aucun couplage au rôle) plutôt que de les dupliquer.
 *
 * Contient aussi la logique d'affectation des demandes, partagée entre
 * admin/interventions.php et admin/intervention_detail.php :
 * admin_reassign_garage() (vers un garage) et admin_assign_internal() (vers
 * un technicien SmartAutoTrack, garage partenaire en appui facultatif).
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

if (!function_exists('admin_reassign_garage')) {
    /**
     * Affecte/réaffecte une intervention à un garage — logique métier
     * partagée entre admin/interventions.php (affectation rapide depuis la
     * liste) et admin/intervention_detail.php (réaffectation complète, avec
     * historique). Chaque appelant gère lui-même le CSRF et son propre
     * formulaire ; cette fonction ne fait que la validation métier + l'écriture
     * (règle unique, jamais dupliquée entre les deux pages).
     *
     * Autorisé seulement si l'intervention est PLANIFIEE ou ANNULEE (refusée) —
     * jamais EN_COURS/TERMINEE (travail réel déjà engagé ou fini). Si un
     * technicien était déjà affecté (par l'ancien garage), il est remis à
     * NULL : il n'appartient pas forcément au nouveau garage, et il est
     * notifié du retrait. L'UPDATE reprend la condition de statut (rowCount
     * vérifié) : une intervention démarrée entre-temps n'est pas réaffectée.
     *
     * @param PDO $conn           Connexion à la base.
     * @param int $interventionId Intervention à (ré)affecter.
     * @param int $newGarageId    Garage cible, qui doit être VALIDE.
     * @return string|null null si succès, sinon un message d'erreur à afficher.
     */
    function admin_reassign_garage(PDO $conn, int $interventionId, int $newGarageId): ?string {
        $stmt = $conn->prepare("
            SELECT i.idClient, i.idGarage, i.statut, i.type, i.idTechnicien, g.nomGarage AS ancien_garage_nom
            FROM intervention i LEFT JOIN garage g ON g.idGarage = i.idGarage
            WHERE i.idIntervention = ?
        ");
        $stmt->execute([$interventionId]);
        $iv = $stmt->fetch();

        $stmt = $conn->prepare("SELECT idUtilisateur, nomGarage FROM garage WHERE idGarage = ? AND statutGarage = 'VALIDE'");
        $stmt->execute([$newGarageId]);
        $garage = $stmt->fetch();

        if (!$iv || !in_array($iv['statut'], ['PLANIFIEE', 'ANNULEE'], true)) {
            return 'Cette demande ne peut plus être réaffectée (intervention en cours ou terminée).';
        }
        if (!$garage) {
            return 'Garage introuvable ou non validé.';
        }
        if ((int)$iv['idGarage'] === $newGarageId) {
            return 'Ce garage est déjà celui affecté à cette intervention.';
        }

        // Réaffectation si un garage était déjà affecté : le libellé du journal et
        // les messages de notification diffèrent d'une première affectation.
        $isReassignment = ($iv['idGarage'] !== null);
        $hadTechnicien = ($iv['idTechnicien'] !== null);

        // Retour à PLANIFIEE : une demande ANNULEE (refusée) redevient visible pour
        // le nouveau garage.
        // L'UPDATE reprend la condition de statut : une intervention démarrée
        // entre la lecture et l'écriture (garage ou technicien) n'est jamais
        // ramenée à PLANIFIEE ni retirée à son technicien.
        $stmt = $conn->prepare("UPDATE intervention SET idGarage = ?, idTechnicien = NULL, statut = 'PLANIFIEE' WHERE idIntervention = ? AND statut IN ('PLANIFIEE', 'ANNULEE')");
        $stmt->execute([$newGarageId, $interventionId]);
        if ($stmt->rowCount() !== 1) {
            return 'Cette demande vient d\'être modifiée, merci de recharger la page.';
        }

        if ($isReassignment) {
            $logTitle = 'Intervention réaffectée par l\'administrateur';
            $logDescription = ($iv['ancien_garage_nom'] ?: 'aucun garage') . ' → ' . $garage['nomGarage']
                . ($hadTechnicien ? ' (technicien réinitialisé)' : '');
        } else {
            $logTitle = 'Intervention affectée à un garage par l\'administrateur';
            $logDescription = $iv['type'] . ' — ' . $garage['nomGarage'];
        }
        log_activity($conn, $logTitle, [
            'idUtilisateur' => $_SESSION['user_id'] ?? null,
            'idIntervention' => $interventionId,
            'idGarage' => $newGarageId,
            'description' => $logDescription,
            'categorie' => 'intervention',
        ]);

        // Notifications : le client dans tous les cas, le garage seulement s'il a
        // un compte utilisateur.
        $clientMsg = $isReassignment
            ? 'Le garage affecté à votre demande d\'intervention (' . $iv['type'] . ') a changé : elle est désormais prise en charge par ' . $garage['nomGarage'] . '.'
            : 'Votre demande d\'intervention (' . $iv['type'] . ') a été transmise à ' . $garage['nomGarage'] . '.';
        $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Garage affecté à votre demande', ?)")
            ->execute([$iv['idClient'], $clientMsg]);

        if ($garage['idUtilisateur']) {
            $garageMsg = $isReassignment
                ? 'Une intervention (' . $iv['type'] . ') vous a été réaffectée par l\'administrateur.'
                : 'Une nouvelle demande d\'intervention (' . $iv['type'] . ') vous a été transmise par l\'administrateur.';
            $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Nouvelle demande d\'intervention', ?)")
                ->execute([$garage['idUtilisateur'], $garageMsg]);
        }

        // Le technicien retiré (ex. technicien SmartAutoTrack affecté par
        // l'admin) est prévenu : la tâche disparaît de son espace.
        if ($hadTechnicien) {
            $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', 'Intervention retirée', ?)")
                ->execute([$iv['idTechnicien'], 'L\'intervention (' . $iv['type'] . ') qui vous était affectée a été confiée par l\'administrateur au garage ' . $garage['nomGarage'] . '.']);
        }

        return null;
    }
}

if (!function_exists('admin_assign_internal')) {
    /**
     * Affecte une demande à un technicien SmartAutoTrack (technicien INTERNE),
     * avec, facultativement, un garage partenaire en appui (lieu de la
     * réparation). Logique métier partagée entre admin/interventions.php et
     * admin/intervention_detail.php, comme admin_reassign_garage() : chaque
     * appelant gère le CSRF et son formulaire.
     *
     * Demandes acceptées : sans technicien, PLANIFIEE ou ANNULEE, et sans
     * garage ou refusées par un garage — c.-à-d. les demandes « à affecter »
     * (même définition que le compteur non_affectees). Une demande encore
     * en attente chez un garage n'est pas prise : c'est à lui d'y répondre.
     * Une demande ANNULEE (refusée) repasse à PLANIFIEE ; le garage qui l'a
     * refusée est remplacé par le garage en appui choisi, ou retiré.
     *
     * Le technicien est vérifié en base (typeTechnicien = 'INTERNE',
     * statutValidation = 'VALIDE'), le garage en appui aussi (statutGarage =
     * 'VALIDE'). L'intervention est verrouillée (SELECT … FOR UPDATE) et
     * l'UPDATE reprend les conditions : deux administrateurs qui affectent la
     * même demande en même temps ne peuvent pas l'affecter deux fois.
     *
     * Notifie le technicien (préfixe « URGENT » si une anomalie CRITIQUE
     * ouverte est liée à l'intervention), le garage en appui s'il a un compte,
     * et le client ; journalise l'affectation (catégorie 'intervention').
     *
     * @param PDO      $conn           Connexion à la base.
     * @param int      $interventionId Intervention à affecter.
     * @param int      $technicienId   Technicien INTERNE validé.
     * @param int|null $garageId       Garage partenaire VALIDE en appui, ou null.
     * @return string|null null si succès, sinon un message d'erreur à afficher.
     */
    function admin_assign_internal(PDO $conn, int $interventionId, int $technicienId, ?int $garageId = null): ?string {
        $stmt = $conn->prepare("
            SELECT u.prenom, u.nom FROM technicien t JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
            WHERE t.idTechnicien = ? AND t.typeTechnicien = 'INTERNE' AND t.statutValidation = 'VALIDE'
        ");
        $stmt->execute([$technicienId]);
        $technicien = $stmt->fetch();
        if (!$technicien) {
            return 'Technicien introuvable, non validé ou rattaché à un garage.';
        }

        $garage = null;
        if ($garageId !== null) {
            $stmt = $conn->prepare("SELECT idUtilisateur, nomGarage FROM garage WHERE idGarage = ? AND statutGarage = 'VALIDE'");
            $stmt->execute([$garageId]);
            $garage = $stmt->fetch();
            if (!$garage) {
                return 'Garage introuvable ou non validé.';
            }
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT i.idClient, i.type, i.statut, v.marque, v.modele, v.immatriculation
                FROM intervention i JOIN vehicule v ON v.idVehicule = i.idVehicule
                WHERE i.idIntervention = ?
                  AND i.idTechnicien IS NULL AND i.statut IN ('PLANIFIEE', 'ANNULEE')
                  AND (i.idGarage IS NULL OR i.statut = 'ANNULEE')
                FOR UPDATE
            ");
            $stmt->execute([$interventionId]);
            $iv = $stmt->fetch();
            if (!$iv) {
                $conn->rollBack();
                return 'Cette demande n\'est plus à affecter (déjà affectée, en attente chez un garage, en cours ou terminée).';
            }

            // Mêmes conditions que la lecture : rowCount = 0 signifie que la
            // demande a changé entre-temps.
            $stmt = $conn->prepare("
                UPDATE intervention SET idTechnicien = ?, idGarage = ?, statut = 'PLANIFIEE'
                WHERE idIntervention = ? AND idTechnicien IS NULL AND statut IN ('PLANIFIEE', 'ANNULEE')
                  AND (idGarage IS NULL OR statut = 'ANNULEE')
            ");
            $stmt->execute([$technicienId, $garageId, $interventionId]);
            if ($stmt->rowCount() !== 1) {
                $conn->rollBack();
                return 'Cette demande vient d\'être modifiée, merci de recharger la page.';
            }

            // Anomalie CRITIQUE encore ouverte liée à la demande : le technicien
            // la voit en tête de sa notification.
            $stmt = $conn->prepare("SELECT 1 FROM anomalie WHERE idIntervention = ? AND niveau = 'CRITIQUE' AND statut IN ('NOUVELLE', 'EN_COURS') LIMIT 1");
            $stmt->execute([$interventionId]);
            $isCritical = (bool)$stmt->fetchColumn();

            $technicienNom = $technicien['prenom'] . ' ' . $technicien['nom'];
            $vehicule = $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ')';
            $motif = $iv['type'] ?: 'Intervention';

            // Garage en appui explicite ; sans appui, 'idGarage' => false : l'entrée
            // ne va jamais dans le journal du garage qui avait refusé la demande.
            log_activity($conn, 'Intervention affectée à un technicien SmartAutoTrack par l\'administrateur', [
                'idUtilisateur' => $_SESSION['user_id'] ?? null,
                'idIntervention' => $interventionId,
                'idTechnicien' => $technicienId,
                'idGarage' => $garageId ?? false,
                'description' => $motif . ' — ' . $technicienNom . ($garage ? ' (garage : ' . $garage['nomGarage'] . ')' : ''),
                'categorie' => 'intervention',
            ]);

            $notif = $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'intervention', ?, ?)");
            $notif->execute([
                $technicienId,
                ($isCritical ? 'URGENT — ' : '') . 'Nouvelle intervention assignée',
                ($isCritical ? 'URGENT (anomalie critique) — ' : '') . 'Vous avez été assigné à une intervention : ' . $motif . ' — ' . $vehicule
                    . ($garage ? '. Garage : ' . $garage['nomGarage'] . '.' : '.'),
            ]);
            if ($garage && $garage['idUtilisateur']) {
                $notif->execute([
                    $garage['idUtilisateur'],
                    'Nouvelle intervention SmartAutoTrack',
                    'SmartAutoTrack vous a confié une intervention (' . $motif . ' — ' . $vehicule . '), avec son technicien ' . $technicienNom . '.',
                ]);
            }
            $notif->execute([
                $iv['idClient'],
                'Technicien affecté à votre demande',
                'Un technicien SmartAutoTrack a été affecté à votre demande (' . $motif . ') : ' . $technicienNom
                    . ($garage ? ', avec le garage ' . $garage['nomGarage'] . '.' : '.'),
            ]);

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return 'Erreur lors de l\'affectation au technicien.';
        }

        return null;
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
