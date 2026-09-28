<?php
// Bootstrap PHPUnit pour SmartAutoTrack
$projectRoot = __DIR__ . '/..';
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/config/database.php';

// Définir une base URL pour les tests fonctionnels (modifiable via phpunit.xml)
if (!defined('HCH_BASE_URL')) {
	define('HCH_BASE_URL', 'http://localhost/HCH/');
}

