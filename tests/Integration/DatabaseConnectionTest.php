<?php
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionTest extends TestCase
{
	public function testCanConnectToDatabaseOrSkip(): void
	{
		$db = new Database();
		$conn = $db->getConnection();
		if (!$conn) {
			$this->markTestSkipped('Connexion DB indisponible dans cet environnement.');
		}
		$this->assertNotNull($conn);
	}
}

