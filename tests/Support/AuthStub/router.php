<?php

declare(strict_types=1);

/**
 * Router-Skript des Auth-Stubs (php -S), siehe {@see \TeacherOS\Tests\Support\AuthStub}.
 *
 * Spielt die Netzwerkgrenze der API zum Anmeldedienst nach (ADR 0002 G5):
 * prueft die Signatur wie der echte Dienst (HMAC-SHA256 ueber
 * "<timestamp>.<nonce>.<roher Body>", Header X-Internal-Timestamp, X-Internal-Nonce
 * und die Signatur im konfigurierten Header, Alter, Nonce-Replay), zeichnet jeden
 * Aufruf auf und antwortet nach Skript. Ohne Skript verhaelt er sich wie der
 * echte Dienst: neue Adresse -> created, bekannte Adresse -> erneut eingeladen.
 *
 * Zustand liegt in einer JSON-Datei (AUTH_STUB_STATE); php -S bearbeitet Anfragen
 * nacheinander, daher genuegt eine einfache Datei.
 *
 * Steuerung (nur fuer Tests):
 *   POST /__stub/script  {"invite"|"delete"|"disable-login"|"enable-login"|"mfa-requirement":[{"status":500,"body":{...},"delay":1.5,"raw":"kein JSON"}]}  haengt Antworten an; je Aufruf wird eine verbraucht
 *   GET  /__stub/calls   aufgezeichnete Aufrufe
 */

$statePath = getenv('AUTH_STUB_STATE') ?: sys_get_temp_dir() . '/auth-stub-state.json';
$secret = getenv('AUTH_STUB_SECRET') ?: '';
$signatureHeader = getenv('AUTH_STUB_SIGNATURE_HEADER') ?: 'X-Webhook-Signature';
$maxAge = 300;

$load = static function () use ($statePath): array {
    $raw = is_file($statePath) ? file_get_contents($statePath) : false;
    $state = $raw === false ? [] : (json_decode($raw, true) ?: []);
    return $state + ['calls' => [], 'script' => ['invite' => [], 'delete' => [], 'disable-login' => [], 'enable-login' => [], 'mfa-requirement' => []], 'invited' => [], 'nonces' => []];
};
$save = static function (array $state) use ($statePath): void {
    file_put_contents($statePath, json_encode($state));
};
$reply = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
};
$header = static function (string $name): string {
    $v = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';
    return is_string($v) ? $v : '';
};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = (string) file_get_contents('php://input');
$state = $load();

if ($path === '/__stub/script' && $method === 'POST') {
    foreach ((json_decode($raw, true) ?: []) as $kind => $responses) {
        $state['script'][$kind] = array_merge($state['script'][$kind] ?? [], $responses);
    }
    $save($state);
    $reply(200, ['ok' => true]);
}
if ($path === '/__stub/calls') {
    $reply(200, ['calls' => $state['calls']]);
}

$routes = ['/internal/users/invite' => 'invite', '/internal/users/delete' => 'delete', '/internal/users/disable-login' => 'disable-login', '/internal/users/enable-login' => 'enable-login', '/internal/users/mfa-requirement' => 'mfa-requirement'];
if ($method !== 'POST' || !isset($routes[$path])) {
    $reply(404, ['error' => 'Not found']);
}
$kind = $routes[$path];

// Signaturpruefung wie im echten Dienst
$timestamp = $header('X-Internal-Timestamp');
$nonce = $header('X-Internal-Nonce');
$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $raw, $secret);
$signatureOk = $secret !== '' && $timestamp !== '' && $nonce !== ''
    && hash_equals($expected, $header($signatureHeader))
    && abs(time() - (int) $timestamp) <= $maxAge
    && !isset($state['nonces'][$nonce]);

$payload = json_decode($raw, true);
$state['calls'][] = [
    'path' => $path,
    'body' => is_array($payload) ? $payload : null,
    'signature_valid' => $signatureOk,
    'content_type' => $header('Content-Type'),
];
if ($signatureOk) {
    $state['nonces'][$nonce] = true;
}

if (!$signatureOk) {
    $save($state);
    $reply(401, ['error' => 'Invalid signature']);
}

$scripted = array_shift($state['script'][$kind]);
if ($scripted !== null) {
    $save($state);
    if (isset($scripted['delay'])) {
        usleep((int) ($scripted['delay'] * 1_000_000));
    }
    if (isset($scripted['raw'])) { // Antwort, die kein JSON ist
        http_response_code((int) ($scripted['status'] ?? 200));
        echo $scripted['raw'];
        exit;
    }
    $reply((int) ($scripted['status'] ?? 200), $scripted['body'] ?? ['ok' => true]);
}

// Standardverhalten
$uuid = static function (string $seed): string {
    $h = md5($seed);
    return sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
};

if ($kind === 'invite') {
    if (($payload['event'] ?? null) !== 'user.invite') {
        $save($state);
        $reply(400, ['error' => 'Webhook processing failed.']);
    }
    $key = strtolower((string) ($payload['email'] ?? $payload['username'] ?? ''));
    $created = !isset($state['invited'][$key]);
    $state['invited'][$key] = true;
    $save($state);
    $reply(200, [
        'ok' => true, 'public_user_id' => $uuid($key), 'created' => $created, 'already_active' => false,
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 72 * 3600), // wie der echte Dienst (ISO 8601, UTC)
    ]);
}

if ($kind === 'mfa-requirement') { // wie der echte Dienst (G2): idempotent 200 mit der Zahl der Konten
    $save($state);
    $reply(200, ['ok' => true, 'affected' => count((array) ($payload['public_user_ids'] ?? []))]);
}

$publicId = (string) ($payload['public_user_id'] ?? '');
if ($kind === 'disable-login' || $kind === 'enable-login') { // wie der echte Dienst: idempotent 200 (Ereignis und Grund prueft erst ein Test an den Aufrufen)
    $save($state);
    $reply(200, ['ok' => true, 'public_user_id' => $publicId]);
}
$deleted = false;
foreach (array_keys($state['invited']) as $key) {
    if ($uuid((string) $key) === $publicId) {
        unset($state['invited'][$key]);
        $deleted = true;
    }
}
$save($state);
$reply(200, ['ok' => true, 'public_user_id' => $publicId, 'deleted' => $deleted]);
