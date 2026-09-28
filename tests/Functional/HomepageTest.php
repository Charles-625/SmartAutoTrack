<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;

final class HomepageTest extends TestCase
{
	public function testHomepageRespondsOrSkip(): void
	{
		$baseUrl = defined('HCH_BASE_URL') ? HCH_BASE_URL : 'http://localhost/HCH/';
		$client = new Client(['http_errors' => false, 'timeout' => 3]);
		try {
			$response = $client->get($baseUrl . 'index.php');
		} catch (\Exception $e) {
			$this->markTestSkipped('Serveur local indisponible: ' . $e->getMessage());
			return;
		}
		$this->assertSame(200, $response->getStatusCode());
		$body = (string) $response->getBody();
		$this->assertStringContainsString('SmartAutoTrack', $body);
	}
}

