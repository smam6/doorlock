<?php

declare(strict_types=1);

/**
 * Device-facing access decision endpoint.
 *
 * Contract (also implemented by firmware/doorlock-esp8266):
 *
 *   POST /api/check-access.php
 *   Headers: X-Device-Id, X-Timestamp, X-Nonce, X-Signature, X-Request-Id
 *   Body:    {"door_id": 1, "card_number": "04AABBCC"}
 *
 * A policy denial is HTTP 200 with "access": false. Authentication problems
 * are HTTP 401; missing/invalid payloads are HTTP 400; database failures are
 * HTTP 503. Every path fails closed.
 */

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\AccessService;
use DoorLock\ApiError;
use DoorLock\DeviceApi;
use DoorLock\DeviceAuth;
use DoorLock\Request;
use DoorLock\Util;

DeviceApi::run(function (Request $request, array &$context): array {
    if ($request->method() !== 'POST') {
        throw ApiError::methodNotAllowed();
    }
    $contentType = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
    if ($contentType !== 'application/json') {
        throw new ApiError('unsupported_media_type', 'Content-Type must be application/json.', 415);
    }

    $body = $request->body();

    // Best-effort, untrusted context for failure logging only. It never
    // influences the decision.
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        if (isset($decoded['door_id']) && is_int($decoded['door_id'])) {
            $context['door_id'] = $decoded['door_id'];
        }
        if (isset($decoded['card_number']) && is_string($decoded['card_number'])) {
            $context['card_number'] = Util::normalizeCardNumber($decoded['card_number']);
        }
    }

    $requestId = $request->requestId();
    $device = DeviceAuth::authenticate($request, $body);
    $context['device'] = $device;

    return AccessService::authorize($device, $body, $requestId, $request->ip());
});
