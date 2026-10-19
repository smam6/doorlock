<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\Auth;
use DoorLock\Rbac;
use DoorLock\Request;
use DoorLock\Security;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    if ($request->method() === 'GET') {
        $user = Auth::user();
        return [
            'data' => [
                'authenticated' => $user !== null,
                'user' => $user !== null ? Auth::publicUser($user) : null,
                'csrf_token' => Auth::csrfToken(),
                'permissions' => $user !== null ? Rbac::permissionsFor((string) $user['role']) : [],
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'site_timezone' => (string) \DoorLock\Config::get('app_timezone', 'UTC'),
            ],
        ];
    }

    if ($request->method() !== 'POST') {
        throw ApiError::methodNotAllowed();
    }

    Security::requireSameOrigin($request);
    Security::requireCsrf($request);
    $input = $request->json();
    $action = Validator::enum($input['action'] ?? 'login', 'action', ['login', 'logout', 'change_password']);

    if ($action === 'login') {
        $username = Validator::username($input['username'] ?? null);
        $password = Validator::string($input['password'] ?? null, 'password', 1, 200, false);
        $user = Auth::login($username, $password, $request->ip());
        return [
            'data' => [
                'user' => $user,
                'csrf_token' => Auth::csrfToken(),
            ],
        ];
    }

    if ($action === 'logout') {
        Auth::logout();
        return ['data' => ['logged_out' => true]];
    }

    $user = Auth::requireUser();
    $current = Validator::string($input['current_password'] ?? null, 'current_password', 1, 200, false);
    $new = Validator::password($input['new_password'] ?? null, (string) $user['username']);
    Auth::changePassword($user, $current, $new);
    return [
        'data' => [
            'changed' => true,
            'csrf_token' => Security::csrfToken(),
        ],
    ];
});
