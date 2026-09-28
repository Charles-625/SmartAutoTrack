<?php
use PHPUnit\Framework\TestCase;

/**
 * Vérifie que la base pointée par config/database.php a bien le nouveau schéma
 * (utilisateur/client/technicien/vehicule/intervention/..., créé le 23/09/2026)
 * et que les colonnes ajoutées au fil des phases de migration sont présentes.
 * Ignoré si la base n'est pas joignable (mêmes conditions que DatabaseConnectionTest).
 */
final class SchemaTest extends TestCase
{
	private static ?PDO $conn = null;

	public static function setUpBeforeClass(): void
	{
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

	public function testExpectedTablesExist(): void
	{
		$conn = $this->connOrSkip();
		$stmt = $conn->query("SELECT LOWER(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
		$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$expected = [
			'utilisateur', 'administrateur', 'client', 'particulier', 'entreprise',
			'technicien', 'garage', 'documentgarage',
			'vehicule', 'anomalie', 'intervention', 'reparation',
			// tables reprises telles quelles de l'ancien schéma (pas d'équivalent nouveau)
			'messages', 'notifications', 'technician_documents',
		];
		foreach ($expected as $table) {
			$this->assertContains($table, $tables, "Table manquante : $table");
		}
	}

	/** @dataProvider migratedColumns */
	public function testColumnsAddedDuringMigrationArePresent(string $table, string $column): void
	{
		$conn = $this->connOrSkip();
		$stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
		$stmt->execute([$table, $column]);
		$this->assertSame(1, (int)$stmt->fetchColumn(), "Colonne manquante : $table.$column");
	}

	public static function migratedColumns(): array
	{
		return [
			'utilisateur.dateCreation'       => ['utilisateur', 'dateCreation'],
			'utilisateur.themePreference'    => ['utilisateur', 'themePreference'],
			'utilisateur.photoProfil'        => ['utilisateur', 'photoProfil'],
			'technicien.statutValidation'    => ['technicien', 'statutValidation'],
			'technicien.competences'         => ['technicien', 'competences'],
			'technicien.experience'          => ['technicien', 'experience'],
			'vehicule.kilometrage'           => ['vehicule', 'kilometrage'],
			'vehicule.annee'                 => ['vehicule', 'annee'],
			'vehicule.dateCreation'          => ['vehicule', 'dateCreation'],
			'anomalie.type'                  => ['anomalie', 'type'],
			'anomalie.niveau'                => ['anomalie', 'niveau'],
			'anomalie.dateResolution'        => ['anomalie', 'dateResolution'],
			'intervention.priorite'          => ['intervention', 'priorite'],
			'reparation.titre'               => ['reparation', 'titre'],
			'reparation.diagnostic'          => ['reparation', 'diagnostic'],
			'reparation.travauxEffectues'    => ['reparation', 'travauxEffectues'],
			'reparation.piecesUtilisees'     => ['reparation', 'piecesUtilisees'],
			'reparation.recommandations'     => ['reparation', 'recommandations'],
			'reparation.dureeIntervention'   => ['reparation', 'dureeIntervention'],
		];
	}

	public function testMessagesAndNotificationsReferenceUtilisateur(): void
	{
		$conn = $this->connOrSkip();
		$stmt = $conn->query("
			SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = DATABASE()
			  AND TABLE_NAME IN ('messages', 'notifications', 'technician_documents')
			  AND REFERENCED_TABLE_NAME IS NOT NULL
		");
		$refs = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$targets = array_map(fn($r) => strtolower($r['REFERENCED_TABLE_NAME']), $refs);

		$this->assertNotEmpty($refs, 'messages/notifications/technician_documents devraient avoir des clés étrangères');
		foreach ($targets as $t) {
			$this->assertNotSame('users', $t, 'Une clé étrangère pointe encore vers l\'ancienne table users');
		}
	}
}
