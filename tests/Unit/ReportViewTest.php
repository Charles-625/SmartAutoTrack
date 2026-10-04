<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/repair_report.php';
require_once __DIR__ . '/../../includes/activity_log.php';

/**
 * Fenêtre « Voir le rapport » des journaux (includes/activity_log.php) :
 * découpe de la description en paires (activity_log_report_fields), données
 * de la fenêtre (activity_log_report_payload) et JSON sans caractère HTML
 * actif pour l'attribut data-report (activity_log_report_json).
 */
final class ReportViewTest extends TestCase
{
	/** Ligne de journal type, telle que renvoyée par activity_log_fetch(). */
	private function entry(array $override = []): array
	{
		return $override + [
			'nomActivite' => REPAIR_REPORT_ACTIVITY,
			'description' => "Titre : Vidange\nDiagnostic : Huile usée\nCoût : 25 000 XAF",
			'dateHeure' => '2026-10-01 14:05:00',
			'idIntervention' => 7,
			'intervention_type' => 'Entretien',
			'marque' => 'Toyota',
			'modele' => 'Corolla',
			'immatriculation' => 'LT-123-AB',
			'idGarage' => 3,
			'nomGarage' => 'Garage du Centre',
			'acteur_nom' => 'Mbarga',
			'acteur_prenom' => 'Paul',
			'acteur_role' => 'technicien',
			'idReparation' => 42,
		];
	}

	public function testFieldsSplitLabelAndValue(): void
	{
		$fields = activity_log_report_fields("Titre : Vidange\nCoût : 25 000 XAF\nDurée : 1,5 h");
		$this->assertSame([
			['label' => 'Titre', 'value' => 'Vidange'],
			['label' => 'Coût', 'value' => '25 000 XAF'],
			['label' => 'Durée', 'value' => '1,5 h'],
		], $fields);
	}

	public function testLineWithoutColonJoinsPreviousValue(): void
	{
		$fields = activity_log_report_fields("Diagnostic : Bruit au freinage\nplaquettes usées\r\nTravaux effectués : Remplacement");
		$this->assertSame('Diagnostic', $fields[0]['label']);
		$this->assertSame("Bruit au freinage\nplaquettes usées", $fields[0]['value']);
		$this->assertSame('Travaux effectués', $fields[1]['label']);
		$this->assertCount(2, $fields);
	}

	public function testColonInsideReportValueDoesNotStartNewField(): void
	{
		$fields = activity_log_report_fields("Titre : Freins\nRecommandations : Revenir dans 1 mois\nAttention : disques limites");
		$this->assertCount(2, $fields);
		$this->assertSame("Revenir dans 1 mois\nAttention : disques limites", $fields[1]['value']);
	}

	public function testUnknownFormatKeepsEveryLabel(): void
	{
		$fields = activity_log_report_fields("Note : a\nAutre : b");
		$this->assertSame(['Note', 'Autre'], array_column($fields, 'label'));
	}

	public function testTextWithoutLabelAndEmptyDescription(): void
	{
		$this->assertSame([['label' => '', 'value' => 'Texte libre']], activity_log_report_fields("\nTexte libre\n"));
		$this->assertSame([], activity_log_report_fields(''));
	}

	public function testComposedReportRoundTrip(): void
	{
		$text = repairReportCompose([
			'titre' => 'Plaquettes', 'diagnostic' => "Usure\navant", 'travaux' => 'Remplacement',
			'pieces' => '', 'duree' => 1.5, 'cout' => 25000, 'kilometrage' => 85000,
			'etatVehicule' => 'BON', 'recommandations' => '',
		]);
		$fields = activity_log_report_fields($text);
		$labels = array_column($fields, 'label');
		$this->assertSame(['Titre', 'Diagnostic', 'Travaux effectués', 'Durée', 'Coût', 'Kilométrage relevé', 'État du véhicule'], $labels);
		$this->assertSame("Usure\navant", $fields[1]['value']);
	}

	public function testPayloadHeaderAndPdfLink(): void
	{
		$p = activity_log_report_payload($this->entry(), 'http://localhost/HCH/');
		$meta = array_column($p['meta'], 'value', 'label');
		$this->assertSame('Entretien n° 7', $meta['Intervention']);
		$this->assertSame('Toyota Corolla (LT-123-AB)', $meta['Véhicule']);
		$this->assertSame('Garage du Centre', $meta['Garage']);
		$this->assertSame('Paul Mbarga (Technicien)', $meta['Rempli par']);
		$this->assertSame('01/10/2026 à 14:05', $meta['Date']);
		$this->assertCount(3, $p['fields']);
		$this->assertSame('http://localhost/HCH/ajax/download_report.php?id=42&format=pdf', $p['pdf']);
	}

	public function testPayloadSmartAutoTrackAndNoRepair(): void
	{
		$p = activity_log_report_payload($this->entry(['nomGarage' => null, 'idGarage' => null, 'idReparation' => null]), '');
		$meta = array_column($p['meta'], 'value', 'label');
		$this->assertSame('SmartAutoTrack', $meta['Garage']);
		$this->assertNull($p['pdf']);
	}

	public function testPayloadDecodesStoredEntities(): void
	{
		// Les saisies sont stockées après sanitize() : le JS utilise textContent.
		$p = activity_log_report_payload($this->entry(['description' => 'Titre : L&#039;embrayage &amp; la bo&icirc;te']), '');
		$this->assertSame("L'embrayage & la boîte", $p['fields'][0]['value']);
	}

	public function testJsonHasNoActiveHtmlCharacters(): void
	{
		$json = activity_log_report_json($this->entry(['description' => "Titre : </script><img src=x onerror=alert(1)> \"x\" 'y' &"]));
		foreach (['<', '>', '"x"', "'", '&'] as $c) {
			$this->assertStringNotContainsString($c, str_replace('\\"', '', $json));
		}
		// Aucun « & » dans le JSON : h() (sans double encodage) puis la lecture
		// de l'attribut par le navigateur rendent exactement le JSON.
		$this->assertSame($json, html_entity_decode(h($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$data = json_decode($json, true);
		$this->assertSame("</script><img src=x onerror=alert(1)> \"x\" 'y' &", $data['fields'][0]['value']);
	}
}
