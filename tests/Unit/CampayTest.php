<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/campay.php';

final class CampayTest extends TestCase
{
	private const KEY = 'cle-webhook-de-test';

	private static function b64(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	private static function jwt(array $payload, string $key = self::KEY, string $alg = 'HS256'): string
	{
		$header = self::b64(json_encode(['alg' => $alg, 'typ' => 'JWT']));
		$body = self::b64(json_encode($payload));
		return $header . '.' . $body . '.' . self::b64(hash_hmac('sha256', "$header.$body", $key, true));
	}

	/** @dataProvider phones */
	public function testNormalizePhone(string $input, ?string $expected): void
	{
		$this->assertSame($expected, campayNormalizePhone($input));
	}

	public static function phones(): array
	{
		return [
			'9 chiffres'          => ['677123456', '237677123456'],
			'avec espaces'        => ['6 77 12 34 56', '237677123456'],
			'indicatif +237'      => ['+237 677 12 34 56', '237677123456'],
			'indicatif 00237'     => ['00237677123456', '237677123456'],
			'fixe (pas un 6)'     => ['222123456', null],
			'trop court'          => ['67712345', null],
			'autre pays'          => ['+33612345678', null],
			'vide'                => ['', null],
		];
	}

	public function testStatusMapping(): void
	{
		$this->assertSame('PAYE', campayStatusToPaiement('SUCCESSFUL'));
		$this->assertSame('ECHOUE', campayStatusToPaiement('failed'));
		$this->assertSame('EN_ATTENTE', campayStatusToPaiement('PENDING'));
		$this->assertSame('EN_ATTENTE', campayStatusToPaiement(''));
	}

	public function testAcceptsValidSignature(): void
	{
		$this->assertTrue(campayVerifyWebhookSignature(self::jwt(['reference' => 'abc']), self::KEY));
	}

	public function testRejectsForgedOrMalformedSignatures(): void
	{
		$this->assertFalse(campayVerifyWebhookSignature(self::jwt(['reference' => 'abc'], 'mauvaise-cle'), self::KEY));
		$this->assertFalse(campayVerifyWebhookSignature(self::jwt(['reference' => 'abc'], self::KEY, 'none'), self::KEY));
		$this->assertFalse(campayVerifyWebhookSignature('pas-un-jwt', self::KEY));
		$this->assertFalse(campayVerifyWebhookSignature('', self::KEY));
		$this->assertFalse(campayVerifyWebhookSignature(self::jwt(['reference' => 'abc']), ''));

		[$h, , $s] = explode('.', self::jwt(['status' => 'FAILED']));
		$tampered = $h . '.' . self::b64(json_encode(['status' => 'SUCCESSFUL'])) . '.' . $s;
		$this->assertFalse(campayVerifyWebhookSignature($tampered, self::KEY));
	}

	public function testRejectsExpiredSignature(): void
	{
		$jwt = self::jwt(['reference' => 'abc', 'exp' => 1000]);
		$this->assertFalse(campayVerifyWebhookSignature($jwt, self::KEY, 2000));
		$this->assertTrue(campayVerifyWebhookSignature($jwt, self::KEY, 500));
	}
}
