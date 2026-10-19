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
    Api::guard($request, 'permissions.read', 'permissions.write');

    if ($request->method() === 'GET') {
        [$page, $perPage] = Validator::pagination($request);
        $where = [];
        $params = [];
        if ($request->query('person_id') !== null && $request->query('person_id') !== '') {
            $where[] = 'pm.person_id = ?';
            $params[] = Validator::id($request->query('person_id'), 'person_id');
        }
        if ($request->query('door_id') !== null && $request->query('door_id') !== '') {
            $where[] = 'pm.door_id = ?';
            $params[] = Validator::id($request->query('door_id'), 'door_id');
        }
        if ($request->query('effect') !== null && $request->query('effect') !== '') {
            $where[] = 'pm.effect = ?';
            $params[] = Validator::enum($request->query('effect'), 'effect', ['allow', 'deny']);
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = ' FROM permissions pm JOIN persons p ON p.id = pm.person_id JOIN doors d ON d.id = pm.door_id';
        $total = (int) Db::scalar('SELECT COUNT(*)' . $from . $sqlWhere, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            'SELECT pm.*, p.name AS person_name, d.name AS door_name' . $from . $sqlWhere
            . ' ORDER BY p.name, d.name LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );
        $permissions = array_map(static fn (array $row) => [
            'person_id' => (int) $row['person_id'],
            'person_name' => (string) $row['person_name'],
            'door_id' => (int) $row['door_id'],
            'door_name' => (string) $row['door_name'],
            'effect' => (string) $row['effect'],
            'created_at' => Util::iso($row['created_at'] ?? null),
            'updated_at' => Util::iso($row['updated_at'] ?? null),
        ], $rows);
        return ['data' => ['permissions' => $permissions], 'meta' => ApiHelpers::pageMeta($page, $perPage, $total)];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $personId = Validator::id($input['person_id'] ?? null, 'person_id');
        $doorId = Validator::id($input['door_id'] ?? null, 'door_id');
        $effect = Validator::enum($input['effect'] ?? null, 'effect', ['allow', 'deny']);
        $person = ApiHelpers::activePerson($personId);
        $door = ApiHelpers::activeDoor($doorId);

        $now = Util::toDb(Util::utcNow());
        $before = null;
        Db::tx(function () use ($personId, $doorId, $effect, $now, $person, $door, &$before): void {
            $existing = Db::one('SELECT effect FROM permissions WHERE person_id = ? AND door_id = ?', [$personId, $doorId]);
            if ($existing !== null) {
                $before = ['effect' => (string) $existing['effect']];
                Db::exec(
                    'UPDATE permissions SET effect = ?, updated_at = ? WHERE person_id = ? AND door_id = ?',
                    [$effect, $now, $personId, $doorId]
                );
                Audit::record('permissions.update', 'permission', $personId . ':' . $doorId, $before, [
                    'effect' => $effect,
                    'person_name' => (string) $person['name'],
                    'door_name' => (string) $door['name'],
                ]);
            } else {
                Db::exec(
                    'INSERT INTO permissions (person_id, door_id, effect, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$personId, $doorId, $effect, $now, $now]
                );
                Audit::record('permissions.create', 'permission', $personId . ':' . $doorId, null, [
                    'effect' => $effect,
                    'person_name' => (string) $person['name'],
                    'door_name' => (string) $door['name'],
                ]);
            }
        });

        return ['status' => $before === null ? 201 : 200, 'data' => ['permission' => [
            'person_id' => $personId,
            'person_name' => (string) $person['name'],
            'door_id' => $doorId,
            'door_name' => (string) $door['name'],
            'effect' => $effect,
        ]]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $personId = Validator::id($input['person_id'] ?? null, 'person_id');
        $doorId = Validator::id($input['door_id'] ?? null, 'door_id');
        $existing = Db::one('SELECT effect FROM permissions WHERE person_id = ? AND door_id = ?', [$personId, $doorId]);
        if ($existing === null) {
            throw ApiError::notFound('Permission not found.');
        }
        Db::tx(function () use ($personId, $doorId, $existing): void {
            Db::exec('DELETE FROM permissions WHERE person_id = ? AND door_id = ?', [$personId, $doorId]);
            Audit::record('permissions.delete', 'permission', $personId . ':' . $doorId, ['effect' => (string) $existing['effect']]);
        });
        return ['data' => ['deleted' => true]];
    }

    throw ApiError::methodNotAllowed();
});
