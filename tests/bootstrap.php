<?php

declare(strict_types=1);

/**
 * Test bootstrap. Must be included after tests/run.php has configured the
 * environment (the application bootstrap loads configuration immediately).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/helpers.php';

use DoorLock\Config;
use DoorLock\Db;

if (!defined('DOORLOCK_ROOT')) {
    define('DOORLOCK_ROOT', dirname(__DIR__));
}

// Sensible defaults for tests that do not need HTTP transport.
Config::override([
    'security' => [
        'allow_insecure_device_http' => true,
        'require_https_for_devices' => false,
        'require_https_for_admin' => false,
    ],
]);

Db::disconnect();
Db::init();
