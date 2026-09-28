<?php
require __DIR__ . '/config.php';

// Simulateur OBD-II simple: génère aléatoirement une anomalie pour un véhicule donné
// Usage: POST /api/obd/sim { vehicle_id, metrics: { battery: %, brakes: %, engine_temp: C } }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if (strpos($path, '/api/') !== false) $path = substr($path, strpos($path, '/api/') + 4);

if ($path === 'obd/sim' && $method === 'POST') {
  only_roles(['admin','technicien']);
  $b = read_json();
  $vehicleId = (int)($b['vehicle_id'] ?? 0);
  $metrics = $b['metrics'] ?? [];
  if (!$vehicleId) json(['error'=>'vehicle_id_manquant'], 400);

  $anoms = [];
  $battery = isset($metrics['battery']) ? (int)$metrics['battery'] : rand(20, 100);
  $brakes = isset($metrics['brakes']) ? (int)$metrics['brakes'] : rand(10, 100);
  $engine = isset($metrics['engine_temp']) ? (int)$metrics['engine_temp'] : rand(70, 120);

  if ($battery < 30) $anoms[] = ['type'=>'batterie','description'=>'Tension batterie faible'];
  if ($brakes < 25) $anoms[] = ['type'=>'freins','description'=>'Usure plaquettes élevée'];
  if ($engine > 110) $anoms[] = ['type'=>'moteur','description'=>'Surchauffe moteur possible'];

  $pdo = db();
  $created = [];
  foreach ($anoms as $a) {
    $stmt = $pdo->prepare('INSERT INTO anomalies(type, description, vehicle_id, statut) VALUES(?,?,?,"nouvelle")');
    $stmt->execute([$a['type'], $a['description'], $vehicleId]);
    $created[] = $pdo->lastInsertId();
    try {
      // Tokens client + admin
      $q = $pdo->prepare('SELECT client_id FROM vehicles WHERE id_vehicle=?');
      $q->execute([$vehicleId]);
      $row = $q->fetch();
      $clientId = $row ? (int)$row['client_id'] : 0;
      $tokens = [];
      if ($clientId) {
        $q = $pdo->prepare('SELECT token FROM push_tokens WHERE user_id=?');
        $q->execute([$clientId]);
        $tokens = array_merge($tokens, array_column($q->fetchAll(), 'token'));
      }
      $q = $pdo->query("SELECT token FROM push_tokens pt JOIN users u ON u.id_user=pt.user_id WHERE u.statut='admin'");
      $tokens = array_merge($tokens, array_column($q->fetchAll(), 'token'));
      if (!empty($tokens)) {
        fcm_send($tokens, 'Anomalie détectée', $a['type'] . ' - ' . $a['description'], [ 'vehicle_id'=>$vehicleId ]);
      }
    } catch (Exception $ex) { /* noop */ }
  }

  json(['ok'=>true, 'created_ids'=>$created, 'metrics'=>['battery'=>$battery,'brakes'=>$brakes,'engine_temp'=>$engine]]);
}

json(['error'=>'route_introuvable'], 404);


