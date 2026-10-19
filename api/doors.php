<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Audit;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    Api::guard($request, 'doors.read', 'doors.write');

    if ($request->method() === 'GET') {
        $includeArchived = Validator::boolValue($request->query('include_archived', '0'), 'include_archived');
        $sql = 'SELECT * FROM doors' . ($includeArchived ? '' : ' WHERE archived_at IS NULL') . ' ORDER BY name, id';
        $rows = Db::all($sql);
        $doors = array_map(static fn (array $row) => ApiHelpers::door($row), $rows);
        $deviceCounts = [];
        foreach (Db::all('SELECT door_id, COUNT(*) AS c FROM devices WHERE is_active = 1 GROUP BY door_id') as $row) {
            $deviceCounts[(int) $row['door_id']] = (int) $row['c'];
        }
        foreach ($doors as &$door) {
            $door['active_devices'] = $deviceCounts[$door['id']] ?? 0;
        }
        unset($door);
        return ['data' => ['doors' => $doors]];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $name = Validator::string($input['name'] ?? null, 'name', 1, 100);
        $location = Validator::nullableString($input['location'] ?? null, 'location', 150);
        $isActive = array_key_exists('is_active', $input) ? Validator::boolValue($input['is_active'], 'is_active') : true;
        $unlockSeconds = array_key_exists('unlock_seconds', $input)
            ? Validator::intValue($input['unlock_seconds'], 'unlock_seconds', 1, 30)
            : 5;
        $now = Util::toDb(Util::utcNow());
        $doorId = Db::tx(function () use ($name, $location, $isActive, $unlockSeconds, $now): int {
            $doorId = Db::insert(
                'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $location, $isActive ? 1 : 0, $unlockSeconds, $now, $now]
            );
            Audit::record('doors.create', 'door', (string) $doorId, null, [
                'name' => $name,
                'location' => $location,
                'is_active' => $isActive,
                'unlock_seconds' => $unlockSeconds,
            ]);
            return $doorId;
        });
        $door = Db::one('SELECT * FROM doors WHERE id = ?', [$doorId]);
        return ['status' => 201, 'data' => ['door' => ApiHelpers::door($door ?? [])]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $door = ApiHelpers::activeDoor($id, true);
        $changes = [];
        $after = [];
        if (array_key_exists('name', $input)) {
            $changes['name'] = Validator::string($input['name'], 'name', 1, 100);
            $after['name'] = $changes['name'];
        }
        if (array_key_exists('location', $input)) {
            $changes['location'] = Validator::nullableString($input['location'], 'location', 150);
            $after['location'] = $changes['location'];
        }
        if (array_key_exists('unlock_seconds', $input)) {
            $changes['unlock_seconds'] = Validator::intValue($input['unlock_seconds'], 'unlock_seconds', 1, 30);
            $after['unlock_seconds'] = $changes['unlock_seconds'];
        }
        if (array_key_exists('toggle', $input) && Validator::boolValue($input['toggle'], 'toggle')) {
            $changes['is_active'] = (int) $door['is_active'] === 1 ? 0 : 1;
            $after['is_active'] = (bool) $changes['is_active'];
        } elseif (array_key_exists('is_active', $input)) {
            $changes['is_active'] = Validator::boolValue($input['is_active'], 'is_active') ? 1 : 0;
            $after['is_active'] = (bool) $changes['is_active'];
        }
        if (array_key_exists('restore', $input) && Validator::boolValue($input['restore'], 'restore')) {
            $changes['archived_at'] = null;
            $after['archived'] = false;
        }
        if ($changes === []) {
            throw ApiError::validation('id', 'No supported fields were supplied.');
        }
        $changes['updated_at'] = Util::toDb(Util::utcNow());
        $set = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $set[] = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;
        Db::tx(function () use ($set, $params, $id, $door, $after): void {
            Db::exec('UPDATE doors SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            Audit::record('doors.update', 'door', (string) $id, [
                'name' => (string) $door['name'],
                'is_active' => (int) $door['is_active'] === 1,
                'unlock_seconds' => (int) $door['unlock_seconds'],
            ], $after);
        });
        $updated = Db::one('SELECT * FROM doors WHERE id = ?', [$id]);
        return ['data' => ['door' => ApiHelpers::door($updated ?? [])]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $door = ApiHelpers::activeDoor($id, true);
        if ($door['archived_at'] !== null) {
            return ['data' => ['archived' => true]];
        }
        $activeDevices = (int) Db::scalar('SELECT COUNT(*) FROM devices WHERE door_id = ? AND is_active = 1', [$id]);
        if ($activeDevices > 0) {
            throw new ApiError('conflict', 'Disable or reassign the devices attached to this door first.', 409);
        }
        $now = Util::toDb(Util::utcNow());
        Db::tx(function () use ($id, $now, $door): void {
            Db::exec('UPDATE doors SET archived_at = ?, is_active = 0, updated_at = ? WHERE id = ?', [$now, $now, $id]);
            Audit::record('doors.archive', 'door', (string) $id, ['name' => (string) $door['name']], ['archived' => true]);
        });
        return ['data' => ['archived' => true]];
    }

    throw ApiError::methodNotAllowed();
});
