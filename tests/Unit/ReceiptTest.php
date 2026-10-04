<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/receipt.php';

/**
 * Reçu de paiement d'une réparation (includes/receipt.php, servi par
 * ajax/download_receipt.php) : n° de reçu, masquage du numéro Mobile Money,
 * formatage du montant en XAF, décodage des entités HTML, et contenu du PDF
 * généré (flux non compressés pour pouvoir le lire).
 */
final class ReceiptTest extends TestCase
{
	public function testReceiptNumber(): void
	{
		$this->assertSame('REC-42', receiptNumber(42));
	}

	public function testMaskPhoneKeepsFirstAndTwoLastDigits(): void
	{
		$this->assertSame('6XX XX XX 37', receiptMaskPhone('237651797837'));
		$this->assertSame('6XX XX XX 56', receiptMaskPhone('6 99 12 34 56'));
		$this->assertSame('6XX XX XX 56', receiptMaskPhone('+237 699-123-456'));
	}

	public function testMaskPhoneNeverRevealsMiddleDigits(): void
	{
		$masked = receiptMaskPhone('237651797837');
		$this->assertStringNotContainsString('5179', str_replace(' ', '', $masked));
		$this->assertSame(2, preg_match_all('/\d/', substr($masked, 1)));
	}

	public function testMaskPhoneEdgeCases(): void
	{
		$this->assertSame('—', receiptMaskPhone(null));
		$this->assertSame('—', receiptMaskPhone(''));
		$this->assertSame('XXX', receiptMaskPhone('123'));
	}

	public function testFormatAmountUsesNonBreakingSpaces(): void
	{
		$nbsp = "\u{00A0}";
		$this->assertSame('25' . $nbsp . '000' . $nbsp . 'XAF', receiptFormatAmount('25000.00'));
		$this->assertSame('1' . $nbsp . '250' . $nbsp . '000' . $nbsp . 'XAF', receiptFormatAmount(1250000));
		$this->assertSame('0' . $nbsp . 'XAF', receiptFormatAmount(null));
	}

	public function testTextDecodesHtmlEntities(): void
	{
		$this->assertSame("Vidange & filtre d'huile", receiptText('Vidange &amp; filtre d&#039;huile'));
		$this->assertSame('', receiptText(null));
	}

	public function testPdfContainsReceiptBlocks(): void
	{
		$pdf = receiptBuildPdf([
			'idPaiement' => 7, 'montant' => '25000.00', 'datePaiement' => '2026-10-03 14:56:05',
			'telephone' => '237651797837', 'operateur' => 'MTN',
			'referenceCampay' => 'abc-123', 'referenceExterne' => 'SAT-12-ffee',
			'client_nom' => 'Nkayou', 'client_prenom' => 'Landry', 'raisonSociale' => 'Transports &amp; Co',
			'idReparation' => 12, 'reparation_titre' => 'Vidange', 'idIntervention' => 3,
			'intervention_type' => 'Entretien', 'marque' => 'Toyota', 'modele' => 'Corolla',
			'immatriculation' => 'LT 900 AA', 'garage_nom' => null,
		], 'Mode démonstration : montant réellement débité plafonné', false);

		$this->assertStringStartsWith('%PDF-', $pdf);
		$text = PdfLite::fromWinAnsi($pdf);
		foreach (['Reçu de paiement', 'REC-7', 'Transports & Co', 'Mobile Money', '6XX XX XX 37',
			'abc-123', 'SAT-12-ffee', 'SmartAutoTrack', 'Paiement confirmé par CamPay', 'Mode démonstration'] as $needle) {
			$this->assertStringContainsString(PdfLite::escape($needle), $text, $needle);
		}
		$this->assertStringNotContainsString('651797837', $pdf);
	}
}
