<?php
/**
 * Helpers du dashboard "v2" Technicien. Réutilise les helpers génériques déjà
 * écrits pour le client (v2_relative, v2_today_fr — purs utilitaires
 * d'affichage, aucun couplage au rôle) plutôt que de les dupliquer.
 */
require_once __DIR__ . '/../../client/includes/helpers.php';
// Intitulés journalisés reconnus par tv2_phrase() : REPAIR_REPORT_ACTIVITY,
// ANOMALY_CLIENT_LOG_NAME.
require_once __DIR__ . '/../../includes/repair_report.php';
require_once __DIR__ . '/../../includes/anomaly_types.php';

if (!function_exists('technicien_log')) {
    /**
     * Enregistre une entrée dans le journal d'activité (table
     * `journalactivites`, partagée — voir includes/activity_log.php). Pour le
     * technicien, l'acteur ET le technicien concerné sont quasi toujours la
     * même personne (lui-même) : les deux sont donc renseignés par défaut
     * avec l'idUtilisateur de la session, sans que l'appelant ait à le répéter.
     */
    function technicien_log(PDO $conn, string $nomActivite, array $extra = []): void {
        $selfId = $_SESSION['user_id'] ?? null;
        log_activity($conn, $nomActivite, $extra + [
            'idUtilisateur' => $selfId,
            'idTechnicien' => $selfId,
        ]);
    }
}

if (!function_exists('tv2_status_info')) {
    /**
     * Statut "métier" affiché au technicien pour une intervention qui lui est
     * assignée — aucun nouveau statut SQL, uniquement des libellés calculés
     * à partir de la colonne `statut` existante.
     */
    function tv2_status_info(array $iv): array {
        if ($iv['statut'] === 'ANNULEE') {
            return ['key' => 'annulee', 'label' => 'Annulée', 'badge' => 'bad'];
        }
        if ($iv['statut'] === 'TERMINEE') {
            return ['key' => 'terminee', 'label' => 'Terminée', 'badge' => 'ok'];
        }
        if ($iv['statut'] === 'EN_COURS') {
            return ['key' => 'en_cours', 'label' => 'En cours', 'badge' => 'warn'];
        }
        return ['key' => 'planifiee', 'label' => 'À démarrer', 'badge' => 'info'];
    }
}

if (!function_exists('tv2_phrase')) {
    /**
     * Traduit une ligne du journal d'activité en phrase à la 2e personne
     * ("Vous avez commencé l'intervention...") quand CE technicien est bien
     * l'auteur de l'action (idUtilisateur = lui-même). Sinon (ex. le garage
     * l'a affecté à une intervention), une formulation neutre à la 3e
     * personne est utilisée. Jamais un rapport rédigé : uniquement une mise
     * en phrase automatique de l'action déjà journalisée par
     * includes/activity_log.php::log_activity() — la donnée reste la même
     * que celle vue par le garage/l'admin, seule la formulation change.
     * Le rapport de fin d'intervention (REPAIR_REPORT_ACTIVITY, écrit par le
     * technicien ou le garage) et l'anomalie déclarée par le client
     * (ANOMALY_CLIENT_LOG_NAME) ont aussi leur phrase ; le texte du rapport
     * reste affiché à part par technicien/journal.php.
     */
    function tv2_phrase(array $j, int $selfId): string {
        $isSelf = ((int)($j['idUtilisateur'] ?? 0)) === $selfId;
        $vehicule = $j['marque'] ? ($j['marque'] . ' ' . $j['modele'] . ' (' . $j['immatriculation'] . ')') : null;
        $type = $j['intervention_type'] ?: 'intervention';

        if (!$isSelf) {
            if ($j['nomActivite'] === 'Intervention assignée à un technicien') {
                return 'Le garage vous a assigné une intervention (' . $type . ')' . ($vehicule ? ' sur ' . $vehicule : '') . '.';
            }
            if ($j['nomActivite'] === ANOMALY_CLIENT_LOG_NAME) {
                return 'Le client a déclaré une anomalie (' . ($j['description'] ?: 'type non précisé') . ')' . ($vehicule ? ' sur ' . $vehicule : '') . '.';
            }
            if ($j['nomActivite'] === REPAIR_REPORT_ACTIVITY) {
                return 'Le garage a rempli le rapport de fin d\'intervention' . ($vehicule ? ' pour le véhicule ' . $vehicule : '') . ' et clôturé l\'intervention.';
            }
            return $j['nomActivite'];
        }

        switch ($j['nomActivite']) {
            case REPAIR_REPORT_ACTIVITY:
                return 'Vous avez rempli le rapport de fin d\'intervention' . ($vehicule ? ' pour le véhicule ' . $vehicule : '') . ' et clôturé l\'intervention.';
            case 'Intervention démarrée':
                return 'Vous avez commencé l\'intervention (' . $type . ')' . ($vehicule ? ' sur ' . $vehicule : '') . '.';
            case 'Intervention terminée':
                return 'Vous avez terminé l\'intervention (' . $type . ')' . ($vehicule ? ' sur ' . $vehicule : '') . '.';
            case 'Réparation renseignée et intervention clôturée':
                return 'Vous avez enregistré une réparation' . ($vehicule ? ' pour le véhicule ' . $vehicule : '') . ' et clôturé l\'intervention.';
            case 'Rapport de réparation ajouté':
                return 'Vous avez enregistré une réparation' . ($vehicule ? ' pour le véhicule ' . $vehicule : '') . '.';
            case 'Anomalie constatée':
                return 'Vous avez constaté une anomalie' . ($vehicule ? ' sur le véhicule ' . $j['immatriculation'] : '') . '.';
            default:
                return $j['nomActivite'];
        }
    }
}

if (!function_exists('tv2_donut_svg')) {
    /** Donut chart SVG pur — identique au principe déjà utilisé pour le garage (garage/includes/helpers.php::gv2_donut_svg). */
    function tv2_donut_svg(array $segments, int $size = 140, int $stroke = 18): string {
        $total = array_sum(array_column($segments, 'value'));
        $r = ($size - $stroke) / 2;
        $cx = $size / 2;
        $cy = $size / 2;
        $circumference = 2 * M_PI * $r;
        $svg = '<svg class="tv2-donut" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
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

if (!function_exists('tv2_trend_svg')) {
    /** Courbe (aire) SVG pure — identique au principe déjà utilisé pour le garage (garage/includes/helpers.php::gv2_trend_svg). */
    function tv2_trend_svg(array $values, int $width = 320, int $height = 90, string $color = '#3956E8'): string {
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
        $id = 'tv2trend' . substr(md5(json_encode($values)), 0, 6);
        $svg = '<svg class="tv2-trend-svg" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">';
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
