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
use DoorLock\DeviceAuth;
use DoorLock\Request;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    $user = Api::guard($request, 'devices.read', 'devices.write');

    if ($request->method() === 'GET') {
        $id = $request->query('id');
        if ($id !== null && $id !== '') {
            $device = deviceFetch(Validator::id($id, 'id'), true);
            return ['data' => ['device' => ApiHelpers::device($device)]];
        }
        $includeInactive = Validator::boolValue($request->query('include_inactive', '1'), 'include_inactive');
        $sql = 'SELECT d.*, o.name AS door_name FROM devices d JOIN doors o ON o.id = d.door_id'
            . ($includeInactive ? '' : ' WHERE d.is_active = 1')
            . ' ORDER BY o.name, d.name, d.id';
        $rows = Db::all($sql);
        return ['data' => ['devices' => array_map(static fn (array $row) => ApiHelpers::device($row), $rows)]];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        if (($input['action'] ?? null) === 'provision') {
            $id = Validator::id($input['id'] ?? null, 'id');
            $device = deviceFetch($id, true);
            $key = DeviceAuth::deriveDeviceKey((string) $device['unique_id'], (int) $device['key_version']);
            Audit::record('devices.provision', 'device', (string) $id, null, ['key_version' => (int) $device['key_version']]);
            return ['data' => ['provisioning' => [
                'device_id' => (int) $device['id'],
                'unique_id' => (string) $device['unique_id'],
                'door_id' => (int) $device['door_id'],
                'key_version' => (int) $device['key_version'],
                'device_key' => $key,
                'endpoint' => '/api/check-access.php',
            ]]];
        }

        $name = Validator::string($input['name'] ?? null, 'name', 1, 100);
        $uniqueIdRaw = $input['unique_id'] ?? null;
        if (!is_string($uniqueIdRaw) || preg_match(Util::DEVICE_UID_PATTERN, trim($uniqueIdRaw)) !== 1) {
            throw ApiError::validation('unique_id', 'Device identifier must be exactly 32 hexadecimal characters.');
        }
        $uniqueId = strtolower(trim($uniqueIdRaw));
        $doorId = Validator::id($input['door_id'] ?? null, 'door_id');
        $door = ApiHelpers::activeDoor($doorId);
        $keyVersion = array_key_exists('key_version', $input) ? Validator::intValue($input['key_version'], 'key_version', 1, 1000) : 1;
        $isActive = array_key_exists('is_active', $input) ? Validator::boolValue($input['is_active'], 'is_active') : true;
        $now = Util::toDb(Util::utcNow());

        try {
            $deviceId = Db::tx(function () use ($name, $uniqueId, $doorId, $keyVersion, $isActive, $now, $door): int {
                $deviceId = Db::insert(
                    'INSERT INTO devices (name, unique_id, door_id, key_version, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$name, $uniqueId, $doorId, $keyVersion, $isActive ? 1 : 0, $now, $now]
                );
                Audit::record('devices.create', 'device', (string) $deviceId, null, [
                    'name' => $name,
                    'unique_id' => $uniqueId,
                    'door_name' => (string) $door['name'],
                    'key_version' => $keyVersion,
                ]);
                return $deviceId;
            });
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                throw new ApiError('conflict', 'A device with this identifier already exists.', 409, ['unique_id' => 'Already in use.']);
            }
            throw $e;
        }

        $device = deviceFetch($deviceId, true);
        $key = DeviceAuth::deriveDeviceKey((string) $device['unique_id'], (int) $device['key_version']);
        return ['status' => 201, 'data' => ['device' => ApiHelpers::device($device), 'device_key' => $key]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $device = deviceFetch($id, true);
        $changes = [];
        $after = [];
        if (array_key_exists('name', $input)) {
            $changes['name'] = Validator::string($input['name'], 'name', 1, 100);
            $after['name'] = $changes['name'];
        }
        if (array_key_exists('door_id', $input)) {
            $doorId = Validator::id($input['door_id'], 'door_id');
            ApiHelpers::activeDoor($doorId);
            $changes['door_id'] = $doorId;
            $after['door_id'] = $doorId;
        }
        if (array_key_exists('is_active', $input)) {
            $changes['is_active'] = Validator::boolValue($input['is_active'], 'is_active') ? 1 : 0;
            $after['is_active'] = (bool) $changes['is_active'];
        }
        if (array_key_exists('toggle', $input) && Validator::boolValue($input['toggle'], 'toggle')) {
            $changes['is_active'] = (int) $device['is_active'] === 1 ? 0 : 1;
            $after['is_active'] = (bool) $changes['is_active'];
        }
        $rotate = array_key_exists('rotate_key', $input) && Validator::boolValue($input['rotate_key'], 'rotate_key');
        if ($rotate) {
            $changes['key_version'] = (int) $device['key_version'] + 1;
            $after['key_version'] = $changes['key_version'];
        } elseif (array_key_exists('key_version', $input)) {
            throw ApiError::validation('key_version', 'Use rotate_key to change the key version.');
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
        Db::tx(function () use ($set, $params, $id, $device, $after, $rotate): void {
            Db::exec('UPDATE devices SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            if ($rotate) {
                Db::exec('DELETE FROM device_nonces WHERE device_id = ?', [$id]);
            }
            Audit::record('devices.update', 'device', (string) $id, [
                'name' => (string) $device['name'],
                'door_id' => (int) $device['door_id'],
                'is_active' => (int) $device['is_active'] === 1,
                'key_version' => (int) $device['key_version'],
            ], $after);
        });

        $updated = deviceFetch($id, true);
        $payload = ['device' => ApiHelpers::device($updated)];
        if ($rotate) {
            $payload['device_key'] = DeviceAuth::deriveDeviceKey((string) $updated['unique_id'], (int) $updated['key_version']);
        }
        return ['data' => $payload];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $device = deviceFetch($id, true);
        if ((int) $device['is_active'] === 0) {
            return ['data' => ['disabled' => true]];
        }
        Db::tx(function () use ($id, $device): void {
            Db::exec('UPDATE devices SET is_active = 0, updated_at = ? WHERE id = ?', [Util::toDb(Util::utcNow()), $id]);
            Audit::record('devices.disable', 'device', (string) $id, ['name' => (string) $device['name']], ['is_active' => false]);
        });
        return ['data' => ['disabled' => true]];
    }

    throw ApiError::methodNotAllowed();
});

/** @return array<string,mixed> */
function deviceFetch(int $id, bool $withDoor): array
{
    $sql = 'SELECT d.*' . ($withDoor ? ', o.name AS door_name' : '')
        . ' FROM devices d' . ($withDoor ? ' JOIN doors o ON o.id = d.door_id' : '') . ' WHERE d.id = ?';
    $device = Db::one($sql, [$id]);
    if ($device === null) {
        throw ApiError::notFound('Device not found.');
    }
    return $device;
}
