<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ai.php';

final class AiTest extends TestCase
{
	protected function setUp(): void
	{
		$_SESSION['ai_calls'] = [];
		unset($_SESSION['ai_history']);
	}

	public function testTextIsDecodedAndTruncated(): void
	{
		$this->assertSame("O'Brien & fils", aiText('O&#039;Brien &amp; fils'));
		$this->assertSame('abc…', aiText('abcdef', 3));
		$this->assertSame('', aiText(null));
	}

	public function testRateLimitBlocksThenRecovers(): void
	{
		$now = 1_000_000;
		for ($i = 0; $i < AI_RATE_LIMIT; $i++) {
			$this->assertTrue(aiAllowMessage($now));
		}
		$this->assertFalse(aiAllowMessage($now));
		$this->assertTrue(aiAllowMessage($now + AI_RATE_WINDOW + 1));
	}

	public function testHistoryIsBoundedAndSeparatedByRole(): void
	{
		for ($i = 0; $i < 20; $i++) {
			aiRemember(ROLE_CLIENT, "question $i", "réponse $i");
		}
		$history = aiHistory(ROLE_CLIENT);
		$this->assertCount(AI_HISTORY_LENGTH, $history);
		$this->assertSame('réponse 19', end($history)['content']);
		$this->assertSame([], aiHistory(ROLE_ADMIN));

		aiResetHistory(ROLE_CLIENT);
		$this->assertSame([], aiHistory(ROLE_CLIENT));
	}

	public function testSystemPromptEmbedsContextPerRole(): void
	{
		$this->assertStringContainsString('Toyota Corolla', aiSystemPrompt(ROLE_CLIENT, 'Toyota Corolla'));
		$this->assertStringContainsString('supervision', aiSystemPrompt(ROLE_ADMIN, 'x'));
	}
}
