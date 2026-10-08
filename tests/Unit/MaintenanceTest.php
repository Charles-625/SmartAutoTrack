<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/maintenance.php';

/**
 * Échéances d'entretien (includes/maintenance.php) : calcul de la prochaine
 * échéance par date et par kilométrage, statut des dates saisies, palier de
 * rappel et validation des règles. Fonctions pures uniquement : aucun accès
 * à la base.
 */
final class MaintenanceTest extends TestCase
{
	private const VIDANGE = ['intervalleMois' => 6, 'intervalleKm' => 5000, 'actif' => true];
	private const TODAY = '2026-10-07';

	public function testDefaultRulesMatchCameroonSevereConditions(): void
	{
		$this->assertSame(['intervalleMois' => 6, 'intervalleKm' => 5000, 'actif' => true], MAINTENANCE_DEFAULT_RULES['VIDANGE']);
		$this->assertSame(['intervalleMois' => 6, 'intervalleKm' => 10000, 'actif' => true], MAINTENANCE_DEFAULT_RULES['FREINS']);
		$this->assertSame(['intervalleMois' => 24, 'intervalleKm' => 40000, 'actif' => true], MAINTENANCE_DEFAULT_RULES['PNEUS']);
		$this->assertCount(5, MAINTENANCE_TYPES);
	}

	public function testNextDueByDateOnly(): void
	{
		// Pas de kilométrage au dernier entretien : seule la date compte.
		$due = maintenanceNextDue(self::VIDANGE, '2026-06-01', null, 90000, self::TODAY);
		$this->assertSame('2026-12-01', $due['dueDate']);
		$this->assertNull($due['dueKm']);
		$this->assertNull($due['kmLeft']);
		$this->assertSame(55, $due['daysLeft']);
		$this->assertSame('ok', $due['status']);
	}

