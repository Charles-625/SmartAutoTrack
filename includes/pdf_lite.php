<?php
/**
 * PdfLite : petit générateur PDF autonome, en PHP pur, sans aucune
 * dépendance (vendor/ n'est pas déployé), suffisant pour un document texte
 * comme le rapport de fin d'intervention (ajax/download_report.php?format=pdf).
 *
 * Ce qu'il sait faire :
 *   - pages A4 portrait, marges, saut de page automatique (ensureSpace(),
 *     writeText()) ;
 *   - polices standard Helvetica et Helvetica-Bold, non incorporées (tout
 *     lecteur PDF les fournit), en WinAnsiEncoding : le texte UTF-8 est
 *     converti en Windows-1252 (toWinAnsi()), un caractère non représentable
 *     devient « ? » ;
 *   - mesure du texte avec les largeurs Adobe AFM des deux polices
 *     (stringWidth()) et coupure en lignes selon la largeur disponible
 *     (wrapText()), retours à la ligne compris, espaces insécables après
 *     « et avant » : ; ! ? ;
 *   - rectangles pleins et/ou contours en couleur, lignes horizontales ou
 *     quelconques ;
 *   - pied de page sur chaque page : texte libre à gauche (setFooterText()),
 *     « Page n / N » à droite, ajouté à la génération (output()).
 *
 * Coordonnées : en points (1/72 de pouce), origine en HAUT à gauche, y vers
 * le bas (plus naturel pour écrire un document) ; la conversion vers le
 * repère PDF (origine en bas à gauche) est faite ici.
 * Sortie : output() renvoie le binaire PDF 1.4 complet (table xref aux
 * offsets exacts, trailer, %%EOF) ; les flux sont compressés (FlateDecode)
 * quand zlib est disponible, sauf setCompression(false) (tests).
 */

final class PdfLite
{
    /** Largeur d'une page A4 portrait, en points. */
    public const PAGE_WIDTH = 595.28;
    /** Hauteur d'une page A4 portrait, en points. */
    public const PAGE_HEIGHT = 841.89;

