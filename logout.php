<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

use DoorLock\ApiError;
use DoorLock\Auth;
use DoorLock\Request;
use DoorLock\RequestContext;
use DoorLock\Response;
use DoorLock\Security;
use DoorLock\Util;

$request = Request::fromGlobals();
try {
    $requestId = $request->requestId();
} catch (ApiError $e) {
    $requestId = Util::randomHex(16);
}
RequestContext::begin($requestId, $request->ip());
$wantsJson = str_contains(strtolower((string) $request->header('accept')), 'application/json');

if ($request->method() !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    if ($wantsJson) {
        Response::json(['success' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Use POST.'], 'request_id' => $requestId], 405);
    } else {
        header('Location: index.php');
    }
    exit;
}

try {
    Security::requireSameOrigin($request);
    Security::requireCsrf($request);
    Auth::logout();
} catch (ApiError $e) {
    if ($wantsJson) {
        http_response_code($e->status);
        Response::json([
            'success' => false,
            'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
            'request_id' => $requestId,
        ], $e->status, ['X-Request-Id' => $requestId]);
        exit;
    }
    header('Location: index.php#login');
    exit;
}

if ($wantsJson) {
    Response::json(['success' => true, 'data' => ['logged_out' => true], 'request_id' => $requestId], 200, ['X-Request-Id' => $requestId]);
    exit;
}
header('Location: index.php#login');
