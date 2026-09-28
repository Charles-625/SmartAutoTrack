<?php
/**
 * Petits helpers d'affichage partagés par les pages "v2" du client particulier.
 * require_once (jamais include simple) pour éviter toute redéclaration si
 * plusieurs pages le chargent dans le même flux.
 */

if (!function_exists('v2_today_fr')) {
    /**
     * Date du jour en français ("23 septembre 2026"), sans dépendre de
     * strftime() (déprécié depuis PHP 8.1) ni de l'extension intl.
     */
    function v2_today_fr() {
        $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return date('j') . ' ' . $mois[(int)date('n')] . ' ' . date('Y');
    }
}

if (!function_exists('v2_address_tokens')) {
    /**
     * Découpe une adresse libre en mots significatifs (>= 3 lettres/chiffres),
     * accents neutralisés — pour une comparaison textuelle simple entre deux
     * adresses (pas un géocodage réel : aucune coordonnée GPS n'existe en base).
     */
    function v2_address_tokens(?string $adresse): array {
        if (!$adresse) return [];
        $normalized = strtolower($adresse);
        $normalized = strtr($normalized, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        preg_match_all('/[a-z0-9]+/', $normalized, $m);
        return array_values(array_unique(array_filter($m[0], function ($w) { return strlen($w) >= 3; })));
    }
}

if (!function_exists('v2_recommend_garages')) {
    /**
     * Classe les garages partenaires actifs pour une nouvelle demande
     * d'intervention, à partir des SEULES données réellement disponibles en
     * base — pas d'IA, pas de "meilleur choix" automatique :
     *   - charge actuelle (nombre d'interventions PLANIFIEE/EN_COURS déjà en
     *     cours dans le garage) → un garage moins chargé est mieux classé,
     *     comme proxy honnête de "disponibilité" ;
     *   - proximité textuelle très simple entre l'adresse du client et celle
     *     du garage (mots en commun, ex. la ville) → proxy de "localisation",
     *     PAS un géocodage (aucune coordonnée GPS n'existe dans le schéma).
     *   - la "spécialité" d'un garage n'est délibérément pas utilisée : cette
     *     donnée n'existe qu'au niveau d'un technicien (technicien.specialite),
     *     jamais au niveau du garage lui-même.
     * Le premier élément retourné est marqué 'recommended' => true, à
     * présenter comme "Garage recommandé" — jamais comme une sélection
     * automatique : le client reste entièrement libre de choisir un autre
     * garage dans le reste de la liste.
     *
     * @return array Garages VALIDE triés (meilleur en premier), chacun avec
     *                'charge' (int, interventions actives) et 'recommended' (bool).
     */
    function v2_recommend_garages(PDO $conn, ?string $clientAdresse = null): array {
        $stmt = $conn->query("
            SELECT g.idGarage, g.nomGarage, g.adresse,
                   (SELECT COUNT(*) FROM intervention i WHERE i.idGarage = g.idGarage AND i.statut IN ('PLANIFIEE', 'EN_COURS')) AS charge
            FROM garage g
            WHERE g.statutGarage = 'VALIDE'
            ORDER BY g.nomGarage
        ");
        $garages = $stmt->fetchAll();
        if (empty($garages)) return [];

        $clientTokens = v2_address_tokens($clientAdresse);

        foreach ($garages as &$g) {
            $g['charge'] = (int)$g['charge'];
            $garageTokens = v2_address_tokens($g['adresse']);
            $locationScore = ($clientTokens && $garageTokens) ? count(array_intersect($clientTokens, $garageTokens)) : 0;
            // La proximité de localisation prime ; la charge départage ensuite
            // (poids arbitraire mais transparent : un seul mot d'adresse en
            // commun pèse plus que n'importe quel écart de charge réaliste).
            $g['score'] = ($locationScore * 1000) - $g['charge'];
            $g['recommended'] = false;
        }
        unset($g);

        usort($garages, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $garages[0]['recommended'] = true;

        return $garages;
    }
}

if (!function_exists('v2_relative')) {
    /**
     * Petite mise en forme relative (français), utilisée pour les anomalies,
     * notifications et demandes d'intervention récentes.
     *
     * Le diff est calculé contre l'heure du serveur MySQL ($dbNow, celle qui a
     * horodaté les lignes via CURRENT_TIMESTAMP), pas contre l'heure PHP : les
     * deux serveurs peuvent avoir un fuseau différent (constaté en local :
     * decalage d'1h entre PHP/Europe-Berlin et MySQL/SYSTEM), ce qui donnerait
     * sinon des "il y a 1 h" pour un événement qui vient d'arriver.
     */
    function v2_relative($datetime, $dbNow) {
        if (!$datetime) return '';
        $ts = strtotime($datetime);
        $now = strtotime($dbNow) ?: time();
        if (!$ts) return '';
        $diff = max(0, $now - $ts);
        if ($diff < 60) return "à l'instant";
        if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
        if ($diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d', $now)) return 'il y a ' . floor($diff / 3600) . ' h';
        if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day', $now))) return 'hier';
        return date('d/m/Y', $ts);
    }
}
