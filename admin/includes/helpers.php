<?php
/**
 * Helpers du dashboard "v2" Administrateur. Réutilise les helpers génériques
 * déjà écrits pour le client (v2_relative, v2_today_fr — purs utilitaires
 * d'affichage, aucun couplage au rôle) plutôt que de les dupliquer.
 */
require_once __DIR__ . '/../../client/includes/helpers.php';

if (!function_exists('av2_status_badge')) {
    /** Classe de badge (.av2-badge.*) pour un statut garage/technicien EN_ATTENTE/VALIDE/REJETE/SUSPENDU. */
    function av2_status_badge(string $statut): string {
        return ['VALIDE' => 'ok', 'EN_ATTENTE' => 'warn', 'REJETE' => 'bad', 'SUSPENDU' => 'neutral'][$statut] ?? 'neutral';
    }
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
     * NULL : il n'appartient pas forcément au nouveau garage.
     *
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

        $isReassignment = ($iv['idGarage'] !== null);
        $hadTechnicien = ($iv['idTechnicien'] !== null);

        $conn->prepare("UPDATE intervention SET idGarage = ?, idTechnicien = NULL, statut = 'PLANIFIEE' WHERE idIntervention = ?")
            ->execute([$newGarageId, $interventionId]);

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

        return null;
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
