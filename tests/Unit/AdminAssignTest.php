<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../admin/includes/helpers.php';

/**
 * Règle d'affectation de l'administrateur (admin_assign_plan) et petits
 * helpers purs associés (admin_assign_label, admin_load_label,
 * admin_assign_parse_id) — sans base de données.
 */
final class AdminAssignTest extends TestCase
{
	/** Intervention de test (par défaut : demande PLANIFIEE non affectée). */
	private static function iv(string $statut = 'PLANIFIEE', ?int $tech = null, ?int $garage = null): array
	{
		return ['statut' => $statut, 'idTechnicien' => $tech, 'idGarage' => $garage];
	}

	private static function interne(int $id = 8, string $statut = 'VALIDE'): array
	{
		return ['idTechnicien' => $id, 'typeTechnicien' => 'INTERNE', 'idGarage' => null, 'statutValidation' => $statut, 'nomGarage' => null, 'garageStatut' => null];
	}

	private static function techGarage(int $id = 19, int $garage = 3, string $garageStatut = 'VALIDE', string $nomGarage = 'Garage Central'): array
	{
		return ['idTechnicien' => $id, 'typeTechnicien' => 'GARAGE', 'idGarage' => $garage, 'statutValidation' => 'VALIDE', 'nomGarage' => $nomGarage, 'garageStatut' => $garageStatut];
	}

	private static function garage(int $id = 3, string $statut = 'VALIDE'): array
	{
		return ['idGarage' => $id, 'nomGarage' => 'Garage ' . $id, 'statutGarage' => $statut];
	}

	private function assertPlan(array $plan, ?int $tech, ?int $garage): void
	{
		$this->assertTrue($plan['ok'], (string)$plan['error']);
		$this->assertNull($plan['error']);
		$this->assertSame($tech, $plan['idTechnicien']);
		$this->assertSame($garage, $plan['idGarage']);
	}

	private function assertError(array $plan, string $error): void
	{
		$this->assertFalse($plan['ok']);
		$this->assertSame($error, $plan['error']);
		$this->assertNull($plan['idTechnicien']);
		$this->assertNull($plan['idGarage']);
	}

	public function testNothingChosenIsRefused(): void
	{
		$this->assertError(admin_assign_plan(self::iv(), null, null), 'Choisissez un technicien ou un garage.');
	}

	public function testInternalTechnicianAlone(): void
	{
		$this->assertPlan(admin_assign_plan(self::iv(), self::interne(), null), 8, null);
	}

	public function testInternalTechnicianWithGarage(): void
	{
		$this->assertPlan(admin_assign_plan(self::iv(), self::interne(), self::garage(10)), 8, 10);
	}

	public function testInternalTechnicianWithUnvalidatedGarageIsRefused(): void
	{
		$this->assertError(admin_assign_plan(self::iv(), self::interne(), self::garage(10, 'SUSPENDU')), 'Ce garage n\'est pas validé.');
	}

	public function testUnvalidatedTechnicianIsRefused(): void
	{
		$this->assertError(admin_assign_plan(self::iv(), self::interne(10, 'EN_ATTENTE'), null), 'Ce technicien n\'est pas validé.');
	}

	public function testGarageTechnicianGoesWithHisGarage(): void
	{
		$this->assertPlan(admin_assign_plan(self::iv(), self::techGarage(), null), 19, 3);
		// Son propre garage choisi en plus : accepté.
		$this->assertPlan(admin_assign_plan(self::iv(), self::techGarage(), self::garage(3)), 19, 3);
	}

	public function testGarageTechnicianWithAnotherGarageIsRefused(): void
	{
		$this->assertError(admin_assign_plan(self::iv(), self::techGarage(), self::garage(10)), 'Ce technicien appartient au garage Garage Central.');
	}

	public function testGarageTechnicianOfUnvalidatedGarageIsRefused(): void
	{
		$this->assertError(admin_assign_plan(self::iv(), self::techGarage(19, 3, 'SUSPENDU'), null), 'Le garage de ce technicien n\'est pas validé.');
	}