	public function testNextDueMonthsAreClampedToEndOfMonth(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, '2026-08-31', null, null, self::TODAY);
		$this->assertSame('2027-02-28', $due['dueDate']);
	}

	public function testNextDueByKilometrage(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, '2026-09-01', 80000, 82000, self::TODAY);
		$this->assertSame(85000, $due['dueKm']);
		$this->assertSame(3000, $due['kmLeft']);
		$this->assertSame('ok', $due['status']);
	}

	public function testFirstReachedWinsKilometrageBeforeDate(): void
	{
		// Date lointaine (mars 2027) mais 5 200 km parcourus : en retard.
		$due = maintenanceNextDue(self::VIDANGE, '2026-09-01', 80000, 85200, self::TODAY);
		$this->assertSame('2027-03-01', $due['dueDate']);
		$this->assertSame(-200, $due['kmLeft']);
		$this->assertSame('overdue', $due['status']);
	}

	public function testFirstReachedWinsDateBeforeKilometrage(): void
	{
		// Peu roulé (1 000 km) mais 6 mois écoulés : en retard.
		$due = maintenanceNextDue(self::VIDANGE, '2026-03-01', 80000, 81000, self::TODAY);
		$this->assertSame('2026-09-01', $due['dueDate']);
		$this->assertSame(-36, $due['daysLeft']);
		$this->assertSame(4000, $due['kmLeft']);
		$this->assertSame('overdue', $due['status']);
	}

	public function testKilometrageReachedExactlyIsOverdue(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, '2026-09-01', 80000, 85000, self::TODAY);
		$this->assertSame(0, $due['kmLeft']);
		$this->assertSame('overdue', $due['status']);
	}

	public function testSoonByDate(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, '2026-04-20', null, null, self::TODAY);
		$this->assertSame('2026-10-20', $due['dueDate']);
		$this->assertSame(13, $due['daysLeft']);
		$this->assertSame('soon', $due['status']);
	}

	public function testSoonByKilometrage(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, '2026-09-01', 80000, 84000, self::TODAY);
		$this->assertSame(MAINTENANCE_SOON_KM, $due['kmLeft']);
		$this->assertSame('soon', $due['status']);
	}

	public function testDueTodayIsSoonNotOverdue(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, '2026-04-07', null, null, self::TODAY);
		$this->assertSame(0, $due['daysLeft']);
		$this->assertSame('soon', $due['status']);
	}

	public function testUnknownWithoutLastService(): void
	{
		$due = maintenanceNextDue(self::VIDANGE, null, null, 90000, self::TODAY);
		$this->assertSame(
			['dueDate' => null, 'dueKm' => null, 'status' => 'unknown', 'daysLeft' => null, 'kmLeft' => null],
			$due
		);
	}

	public function testKilometrageWithoutCurrentKilometrage(): void
	{
		// dueKm connu, mais sans kilométrage actuel seule la date décide.
		$due = maintenanceNextDue(self::VIDANGE, '2026-09-01', 80000, null, self::TODAY);
		$this->assertSame(85000, $due['dueKm']);
		$this->assertNull($due['kmLeft']);
		$this->assertSame('ok', $due['status']);
	}

	public function testRuleWithoutKilometrageUsesDateOnly(): void
	{
		$rule = ['intervalleMois' => 6, 'intervalleKm' => null, 'actif' => true];
		$due = maintenanceNextDue($rule, '2026-09-01', 80000, 200000, self::TODAY);
		$this->assertNull($due['dueKm']);
		$this->assertSame('ok', $due['status']);
	}

	/**
	 * @dataProvider dateStatuses
	 */
	public function testDateStatus(?string $dueDate, string $status, ?int $daysLeft): void
	{
		$s = maintenanceDateStatus($dueDate, self::TODAY);
		$this->assertSame($status, $s['status']);
		$this->assertSame($daysLeft, $s['daysLeft']);
		$this->assertSame($dueDate, $s['dueDate']);
		$this->assertNull($s['dueKm']);
		$this->assertNull($s['kmLeft']);
	}

	public static function dateStatuses(): array
	{
		return [
			'non renseignée'     => [null, 'unknown', null],
			'dans 31 jours'      => ['2026-11-07', 'ok', 31],
			'dans 30 jours'      => ['2026-11-06', 'soon', 30],
			"aujourd'hui"        => ['2026-10-07', 'soon', 0],
			'hier'               => ['2026-10-06', 'overdue', -1],
			'passage d\'année'   => ['2027-01-01', 'ok', 86],
		];
	}

	/**
	 * @dataProvider reminderLevels
	 */
	public function testReminderLevel(string $status, ?int $daysLeft, ?string $level): void
	{
		$this->assertSame($level, maintenanceReminderLevel($status, $daysLeft));
	}

	public static function reminderLevels(): array
	{
		return [
			'à jour'                     => ['ok', 45, null],
			'inconnue'                   => ['unknown', null, null],
			'30 jours'                   => ['soon', 30, 'J30'],
			'8 jours'                    => ['soon', 8, 'J30'],
			'7 jours'                    => ['soon', 7, 'J7'],
			'1 jour'                     => ['soon', 1, 'J7'],
			'jour même'                  => ['soon', 0, 'J0'],
			'dépassée'                   => ['overdue', -1, 'RETARD'],
			'km dépassé, date lointaine' => ['overdue', 120, 'RETARD'],
			'bientôt par km seul'        => ['soon', 120, 'J30'],
			'bientôt sans date'          => ['soon', null, 'J30'],
		];
	}

	public function testValidRulesAreAccepted(): void
	{
		$this->assertNull(maintenanceRuleError('VIDANGE', 6, 5000));
		$this->assertNull(maintenanceRuleError('FREINS', 1, 500));
		$this->assertNull(maintenanceRuleError('PNEUS', 60, 200000));
		$this->assertNull(maintenanceRuleError('PNEUS', 24, null));
	}

	public function testInvalidRulesAreRejected(): void
	{
		$this->assertNotNull(maintenanceRuleError('ASSURANCE', 6, 5000));
		$this->assertNotNull(maintenanceRuleError('vidange', 6, 5000));
		$this->assertNotNull(maintenanceRuleError('VIDANGE', 0, 5000));
		$this->assertNotNull(maintenanceRuleError('VIDANGE', 61, 5000));
		$this->assertNotNull(maintenanceRuleError('VIDANGE', 6, 499));
		$this->assertNotNull(maintenanceRuleError('VIDANGE', 6, 200001));
	}

	public function testValidDate(): void
	{
		$this->assertTrue(maintenanceValidDate('2026-02-28'));
		$this->assertFalse(maintenanceValidDate('2026-02-30'));
		$this->assertFalse(maintenanceValidDate('2026-2-3'));
		$this->assertFalse(maintenanceValidDate('07/10/2026'));
		$this->assertFalse(maintenanceValidDate('2026-10-07 10:00:00'));
	}

	public function testScheduleOrdersByUrgencyAndSkipsInactiveRules(): void
	{
		$vehicle = ['kilometrage' => 85200, 'dateExpirationAssurance' => '2026-10-20', 'dateProchaineVisiteTechnique' => null];
		$last = ['VIDANGE' => ['dateEntretien' => '2026-09-01', 'kilometrage' => 80000]];
		$rules = MAINTENANCE_DEFAULT_RULES;
		$rules['PNEUS']['actif'] = false;
		$items = maintenanceBuildSchedule($vehicle, $last, $rules, self::TODAY);
		$this->assertSame(['VIDANGE', 'ASSURANCE', 'VISITE_TECHNIQUE', 'FREINS'], array_column($items, 'type'));
		$this->assertSame(['overdue', 'soon', 'unknown', 'unknown'], array_column($items, 'status'));
		$this->assertSame('Vidange', $items[0]['libelle']);
		$this->assertSame('2026-09-01', $items[0]['lastDate']);
	}

	public function testDescribeIsReadableFrench(): void
	{
		$item = ['type' => 'VIDANGE', 'libelle' => 'Vidange', 'lastDate' => '2026-09-01', 'lastKm' => 80000]
			+ maintenanceNextDue(self::VIDANGE, '2026-09-01', 80000, 85200, self::TODAY);
		$this->assertSame(
			'Vidange : en retard — échéance le 01/03/2027 ou à 85 000 km (dans 145 jours ; 200 km au-delà) ; dernier entretien le 01/09/2026 à 80 000 km.',
			maintenanceDescribe($item)
		);
		$unknown = ['type' => 'ASSURANCE', 'libelle' => 'Assurance', 'lastDate' => null, 'lastKm' => null]
			+ maintenanceDateStatus(null, self::TODAY);
		$this->assertSame('Assurance : inconnue — date non renseignée.', maintenanceDescribe($unknown));
	}

	/**
	 * Parcours complet : vidange cochée à la clôture d'une réparation
	 * (07/10/2026, 80 000 km) -> échéance recalculée -> palier de rappel
	 * selon le kilométrage relevé ensuite et la date du jour.
	 */
	public function testRepairClosureFlowToReminderLevels(): void
	{
		$last = ['VIDANGE' => ['dateEntretien' => '2026-10-07', 'kilometrage' => 80000]];
		$vehicle = ['kilometrage' => 80000, 'dateExpirationAssurance' => null, 'dateProchaineVisiteTechnique' => null];
		$find = function (array $schedule): array {
			foreach ($schedule as $item) {
				if ($item['type'] === 'VIDANGE') {
					return $item;
				}
			}
			$this->fail('Échéance de vidange absente.');
		};

		// Juste après la clôture : à jour, aucun rappel.
		$item = $find(maintenanceBuildSchedule($vehicle, $last, MAINTENANCE_DEFAULT_RULES, self::TODAY));
		$this->assertSame(['2027-04-07', 85000, 'ok'], [$item['dueDate'], $item['dueKm'], $item['status']]);
		$this->assertNull(maintenanceReminderLevel($item['status'], $item['daysLeft']));
		$this->assertStringContainsString('dernier entretien le 07/10/2026 à 80 000 km', maintenanceDescribe($item));

		// 800 km avant la limite, date lointaine : bientôt, palier J30.
		$item = $find(maintenanceBuildSchedule(['kilometrage' => 84200] + $vehicle, $last, MAINTENANCE_DEFAULT_RULES, '2026-12-01'));
		$this->assertSame('soon', $item['status']);
		$this->assertSame('J30', maintenanceReminderLevel($item['status'], $item['daysLeft']));

		// Peu roulé, la date approche : J7 puis J0 (même échéance, paliers distincts).
		$item = $find(maintenanceBuildSchedule(['kilometrage' => 82000] + $vehicle, $last, MAINTENANCE_DEFAULT_RULES, '2027-04-01'));
		$this->assertSame('J7', maintenanceReminderLevel($item['status'], $item['daysLeft']));
		$item = $find(maintenanceBuildSchedule(['kilometrage' => 82000] + $vehicle, $last, MAINTENANCE_DEFAULT_RULES, '2027-04-07'));
		$this->assertSame('J0', maintenanceReminderLevel($item['status'], $item['daysLeft']));

		// Limite de 5 000 km atteinte avant la date : en retard.
		$item = $find(maintenanceBuildSchedule(['kilometrage' => 85000] + $vehicle, $last, MAINTENANCE_DEFAULT_RULES, '2027-01-15'));
		$this->assertSame('overdue', $item['status']);
		$this->assertSame('RETARD', maintenanceReminderLevel($item['status'], $item['daysLeft']));
	}
}
