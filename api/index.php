<?php
require __DIR__ . '/config.php';

/**
 * Point d'entrée de l'API REST HÉRITÉE (JSON), sur l'ancienne base
 * `smartautotrack` — et non sur la base `charles` du site actuel. Conservée
 * à titre d'héritage (jetons push FCM : clients mobiles) ; les pages PHP et
 * le JavaScript du site ne l'appellent pas.
 *
 * Routage manuel par chemin + méthode HTTP (voir la normalisation ci-dessous) :
 *  - ping, auth/ping                     : test de disponibilité (public)
 *  - auth/register, auth/login (POST)    : inscription / connexion (public)
 *  - auth/me (GET), auth/logout (POST)   : session courante
 *  - push/register (POST)                : jeton FCM de l'utilisateur connecté
 *  - vehicles, vehicles/{id}             : lecture connectée, écriture admin
 *  - anomalies, anomalies/{id}           : lecture connectée, écriture admin/technicien
 *                                          (suppression admin), push FCM à la création
 *  - reparations, reparations/{id}       : idem anomalies
 *  - interventions, interventions/{id}   : lecture connectée, écriture admin
 *
 * Tables : users, vehicles, anomalies, reparations, interventions, push_tokens.
 * Chaque route répond via json(), qui termine le script ; une route non
 * reconnue tombe sur le 404 final.
 */

// Router simple par chemin + méthode
$method = $_SERVER['REQUEST_METHOD'];
// Préférence à PATH_INFO si disponible (index.php/xxx)
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normaliser le chemin pour supporter:
// - /smartautotrack/api/xxx (via .htaccess)
// - /smartautotrack/api/index.php/xxx (sans réécriture)
// - /api/xxx si alias
if (strpos($path, '/smartautotrack/api/') !== false) {
  $path = substr($path, strpos($path, '/smartautotrack/api/') + strlen('/smartautotrack/api/'));
}
if (strpos($path, '/api/') !== false) {
  $path = substr($path, strpos($path, '/api/') + 4);
}
// Support index.php/xxx
if (strpos($path, 'index.php/') !== false) {
  $path = substr($path, strpos($path, 'index.php/') + strlen('index.php/'));
}
// Nettoyage des slashes initiaux
$path = ltrim($path, '/');

// Support direct: si path est vide et qu'on a PATH_INFO vide, permettre ?path=xxx
if ($path === '' && isset($_GET['path'])) {
  $path = ltrim((string)$_GET['path'], '/');
}

// Certains environnements n'exposent pas PATH_INFO: strip "index.php" final
if ($path === 'index.php' || preg_match('#(^|/)index\\.php$#', $path)) {
  $path = '';
  if (isset($_GET['path'])) {
    $path = ltrim((string)$_GET['path'], '/');
  }
}

// Si chemin vide: renvoyer un ping OK
if ($path === '' || $path === false) {
  json(['ok'=>true, 'endpoint'=>'root', 'time'=>date('c')]);
}

// Dispatch
if ($path === 'ping' || $path === 'auth/ping') {
  json(['ok' => true, 'time' => date('c')]);
}

// Auth
if ($path === 'auth/register' && $method === 'POST') {
  $b = read_json();
  $nom = trim($b['nom'] ?? '');
  $email = trim($b['email'] ?? '');
  $mdp = $b['mot_de_passe'] ?? '';
  $statut = $b['statut'] ?? '';
  // Attention (héritage) : le rôle est choisi librement par l'appelant, y compris 'admin'.
  if (!$nom || !$email || !$mdp || !in_array($statut, ['client','admin','technicien'], true)) json(['error'=>'champs_invalides'], 400);
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id_user FROM users WHERE email=?');
  $stmt->execute([$email]);
  if ($stmt->fetch()) json(['error'=>'email_existant'], 409);
  $hash = password_hash($mdp, PASSWORD_BCRYPT);
  $stmt = $pdo->prepare('INSERT INTO users(nom,email,mot_de_passe,statut) VALUES(?,?,?,?)');
  $stmt->execute([$nom,$email,$hash,$statut]);
  json(['ok'=>true]);
}

if ($path === 'auth/login' && $method === 'POST') {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $b = read_json();
  $email = trim($b['email'] ?? '');
  $mdp = $b['mot_de_passe'] ?? '';
  if (!$email || !$mdp) json(['error'=>'champs_invalides'], 400);
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id_user, nom, email, mot_de_passe, statut FROM users WHERE email=?');
  $stmt->execute([$email]);
  $u = $stmt->fetch();
  if (!$u || !password_verify($mdp, $u['mot_de_passe'])) json(['error'=>'identifiants_invalides'], 401);
  // Le hash ne doit jamais sortir en session ni dans la réponse.
  unset($u['mot_de_passe']);
  $_SESSION['user'] = $u;
  json(['ok'=>true,'user'=>$u]);
}

