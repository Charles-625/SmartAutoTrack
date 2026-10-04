<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/pdf_lite.php';

/**
 * Générateur PDF autonome (includes/pdf_lite.php) utilisé par
 * ajax/download_report.php?format=pdf : structure du fichier (en-tête,
 * %%EOF, table xref aux offsets exacts), conversion UTF-8 → Windows-1252,
 * échappement des chaînes, coupure des lignes et saut de page automatique.
 * Les flux sont laissés non compressés pour pouvoir lire leur contenu.
 */
final class PdfLiteTest extends TestCase
{
	/** Document de test non compressé. */
	private function newPdf(): PdfLite
	{
		$pdf = new PdfLite();
		$pdf->setCompression(false);
		return $pdf;
	}

	public function testDocumentStartsWithHeaderAndEndsWithEof(): void
	{
		$pdf = $this->newPdf();
		$pdf->addPage();
		$pdf->text(50, 60, 'Bonjour');
		$out = $pdf->output();
		$this->assertStringStartsWith('%PDF-', $out);
		$this->assertStringEndsWith("%%EOF\n", $out);
	}

	public function testXrefPointsToEachObject(): void
	{
		$pdf = $this->newPdf();
		$pdf->setTitle('Rapport n° 12');
		$pdf->setFooterText('Pied de page');
		$pdf->addPage();
		$pdf->rect(0, 0, PdfLite::PAGE_WIDTH, 70, [21, 101, 192], [0, 0, 0]);
		$pdf->writeText(str_repeat("Ligne de texte assez longue pour remplir la page. ", 400), 50, 495, 14);
		$out = $pdf->output();
		$this->assertGreaterThan(1, $pdf->pageCount());

		// startxref donne la position exacte du mot-clé xref.
		$this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $out, $m));
		$xrefPos = (int)$m[1];
		$this->assertSame('xref', substr($out, $xrefPos, 4));

		$this->assertSame(1, preg_match('/\Gxref\n0 (\d+)\n/', $out, $h, 0, $xrefPos));
		$size = (int)$h[1];
		$entries = substr($out, $xrefPos + strlen($h[0]), 20 * $size);
		$this->assertSame('0000000000 65535 f ' . "\n", substr($entries, 0, 20));
		for ($n = 1; $n < $size; $n++) {
			$entry = substr($entries, 20 * $n, 20);
			$this->assertSame(1, preg_match('/^(\d{10}) 00000 n \n$/', $entry), "Entrée xref $n mal formée");
			$offset = (int)substr($entry, 0, 10);
			$this->assertSame("$n 0 obj", substr($out, $offset, strlen("$n 0 obj")), "Offset faux pour l'objet $n");
		}
		$this->assertStringContainsString('/Size ' . $size, $out);
		$this->assertStringContainsString('/Count ' . $pdf->pageCount(), $out);

		// Chaque flux déclare sa longueur exacte.
		preg_match_all('/<< \/Length (\d+) >>\nstream\n/', $out, $streams, PREG_OFFSET_CAPTURE);
		$this->assertNotEmpty($streams[0]);
		foreach ($streams[0] as $i => $s) {
			$start = $s[1] + strlen($s[0]);
			$this->assertSame("\nendstream", substr($out, $start + (int)$streams[1][$i][0], 10));
		}
	}

	public function testAccentsAreConvertedToWindows1252(): void
	{
		$this->assertSame("\xE9\xE8\xEA\xE0\xE7\xF9\xEE\xF4", PdfLite::toWinAnsi('éèêàçùîô'));
		$this->assertSame("\xAB\xA0\xBB \x97 \x80 \x92 \x85 \x9C", PdfLite::toWinAnsi("«\u{00A0}» — € ’ … œ"));
		$this->assertSame("\xC9tat", PdfLite::toWinAnsi('État'));
		// Caractères absents de Windows-1252 et octets invalides.
		$this->assertSame('a?b?c', PdfLite::toWinAnsi("a\u{4E2D}b\xFFc"));
		$this->assertSame('?', PdfLite::toWinAnsi("\u{1F600}"));
		// Aller-retour.
		$this->assertSame('Pièces « usées » — 25 €', PdfLite::fromWinAnsi(PdfLite::toWinAnsi('Pièces « usées » — 25 €')));

		$pdf = $this->newPdf();
		$pdf->text(50, 60, 'Kilométrage relevé');
		$this->assertStringContainsString("(Kilom\xE9trage relev\xE9) Tj", $pdf->output());
	}

	public function testStringsAreEscaped(): void
	{
		$this->assertSame('a\\(b\\) c\\\\d', PdfLite::escape('a(b) c\\d'));

		$pdf = $this->newPdf();
		$pdf->text(50, 60, 'Pièces (avant) \\ arrière');
		$this->assertStringContainsString("(Pi\xE8ces \\(avant\\) \\\\ arri\xE8re) Tj", $pdf->output());
	}

	public function testWidthsFollowHelveticaMetrics(): void
	{
		$pdf = $this->newPdf();
		// AFM : H = 722, i = 222 (Helvetica) ; i = 278 en gras ; 1000 unités = corps.
		$this->assertEqualsWithDelta(9.44, $pdf->stringWidth('Hi', false, 10), 0.001);
		$this->assertEqualsWithDelta(10.0, $pdf->stringWidth('Hi', true, 10), 0.001);
		$this->assertEqualsWithDelta(5.56, $pdf->stringWidth('é', false, 10), 0.001);
	}

	public function testWrapRespectsWidth(): void
	{
		$pdf = $this->newPdf();
		$pdf->setFont(false, 10);
		$text = "Remplacement des plaquettes de frein avant et contrôle des disques, purge du circuit.\n\n"
			. 'Mot' . str_repeat('x', 120);
		$lines = $pdf->wrapText($text, 150);
		$this->assertGreaterThan(4, count($lines));
		foreach ($lines as $line) {
			$this->assertLessThanOrEqual(150, $pdf->stringWidth($line), "Ligne trop large : $line");
		}
		// Le retour à la ligne vide est conservé, et aucun mot n'est perdu.
		$this->assertContains('', $lines);
		$joined = implode(' ', array_filter($lines, 'strlen'));
		$this->assertStringContainsString('contrôle', $joined);
		$this->assertSame('Mot' . str_repeat('x', 120), implode('', array_slice($lines, array_search('', $lines, true) + 1)));
	}

	public function testWrapKeepsFrenchPunctuationAttached(): void
	{
		$pdf = $this->newPdf();
		$pdf->setFont(false, 10);
		// Chaque largeur coupe à un endroit différent : jamais de « ou de »
		// isolé en bord de ligne, ni de ligne qui commence par : ou ?.
		$text = str_repeat("redressage de l'aile « gauche » et peinture : finition vernis ? ", 6);
		for ($w = 60; $w <= 200; $w += 7) {
			foreach ($pdf->wrapText($text, $w) as $line) {
				$this->assertDoesNotMatchRegularExpression('/«$|^»|^[:;!?]/u', $line, "Largeur $w : « $line »");
			}
		}
	}

	public function testLongTextSpansSeveralPages(): void
	{
		$pdf = $this->newPdf();
		$pdf->setFooterText('Document généré par SmartAutoTrack');
		$pdf->addPage();
		$count = $pdf->writeText(str_repeat("Travaux effectués sur le véhicule.\n", 150), 50, 495, 14);
		$this->assertSame(151, $count);
		$this->assertGreaterThanOrEqual(3, $pdf->pageCount());
		$out = $pdf->output();
		$this->assertSame($pdf->pageCount(), preg_match_all('/\/Type \/Page\b(?!s)/', $out));
		$this->assertStringContainsString('(Page 1 / ' . $pdf->pageCount() . ') Tj', $out);
		$this->assertStringContainsString('(Page ' . $pdf->pageCount() . ' / ' . $pdf->pageCount() . ') Tj', $out);
		$this->assertStringContainsString("(Document g\xE9n\xE9r\xE9 par SmartAutoTrack) Tj", $out);
	}
}
