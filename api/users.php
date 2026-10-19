<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Audit;
use DoorLock\ConstraintViolation;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    $actor = Api::guard($request, 'users.read', 'users.write');
    $actorId = (int) $actor['id'];

    if ($request->method() === 'GET') {
        $rows = Db::all('SELECT * FROM web_users ORDER BY username, id');
        return ['data' => ['users' => array_map(static fn (array $row) => ApiHelpers::webUser($row), $rows)]];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $username = Validator::username($input['username'] ?? null);
        $password = Validator::password($input['password'] ?? null, $username);
        $role = Validator::role($input['role'] ?? 'viewer');
        $now = Util::toDb(Util::utcNow());

        try {
            $userId = Db::tx(function () use ($username, $password, $role, $now): int {
                $userId = Db::insert(
                    'INSERT INTO web_users (username, password_hash, role, is_active, password_changed_at, created_at, updated_at)
                     VALUES (?, ?, ?, 1, ?, ?, ?)',
                    [$username, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $role, $now, $now, $now]
                );
                Audit::record('users.create', 'web_user', (string) $userId, null, [
                    'username' => $username,
                    'role' => $role,
                ]);
                return $userId;
            });
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                throw new ApiError('conflict', 'That username is already taken.', 409, ['username' => 'Already in use.']);
            }
            throw $e;
        }

        $created = Db::one('SELECT * FROM web_users WHERE id = ?', [$userId]);
        return ['status' => 201, 'data' => ['user' => ApiHelpers::webUser($created ?? [])]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $target = Db::one('SELECT * FROM web_users WHERE id = ?', [$id]);
        if ($target === null) {
            throw ApiError::notFound('User not found.');
        }
        $newRole = array_key_exists('role', $input) ? Validator::role($input['role']) : (string) $target['role'];
        $newActive = array_key_exists('is_active', $input)
            ? Validator::boolValue($input['is_active'], 'is_active')
            : (int) $target['is_active'] === 1;

        if ($id === $actorId && ($newRole !== (string) $target['role'] || $newActive !== ((int) $target['is_active'] === 1))) {
            throw new ApiError('self_modification_forbidden', 'You cannot change your own role or active state.', 409);
        }
        users_assert_admin_remains($id, $newRole, $newActive);

        $changes = [];
        $after = [];
        if ($newRole !== (string) $target['role']) {
            $changes['role'] = $newRole;
            $after['role'] = $newRole;
        }
        if ($newActive !== ((int) $target['is_active'] === 1)) {
            $changes['is_active'] = $newActive ? 1 : 0;
            $after['is_active'] = $newActive;
        }

        $password = null;
        if (array_key_exists('password', $input) && $input['password'] !== null && $input['password'] !== '') {
            $password = Validator::password($input['password'], (string) $target['username']);
        }
        if ($changes === [] && $password === null) {
            throw ApiError::validation('id', 'No supported fields were supplied.');
        }

        $now = Util::toDb(Util::utcNow());
        Db::tx(function () use ($id, $changes, $password, $now, $target, $after): void {
            $set = [];
            $params = [];
            foreach ($changes as $column => $value) {
                $set[] = $column . ' = ?';
                $params[] = $value;
            }
            if ($password !== null) {
                $set[] = 'password_hash = ?';
                $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $set[] = 'password_changed_at = ?';
                $params[] = $now;
                $set[] = 'session_version = session_version + 1';
                $after['password_rotated'] = true;
            }
            $set[] = 'updated_at = ?';
            $params[] = $now;
            $params[] = $id;
            Db::exec('UPDATE web_users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            Audit::record('users.update', 'web_user', (string) $id, [
                'username' => (string) $target['username'],
                'role' => (string) $target['role'],
                'is_active' => (int) $target['is_active'] === 1,
            ], $after);
        });

        $updated = Db::one('SELECT * FROM web_users WHERE id = ?', [$id]);
        return ['data' => ['user' => ApiHelpers::webUser($updated ?? [])]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $target = Db::one('SELECT * FROM web_users WHERE id = ?', [$id]);
        if ($target === null) {
            throw ApiError::notFound('User not found.');
        }
        if ($id === $actorId) {
            throw new ApiError('self_modification_forbidden', 'You cannot deactivate your own account.', 409);
        }
        users_assert_admin_remains($id, (string) $target['role'], false);
        if ((int) $target['is_active'] === 0) {
            return ['data' => ['deactivated' => true]];
        }
        Db::tx(function () use ($id, $target): void {
            Db::exec('UPDATE web_users SET is_active = 0, session_version = session_version + 1, updated_at = ? WHERE id = ?', [Util::toDb(Util::utcNow()), $id]);
            Audit::record('users.deactivate', 'web_user', (string) $id, [
                'username' => (string) $target['username'],
                'role' => (string) $target['role'],
            ], ['is_active' => false]);
        });
        return ['data' => ['deactivated' => true]];
    }

    throw ApiError::methodNotAllowed();
});

function users_assert_admin_remains(int $targetId, string $newRole, bool $newActive): void
{
    $otherAdmins = (int) Db::scalar(
        'SELECT COUNT(*) FROM web_users WHERE role = ? AND is_active = 1 AND id <> ?',
        ['admin', $targetId]
    );
    if ($otherAdmins === 0 && ($newRole !== 'admin' || !$newActive)) {
        throw new ApiError('last_admin', 'At least one active administrator must remain.', 409);
    }
}
