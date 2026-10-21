<?php

declare(strict_types=1);

/**
 * HTTP contract tests for the administrative and device APIs.
 *
 * These tests exercise the application through a real web server (PHP-FPM,
 * Apache or the development PHP.wasm server), including cookies, CSRF,
 * Origin checks and HMAC device authentication.
 *
 * Usage:
 *   DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090 \
 *   DOORLOCK_TEST_ADMIN=admin \
 *   DOORLOCK_TEST_ADMIN_PASSWORD='…' \
 *   node tools/php-wasm/php.mjs tests/http/api_test.php
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require __DIR__ . '/http_client.php';

use DoorLock\DeviceAuth;
use DoorLock\Util;

$admin = (string) (getenv('DOORLOCK_TEST_ADMIN') ?: 'admin');
$adminPassword = (string) (getenv('DOORLOCK_TEST_ADMIN_PASSWORD') ?: '');
$failures = 0;
$passes = 0;
$skipped = 0;

if ($adminPassword === '') {
    fwrite(STDERR, "DOORLOCK_TEST_ADMIN_PASSWORD must be set.\n");
    exit(64);
}

function check(string $id, string $description, callable $fn): void
{
    global $failures, $passes;
    try {
        $fn();
        $passes++;
        echo "PASS  {$id}  {$description}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  {$id}  {$description} — " . $e->getMessage() . "\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function jsonBody(array $response): array
{
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Response is not JSON: ' . substr($response['body'], 0, 200));
    }
    return $decoded;
}

$jar = tempnam(sys_get_temp_dir(), 'doorlock-jar-');
$viewerJar = tempnam(sys_get_temp_dir(), 'doorlock-viewer-');
$csrf = '';
$viewerCsrf = '';
$unique = bin2hex(random_bytes(4));
$doorId = null;
$personId = null;
$card = null;
$deviceUid = null;
$deviceKey = null;

check('HTTP-001', 'Health endpoint reports a healthy database', function (): void {
    $response = http('GET', '/api/health.php');
    assertSame(200, $response['status']);
    assertSame('ok', jsonBody($response)['status']);
});

check('HTTP-002', 'Anonymous session bootstrap returns a CSRF token', function () use (&$csrf, $jar): void {
    $response = http('GET', '/api/auth.php', null, ['Accept' => 'application/json'], $jar);
    assertSame(200, $response['status']);
    $data = jsonBody($response)['data'];
    assertSame(false, $data['authenticated']);
    assertTrue(strlen((string) $data['csrf_token']) >= 64, 'CSRF token too short');
    $csrf = (string) $data['csrf_token'];
});

check('HTTP-003', 'State-changing request without a CSRF token is rejected', function (): void {
    $response = http('POST', '/api/auth.php', json_encode(['action' => 'login', 'username' => 'admin', 'password' => 'x']), ['Content-Type' => 'application/json']);
    assertSame(403, $response['status']);
    assertSame('csrf_failed', jsonBody($response)['error']['code']);
});

check('HTTP-004', 'Login with the correct password succeeds and rotates the token', function () use (&$csrf, &$jar, &$admin, &$adminPassword): void {
    $response = http('POST', '/api/auth.php', json_encode([
        'action' => 'login',
        'username' => $admin,
        'password' => $adminPassword,
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(200, $response['status']);
    $data = jsonBody($response)['data'];
    assertTrue(isset($data['user']['username']), 'No user returned');
    assertTrue($data['csrf_token'] !== $csrf, 'CSRF token was not rotated');
    $csrf = (string) $data['csrf_token'];
});

check('HTTP-005', 'Authenticated paginated person listing works', function () use (&$jar): void {
    $response = http('GET', '/api/persons.php?per_page=5', null, [], $jar);
    assertSame(200, $response['status']);
    $payload = jsonBody($response);
    assertSame(true, $payload['success']);
    assertTrue(isset($payload['meta']['total']), 'Missing pagination metadata');
});

check('HTTP-006', 'A person and card can be created and then archived', function () use (&$jar, &$csrf, &$personId, &$card, &$unique): void {
    $response = http('POST', '/api/persons.php', json_encode([
        'name' => 'HTTP Test ' . $unique,
        'notes' => 'created by automated HTTP test',
        'card_number' => 'AB' . $unique . 'CD',
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $response['status'], $response['body']);
    $person = jsonBody($response)['data']['person'];
    $personId = (int) $person['id'];
    $card = (string) $person['cards'][0]['card_number'];
    assertTrue($personId > 0, 'Invalid person id');

    $extra = http('POST', '/api/persons.php', json_encode(['name' => 'HTTP Archive ' . $unique]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $extra['status']);
    $extraId = (int) jsonBody($extra)['data']['person']['id'];
    $delete = http('DELETE', '/api/persons.php', json_encode(['id' => $extraId]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(200, $delete['status']);
    assertSame(true, jsonBody($delete)['data']['archived']);
});

check('HTTP-007', 'A viewer can read but cannot write or list users', function () use (&$jar, &$csrf, &$unique): void {
    global $admin;
    $username = 'viewer' . $unique;
    $password = 'Viewer-Test-Password-9!';
    $create = http('POST', '/api/users.php', json_encode([
        'username' => $username,
        'password' => $password,
        'role' => 'viewer',
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $create['status'], $create['body']);

    global $viewerJar, $viewerCsrf;
    $bootstrap = http('GET', '/api/auth.php', null, [], $viewerJar);
    $viewerCsrf = (string) jsonBody($bootstrap)['data']['csrf_token'];
    $login = http('POST', '/api/auth.php', json_encode([
        'action' => 'login',
        'username' => $username,
        'password' => $password,
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $viewerCsrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $viewerJar);
    assertSame(200, $login['status']);
    $viewerCsrf = (string) jsonBody($login)['data']['csrf_token'];

    assertSame(200, http('GET', '/api/persons.php', null, [], $viewerJar)['status']);
    $denied = http('POST', '/api/persons.php', json_encode(['name' => 'Nope']), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $viewerCsrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $viewerJar);
    assertSame(403, $denied['status']);
    assertSame('forbidden', jsonBody($denied)['error']['code']);
    assertSame(403, http('GET', '/api/users.php', null, [], $viewerJar)['status']);
});

check('HTTP-008', 'Cross-origin writes are rejected', function () use (&$jar, &$csrf): void {
    $response = http('POST', '/api/persons.php', json_encode(['name' => 'Evil']), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => 'https://evil.example',
    ], $jar);
    assertSame(403, $response['status']);
    assertSame('origin_rejected', jsonBody($response)['error']['code']);
});

check('HTTP-009', 'A group can be created with members and doors', function () use (&$jar, &$csrf, &$unique, &$doorId): void {
    $doors = jsonBody(http('GET', '/api/doors.php', null, [], $jar))['data']['doors'];
    $doorId = (int) $doors[0]['id'];
    $response = http('POST', '/api/groups.php', json_encode([
        'name' => 'HTTP Group ' . $unique,
        'description' => 'automated',
        'member_ids' => [],
        'door_ids' => [$doorId],
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $response['status'], $response['body']);
    $group = jsonBody($response)['data']['group'];
    assertSame(1, count($group['doors']));
});

check('HTTP-010', 'Invalid weekday input is rejected with a field error', function () use (&$jar, &$csrf, &$doorId, &$personId): void {
    $response = http('POST', '/api/schedules.php', json_encode([
        'person_id' => $personId,
        'door_id' => $doorId,
        'days' => '9',
        'start_time' => '08:00',
        'end_time' => '09:00',
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(400, $response['status']);
    assertSame('validation_failed', jsonBody($response)['error']['code']);
});

check('HTTP-011', 'An explicit permission upsert returns 201 then 200', function () use (&$jar, &$csrf, &$personId, &$doorId): void {
    $body = json_encode(['person_id' => $personId, 'door_id' => $doorId, 'effect' => 'allow']);
    $first = http('POST', '/api/permissions.php', $body, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $first['status']);
    $second = http('POST', '/api/permissions.php', $body, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(200, $second['status']);
});

check('HTTP-012', 'A device can be registered and provisioned', function () use (&$jar, &$csrf, &$doorId, &$deviceUid, &$deviceKey): void {
    $deviceUid = bin2hex(random_bytes(16));
    $create = http('POST', '/api/devices.php', json_encode([
        'name' => 'HTTP Device',
        'unique_id' => $deviceUid,
        'door_id' => $doorId,
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $create['status'], $create['body']);
    $device = jsonBody($create)['data'];
    $deviceKey = (string) $device['device_key'];
    assertSame(64, strlen($deviceKey));

    $provision = http('POST', '/api/devices.php', json_encode(['action' => 'provision', 'id' => $device['device']['id']]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(200, $provision['status']);
    assertSame($deviceKey, jsonBody($provision)['data']['provisioning']['device_key']);
});

check('HTTP-013', 'A single-use temporary grant is consumed exactly once', function () use (&$jar, &$csrf, &$personId, &$doorId, &$card, &$deviceUid, &$deviceKey): void {
    $remove = http('DELETE', '/api/permissions.php', json_encode(['person_id' => $personId, 'door_id' => $doorId]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(200, $remove['status']);
    $now = new DateTimeImmutable('now', Util::appTimezone());
    $grant = http('POST', '/api/grants.php', json_encode([
        'person_id' => $personId,
        'door_id' => $doorId,
        'valid_from' => $now->modify('-1 hour')->format('Y-m-d\TH:i'),
        'valid_to' => $now->modify('+1 day')->format('Y-m-d\TH:i'),
        'max_uses' => 1,
        'note' => 'http test',
    ]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(201, $grant['status'], $grant['body']);

    $body = json_encode(['door_id' => $doorId, 'card_number' => $card]);
    $first = signedRequest($deviceUid, $deviceKey, $body);
    assertSame(200, $first['status']);
    assertSame(true, jsonBody($first)['access']);

    $second = signedRequest($deviceUid, $deviceKey, $body);
    assertSame(200, $second['status']);
    $payload = jsonBody($second);
    assertSame(false, $payload['access']);
    assertSame('grant_exhausted', $payload['reason']);
});

check('HTTP-014', 'Replaying a nonce is rejected with 401', function () use (&$deviceUid, &$deviceKey, &$doorId, &$card): void {
    $body = json_encode(['door_id' => $doorId, 'card_number' => $card]);
    $nonce = bin2hex(random_bytes(16));
    $timestamp = (string) time();
    $requestId = 'replay-' . bin2hex(random_bytes(4));
    $signature = DeviceAuth::signature($deviceKey, $deviceUid, (int) $timestamp, $nonce, $requestId, $body);
    $headers = [
        'Content-Type' => 'application/json',
        'X-Device-Id' => $deviceUid,
        'X-Timestamp' => $timestamp,
        'X-Nonce' => $nonce,
        'X-Signature' => $signature,
        'X-Request-Id' => $requestId,
    ];
    $first = http('POST', '/api/check-access.php', $body, $headers);
    assertTrue(in_array($first['status'], [200, 401], true), 'Unexpected status ' . $first['status']);
    if ($first['status'] === 200) {
        $second = http('POST', '/api/check-access.php', $body, $headers);
        assertSame(401, $second['status']);
        assertSame('replayed_request', jsonBody($second)['reason']);
    }
});

check('HTTP-015', 'A bad signature is rejected without an access decision', function () use (&$doorId, &$card): void {
    $body = json_encode(['door_id' => $doorId, 'card_number' => $card]);
    $response = http('POST', '/api/check-access.php', $body, [
        'Content-Type' => 'application/json',
        'X-Device-Id' => str_repeat('a', 32),
        'X-Timestamp' => (string) time(),
        'X-Nonce' => bin2hex(random_bytes(16)),
        'X-Signature' => str_repeat('0', 64),
        'X-Request-Id' => 'badsig-' . bin2hex(random_bytes(4)),
    ]);
    assertSame(401, $response['status']);
    assertSame(false, jsonBody($response)['access']);
});

check('HTTP-016', 'Access logs and CSV export are available to administrators', function () use (&$jar): void {
    $logs = http('GET', '/api/logs.php?per_page=5', null, [], $jar);
    assertSame(200, $logs['status']);
    $csv = http('GET', '/api/logs.php?format=csv', null, [], $jar);
    assertSame(200, $csv['status']);
    assertTrue(str_contains((string) $csv['headers']['content-type'], 'text/csv'), 'CSV content type missing');
    assertTrue(str_contains($csv['body'], 'occurred_at_utc'), 'CSV header row missing');
});

check('HTTP-017', 'Audit trail records privileged actions', function () use (&$jar): void {
    $audit = http('GET', '/api/audit.php?per_page=20', null, [], $jar);
    assertSame(200, $audit['status']);
    $entries = jsonBody($audit)['data']['entries'];
    assertTrue(count($entries) > 0, 'Audit log is empty');
});

check('HTTP-018', 'Administrators cannot deactivate their own account', function () use (&$jar, &$csrf): void {
    $me = jsonBody(http('GET', '/api/auth.php', null, [], $jar))['data']['user'];
    $response = http('PUT', '/api/users.php', json_encode(['id' => $me['id'], 'is_active' => false]), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(409, $response['status']);
    assertSame('self_modification_forbidden', jsonBody($response)['error']['code']);
});

check('HTTP-019', 'Login is required for the administrative API', function (): void {
    $response = http('GET', '/api/persons.php');
    assertSame(401, $response['status']);
    assertSame('unauthenticated', jsonBody($response)['error']['code']);
});

check('HTTP-020', 'Logout invalidates the session', function () use (&$jar, &$csrf): void {
    $response = http('POST', '/api/auth.php', json_encode(['action' => 'logout']), [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrf,
        'Origin' => (string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8090'),
    ], $jar);
    assertSame(200, $response['status']);
    assertSame(401, http('GET', '/api/persons.php', null, [], $jar)['status']);
});

@unlink($jar);
@unlink($viewerJar);

echo str_repeat('-', 80) . "\n";
echo "HTTP TOTAL " . ($passes + $failures) . " | PASS {$passes} | FAIL {$failures}\n";
exit($failures === 0 ? 0 : 1);

/** @return array{status:int,headers:array<string,string>,body:string} */
function signedRequest(string $uid, string $key, string $body): array
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $requestId = 'http-' . bin2hex(random_bytes(4));
    $signature = DeviceAuth::signature($key, $uid, (int) $timestamp, $nonce, $requestId, $body);
    return http('POST', '/api/check-access.php', $body, [
        'Content-Type' => 'application/json',
        'X-Device-Id' => $uid,
        'X-Timestamp' => $timestamp,
        'X-Nonce' => $nonce,
        'X-Signature' => $signature,
        'X-Request-Id' => $requestId,
    ]);
}