	public function testGarageAlone(): void
	{
		$this->assertPlan(admin_assign_plan(self::iv(), null, self::garage(10)), null, 10);
		$this->assertError(admin_assign_plan(self::iv(), null, self::garage(10, 'EN_ATTENTE')), 'Ce garage n\'est pas validé.');
	}

	public function testStartedOrFinishedInterventionIsNotReassignable(): void
	{
		$error = 'Cette intervention a déjà démarré / est terminée : elle ne peut plus être réaffectée.';
		$this->assertError(admin_assign_plan(self::iv('EN_COURS', 8), self::interne(9), null), $error);
		$this->assertError(admin_assign_plan(self::iv('TERMINEE', 8), null, self::garage()), $error);
	}

	public function testRefusedRequestCanBeReassigned(): void
	{
		// ANNULEE = refusée par le garage 3 : réaffectable à un autre garage ou à un technicien.
		$this->assertPlan(admin_assign_plan(self::iv('ANNULEE', null, 3), null, self::garage(10)), null, 10);
		$this->assertPlan(admin_assign_plan(self::iv('ANNULEE', null, 3), self::interne(), null), 8, null);
	}

	public function testPlannedAssignedInterventionCanBeReassigned(): void
	{
		// Technicien interne → technicien d'un garage (disponibilité).
		$this->assertPlan(admin_assign_plan(self::iv('PLANIFIEE', 8), self::techGarage(), null), 19, 3);
		// Garage → autre garage ; technicien de garage → garage seul (technicien retiré).
		$this->assertPlan(admin_assign_plan(self::iv('PLANIFIEE', null, 3), null, self::garage(10)), null, 10);
		$this->assertPlan(admin_assign_plan(self::iv('PLANIFIEE', 19, 3), null, self::garage(3)), null, 3);
		// Même technicien interne, garage ajouté.
		$this->assertPlan(admin_assign_plan(self::iv('PLANIFIEE', 8), self::interne(), self::garage(10)), 8, 10);
	}

	public function testSameAssignmentIsRefused(): void
	{
		$error = 'Cette intervention est déjà affectée ainsi.';
		$this->assertError(admin_assign_plan(self::iv('PLANIFIEE', 8), self::interne(), null), $error);
		$this->assertError(admin_assign_plan(self::iv('PLANIFIEE', null, 3), null, self::garage(3)), $error);
		// Technicien de garage déjà affecté : le garage est déduit, donc identique.
		$this->assertError(admin_assign_plan(self::iv('PLANIFIEE', 19, 3), self::techGarage(), null), $error);
	}

	public function testIdsFromDatabaseStringsAreCompared(): void
	{
		// PDO peut renvoyer des chaînes : la comparaison se fait sur des entiers.
		$plan = admin_assign_plan(['statut' => 'PLANIFIEE', 'idTechnicien' => '8', 'idGarage' => null], self::interne(), null);
		$this->assertError($plan, 'Cette intervention est déjà affectée ainsi.');
	}

	public function testAssignmentLabel(): void
	{
		$this->assertSame('Jean Dupont (SmartAutoTrack)', admin_assign_label('Jean Dupont', 'INTERNE', null));
		$this->assertSame('Jean Dupont (SmartAutoTrack), garage Auto+', admin_assign_label('Jean Dupont', 'INTERNE', 'Auto+'));
		$this->assertSame('Paul Martin (garage Auto+)', admin_assign_label('Paul Martin', 'GARAGE', 'Auto+'));
		$this->assertSame('le garage Auto+', admin_assign_label(null, null, 'Auto+'));
		$this->assertSame('aucune affectation', admin_assign_label(null, null, null));
	}

	public function testLoadLabel(): void
	{
		$this->assertSame('— disponible', admin_load_label(0));
		$this->assertSame('— 1 active', admin_load_label(1));
		$this->assertSame('— 2 actives', admin_load_label(2));
	}

	public function testParseOptionalId(): void
	{
		$this->assertNull(admin_assign_parse_id(null));
		$this->assertNull(admin_assign_parse_id(''));
		$this->assertSame(12, admin_assign_parse_id('12'));
		$this->assertFalse(admin_assign_parse_id('abc'));
		$this->assertFalse(admin_assign_parse_id('0'));
		$this->assertFalse(admin_assign_parse_id(['1']));
	}
}