if ($path === 'auth/me' && $method === 'GET') {
  $u = auth_user();
  if (!$u) json(['user'=>null]);
  json(['user'=>$u]);
}

// Enregistrement token push
if ($path === 'push/register' && $method === 'POST') {
  $u = require_auth();
  $b = read_json();
  $token = trim($b['token'] ?? '');
  if (!$token) json(['error'=>'token_requis'], 400);
  $pdo = db();
  $stmt = $pdo->prepare('INSERT IGNORE INTO push_tokens(user_id, token) VALUES(?, ?)');
  $stmt->execute([$u['id_user'], $token]);
  json(['ok'=>true]);
}

if ($path === 'auth/logout' && $method === 'POST') {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  session_destroy();
  json(['ok'=>true]);
}

// Vehicles CRUD (extraits principaux)
if ($path === 'vehicles' && $method === 'GET') {
  $u = require_auth();
  $pdo = db();
  // Un client ne voit que ses véhicules ; admin et technicien voient tout le parc.
  if ($u['statut'] === 'client') {
    $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE client_id=? ORDER BY id_vehicle DESC');
    $stmt->execute([$u['id_user']]);
  } else {
    $stmt = $pdo->query('SELECT v.*, u.nom AS client_nom FROM vehicles v LEFT JOIN users u ON u.id_user=v.client_id ORDER BY id_vehicle DESC');
  }
  json($stmt->fetchAll());
}

if ($path === 'vehicles' && $method === 'POST') {
  only_roles(['admin']);
  $b = read_json();
  $pdo = db();
  $stmt = $pdo->prepare('INSERT INTO vehicles(marque,modele,annee,etat,client_id) VALUES(?,?,?,?,?)');
  $stmt->execute([$b['marque']??'', $b['modele']??'', $b['annee']??date('Y'), $b['etat']??'bon', $b['client_id']??null]);
  json(['ok'=>true,'id'=>$pdo->lastInsertId()], 201);
}

// vehicles/{id}
if (preg_match('#^vehicles/(\d+)$#', $path, $m)) {
  $id = (int)$m[1];
  if ($method === 'PUT') {
    only_roles(['admin']);
    $b = read_json();
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE vehicles SET marque=?, modele=?, annee=?, etat=?, client_id=? WHERE id_vehicle=?');
    $stmt->execute([$b['marque']??'', $b['modele']??'', $b['annee']??date('Y'), $b['etat']??'bon', $b['client_id']??null, $id]);
    json(['ok'=>true]);
  }
  if ($method === 'DELETE') {
    only_roles(['admin']);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM vehicles WHERE id_vehicle=?');
    $stmt->execute([$id]);
    json(['ok'=>true]);
  }
}

// Anomalies list + create
if ($path === 'anomalies' && $method === 'GET') {
  require_auth();
  $pdo = db();
  $stmt = $pdo->query('SELECT a.*, v.marque, v.modele FROM anomalies a JOIN vehicles v ON v.id_vehicle=a.vehicle_id ORDER BY date_detection DESC');
  json($stmt->fetchAll());
}

if ($path === 'anomalies' && $method === 'POST') {
  only_roles(['admin','technicien']);
  $b = read_json();
  $pdo = db();
  $stmt = $pdo->prepare('INSERT INTO anomalies(type,description,vehicle_id,statut) VALUES(?,?,?,?)');
  $stmt->execute([$b['type']??'inconnue', $b['description']??'', $b['vehicle_id']??null, $b['statut']??'nouvelle']);
  // Notifications: récupérer tokens client et admin
  try {
    $vehId = (int)($b['vehicle_id'] ?? 0);
    if ($vehId) {
      $q = $pdo->prepare('SELECT client_id FROM vehicles WHERE id_vehicle=?');
      $q->execute([$vehId]);
      $row = $q->fetch();
      $clientId = $row ? (int)$row['client_id'] : 0;
      // Tokens client
      $tokens = [];
      if ($clientId) {
        $q = $pdo->prepare('SELECT token FROM push_tokens WHERE user_id=?');
        $q->execute([$clientId]);
        $tokens = array_merge($tokens, array_column($q->fetchAll(), 'token'));
      }
      // Tokens admin
      $q = $pdo->query("SELECT token FROM push_tokens pt JOIN users u ON u.id_user=pt.user_id WHERE u.statut='admin'");
      $tokens = array_merge($tokens, array_column($q->fetchAll(), 'token'));
      if (!empty($tokens)) {
        fcm_send($tokens, 'Nouvelle anomalie', ($b['type']??'Anomalie') . ' détectée', [ 'vehicle_id'=>$vehId ]);
      }
    }
  } catch (Exception $e) { /* noop */ }
  json(['ok'=>true,'id'=>$pdo->lastInsertId()], 201);
}

