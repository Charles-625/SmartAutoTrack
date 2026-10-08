<?php
/**
 * Composant de marque SmartAutoTrack (logo + nom).
 *
 * Chargé par config/config.php, donc disponible sur toutes les pages.
 * Les fichiers du logo sont dans assets/img/brand/ :
 *   - sat-mark.svg                : tuile bleue, lettres blanches (icône d'application) ;
 *   - sat-short.svg               : icône courte (« A » à coche), pour l'onglet du navigateur ;
 *   - sat-color-light(-notile).svg: lettres colorées pour fond clair ;
 *   - sat-color-dark-notile.svg   : lettres colorées pour fond bleu nuit ;
 *   - sat-mono.svg                : version une couleur (blanc) ;
 *   - sat-wordmark-{dark|light}.svg : mêmes lettres que les « notile », recadrées au plus près
 *                                   (sans marge transparente), utilisées par brand_lockup() ;
 *   - sat-mark-512.png, sat-short-512.png : versions PNG 512 px.
 * Le style est dans assets/css/style.css (préfixe .brand-lockup).
 */

if (!function_exists('brand_lockup')) {
    /**
     * Renvoie le HTML du bloc de marque « monogramme SAT + SmartAutoTrack + slogan ».
     *
     * @param string $variant 'dark' pour un fond bleu nuit (barres latérales),
     *                        'light' pour un fond clair (accueil). Toute autre valeur vaut 'dark'.
     * @param string $size    'sm' (pied de page : lettres 18 px de haut, sans slogan),
     *                        'md' (barres latérales : lettres 22 px de haut, nom 19 px)
     *                        ou 'lg' (accueil : lettres 26 px de haut). Toute autre valeur vaut 'md'.
     * @return string HTML prêt à afficher (aucune donnée utilisateur, rien à échapper en plus).
     */
    function brand_lockup(string $variant = 'dark', string $size = 'md'): string
    {
        $variant = $variant === 'light' ? 'light' : 'dark';
        // [largeur, hauteur] du monogramme recadré (rapport 424 × 194).
        $sizes = ['sm' => [39, 18], 'md' => [48, 22], 'lg' => [57, 26]];
        if (!isset($sizes[$size])) {
            $size = 'md';
        }
        [$w, $h] = $sizes[$size];
        $base = defined('SITE_URL') ? SITE_URL : '';
        $src = $base . 'assets/img/brand/sat-wordmark-' . $variant . '.svg';

        return '<span class="brand-lockup brand-lockup--' . $variant . ' brand-lockup--' . $size . '"'
            . ' role="img" aria-label="SmartAutoTrack">'
            . '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" class="brand-lockup__mark"'
            . ' width="' . $w . '" height="' . $h . '">'
            . '<span class="brand-lockup__text">'
            . '<span class="brand-lockup__name">'
            . '<span class="brand-lockup__smart">Smart</span><span class="brand-lockup__autotrack">AutoTrack</span>'
            . '</span>'
            . '<span class="brand-lockup__tagline">Suivi · Entretien · SAV</span>'
            . '</span>'
            . '</span>';
    }
}
