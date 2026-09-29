<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/login_throttle.php';

final class LoginThrottleTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sat_throttle_test_' . bin2hex(random_bytes(4));
		putenv('HCH_THROTTLE_DIR=' . $this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
			unlink($f);
		}
		@rmdir($this->dir);
		putenv('HCH_THROTTLE_DIR');
	}

	public function testAllowsAttemptsBelowTheLimit(): void
	{
		$now = 1_000_000;
		for ($i = 0; $i < LOGIN_THROTTLE_MAX_PER_EMAIL - 1; $i++) {
			loginThrottleRecordFailure('victime@example.com', '10.0.0.1', $now);
		}
		$this->assertSame(0, loginThrottleRetryAfter('victime@example.com', '10.0.0.1', $now));
	}

	public function testBlocksAnEmailAfterTooManyFailures(): void
	{
		$now = 1_000_000;
		for ($i = 0; $i < LOGIN_THROTTLE_MAX_PER_EMAIL; $i++) {
			loginThrottleRecordFailure('Victime@Example.com', '10.0.0.' . $i, $now);
		}
		$this->assertSame(LOGIN_THROTTLE_WINDOW, loginThrottleRetryAfter('victime@example.com', '10.9.9.9', $now));
		$this->assertSame(0, loginThrottleRetryAfter('victime@example.com', '10.9.9.9', $now + LOGIN_THROTTLE_WINDOW));
	}

	public function testBlocksAnIpTryingManyEmails(): void
	{
		$now = 1_000_000;
		for ($i = 0; $i < LOGIN_THROTTLE_MAX_PER_IP; $i++) {
			loginThrottleRecordFailure("user$i@example.com", '10.0.0.1', $now);
		}
		$this->assertGreaterThan(0, loginThrottleRetryAfter('nouveau@example.com', '10.0.0.1', $now));
		$this->assertSame(0, loginThrottleRetryAfter('nouveau@example.com', '10.0.0.2', $now));
	}

	public function testSuccessfulLoginClearsEmailCounter(): void
	{
		$now = time();
		for ($i = 0; $i < LOGIN_THROTTLE_MAX_PER_EMAIL; $i++) {
			loginThrottleRecordFailure('victime@example.com', '10.0.0.' . $i, $now);
		}
		loginThrottleClear('victime@example.com');
		$this->assertSame(0, loginThrottleRetryAfter('victime@example.com', '10.9.9.9', $now));
	}
}
