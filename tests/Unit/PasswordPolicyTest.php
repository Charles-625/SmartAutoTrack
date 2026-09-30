<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/password_policy.php';

final class PasswordPolicyTest extends TestCase
{
	public function testAcceptsAPasswordMeetingEveryRule(): void
	{
		$this->assertSame([], passwordPolicyMissing('Garage@2026'));
		$this->assertNull(passwordPolicyError('Garage@2026'));
	}

	/**
	 * @dataProvider weakPasswords
	 */
	public function testRejectsAPasswordMissingOneRule(string $password, string $missingRule): void
	{
		$this->assertSame([$missingRule], passwordPolicyMissing($password));
	}

	public static function weakPasswords(): array
	{
		return [
			'trop court'          => ['Ga@2026', 'au moins ' . PASSWORD_MIN_LENGTH . ' caractères'],
			'sans minuscule'      => ['GARAGE@2026', 'une lettre minuscule'],
			'sans majuscule'      => ['garage@2026', 'une lettre majuscule'],
			'sans chiffre'        => ['Garage@Yaounde', 'un chiffre'],
			'sans caractère spécial' => ['Garage2026', 'un caractère spécial (ex. ! @ # $ %)'],
		];
	}

	public function testErrorMessageListsEveryMissingRule(): void
	{
		$this->assertSame(
			'Le mot de passe doit contenir une lettre majuscule, un chiffre, un caractère spécial (ex. ! @ # $ %).',
			passwordPolicyError('motdepasse')
		);
	}

	public function testCustomLabelIsUsedInTheMessage(): void
	{
		$this->assertStringStartsWith('Le nouveau mot de passe doit contenir', passwordPolicyError('abc', 'Le nouveau mot de passe'));
	}
}
