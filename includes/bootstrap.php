<?php

declare(strict_types=1);

/**
 * DoorLock bootstrap.
 *
 * This file is the single entry point for configuration, autoloading (explicit
 * requires – no Composer dependency), logging and error handling.
 */

if (!defined('DOORLOCK_ROOT')) {
    define('DOORLOCK_ROOT', dirname(__DIR__));
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DOORLOCK_ROOT . '/storage/logs/php-error.log');
date_default_timezone_set('UTC');

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sql.php';
require_once __DIR__ . '/migrator.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/access_engine.php';
require_once __DIR__ . '/access_service.php';
require_once __DIR__ . '/device_auth.php';
require_once __DIR__ . '/device_api.php';
require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/cli.php';

// `expose_php` is PHP_INI_PERDIR on most SAPIs, so also strip the header.
if (!\DoorLock\Util::isCli()) {
    @ini_set('expose_php', '0');
    header_remove('X-Powered-By');
}

use DoorLock\Config;
use DoorLock\ConfigError;
use DoorLock\Logger;

$configFile = getenv('DOORLOCK_CONFIG_FILE');
if ($configFile === false || $configFile === '') {
    $configFile = DOORLOCK_ROOT . '/config/config.local.php';
}

try {
    Config::load($configFile);
    $logDir = (string) Config::get('log_dir', 'storage/logs');
    if (!str_starts_with($logDir, '/')) {
        $logDir = DOORLOCK_ROOT . '/' . $logDir;
    }
    Logger::init($logDir, (string) Config::get('log_level', 'info'));
} catch (ConfigError $e) {
    // Never expose configuration details to a client.
    error_log('[doorlock] configuration error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (defined('DOORLOCK_API_REQUEST')) {
        echo '{"success":false,"error":{"code":"configuration_error","message":"The service is not configured correctly."}}';
    } else {
        echo 'DoorLock is not configured. Run bin/install.php or provide DOORLOCK_* environment variables.';
    }
    exit(1);
}

// Convert warnings/notices into exceptions so they cannot silently corrupt a
// response or be mistaken for success. Respect the @-suppression operator.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        error_log("[doorlock] deprecation: {$message} in {$file}:{$line}");
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    Logger::error('php_fatal_error', [
        'message' => $error['message'],
        'file' => $error['file'],
        'line' => $error['line'],
    ]);
    if (defined('DOORLOCK_API_REQUEST') && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code(500);
        echo '{"success":false,"access":false,"error":{"code":"internal_error","message":"An internal error occurred."}}';
    }
});

foreach (['storage/logs', 'storage/tmp'] as $relative) {
    $dir = DOORLOCK_ROOT . '/' . $relative;
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
}
