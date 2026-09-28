<?php
/**
 * Configuration de la base de données SmartAutoTrack
 *
 * Les identifiants ne sont plus écrits dans le code. Ordre de résolution :
 *   1. variables d'environnement HCH_DB_HOST / HCH_DB_NAME / HCH_DB_USER / HCH_DB_PASS
 *   2. fichier config/local.php (non versionné, voir config/local.example.php)
 */

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $local = [];
        $localFile = __DIR__ . '/local.php';
        if (is_file($localFile)) {
            $loaded = require $localFile;
            if (is_array($loaded)) {
                $local = $loaded;
            }
        }

        $this->host     = getenv('HCH_DB_HOST') ?: ($local['db_host'] ?? 'localhost');
        $this->db_name  = getenv('HCH_DB_NAME') ?: ($local['db_name'] ?? 'Charles');
        $this->username = getenv('HCH_DB_USER') ?: ($local['db_user'] ?? 'root');
        $pass           = getenv('HCH_DB_PASS');
        $this->password = ($pass !== false) ? $pass : ($local['db_pass'] ?? '');
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                )
            );
        } catch (PDOException $exception) {
            // Le détail (hôte, utilisateur, message SQL) reste dans les logs serveur.
            error_log('[SmartAutoTrack] Connexion base de données impossible : ' . $exception->getMessage());

            if (PHP_SAPI !== 'cli') {
                http_response_code(503);
                exit('Service temporairement indisponible. Veuillez réessayer plus tard.');
            }
        }

        return $this->conn;
    }
}
?>
