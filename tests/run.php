<?php

declare(strict_types=1);

/**
 * DoorLock automated test runner.
 *
 * Runs unit tests and database integration tests. HTTP-level tests live in
 * tests/http.php and are executed by tests/run-all.sh.
 *
 * Usage: php tests/run.php [--filter=TEXT] [--tag=engine] [--list] [--json=PATH]
 */

$root = dirname(__DIR__);
$dbPath = getenv('DOORLOCK_TEST_DB_PATH');
if (!is_string($dbPath) || $dbPath === '') {
    $dbPath = sys_get_temp_dir() . '/doorlock-tests-' . getmypid() . '.sqlite';
}

$env = [
    'DOORLOCK_TEST_DB_PATH' => $dbPath,
    'DOORLOCK_CONFIG_FILE' => $root . '/tests/nonexistent-config.php',
    'DOORLOCK_ENV' => 'testing',
    'DOORLOCK_APP_KEY' => str_repeat('0123456789abcdef', 4),
    'DOORLOCK_DB_DRIVER' => 'sqlite',
    'DOORLOCK_DB_SQLITE_PATH' => $dbPath,
    'DOORLOCK_LOG_DIR' => sys_get_temp_dir() . '/doorlock-test-logs',
    'DOORLOCK_TIMEZONE' => 'Asia/Tehran',
    'DOORLOCK_TRUST_PROXY' => '0',
    'DOORLOCK_REQUIRE_HTTPS_FOR_DEVICES' => '0',
    'DOORLOCK_ALLOW_INSECURE_DEVICE_HTTP' => '1',
    'DOORLOCK_LOGIN_MAX_ATTEMPTS' => '5',
];
foreach ($env as $key => $value) {
    if (getenv($key) === false) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

require __DIR__ . '/bootstrap.php';

use DoorLock\Cli;
use DoorLock\Tests\TestRunner;
use function DoorLock\Tests\fresh_database;

$runner = new TestRunner();
$GLOBALS['__doorlock_test_runner'] = $runner;

fresh_database();

$files = array_merge(
    glob(__DIR__ . '/unit/*_test.php') ?: [],
    glob(__DIR__ . '/integration/*_test.php') ?: []
);
sort($files, SORT_STRING);
foreach ($files as $file) {
    require_once $file;
}

$options = Cli::parse()['options'];
if (isset($options['list'])) {
    foreach ($runner->list() as $test) {
        echo $test['id'] . "\t" . $test['description'] . "\t" . implode(',', $test['tags']) . "\n";
    }
    exit(0);
}

$filter = isset($options['filter']) && $options['filter'] !== true ? (string) $options['filter'] : null;
$tag = isset($options['tag']) && $options['tag'] !== true ? (string) $options['tag'] : null;

echo "DoorLock test suite\n";
echo 'PHP ' . PHP_VERSION . " | DB driver: " . \DoorLock\Db::driver() . " | DB: " . $dbPath . "\n";
echo str_repeat('-', 100) . "\n";

$start = microtime(true);
$results = $runner->run($filter, $tag);
$elapsed = microtime(true) - $start;

$counts = ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0];
foreach ($results as $result) {
    $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
}

echo str_repeat('-', 100) . "\n";
printf(
    "TOTAL %d | PASS %d | FAIL %d | BLOCKED %d | %.2fs\n",
    count($results),
    $counts['PASS'],
    $counts['FAIL'],
    $counts['BLOCKED'],
    $elapsed
);

if (isset($options['json']) && $options['json'] !== true) {
    $path = (string) $options['json'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    file_put_contents($path, json_encode([
        'generated_at' => gmdate('c'),
        'php' => PHP_VERSION,
        'driver' => \DoorLock\Db::driver(),
        'summary' => $counts + ['total' => count($results)],
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "JSON report written to {$path}\n";
}

exit($counts['FAIL'] > 0 ? 1 : 0);
