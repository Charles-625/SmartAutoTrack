<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/subscription.php';

final class SubscriptionTest extends TestCase
{
	public function testParticulierPricesAreFixed(): void
	{
		$this->assertSame(1000, subscriptionPrice('PARTICULIER', 'MENSUEL'));
		$this->assertSame(10000, subscriptionPrice('PARTICULIER', 'ANNUEL'));
		// Le nombre de véhicules n'entre pas dans le prix d'un particulier.
		$this->assertSame(1000, subscriptionPrice('PARTICULIER', 'MENSUEL', 3));
	}

	public function testUnknownPeriodiciteOrTypeIsNotPayable(): void
	{
		$this->assertNull(subscriptionPrice('PARTICULIER', 'ESSAI'));
		$this->assertNull(subscriptionPrice('PARTICULIER', 'HEBDO'));
		$this->assertNull(subscriptionPrice('GARAGE', 'MENSUEL', 10));
	}

	/**
	 * @dataProvider entrepriseTiers
	 */
	public function testEntrepriseUnitPriceFollowsFleetTier(int $nbVehicules, ?int $unitPrice): void
	{
		$this->assertSame($unitPrice, subscriptionEntrepriseUnitPrice($nbVehicules));
	}

	public static function entrepriseTiers(): array
	{
		return [
			'aucun véhicule'     => [0, null],
			'1 véhicule'         => [1, 2000],
			'5 véhicules'        => [5, 2000],
			'6 véhicules'        => [6, 1500],
			'20 véhicules'       => [20, 1500],
			'21 véhicules'       => [21, 1200],
			'50 véhicules'       => [50, 1200],
			'51 véhicules (devis)' => [51, null],
		];
	}

	public function testEntrepriseMonthlyPriceIsVehiclesTimesUnitPrice(): void
	{
		$this->assertSame(4 * 2000, subscriptionPrice('ENTREPRISE', 'MENSUEL', 4));
		$this->assertSame(5 * 2000, subscriptionPrice('ENTREPRISE', 'MENSUEL', 5));
		$this->assertSame(6 * 1500, subscriptionPrice('ENTREPRISE', 'MENSUEL', 6));
		$this->assertSame(20 * 1500, subscriptionPrice('ENTREPRISE', 'MENSUEL', 20));
		$this->assertSame(21 * 1200, subscriptionPrice('ENTREPRISE', 'MENSUEL', 21));
		$this->assertSame(50 * 1200, subscriptionPrice('ENTREPRISE', 'MENSUEL', 50));
	}

	public function testEntrepriseOutsideOnlineRangeIsNotPayable(): void
	{
		$this->assertNull(subscriptionPrice('ENTREPRISE', 'MENSUEL', SUB_ENTREPRISE_MIN_VEHICLES - 1));
		$this->assertNull(subscriptionPrice('ENTREPRISE', 'MENSUEL', 51));
		$this->assertNull(subscriptionPrice('ENTREPRISE', 'ANNUEL', 51));
	}

	public function testEntrepriseAnnualIsTenMonths(): void
	{
		foreach ([4, 5, 6, 20, 21, 50] as $n) {
			$this->assertSame(10 * subscriptionPrice('ENTREPRISE', 'MENSUEL', $n), subscriptionPrice('ENTREPRISE', 'ANNUEL', $n), "$n véhicules");
		}
	}

	/**
	 * @dataProvider periods
	 */
	public function testPeriodEnd(string $start, string $periodicite, string $expectedEnd): void
	{
		$end = subscriptionPeriodEnd(new DateTimeImmutable($start), $periodicite);
		$this->assertSame($expectedEnd, $end->format('Y-m-d H:i:s'));
	}

	public static function periods(): array
	{
		return [
			'essai 30 jours'             => ['2026-10-03 14:30:00', 'ESSAI', '2026-11-02 14:30:00'],
			'mois offert 30 jours'       => ['2026-10-03 14:30:00', 'OFFERT', '2026-11-02 14:30:00'],
			'mensuel'                    => ['2026-10-03 14:30:00', 'MENSUEL', '2026-11-03 14:30:00'],
			'annuel'                     => ['2026-10-03 14:30:00', 'ANNUEL', '2027-10-03 14:30:00'],
			'31 janvier + 1 mois'        => ['2026-01-31 09:00:00', 'MENSUEL', '2026-02-28 09:00:00'],
			'31 janvier bissextile'      => ['2028-01-31 09:00:00', 'MENSUEL', '2028-02-29 09:00:00'],
			'31 mars + 1 mois'           => ['2026-03-31 09:00:00', 'MENSUEL', '2026-04-30 09:00:00'],
			'décembre vers janvier'      => ['2026-12-15 09:00:00', 'MENSUEL', '2027-01-15 09:00:00'],
			'29 février + 12 mois'       => ['2028-02-29 09:00:00', 'ANNUEL', '2029-02-28 09:00:00'],
		];
	}

	public function testPeriodEndRejectsUnknownPeriodicite(): void
	{
		$this->expectException(InvalidArgumentException::class);
		subscriptionPeriodEnd(new DateTimeImmutable('2026-10-03'), 'HEBDO');
	}

	public function testParticulierVehicleLimits(): void
	{
		$this->assertSame(1, subscriptionVehicleLimit('PARTICULIER', null));
		$this->assertSame(3, subscriptionVehicleLimit('PARTICULIER', ['periodicite' => 'MENSUEL', 'nbVehicules' => null]));
		$this->assertSame(3, subscriptionVehicleLimit('PARTICULIER', ['periodicite' => 'ESSAI', 'nbVehicules' => null]));
	}

	public function testEntrepriseVehicleLimits(): void
	{
		$this->assertSame(3, subscriptionVehicleLimit('ENTREPRISE', null));
		$this->assertSame(12, subscriptionVehicleLimit('ENTREPRISE', ['periodicite' => 'MENSUEL', 'nbVehicules' => 12]));
		$this->assertSame(50, subscriptionVehicleLimit('ENTREPRISE', ['periodicite' => 'ANNUEL', 'nbVehicules' => '50']));
		// Jamais moins que le minimum Premium, même sans nombre enregistré.
		$this->assertSame(SUB_ENTREPRISE_MIN_VEHICLES, subscriptionVehicleLimit('ENTREPRISE', ['periodicite' => 'ESSAI', 'nbVehicules' => null]));
	}
}
