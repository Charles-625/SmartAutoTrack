<?php
/**
 * Helpers du dashboard "v2" Garage. Réutilise les helpers génériques déjà
 * écrits pour le client (v2_relative, v2_today_fr — aucun couplage au rôle
 * client, purs utilitaires d'affichage) plutôt que de les dupliquer.
 */
require_once __DIR__ . '/../../client/includes/helpers.php';

if (!function_exists('garage_log')) {
    /**
     * Enregistre une entrée dans le journal d'activité (table
     * `journalactivites`, partagée avec les autres rôles — voir
     * includes/activity_log.php::log_activity()). L'acteur est toujours le
     * garage actuellement connecté (session courante) ; idTechnicien reste
     * NULL pour une action prise par le garage lui-même (ex. acceptation
     * d'une demande), et renseigné quand l'action concerne spécifiquement un
     * technicien (ex. démarrage d'une tâche, affectation).
     *
     * $extra permet de préciser, si pertinent : idReparation, idAnomalie,
     * categorie (déduite automatiquement sinon).
     */
    function garage_log(PDO $conn, int $idIntervention, string $nomActivite, ?string $description = null, ?int $idTechnicien = null, array $extra = []): void {
        log_activity($conn, $nomActivite, $extra + [
            'idUtilisateur' => $_SESSION['user_id'] ?? null,
            'idIntervention' => $idIntervention,
            'idTechnicien' => $idTechnicien,
            'description' => $description,
        ]);
    }
}

if (!function_exists('garage_status_info')) {
    /**
     * Détermine le statut "métier" affiché au garage pour une intervention,
     * à partir des colonnes réellement existantes (statut + idTechnicien) —
     * aucun nouveau statut SQL : "Nouvelle demande" et "Acceptée" sont des
     * libellés calculés, pas des valeurs stockées.
     */
    function garage_status_info(array $iv): array {
        if ($iv['statut'] === 'ANNULEE') {
            return ['key' => 'annulee', 'label' => 'Refusée / Annulée', 'badge' => 'bad'];
        }
        if ($iv['statut'] === 'TERMINEE') {
            return ['key' => 'terminee', 'label' => 'Terminée', 'badge' => 'ok'];
        }
        if ($iv['statut'] === 'EN_COURS') {
            return ['key' => 'en_cours', 'label' => 'En cours', 'badge' => 'warn'];
        }
        // PLANIFIEE en base :
        if (empty($iv['idTechnicien'])) {
            return ['key' => 'nouvelle', 'label' => 'Nouvelle demande', 'badge' => 'info'];
        }
        return ['key' => 'planifiee', 'label' => 'Planifiée', 'badge' => 'neutral'];
    }
}

if (!function_exists('gv2_donut_svg')) {
    /**
     * Petit donut chart en SVG pur (pas de dépendance JS/lib externe,
     * cohérent avec les icônes déjà à la main du projet), calculé à partir
     * de segments réels [ ['value' => int, 'color' => '#hex'], ... ].
     */
    function gv2_donut_svg(array $segments, int $size = 140, int $stroke = 18): string {
        $total = array_sum(array_column($segments, 'value'));
        $r = ($size - $stroke) / 2;
        $cx = $size / 2;
        $cy = $size / 2;
        $circumference = 2 * M_PI * $r;
        $svg = '<svg class="gv2-donut" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#EEF2F2" stroke-width="' . $stroke . '"/>';
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

if (!function_exists('gv2_trend_svg')) {
    /**
     * Petite courbe (aire) en SVG pur pour une série de valeurs (ex. nombre
     * d'interventions par jour sur les 7 derniers jours). Pas de librairie —
     * un chemin quadratique simple calculé à partir des points réels.
     */
    function gv2_trend_svg(array $values, int $width = 320, int $height = 90, string $color = '#0D9488'): string {
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
        $id = 'gv2trend' . substr(md5(json_encode($values)), 0, 6);
        $svg = '<svg class="gv2-trend-svg" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">';
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
