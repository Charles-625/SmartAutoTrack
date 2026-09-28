<?php
use PHPUnit\Framework\TestCase;

final class UtilTest extends TestCase
{
	public function testSanitize(): void
	{
		$raw = "  <b>Hello</b> & \"World\"  ";
		$clean = sanitize($raw);
		$this->assertSame('Hello &amp; &quot;World&quot;', $clean);
	}

	public function testValidateEmail(): void
	{
		$this->assertTrue(validateEmail('john.doe@example.com'));
		$this->assertFalse(validateEmail('not-an-email'));
	}

	public function testFormatDate(): void
	{
		$formatted = formatDate('2025-01-02 03:04:05');
		$this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $formatted);
	}
}
