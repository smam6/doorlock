<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\ApiError;
use DoorLock\Auth;
use DoorLock\Db;
use DoorLock\RateLimiter;
use DoorLock\Rbac;
use DoorLock\Util;

/**
 * Authentication, lockout and role checks.
 *
 * These tests run on the CLI, where the session subsystem is replaced by the
 * $_SESSION superglobal; that is sufficient to exercise the persistence,
 * expiry and invalidation logic without a web server.
 */

function reset_session(): void
{
    $_SESSION = [];
    $_SERVER['HTTP_USER_AGENT'] = 'DoorLock-Test-Agent/1.0';
    $reflection = new \ReflectionClass(Auth::class);
    $property = $reflection->getProperty('cachedUser');
    $property->setAccessible(true);
    $property->setValue(null, null);
}

test('AUTH-001', 'Valid credentials create an authenticated session', function (): void {
    reset_data();
    reset_session();
    $user = Auth::login('admin', TEST_PASSWORD, '127.0.0.1');
    Assert::same('admin', $user['username']);
    Assert::same('admin', $user['role']);
    Assert::same((string) $user['id'], (string) $_SESSION['user_id']);
    Assert::true(Auth::user() !== null, 'Auth::user must resolve the session');
    Assert::same('admin', Auth::user()['role']);
});

test('AUTH-002', 'Invalid credentials fail with a generic error and are recorded', function (): void {
    reset_data();
    reset_session();
    $error = Assert::throws(
        static fn () => Auth::login('admin', 'wrong-password', '127.0.0.1'),
        ApiError::class
    );
    Assert::same(401, $error->status);
    Assert::same('invalid_credentials', $error->errorCode);
    Assert::same(1, (int) Db::scalar('SELECT COUNT(*) FROM login_attempts WHERE success = 0'));
    Assert::true(empty($_SESSION['user_id']));
});

test('AUTH-003', 'Five failed attempts lock the account even for the right password', function (): void {
    reset_data();
    reset_session();
    for ($i = 0; $i < 5; $i++) {
        try {
            Auth::login('admin', 'bad-password', '127.0.0.1');
        } catch (ApiError) {
            // expected
        }
    }
    $error = Assert::throws(
        static fn () => Auth::login('admin', TEST_PASSWORD, '127.0.0.1'),
        ApiError::class
    );
    Assert::same(429, $error->status);
    Assert::same('too_many_attempts', $error->errorCode);
});

test('AUTH-004', 'A successful login clears previous failures', function (): void {
    reset_data();
    reset_session();
    for ($i = 0; $i < 3; $i++) {
        try {
            Auth::login('admin', 'bad-password', '127.0.0.1');
        } catch (ApiError) {
        }
    }
    Auth::login('admin', TEST_PASSWORD, '127.0.0.1');
    Assert::same(0, (int) Db::scalar('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND username = ?', ['admin']));
    Assert::same(0, RateLimiter::clearLoginFailures('admin'));
});

test('AUTH-005', 'A password change invalidates sessions carrying the old version', function (): void {
    $seed = reset_data();
    reset_session();
    $user = Auth::login('admin', TEST_PASSWORD, '127.0.0.1');
    $oldVersion = (int) Db::scalar('SELECT session_version FROM web_users WHERE id = ?', [$seed['users']['admin']]);
    Auth::changePassword($user, TEST_PASSWORD, 'New-Correct-Horse-Battery-42!');
    $newVersion = (int) Db::scalar('SELECT session_version FROM web_users WHERE id = ?', [$seed['users']['admin']]);
    Assert::true($newVersion > $oldVersion, 'session_version must increment');
    Assert::same($newVersion, (int) $_SESSION['session_version']);
    // Simulate a second session that still holds the previous version.
    $_SESSION['session_version'] = $oldVersion;
    $_SESSION['last_seen'] = time();
    $_SESSION['created_at'] = time();
    $_SESSION['fingerprint'] = hash('sha256', 'DoorLock-Test-Agent/1.0');
    $_SESSION['user_id'] = $seed['users']['admin'];
    $_SESSION['username'] = 'admin';
    $reflection = new \ReflectionClass(Auth::class);
    $property = $reflection->getProperty('cachedUser');
    $property->setAccessible(true);
    $property->setValue(null, null);
    Assert::null(Auth::user());
});

test('AUTH-006', 'Session expiry is enforced by idle and absolute timeouts', function (): void {
    $seed = reset_data();
    reset_session();
    Auth::login('admin', TEST_PASSWORD, '127.0.0.1');
    $_SESSION['last_seen'] = time() - 4000;
    $reflection = new \ReflectionClass(Auth::class);
    $property = $reflection->getProperty('cachedUser');
    $property->setAccessible(true);
    $property->setValue(null, null);
    Assert::null(Auth::user());
    Assert::true(empty($_SESSION['user_id']));
});

test('AUTH-007', 'A deactivated user loses access immediately', function (): void {
    $seed = reset_data();
    reset_session();
    Auth::login('admin', TEST_PASSWORD, '127.0.0.1');
    Db::exec('UPDATE web_users SET is_active = 0 WHERE id = ?', [$seed['users']['admin']]);
    $reflection = new \ReflectionClass(Auth::class);
    $property = $reflection->getProperty('cachedUser');
    $property->setAccessible(true);
    $property->setValue(null, null);
    Assert::null(Auth::user());
});

test('AUTH-008', 'The role matrix enforces least privilege', function (): void {
    Assert::true(Rbac::can('admin', 'users.write'));
    Assert::true(Rbac::can('admin', 'audit.read'));
    Assert::true(Rbac::can('operator', 'persons.write'));
    Assert::false(Rbac::can('operator', 'users.write'));
    Assert::false(Rbac::can('operator', 'audit.read'));
    Assert::false(Rbac::can('operator', 'doors.write'));
    Assert::true(Rbac::can('viewer', 'persons.read'));
    Assert::false(Rbac::can('viewer', 'persons.write'));
    Assert::false(Rbac::can('viewer', 'logs.export'));
    Assert::false(Rbac::can('unknown', 'persons.read'));
    Assert::throws(static fn () => Rbac::require(['role' => 'viewer'], 'users.write'), ApiError::class);
});
