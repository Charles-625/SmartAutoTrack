<?php
use PHPUnit\Framework\TestCase;

final class VehicleFieldsTest extends TestCase
{
	/**
	 * @dataProvider realModels
	 */
	public function testAcceptsRealCarModels(string $model): void
	{
		$this->assertTrue(validateModel(sanitize($model)), $model);
	}

	public static function realModels(): array
	{
		return array_map(fn($m) => [$m], [
			'Corolla', '308', 'Classe C', 'RAV4', 'C-HR', 'CX-5', 'Model 3', 'ID.4',
			'Ka+', 'up!', 'Mégane', 'e-208', 'Land Cruiser', 'F-150', 'X5', 'Série 3',
		]);
	}

	/**
	 * @dataProvider invalidModels
	 */
	public function testRejectsInvalidModels(string $model): void
	{
		$this->assertFalse(validateModel(sanitize($model)), $model);
	}

	public static function invalidModels(): array
	{
		return [['-Corolla'], ['Corolla@'], ['Yaris#1'], [str_repeat('A', 51)]];
	}

	/**
	 * @dataProvider validPlates
	 */
	public function testAcceptsPlatesMixingLettersAndDigits(string $plate): void
	{
		$this->assertTrue(validatePlate(normalizePlate($plate)), $plate);
	}

	public static function validPlates(): array
	{
		return [['LT 123 AB'], ['CE-456-DF'], ['AB123CD'], ['lt 123 ab']];
	}

	/**
	 * @dataProvider invalidPlates
	 */
	public function testRejectsInvalidPlates(string $plate): void
	{
		$this->assertFalse(validatePlate(normalizePlate($plate)), $plate);
	}

	public static function invalidPlates(): array
	{
		return [['LT_123'], ['LT.123.AB'], ['-LT123'], ['A'], ['ABCDEFGHIJ123456']];
	}

	public function testNormalizePlateUppercasesAndCollapsesSpaces(): void
	{
		$this->assertSame('LT 123 AB', normalizePlate('  lt   123 ab '));
	}
}
