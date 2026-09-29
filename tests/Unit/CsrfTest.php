<?php
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
	protected function setUp(): void
	{
		$_SESSION['csrf_token'] = 'jeton-de-test';
		$_POST = [];
		unset($_SERVER['HTTP_X_CSRF_TOKEN']);
	}

	public function testAcceptsTokenFromPostField(): void
	{
		$_POST['csrf_token'] = 'jeton-de-test';
		$this->assertTrue(verifyRequestCSRF());
	}

	public function testAcceptsTokenFromHeader(): void
	{
		$_SERVER['HTTP_X_CSRF_TOKEN'] = 'jeton-de-test';
		$this->assertTrue(verifyRequestCSRF());
	}

	public function testRejectsMissingOrWrongToken(): void
	{
		$this->assertFalse(verifyRequestCSRF());
		$_SERVER['HTTP_X_CSRF_TOKEN'] = 'autre';
		$this->assertFalse(verifyRequestCSRF());
		$_POST['csrf_token'] = ['tableau'];
		$this->assertFalse(verifyRequestCSRF());
	}
}
