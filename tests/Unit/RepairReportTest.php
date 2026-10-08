<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/repair_report.php';

/**
 * Rapport de fin d'intervention (includes/repair_report.php) : fonctions pures
 * de validation (repairReportParse, dont les cases « Entretien effectué »),
 * de mise en forme (repairReportCompose, repairReportKmError), repérage du rapport dans le journal
 * (activity_log_is_report) et liste blanche de la page de retour.
 */
final class RepairReportTest extends TestCase
{
	/** Saisie complète et valide du formulaire, champs remplaçables. */
	private function validPost(array $override = []): array
	{
		return $override + [
			'intervention_id' => '12',
			'titre' => 'Remplacement des plaquettes',
			'description' => 'Bruit au freinage',
			'diagnostic' => 'Plaquettes usées',
			'travaux_effectues' => 'Plaquettes avant remplacées',
			'pieces_utilisees' => '',
			'duree_intervention' => '1.5',
			'cout' => '25000',
			'recommandations' => '',
			'kilometrage' => '85000',
			'etat_vehicule' => 'BON',
		];
	}

	public function testValidPostHasNoError(): void
	{
		$parsed = repairReportParse($this->validPost());
		$this->assertSame([], $parsed['errors']);
		$this->assertSame(12, $parsed['data']['interventionId']);
		$this->assertSame(85000, $parsed['data']['kilometrage']);
		$this->assertSame('BON', $parsed['data']['etatVehicule']);
	}

	public function testKilometrageIsRequiredAndInteger(): void
	{
		$this->assertContains('Kilométrage relevé requis.', repairReportParse($this->validPost(['kilometrage' => '']))['errors']);
		foreach (['85 000', '-5', '12.5', 'abc', (string)(REPAIR_MAX_KILOMETRAGE + 1)] as $km) {
			$parsed = repairReportParse($this->validPost(['kilometrage' => $km]));
			$this->assertCount(1, $parsed['errors'], $km);
			$this->assertStringStartsWith('Kilométrage relevé invalide', $parsed['errors'][0]);
		}
	}

	public function testEtatVehiculeIsWhitelisted(): void
	{
		foreach (array_keys(REPAIR_VEHICLE_STATES) as $etat) {
			$this->assertSame([], repairReportParse($this->validPost(['etat_vehicule' => $etat]))['errors']);
		}
		foreach (['', 'bon', 'ACTIF', 'HORS SERVICE'] as $etat) {
			$parsed = repairReportParse($this->validPost(['etat_vehicule' => $etat]));
			$this->assertSame('', $parsed['data']['etatVehicule']);
			$this->assertContains('État du véhicule à la sortie requis.', $parsed['errors']);
		}
	}

	public function testComposeWritesOneLinePerFilledField(): void
	{
		$data = repairReportParse($this->validPost([
			'pieces_utilisees' => '2 plaquettes',
			'recommandations' => 'Contrôler les disques',
			'etat_vehicule' => 'A_SURVEILLER',
		]))['data'];
		$this->assertSame(implode("\n", [
			'Titre : Remplacement des plaquettes',
			'Diagnostic : Plaquettes usées',
			'Travaux effectués : Plaquettes avant remplacées',
			'Pièces utilisées : 2 plaquettes',
			'Durée : 1,5 h',
			'Coût : 25 000 XAF',
			'Kilométrage relevé : 85 000 km',
			'État du véhicule : À surveiller',
			'Recommandations : Contrôler les disques',
		]), repairReportCompose($data));
	}

	public function testEntretienIsOptionalAndWhitelisted(): void
	{
		$this->assertSame([], repairReportParse($this->validPost())['data']['entretien']);
		$this->assertSame([], repairReportParse($this->validPost(['entretien' => 'VIDANGE']))['data']['entretien']);
		$parsed = repairReportParse($this->validPost(['entretien' => ['PNEUS', 'vidange', 'ASSURANCE', 'VIDANGE', 'PNEUS', ['FREINS']]]));
		$this->assertSame([], $parsed['errors']);
		// Ordre de REPAIR_MAINTENANCE_CHECKS, sans doublon ni valeur inconnue.
		$this->assertSame(['VIDANGE', 'PNEUS'], $parsed['data']['entretien']);
		foreach (array_keys(REPAIR_MAINTENANCE_CHECKS) as $type) {
			$this->assertArrayHasKey($type, MAINTENANCE_SERVICE_TYPES);
		}
	}

	public function testComposeListsMaintenanceDone(): void
	{
		$data = repairReportParse($this->validPost(['entretien' => ['FREINS', 'VIDANGE']]))['data'];
		$this->assertStringContainsString("État du véhicule : Bon
Entretien : Vidange, Contrôle des freins", repairReportCompose($data));
		$this->assertStringNotContainsString('Entretien', repairReportCompose(repairReportParse($this->validPost())['data']));
	}

	public function testComposeOmitsEmptyLines(): void
	{
		$report = repairReportCompose(repairReportParse($this->validPost(['duree_intervention' => '2']))['data']);
		$this->assertStringNotContainsString('Pièces utilisées', $report);
		$this->assertStringNotContainsString('Recommandations', $report);
		$this->assertStringContainsString("Durée : 2 h\n", $report);
		// Entrée REPAIR_REPORT_ACTIVITY non vide : affichée comme rapport par les pages journal.
		$this->assertTrue(activity_log_is_report(['nomActivite' => REPAIR_REPORT_ACTIVITY, 'description' => $report]));
		$this->assertFalse(activity_log_is_report(['nomActivite' => REPAIR_REPORT_ACTIVITY, 'description' => null]));
		$this->assertFalse(activity_log_is_report(['nomActivite' => 'Anomalie constatée', 'description' => $report]));
	}

	public function testKmErrorMessage(): void
	{
		$this->assertSame(
			'Le kilométrage relevé (8 000 km) est inférieur au kilométrage actuel du véhicule (85 000 km). Vérifiez le compteur.',
			repairReportKmError(8000, 85000)
		);
	}

	public function testReturnKeyIsWhitelisted(): void
	{
		$this->assertSame('taches', repairReportReturnKey('taches'));
		$this->assertSame('interventions', repairReportReturnKey('interventions'));
		$this->assertSame('', repairReportReturnKey('https://exemple.com'));
		$this->assertSame('', repairReportReturnKey('../admin/dashboard'));
		$this->assertSame('', repairReportReturnKey(null));
		$this->assertSame('', repairReportReturnKey(['taches']));
	}
}
