<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ai.php';

final class AiTest extends TestCase
{
	protected function setUp(): void
	{
		$_SESSION['ai_calls'] = [];
		unset($_SESSION['ai_history']);
		// Clé factice : les tests de aiChat() passent par un transport simulé,
		// sans dépendre de config/local.php ni appeler OpenRouter.
		putenv('OPENROUTER_API_KEY=sk-or-v1-test');
	}

	protected function tearDown(): void
	{
		putenv('OPENROUTER_API_KEY');
	}

	/** Réponse HTTP factice au format de httpJsonRequest(). */
	private static function http(int $status, $data): array
	{
		return ['status' => $status, 'data' => $data, 'raw' => json_encode($data)];
	}

	private static function ok(string $text): array
	{
		return self::http(200, ['choices' => [['message' => ['content' => $text]]]]);
	}

	public function testModelListPutsPrimaryFirstWithoutDuplicates(): void
	{
		$this->assertSame(['a:free', 'b:free', 'c:free'], aiModelList('a:free', ' b:free, a:free ,, c:free '));
		$this->assertSame(['a:free'], aiModelList('a:free', ''));
		$this->assertCount(AI_MAX_MODELS, aiModelList('m0', 'm1,m2,m3,m4,m5'));
		$this->assertSame([AI_DEFAULT_MODEL], aiModelList('', ''));
	}

	public function testFallbackModelAnswersWhenPrimaryIsSaturatedOrRemoved(): void
	{
		$calls = [];
		$transport = function (string $model) use (&$calls) {
			$calls[] = $model;
			return [
				'principal' => self::http(429, ['error' => ['message' => 'rate-limited']]),
				'retire' => self::http(404, ['error' => ['message' => 'unavailable']]),
				'secours' => self::ok('Réponse du secours'),
			][$model];
		};
		$answer = aiChat([['role' => 'user', 'content' => 'Bonjour']], 50, $transport, ['principal', 'retire', 'secours']);
		$this->assertSame('Réponse du secours', $answer);
		$this->assertSame(['principal', 'retire', 'secours'], $calls);
	}

	public function testEmptyAnswerNetworkErrorAndReservedModelMoveToTheNextModel(): void
	{
		$transport = function (string $model) {
			if ($model === 'reseau') {
				throw new RuntimeException('timeout');
			}
			return [
				'muet' => self::ok('   '),
				'reserve' => self::http(403, ['error' => ['message' => 'only available on agentic harnesses']]),
				'bon' => self::ok('OK'),
			][$model];
		};
		$this->assertSame('OK', aiChat([], 50, $transport, ['muet', 'reseau', 'reserve', 'bon']));
	}

	public function testRejectedKeyStopsImmediately(): void
	{
		$calls = 0;
		$transport = function () use (&$calls) {
			$calls++;
			return self::http(401, ['error' => ['message' => 'invalid key']]);
		};
		try {
			aiChat([], 50, $transport, ['a', 'b', 'c']);
			$this->fail('AiException attendue');
		} catch (AiException $e) {
			$this->assertFalse($e->retryable);
			$this->assertStringContainsString('401', $e->detail);
		}
		$this->assertSame(1, $calls);
	}

