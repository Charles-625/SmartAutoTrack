<?php
use PHPUnit\Framework\TestCase;

/**
 * Teste config/roles.php (détection de rôle, profil, validation de compte)
 * sur des lignes jetables créées puis supprimées par le test lui-même —
 * n'utilise aucune donnée réelle migrée.
 */
final class RolesTest extends TestCase
{
	private static ?PDO $conn = null;
	private const BASE_ID = 999001;

	public static function setUpBeforeClass(): void
	{
		require_once __DIR__ . '/../../config/roles.php';
		$db = new Database();
		self::$conn = $db->getConnection();
	}

	private function connOrSkip(): PDO
	{
		if (!self::$conn) {
			$this->markTestSkipped('Connexion DB indisponible dans cet environnement.');
		}
		return self::$conn;
	}

	protected function tearDown(): void
	{
		if (!self::$conn) return;
		// Nettoyage inconditionnel : l'ordre respecte les clés étrangères RESTRICT.
		foreach (range(self::BASE_ID, self::BASE_ID + 4) as $id) {
			self::$conn->prepare('DELETE FROM utilisateur WHERE idUtilisateur = ?')->execute([$id]);
		}
	}

	private function insertUtilisateur(PDO $conn, int $id, string $email): void
	{
		$conn->prepare('INSERT INTO utilisateur (idUtilisateur, nom, prenom, email, telephone, motDePasse) VALUES (?, ?, ?, ?, ?, ?)')
			->execute([$id, 'Test', 'Roles', $email, '0600000000', password_hash('x', PASSWORD_DEFAULT)]);
	}

	public function testGetUserRoleForEachSubtype(): void
	{
		$conn = $this->connOrSkip();
		$id = self::BASE_ID;

		$this->insertUtilisateur($conn, $id, 'roles.admin@example.invalid');
		$conn->prepare('INSERT INTO administrateur (idAdministrateur) VALUES (?)')->execute([$id]);
		$this->assertSame(ROLE_ADMIN, getUserRole($conn, $id));

		$id2 = self::BASE_ID + 1;
		$this->insertUtilisateur($conn, $id2, 'roles.client@example.invalid');
		$conn->prepare("INSERT INTO client (idClient, typeClient) VALUES (?, 'PARTICULIER')")->execute([$id2]);
		$this->assertSame(ROLE_CLIENT, getUserRole($conn, $id2));

		$id3 = self::BASE_ID + 2;
		$this->insertUtilisateur($conn, $id3, 'roles.tech@example.invalid');
		$conn->prepare("INSERT INTO technicien (idTechnicien, statutValidation) VALUES (?, 'EN_ATTENTE')")->execute([$id3]);
		$this->assertSame(ROLE_TECHNICIEN, getUserRole($conn, $id3));
	}

	public function testGetUserRoleReturnsNullForUnknownId(): void
	{
		$conn = $this->connOrSkip();
		$this->assertNull(getUserRole($conn, 999999999));
	}

	public function testIsAccountUsableGatesUnvalidatedTechnicienOnly(): void
	{
		$conn = $this->connOrSkip();
		$id = self::BASE_ID + 3;
		$this->insertUtilisateur($conn, $id, 'roles.tech2@example.invalid');
		$conn->prepare("INSERT INTO technicien (idTechnicien, statutValidation) VALUES (?, 'EN_ATTENTE')")->execute([$id]);

		$profile = getUserProfile($conn, $id);
		$this->assertFalse(isAccountUsable($profile), 'Un technicien EN_ATTENTE ne doit pas être utilisable');

		$conn->prepare("UPDATE technicien SET statutValidation = 'VALIDE' WHERE idTechnicien = ?")->execute([$id]);
		$profile = getUserProfile($conn, $id);
		$this->assertTrue(isAccountUsable($profile), 'Un technicien VALIDE doit être utilisable');

		// Un client est toujours utilisable (pas de notion de validation)
		$id2 = self::BASE_ID + 4;
		$this->insertUtilisateur($conn, $id2, 'roles.client2@example.invalid');
		$conn->prepare("INSERT INTO client (idClient, typeClient) VALUES (?, 'PARTICULIER')")->execute([$id2]);
		$this->assertTrue(isAccountUsable(getUserProfile($conn, $id2)));
	}

	public function testFindUserByEmail(): void
	{
		$conn = $this->connOrSkip();
		$id = self::BASE_ID;
		$this->insertUtilisateur($conn, $id, 'roles.findme@example.invalid');

		$found = findUserByEmail($conn, 'roles.findme@example.invalid');
		$this->assertNotNull($found);
		$this->assertSame($id, (int)$found['idUtilisateur']);

		$this->assertNull(findUserByEmail($conn, 'roles.doesnotexist@example.invalid'));
	}
}
