<?php

declare(strict_types=1);

/**
 * Regression tests for the development HTTP harness (tools/php-wasm/serve.mjs).
 *
 * The harness must expose the application only: repository metadata,
 * documentation and local configuration have to answer 404, exactly like the
 * shipped Apache and nginx rules do in production.
 *
 * Requires a running server:
 *   node tools/php-wasm/serve.mjs --port 8090 --host 0.0.0.0
 *
 * Usage:
 *   DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090 \
 *   node tools/php-wasm/php.mjs tests/http/dev_harness_test.php
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require __DIR__ . '/http_client.php';

$failures = 0;
$passes = 0;

/** @param array{status:int,headers:array<string,string>,body:string} $response */
function assertStatus(string $path, array $response, int $expected): void
{
    global $failures, $passes;
    $actual = $response['status'];
    if ($actual === $expected) {
        $passes++;
        echo "PASS  {$path}  {$expected}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$path}  expected {$expected}, got {$actual}\n";
}

$forbidden = [
    '/README.md',
    '/SECURITY.md',
    '/docs/architecture.md',
    '/docs/audit-report.md',
    '/.htaccess',
    '/.env',
    '/.git/config',
    '/config/config.local.php',
];

$public = [
    '/',
    '/index.php',
];

echo 'Harness: ' . httpBaseUrl() . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

foreach ($forbidden as $path) {
    try {
        assertStatus($path, http('GET', $path), 404);
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  {$path}  transport error: " . $e->getMessage() . PHP_EOL;
    }
}

foreach ($public as $path) {
    try {
        assertStatus($path, http('GET', $path), 200);
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  {$path}  transport error: " . $e->getMessage() . PHP_EOL;
    }
}

// The allowlist must not break real assets or the API.
foreach (['/assets/css/app.css', '/assets/js/app.js', '/api/health.php'] as $path) {
    try {
        assertStatus($path, http('GET', $path), 200);
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  {$path}  transport error: " . $e->getMessage() . PHP_EOL;
    }
}

echo str_repeat('-', 60) . PHP_EOL;
echo 'HARNESS TOTAL ' . ($passes + $failures) . " | PASS {$passes} | FAIL {$failures}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