    /**
     * Largeurs Helvetica (Adobe AFM, unités de 1/1000 de corps) des codes
     * Windows-1252 32 à 255, par lignes de 16. Les codes non définis de
     * Windows-1252 (127, 129, 141, 143, 144, 157) prennent la largeur de la puce.
     */
    private const WIDTHS_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 350,
        556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
        350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    /** Largeurs Helvetica-Bold (Adobe AFM), mêmes conventions que WIDTHS_REGULAR. */
    private const WIDTHS_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 350,
        556, 350, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
        350, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 350, 500, 667,
        278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
        611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
    ];

    /**
     * Caractères Unicode placés par Windows-1252 dans la plage 0x80-0x9F
     * (point de code => octet). Les plages 0x20-0x7E et 0xA0-0xFF sont
     * identiques à Unicode.
     */
    private const CP1252_EXTRA = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85,
        0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A,
        0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
        0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
        0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /** @var string[] Flux de contenu de chaque page (opérateurs PDF). */
    private array $pages = [];
    private float $marginLeft;
    private float $marginRight;
    private float $marginTop;
    private float $marginBottom;
    /** Position verticale courante (haut de la prochaine ligne), depuis le haut de la page. */
    private float $y = 0.0;
    private bool $bold = false;
    private float $fontSize = 10.0;
    private string $textColor = '0 0 0';
    private string $footerText = '';
    private string $title = '';
    private bool $compress;

    /**
     * @param float $margin       Marges gauche, droite et haute, en points.
     * @param float $marginBottom Marge basse (laisse la place au pied de page).
     */
    public function __construct(float $margin = 50.0, float $marginBottom = 60.0)
    {
        $this->marginLeft = $margin;
        $this->marginRight = $margin;
        $this->marginTop = $margin;
        $this->marginBottom = $marginBottom;
        $this->compress = function_exists('gzcompress');
    }

    /** Titre du document (dictionnaire Info, affiché par le lecteur PDF). */
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /** Texte du pied de page, à gauche, répété sur chaque page. */
    public function setFooterText(string $text): void
    {
        $this->footerText = $text;
    }

    /** Active ou non la compression des flux (sans effet si zlib manque). */
    public function setCompression(bool $compress): void
    {
        $this->compress = $compress && function_exists('gzcompress');
    }

    /** Ajoute une page et place le curseur en haut de la zone utile. */
    public function addPage(): void
    {
        $this->pages[] = '';
        $this->y = $this->marginTop;
    }

    /** Nombre de pages actuel. */
    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Position verticale courante, depuis le haut de la page. */
    public function getY(): float
    {
        return $this->y;
    }

    /** Déplace le curseur vertical. */
    public function setY(float $y): void
    {
        $this->y = $y;
    }

    /** Abscisse du bord gauche de la zone utile. */
    public function leftX(): float
    {
        return $this->marginLeft;
    }

    /** Largeur de la zone utile (page moins les marges gauche et droite). */
    public function contentWidth(): float
    {
        return self::PAGE_WIDTH - $this->marginLeft - $this->marginRight;
    }

    /** Choisit la police courante : Helvetica ou Helvetica-Bold, et le corps en points. */
    public function setFont(bool $bold, float $size): void
    {
        $this->bold = $bold;
        $this->fontSize = $size;
    }

    /** Couleur du texte (composantes 0-255). */
    public function setTextColor(int $r, int $g, int $b): void
    {
        $this->textColor = self::rgb([$r, $g, $b]);
    }

    /**
     * Saute à une nouvelle page si la hauteur demandée ne tient plus avant
     * la marge basse.
     *
     * @param float $height Hauteur nécessaire, en points.
     * @return bool true si une page a été ajoutée.
     */
    public function ensureSpace(float $height): bool
    {
        if (!$this->pages) {
            $this->addPage();
            return true;
        }
        if ($this->y + $height > self::PAGE_HEIGHT - $this->marginBottom) {
            $this->addPage();
            return true;
        }
        return false;
    }

    /**
     * Rectangle plein et/ou contour.
     *
     * @param int[]|null $fill   Couleur de remplissage [r, g, b], null = pas de fond.
     * @param int[]|null $stroke Couleur du contour [r, g, b], null = pas de contour.
     */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill, ?array $stroke = null, float $lineWidth = 0.8): void
    {
        if ($fill === null && $stroke === null) return;
        $ops = 'q ';
        if ($fill !== null) $ops .= self::rgb($fill) . ' rg ';
        if ($stroke !== null) $ops .= self::rgb($stroke) . ' RG ' . self::num($lineWidth) . ' w ';
        $ops .= self::num($x) . ' ' . self::num(self::PAGE_HEIGHT - $y - $h) . ' ' . self::num($w) . ' ' . self::num($h) . ' re ';
        $ops .= ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->out($ops . ' Q');
    }

    /**
     * Trait d'un point à un autre (horizontal si $y1 = $y2).
     *
     * @param int[] $color Couleur [r, g, b].
     */
    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [0, 0, 0], float $width = 0.5): void
    {
        $this->out('q ' . self::rgb($color) . ' RG ' . self::num($width) . ' w '
            . self::num($x1) . ' ' . self::num(self::PAGE_HEIGHT - $y1) . ' m '
            . self::num($x2) . ' ' . self::num(self::PAGE_HEIGHT - $y2) . ' l S Q');
    }

    /**
     * Écrit une ligne de texte (sans coupure) dans la police et la couleur
     * courantes.
     *
     * @param float  $x        Abscisse du début du texte.
     * @param float  $baseline Ordonnée de la ligne de base, depuis le haut.
     * @param string $text     Texte UTF-8.
     */
    public function text(float $x, float $baseline, string $text): void
    {
        $this->textEncoded($x, $baseline, self::toWinAnsi($text));
    }

    /** Comme text(), mais aligné à droite sur l'abscisse $xRight. */
    public function textRight(float $xRight, float $baseline, string $text): void
    {
        $enc = self::toWinAnsi($text);
        $this->textEncoded($xRight - $this->encodedWidth($enc, $this->bold, $this->fontSize), $baseline, $enc);
    }

    /**
     * Largeur d'un texte UTF-8, en points.
     *
     * @param bool|null  $bold Police grasse ; null = police courante.
     * @param float|null $size Corps ; null = corps courant.
     */
    public function stringWidth(string $text, ?bool $bold = null, ?float $size = null): float
    {
        return $this->encodedWidth(self::toWinAnsi($text), $bold ?? $this->bold, $size ?? $this->fontSize);
    }

    /**
     * Coupe un texte UTF-8 en lignes ne dépassant pas $width dans la police
     * courante : retours à la ligne respectés, coupure entre les mots, mot
     * trop long coupé caractère par caractère. Une ligne vide du texte donne
     * une ligne vide.
     *
     * @return string[] Lignes UTF-8 (après conversion Windows-1252 : un
     *                  caractère non représentable y apparaît en « ? »).
     */
    public function wrapText(string $text, float $width): array
    {
        return array_map([self::class, 'fromWinAnsi'], $this->wrapEncoded(self::toWinAnsi($text), $width));
    }

    /**
     * Écrit un paragraphe coupé en lignes à partir du curseur, en passant à
     * la page suivante quand la marge basse est atteinte. Le curseur est
     * placé sous la dernière ligne.
     *
     * @param string $text       Texte UTF-8 (retours à la ligne acceptés).
     * @param float  $x          Abscisse du bord gauche du paragraphe.
     * @param float  $width      Largeur disponible.
     * @param float  $lineHeight Interligne, en points.
     * @return int Nombre de lignes écrites.
     */
    public function writeText(string $text, float $x, float $width, float $lineHeight): int
    {
        $lines = $this->wrapEncoded(self::toWinAnsi($text), $width);
        foreach ($lines as $line) {
            $this->ensureSpace($lineHeight);
            if ($line !== '') $this->textEncoded($x, $this->y + $this->fontSize * 0.8, $line);
            $this->y += $lineHeight;
        }
        return count($lines);
    }

    /**
     * Assemble le document : objets, pied de page de chaque page, table
     * xref (offset exact de chaque objet) et trailer.
     *
     * @return string Binaire PDF complet.
     */
    public function output(): string
    {
        if (!$this->pages) $this->addPage();
        $count = count($this->pages);

        // Numérotation : 1 catalogue, 2 arbre des pages, 3 et 4 polices,
        // 5 informations, puis pour chaque page son objet et son flux.
        $objects = [];
        $kids = [];
        for ($i = 0; $i < $count; $i++) $kids[] = (6 + 2 * $i) . ' 0 R';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $info = '<< /Producer ' . self::textString('SmartAutoTrack (PdfLite)')
            . ' /CreationDate (D:' . date('YmdHis') . ')';
        if ($this->title !== '') $info .= ' /Title ' . self::textString($this->title);
        $objects[5] = $info . ' >>';

        foreach ($this->pages as $i => $content) {
            $content .= $this->footerOps($i + 1, $count);
            $filter = '';
            if ($this->compress) {
                $content = gzcompress($content);
                $filter = ' /Filter /FlateDecode';
            }
            $objects[6 + 2 * $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . self::num(self::PAGE_WIDTH) . ' ' . self::num(self::PAGE_HEIGHT) . ']'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (7 + 2 * $i) . ' 0 R >>';
            $objects[7 + 2 * $i] = '<< /Length ' . strlen($content) . $filter . " >>\nstream\n" . $content . "\nendstream";
        }

        // Le commentaire binaire de la 2e ligne signale un fichier binaire aux outils de transfert.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        // Chaque entrée fait exactement 20 octets (fin de ligne « espace + LF »).
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) $pdf .= sprintf('%010d 00000 n ', $offsets[$n]) . "\n";
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    /**
     * Convertit un texte UTF-8 en Windows-1252 (WinAnsiEncoding). Les
     * espaces insécables fines (U+202F) deviennent des insécables, les
     * tabulations des espaces ; tout autre caractère non représentable, de
     * contrôle ou mal encodé devient « ? ».
     */
    public static function toWinAnsi(string $text): string
    {
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len;) {
            $c = ord($text[$i]);
            // Décodage UTF-8 manuel (aucune dépendance à mbstring/iconv).
            if ($c < 0x80) {
                $cp = $c; $n = 1;
            } elseif ($c >= 0xC2 && $c <= 0xDF) {
                $cp = $c & 0x1F; $n = 2;
            } elseif ($c >= 0xE0 && $c <= 0xEF) {
                $cp = $c & 0x0F; $n = 3;
            } elseif ($c >= 0xF0 && $c <= 0xF4) {
                $cp = $c & 0x07; $n = 4;
            } else {
                $out .= '?'; $i++;
                continue;
            }
            $ok = $i + $n <= $len;
            for ($k = 1; $ok && $k < $n; $k++) {
                $b = ord($text[$i + $k]);
                if (($b & 0xC0) !== 0x80) { $ok = false; break; }
                $cp = ($cp << 6) | ($b & 0x3F);
            }
            if (!$ok) {
                $out .= '?'; $i++;
                continue;
            }
            $i += $n;

            if ($cp === 0x09) $out .= ' ';
            elseif ($cp === 0x0A || $cp === 0x0D) $out .= chr($cp);
            elseif (($cp >= 0x20 && $cp <= 0x7E) || ($cp >= 0xA0 && $cp <= 0xFF)) $out .= chr($cp);
            elseif ($cp === 0x202F) $out .= "\xA0";
            elseif (isset(self::CP1252_EXTRA[$cp])) $out .= chr(self::CP1252_EXTRA[$cp]);
            else $out .= '?';
        }
        return $out;
    }

    /** Conversion inverse de toWinAnsi() : texte Windows-1252 vers UTF-8. */
    public static function fromWinAnsi(string $text): string
    {
        static $reverse = null;
        if ($reverse === null) $reverse = array_flip(self::CP1252_EXTRA);
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($text[$i]);
            $cp = $c < 0x80 || $c >= 0xA0 ? $c : ($reverse[$c] ?? 0x3F);
            $out .= self::utf8($cp);
        }
        return $out;
    }

    /** Échappe une chaîne littérale PDF : \ ( ) et le retour chariot. */
    public static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', '\\r'], $s);
    }

    // ------------------------------------------------------------------
    // Fonctions internes
    // ------------------------------------------------------------------

    /** Ajoute des opérateurs au flux de la page courante (créée au besoin). */
    private function out(string $ops): void
    {
        if (!$this->pages) $this->addPage();
        $this->pages[count($this->pages) - 1] .= $ops . "\n";
    }

    /** Écrit une ligne déjà convertie en Windows-1252. */
    private function textEncoded(float $x, float $baseline, string $enc): void
    {
        $this->out('BT /' . ($this->bold ? 'F2' : 'F1') . ' ' . self::num($this->fontSize) . ' Tf '
            . $this->textColor . ' rg ' . self::num($x) . ' ' . self::num(self::PAGE_HEIGHT - $baseline) . ' Td ('
            . self::escape($enc) . ') Tj ET');
    }

    /** Largeur, en points, d'un texte déjà converti en Windows-1252. */
    private function encodedWidth(string $enc, bool $bold, float $size): float
    {
        $table = $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR;
        $units = 0;
        $len = strlen($enc);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($enc[$i]);
            $units += $c >= 32 ? $table[$c - 32] : 278;
        }
        return $units * $size / 1000;
    }

    /**
     * Coupe un texte Windows-1252 en lignes (voir wrapText()). L'espace qui
     * suit « et celle qui précède » : ; ! ? deviennent insécables (0xA0) :
     * un guillemet ou un signe de ponctuation ne reste jamais seul en début
     * ou en fin de ligne.
     *
     * @return string[] Lignes Windows-1252.
     */
    private function wrapEncoded(string $enc, float $width): array
    {
        $lines = [];
        $space = $this->encodedWidth(' ', $this->bold, $this->fontSize);
        $enc = preg_replace(['/\xAB /', '/ (?=[\xBB:;!?])/'], ["\xAB\xA0", "\xA0"], $enc);
        foreach (preg_split('/\r\n|\r|\n/', $enc) as $paragraph) {
            $current = '';
            $currentWidth = 0.0;
            foreach (explode(' ', trim($paragraph, ' ')) as $word) {
                if ($word === '') continue;
                $w = $this->encodedWidth($word, $this->bold, $this->fontSize);
                if ($current !== '' && $currentWidth + $space + $w <= $width) {
                    $current .= ' ' . $word;
                    $currentWidth += $space + $w;
                    continue;
                }
                if ($current !== '') $lines[] = $current;
                // Mot plus large que la ligne : coupé caractère par caractère.
                while ($w > $width && strlen($word) > 1) {
                    $cut = '';
                    $cutWidth = 0.0;
                    for ($i = 0, $n = strlen($word); $i < $n; $i++) {
                        $cw = $this->encodedWidth($word[$i], $this->bold, $this->fontSize);
                        if ($cut !== '' && $cutWidth + $cw > $width) break;
                        $cut .= $word[$i];
                        $cutWidth += $cw;
                    }
                    $lines[] = $cut;
                    $word = substr($word, strlen($cut));
                    $w = $this->encodedWidth($word, $this->bold, $this->fontSize);
                }
                $current = $word;
                $currentWidth = $w;
            }
            $lines[] = $current;
        }
        return $lines;
    }

    /** Opérateurs du pied de page : filet, texte libre à gauche, « Page n / N » à droite. */
    private function footerOps(int $page, int $count): string
    {
        $y = self::PAGE_HEIGHT - $this->marginBottom + 22;
        $right = self::PAGE_WIDTH - $this->marginRight;
        $ops = 'q 0.82 0.85 0.9 RG 0.5 w ' . self::num($this->marginLeft) . ' ' . self::num(self::PAGE_HEIGHT - $y + 12) . ' m '
            . self::num($right) . ' ' . self::num(self::PAGE_HEIGHT - $y + 12) . " l S Q\n";
        $grey = '0.42 0.45 0.5';
        if ($this->footerText !== '') {
            $ops .= 'BT /F1 8 Tf ' . $grey . ' rg ' . self::num($this->marginLeft) . ' ' . self::num(self::PAGE_HEIGHT - $y)
                . ' Td (' . self::escape(self::toWinAnsi($this->footerText)) . ") Tj ET\n";
        }
        $label = 'Page ' . $page . ' / ' . $count;
        $x = $right - $this->encodedWidth($label, false, 8);
        $ops .= 'BT /F1 8 Tf ' . $grey . ' rg ' . self::num($x) . ' ' . self::num(self::PAGE_HEIGHT - $y)
            . ' Td (' . $label . ") Tj ET\n";
        return $ops;
    }

    /** Nombre au format PDF (point décimal, 2 décimales au plus, indépendant de la locale). */
    private static function num(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** Couleur [r, g, b] 0-255 vers « r g b » 0-1 pour les opérateurs rg / RG. */
    private static function rgb(array $c): string
    {
        return self::num(($c[0] ?? 0) / 255) . ' ' . self::num(($c[1] ?? 0) / 255) . ' ' . self::num(($c[2] ?? 0) / 255);
    }

    /** Chaîne de texte du dictionnaire Info, en UTF-16BE (hexadécimal, avec BOM). */
    private static function textString(string $utf8): string
    {
        $hex = 'FEFF';
        foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = self::codepoint($ch);
            if ($cp >= 0x10000) {
                $cp -= 0x10000;
                $hex .= sprintf('%04X%04X', 0xD800 | ($cp >> 10), 0xDC00 | ($cp & 0x3FF));
            } else {
                $hex .= sprintf('%04X', $cp);
            }
        }
        return '<' . $hex . '>';
    }

    /** Point de code d'un caractère UTF-8 valide. */
    private static function codepoint(string $ch): int
    {
        $c = ord($ch[0]);
        if ($c < 0x80) return $c;
        $n = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : 2);
        $cp = $c & (0xFF >> ($n + 1));
        for ($k = 1; $k < $n; $k++) $cp = ($cp << 6) | (ord($ch[$k]) & 0x3F);
        return $cp;
    }

    /** Encode un point de code (< 0x10000) en UTF-8. */
    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) return chr($cp);
        if ($cp < 0x800) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
