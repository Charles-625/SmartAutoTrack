<?php
/**
 * Limitation des tentatives de connexion (anti brute-force).
 *
 * Compteurs stockés dans des fichiers (un par clé) plutôt qu'en session :
 * un attaquant peut simplement ne pas renvoyer son cookie de session.
 * Deux clés par tentative : l'email visé et l'adresse IP d'origine.
 */

const LOGIN_THROTTLE_WINDOW = 900;          // secondes (15 min)
const LOGIN_THROTTLE_MAX_PER_EMAIL = 5;
const LOGIN_THROTTLE_MAX_PER_IP = 20;

function loginThrottleDir(): string {
    $dir = getenv('HCH_THROTTLE_DIR') ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartautotrack_login_throttle');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function loginThrottleFile(string $key): string {
    return loginThrottleDir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
}

/** Horodatages des échecs encore dans la fenêtre. */
function loginThrottleFailures(string $key, ?int $now = null): array {
    $now = $now ?? time();
    $file = loginThrottleFile($key);
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    return array_values(array_filter($data, function ($t) use ($now) {
        return is_int($t) && $t > $now - LOGIN_THROTTLE_WINDOW;
    }));
}

function loginThrottleKeys(string $email, string $ip): array {
    return [
        'email:' . strtolower(trim($email)) => LOGIN_THROTTLE_MAX_PER_EMAIL,
        'ip:' . $ip => LOGIN_THROTTLE_MAX_PER_IP,
    ];
}

/**
 * Nombre de secondes avant une nouvelle tentative possible (0 = autorisée).
 */
function loginThrottleRetryAfter(string $email, string $ip, ?int $now = null): int {
    $now = $now ?? time();
    $wait = 0;
    foreach (loginThrottleKeys($email, $ip) as $key => $max) {
        $failures = loginThrottleFailures($key, $now);
        if (count($failures) >= $max) {
            sort($failures);
            $oldestRelevant = $failures[count($failures) - $max];
            $wait = max($wait, $oldestRelevant + LOGIN_THROTTLE_WINDOW - $now);
        }
    }
    return $wait;
}

function loginThrottleRecordFailure(string $email, string $ip, ?int $now = null): void {
    $now = $now ?? time();
    foreach (array_keys(loginThrottleKeys($email, $ip)) as $key) {
        $failures = loginThrottleFailures($key, $now);
        $failures[] = $now;
        @file_put_contents(loginThrottleFile($key), json_encode($failures), LOCK_EX);
    }
}

/** Connexion réussie : on efface les échecs liés à cet email (pas ceux de l'IP). */
function loginThrottleClear(string $email): void {
    @unlink(loginThrottleFile('email:' . strtolower(trim($email))));
}
