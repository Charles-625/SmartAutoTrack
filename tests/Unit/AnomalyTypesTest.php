<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/anomaly_types.php';

/**
 * Référentiel des anomalies (includes/anomaly_types.php) : listes blanches du
 * type et de la gravité choisis par le client, et libellés de gravité.
 */
final class AnomalyTypesTest extends TestCase
{
	public function testEveryListedTypeIsValid(): void
	{
		foreach (ANOMALY_TYPES as $type) {
			$this->assertTrue(anomaly_is_valid_type($type), $type);
		}
		$this->assertSame(count(ANOMALY_TYPES), count(array_unique(ANOMALY_TYPES)));
	}

	public function testTypeOutsideTheListIsRejected(): void
	{
		$this->assertFalse(anomaly_is_valid_type(''));
		$this->assertFalse(anomaly_is_valid_type('freinage'));
		$this->assertFalse(anomaly_is_valid_type(' Freinage'));
		$this->assertFalse(anomaly_is_valid_type('<script>'));
		// Valeur stockée dans anomalie.type (VARCHAR(100)).
		foreach (ANOMALY_TYPES as $type) {
			$this->assertLessThanOrEqual(100, mb_strlen($type));
		}
	}

	public function testSeveritiesMatchTheAnomalieNiveauEnum(): void
	{
		$this->assertSame(['FAIBLE', 'MOYEN', 'CRITIQUE'], array_keys(ANOMALY_CLIENT_SEVERITIES));
		foreach (['FAIBLE', 'MOYEN', 'CRITIQUE'] as $niveau) {
			$this->assertTrue(anomaly_is_valid_niveau($niveau));
		}
	}

	public function testUnknownNiveauIsRejected(): void
	{
		$this->assertFalse(anomaly_is_valid_niveau(''));
		$this->assertFalse(anomaly_is_valid_niveau('critique'));
		$this->assertFalse(anomaly_is_valid_niveau('URGENT'));
		$this->assertFalse(anomaly_is_valid_niveau('0'));
	}

	public function testSeverityLabel(): void
	{
		$this->assertSame('Je peux rouler normalement', anomaly_severity_label('FAIBLE'));
		$this->assertSame('Je dois faire attention', anomaly_severity_label('MOYEN'));
		$this->assertSame('Véhicule immobilisé / dangereux', anomaly_severity_label('CRITIQUE'));
		// Niveau inconnu : renvoyé tel quel (à échapper à l'affichage).
		$this->assertSame('AUTRE', anomaly_severity_label('AUTRE'));
		$this->assertSame('', anomaly_severity_label(null));
	}

	public function testRequestMotifIsNotAnAnomalyType(): void
	{
		$this->assertSame('Anomalie constatée', ANOMALY_REQUEST_MOTIF);
		$this->assertFalse(anomaly_is_valid_type(ANOMALY_REQUEST_MOTIF));
	}

	public function testListedTypeIsStoredAsIsAndIgnoresThePrecision(): void
	{
		$this->assertSame('Freinage', anomaly_resolve_type('Freinage', 'texte ignoré'));
		$this->assertNull(anomaly_resolve_type('Inconnu', 'Fumée blanche'));
	}

	/**
	 * @dataProvider validCustomTypes
	 */
	public function testOtherStoresTheClientPrecision(string $custom, string $expected): void
	{
		$this->assertSame($expected, anomaly_resolve_type(ANOMALY_TYPE_OTHER, $custom));
	}

	public static function validCustomTypes(): array
	{
		return [
			'simple'             => ['Fumée blanche à l\'échappement', 'Fumée blanche à l\'échappement'],
			'espaces nettoyés'   => ['  Vibration   du  volant ', 'Vibration du volant'],
			'chiffres et signes' => ['Voyant 3 (huile) - tableau de bord', 'Voyant 3 (huile) - tableau de bord'],
			'ponctuation'        => ['Bruit au démarrage, côté droit.', 'Bruit au démarrage, côté droit.'],
		];
	}

	/**
	 * @dataProvider invalidCustomTypes
	 */
	public function testOtherRejectsAnEmptyTooLongOrUnsafePrecision(string $custom): void
	{
		$this->assertNull(anomaly_resolve_type(ANOMALY_TYPE_OTHER, $custom));
	}

	public static function invalidCustomTypes(): array
	{
		return [
			'vide'           => [''],
			'trop court'     => ['ab'],
			'trop long'      => [str_repeat('a', ANOMALY_CUSTOM_TYPE_MAX + 1)],
			'balise'         => ['<script>alert(1)</script>'],
			'symbole'        => ['Bruit @ moteur'],
			'débute signe'   => ['- bruit'],
		];
	}
}