	public function testAllModelsFailingReportsEachAttempt(): void
	{
		$transport = fn(string $model) => self::http(429, ['error' => ['message' => "busy $model"]]);
		try {
			aiChat([], 50, $transport, ['a', 'b']);
			$this->fail('AiException attendue');
		} catch (AiException $e) {
			$this->assertStringContainsString('trop de demandes', $e->getMessage());
			$this->assertStringContainsString('busy a', $e->detail);
			$this->assertStringContainsString('busy b', $e->detail);
		}
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

	public function testPromptsRestrictToAutomotiveWithFixedRefusal(): void
	{
		foreach ([ROLE_CLIENT, ROLE_ADMIN] as $role) {
			$prompt = aiSystemPrompt($role, 'x');
			$this->assertStringContainsString('PÉRIMÈTRE STRICT', $prompt);
			$this->assertStringContainsString(AI_OFF_TOPIC_MESSAGE, $prompt);
		}
		$photo = aiImageContent('', 'data:image/jpeg;base64,AAAA');
		$this->assertStringContainsString(AI_OFF_TOPIC_MESSAGE, $photo[0]['text']);
		$this->assertSame('data:image/jpeg;base64,AAAA', $photo[1]['image_url']['url']);
	}

	public function testTruncatedRefusalIsCompleted(): void
	{
		$this->assertSame(AI_OFF_TOPIC_MESSAGE, aiCompleteRefusal('Je suis'));
		$this->assertSame(AI_OFF_TOPIC_MESSAGE, aiCompleteRefusal('« Je suis l\'assistant SmartAutoTrack : je réponds…'));
		$this->assertSame(AI_OFF_TOPIC_MESSAGE, aiCompleteRefusal(AI_OFF_TOPIC_MESSAGE));
		$this->assertSame('Faites vérifier vos freins.', aiCompleteRefusal('Faites vérifier vos freins.'));
		$this->assertSame('Je', aiCompleteRefusal('Je'));
	}

	/** Véhicule au format de maintenanceClientSchedule(), échéances calculées pour le 07/10/2026. */
	private static function scheduledVehicle(string $plate, array $last): array
	{
		$vehicle = ['kilometrage' => 86000, 'dateExpirationAssurance' => '2026-10-10', 'dateProchaineVisiteTechnique' => null];
		return [
			'idVehicule' => 1, 'marque' => 'Toyota', 'modele' => 'Corolla', 'immatriculation' => $plate, 'kilometrage' => 86000,
			'echeances' => maintenanceBuildSchedule($vehicle, $last, MAINTENANCE_DEFAULT_RULES, '2026-10-07'),
		];
	}

	public function testScheduleLinesPutUrgentItemsFirstWithVehicleName(): void
	{
		$lines = aiScheduleLines([self::scheduledVehicle('LT 123 AB', [
			'VIDANGE' => ['dateEntretien' => '2026-04-01', 'kilometrage' => 80000],
			'PNEUS' => ['dateEntretien' => '2026-01-15', 'kilometrage' => 70000],
		])]);

		$this->assertCount(5, $lines);
		$this->assertStringStartsWith('- Toyota Corolla (LT 123 AB) — Vidange : en retard', $lines[0]);
		$this->assertStringContainsString('Assurance : bientôt', $lines[1]);
		$this->assertStringContainsString('dans 3 jours', $lines[1]);
		$this->assertStringContainsString('Visite technique : inconnue', implode("\n", $lines));
	}

	public function testScheduleLinesAreCappedForLargeFleets(): void
	{
		$fleet = array_fill(0, 20, self::scheduledVehicle('LT 1 AA', []));
		$lines = aiScheduleLines($fleet);

		$this->assertCount(AI_SCHEDULE_MAX_LINES + 1, $lines);
		$this->assertStringContainsString('et ' . (20 * 5 - AI_SCHEDULE_MAX_LINES) . ' autre(s)', end($lines));
		// Les 20 assurances « bientôt » restent toutes dans la liste.
		$this->assertCount(20, array_filter($lines, fn($l) => str_contains($l, 'Assurance : bientôt')));
	}

	public function testScheduleInstructionsOnlyWhenScheduleIsInContext(): void
	{
		$this->assertStringNotContainsString('ne les recalcule pas', aiSystemPrompt(ROLE_CLIENT, 'Véhicules (0) :'));

		$prompt = aiSystemPrompt(ROLE_CLIENT, AI_SCHEDULE_HEADING . " :\n- Toyota Corolla — Vidange : en retard");
		$this->assertStringContainsString('ne les recalcule pas et n\'invente aucune date', $prompt);
		$this->assertStringContainsString('rappelle-la poliment au début de ta réponse', $prompt);
		$this->assertStringContainsString('renseigner dans la fiche du véhicule', $prompt);
	}
}
