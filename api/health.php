<?php

declare(strict_types=1);

/**
 * Liveness/readiness probe.
 *
 * Deliberately unauthenticated but reveals nothing beyond whether the
 * application can reach its database.
 */

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Response;
use DoorLock\Util;

$request = Request::fromGlobals();
$requestId = Util::randomHex(16);
$start = microtime(true);

try {
    if ($request->method() !== 'GET') {
        Response::json(
            ['status' => 'error', 'error' => 'method_not_allowed', 'request_id' => $requestId],
            405,
            ['Allow' => 'GET', 'X-Request-Id' => $requestId]
        );
        exit;
    }
    Db::scalar('SELECT 1');
    Response::json([
        'status' => 'ok',
        'database' => 'ok',
        'time' => Util::iso(Util::toDb(Util::utcNow())),
        'latency_ms' => (int) round((microtime(true) - $start) * 1000),
        'request_id' => $requestId,
    ], 200, ['X-Request-Id' => $requestId]);
} catch (\Throwable $e) {
    Response::json([
        'status' => 'unavailable',
        'database' => 'unavailable',
        'time' => Util::iso(Util::toDb(Util::utcNow())),
        'request_id' => $requestId,
    ], 503, ['X-Request-Id' => $requestId, 'Retry-After' => '10']);
}
