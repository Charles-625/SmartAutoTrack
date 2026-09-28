<?php
use PHPUnit\Framework\TestCase;

final class EscapeTest extends TestCase
{
	/** @dataProvider payloads */
	public function testHNeutralisesXssPayloads(string $payload): void
	{
		$out = h($payload);
		$this->assertStringNotContainsString('<', $out);
		$this->assertStringNotContainsString('>', $out);
		$this->assertStringNotContainsString('"', $out);
		$this->assertStringNotContainsString("'", $out);
	}

	public static function payloads(): array
	{
		return [
			['<script>alert(1)</script>'],
			['"><img src=x onerror=alert(1)>'],
			["' onmouseover='alert(1)"],
			['<svg/onload=alert(1)>'],
			['</script><script>alert(1)</script>'],
		];
	}

	public function testHKeepsAlreadyEncodedDataReadable(): void
	{
		// Les champs saisis passent par sanitize() avant stockage : pas de double encodage.
		$this->assertSame('O&#039;Brien &amp; fils', h('O&#039;Brien &amp; fils'));
		$this->assertSame('a &amp; b', h('a & b'));
	}

	public function testHHandlesNullAndNumbers(): void
	{
		$this->assertSame('', h(null));
		$this->assertSame('42', h(42));
	}

	/**
	 * Garde-fou : dans les pages, tout `<?php echo $variable ?>` affichant un champ texte
	 * doit passer par h(). Les compteurs, dates, classes CSS et identifiants sont tolérés.
	 */
	public function testPagesDoNotEchoRawTextFields(): void
	{
		$root = realpath(__DIR__ . '/../..');
		$dirs = ['admin', 'client', 'technicien', 'messages', 'includes', 'auth', 'garage'];
		$files = [$root . '/profile.php', $root . '/dashboard.php', $root . '/settings.php'];
		foreach ($dirs as $d) {
			foreach (glob("$root/$d/*.php") as $f) $files[] = $f;
		}

		$violations = [];
		foreach ($files as $f) {
			foreach (file($f) as $i => $line) {
				if (!preg_match_all('/<\?php\s+echo\s+(.*?)\s*;?\s*\?>/', $line, $m)) continue;
				foreach ($m[1] as $e) {
					$e = trim($e);
					if (strpos($e, '$') === false) continue;
					if (preg_match('/^(h\(|htmlspecialchars|json_encode|SITE_URL|number_format|formatDate|count\(|\(int\)|generateCSRF|array_sum|round\(|date\(|gv2_donut_svg\(|gv2_trend_svg\(|tv2_donut_svg\(|tv2_trend_svg\(|av2_donut_svg\(|av2_trend_svg\()/', $e)) continue;
					if (preg_match('/^\$(stats|index|vehicle_id|tomorrow_count|success_rate|intervention_id)\b/', $e)) continue;
					if (preg_match('/\?\s*\'[^\']*\'\s*:\s*\'[^\']*\'$/', $e) && !preg_match('/^(ucfirst|substr)/', $e)) continue;
					if (preg_match('/\[\'(id|\w+_id|count|\w+_count|severity_avg)\'\]/', $e)) continue;
					$violations[] = basename(dirname($f)) . '/' . basename($f) . ':' . ($i + 1) . ' ' . $e;
				}
			}
		}
		$this->assertSame([], $violations, "Sorties non échappées :\n" . implode("\n", $violations));
	}
}