// anomalies/{id}
if (preg_match('#^anomalies/(\d+)$#', $path, $m)) {
  $id = (int)$m[1];
  if ($method === 'PUT') {
    only_roles(['admin','technicien']);
    $b = read_json();
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE anomalies SET type=COALESCE(?, type), description=COALESCE(?, description), statut=COALESCE(?, statut) WHERE id_anomaly=?');
    $stmt->execute([$b['type']??null, $b['description']??null, $b['statut']??null, $id]);
    json(['ok'=>true]);
  }
  if ($method === 'DELETE') {
    only_roles(['admin']);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM anomalies WHERE id_anomaly=?');
    $stmt->execute([$id]);
    json(['ok'=>true]);
  }
}

// Réparations list
if ($path === 'reparations' && $method === 'GET') {
  require_auth();
  $pdo = db();
  $stmt = $pdo->query('SELECT r.*, v.marque, v.modele, u.nom AS technicien FROM reparations r JOIN vehicles v ON v.id_vehicle=r.vehicle_id LEFT JOIN users u ON u.id_user=r.technicien_id ORDER BY date_reparation DESC');
  json($stmt->fetchAll());
}

if ($path === 'reparations' && $method === 'POST') {
  only_roles(['admin','technicien']);
  $b = read_json();
  $pdo = db();
  $stmt = $pdo->prepare('INSERT INTO reparations(rapport, statut, vehicle_id, technicien_id) VALUES(?,?,?,?)');
  $stmt->execute([$b['rapport']??'', $b['statut']??'en attente', $b['vehicle_id']??null, $b['technicien_id']??null]);
  json(['ok'=>true,'id'=>$pdo->lastInsertId()], 201);
}

if (preg_match('#^reparations/(\d+)$#', $path, $m)) {
  $id = (int)$m[1];
  if ($method === 'PUT') {
    only_roles(['admin','technicien']);
    $b = read_json();
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE reparations SET rapport=COALESCE(?, rapport), statut=COALESCE(?, statut), technicien_id=COALESCE(?, technicien_id) WHERE id_reparation=?');
    $stmt->execute([$b['rapport']??null, $b['statut']??null, $b['technicien_id']??null, $id]);
    json(['ok'=>true]);
  }
  if ($method === 'DELETE') {
    only_roles(['admin']);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM reparations WHERE id_reparation=?');
    $stmt->execute([$id]);
    json(['ok'=>true]);
  }
}

// Interventions list
if ($path === 'interventions' && $method === 'GET') {
  require_auth();
  $pdo = db();
  $stmt = $pdo->query('SELECT i.*, v.marque, v.modele, a.nom AS admin_nom, t.nom AS technicien_nom FROM interventions i JOIN vehicles v ON v.id_vehicle=i.vehicle_id LEFT JOIN users a ON a.id_user=i.admin_id LEFT JOIN users t ON t.id_user=i.technicien_id ORDER BY date_intervention DESC');
  json($stmt->fetchAll());
}

if ($path === 'interventions' && $method === 'POST') {
  only_roles(['admin']);
  $b = read_json();
  $pdo = db();
  $stmt = $pdo->prepare('INSERT INTO interventions(statut, vehicle_id, admin_id, technicien_id, date_intervention) VALUES(?,?,?,?,COALESCE(?, CURRENT_TIMESTAMP))');
  $stmt->execute([$b['statut']??'planifiee', $b['vehicle_id']??null, $b['admin_id']??null, $b['technicien_id']??null, $b['date_intervention']??null]);
  json(['ok'=>true,'id'=>$pdo->lastInsertId()], 201);
}

if (preg_match('#^interventions/(\d+)$#', $path, $m)) {
  $id = (int)$m[1];
  if ($method === 'PUT') {
    only_roles(['admin']);
    $b = read_json();
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE interventions SET statut=COALESCE(?, statut), technicien_id=COALESCE(?, technicien_id), date_intervention=COALESCE(?, date_intervention) WHERE id_intervention=?');
    $stmt->execute([$b['statut']??null, $b['technicien_id']??null, $b['date_intervention']??null, $id]);
    json(['ok'=>true]);
  }
  if ($method === 'DELETE') {
    only_roles(['admin']);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM interventions WHERE id_intervention=?');
    $stmt->execute([$id]);
    json(['ok'=>true]);
  }
}

// Fallback
json(['error' => 'route_introuvable', 'path' => $path, 'method' => $method], 404);


