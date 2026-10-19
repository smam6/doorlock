<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Audit;
use DoorLock\Config;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    Api::guard($request, 'schedules.read', 'schedules.write');

    if ($request->method() === 'GET') {
        $id = $request->query('id');
        if ($id !== null && $id !== '') {
            $schedule = Db::one(
                'SELECT s.*, p.name AS person_name, d.name AS door_name
                   FROM schedules s
                   JOIN persons p ON p.id = s.person_id
                   JOIN doors d ON d.id = s.door_id
                  WHERE s.id = ?',
                [Validator::id($id, 'id')]
            );
            if ($schedule === null) {
                throw ApiError::notFound('Schedule not found.');
            }
            return ['data' => ['schedule' => ApiHelpers::schedule($schedule)]];
        }

        [$page, $perPage] = Validator::pagination($request);
        $where = [];
        $params = [];
        if ($request->query('person_id') !== null && $request->query('person_id') !== '') {
            $where[] = 's.person_id = ?';
            $params[] = Validator::id($request->query('person_id'), 'person_id');
        }
        if ($request->query('door_id') !== null && $request->query('door_id') !== '') {
            $where[] = 's.door_id = ?';
            $params[] = Validator::id($request->query('door_id'), 'door_id');
        }
        if ($request->query('enabled') !== null) {
            $where[] = 's.enabled = ?';
            $params[] = Validator::boolValue($request->query('enabled'), 'enabled') ? 1 : 0;
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = ' FROM schedules s JOIN persons p ON p.id = s.person_id JOIN doors d ON d.id = s.door_id';
        $total = (int) Db::scalar('SELECT COUNT(*)' . $from . $sqlWhere, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            'SELECT s.*, p.name AS person_name, d.name AS door_name' . $from . $sqlWhere
            . ' ORDER BY p.name, d.name, s.start_time, s.id LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );
        return [
            'data' => ['schedules' => array_map(static fn (array $row) => ApiHelpers::schedule($row), $rows)],
            'meta' => ApiHelpers::pageMeta($page, $perPage, $total),
        ];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $personId = Validator::id($input['person_id'] ?? null, 'person_id');
        $doorId = Validator::id($input['door_id'] ?? null, 'door_id');
        $person = ApiHelpers::activePerson($personId);
        $door = ApiHelpers::activeDoor($doorId);
        $days = Validator::days($input['days'] ?? null);
        $start = Validator::time($input['start_time'] ?? null, 'start_time');
        $end = Validator::time($input['end_time'] ?? null, 'end_time');
        $enabled = array_key_exists('enabled', $input) ? Validator::boolValue($input['enabled'], 'enabled') : true;

        $existing = (int) Db::scalar('SELECT COUNT(*) FROM schedules WHERE person_id = ? AND door_id = ?', [$personId, $doorId]);
        if ($existing >= Config::int('limits.max_schedules_per_pair', 50)) {
            throw new ApiError('limit_reached', 'This person/door pair already has the maximum number of schedules.', 409);
        }

        $now = Util::toDb(Util::utcNow());
        $scheduleId = Db::tx(function () use ($personId, $doorId, $days, $start, $end, $enabled, $now, $person, $door): int {
            $scheduleId = Db::insert(
                'INSERT INTO schedules (person_id, door_id, days, start_time, end_time, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$personId, $doorId, $days, $start, $end, $enabled ? 1 : 0, $now, $now]
            );
            Audit::record('schedules.create', 'schedule', (string) $scheduleId, null, [
                'person_id' => $personId,
                'person_name' => (string) $person['name'],
                'door_id' => $doorId,
                'door_name' => (string) $door['name'],
                'days' => $days,
                'start_time' => $start,
                'end_time' => $end,
                'enabled' => $enabled,
            ]);
            return $scheduleId;
        });

        $schedule = Db::one(
            'SELECT s.*, p.name AS person_name, d.name AS door_name FROM schedules s
               JOIN persons p ON p.id = s.person_id JOIN doors d ON d.id = s.door_id WHERE s.id = ?',
            [$scheduleId]
        );
        return ['status' => 201, 'data' => ['schedule' => ApiHelpers::schedule($schedule ?? [])]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $schedule = Db::one('SELECT * FROM schedules WHERE id = ?', [$id]);
        if ($schedule === null) {
            throw ApiError::notFound('Schedule not found.');
        }
        $changes = [];
        $after = [];
        if (array_key_exists('days', $input)) {
            $changes['days'] = Validator::days($input['days']);
            $after['days'] = $changes['days'];
        }
        if (array_key_exists('start_time', $input)) {
            $changes['start_time'] = Validator::time($input['start_time'], 'start_time');
            $after['start_time'] = $changes['start_time'];
        }
        if (array_key_exists('end_time', $input)) {
            $changes['end_time'] = Validator::time($input['end_time'], 'end_time');
            $after['end_time'] = $changes['end_time'];
        }
        if (array_key_exists('enabled', $input)) {
            $changes['enabled'] = Validator::boolValue($input['enabled'], 'enabled') ? 1 : 0;
            $after['enabled'] = (bool) $changes['enabled'];
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
        Db::tx(function () use ($set, $params, $id, $schedule, $after): void {
            Db::exec('UPDATE schedules SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            Audit::record('schedules.update', 'schedule', (string) $id, [
                'days' => (string) $schedule['days'],
                'start_time' => (string) $schedule['start_time'],
                'end_time' => (string) $schedule['end_time'],
                'enabled' => (int) $schedule['enabled'] === 1,
            ], $after);
        });

        $updated = Db::one(
            'SELECT s.*, p.name AS person_name, d.name AS door_name FROM schedules s
               JOIN persons p ON p.id = s.person_id JOIN doors d ON d.id = s.door_id WHERE s.id = ?',
            [$id]
        );
        return ['data' => ['schedule' => ApiHelpers::schedule($updated ?? [])]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $schedule = Db::one('SELECT * FROM schedules WHERE id = ?', [$id]);
        if ($schedule === null) {
            throw ApiError::notFound('Schedule not found.');
        }
        Db::tx(function () use ($id, $schedule): void {
            Db::exec('DELETE FROM schedules WHERE id = ?', [$id]);
            Audit::record('schedules.delete', 'schedule', (string) $id, [
                'person_id' => (int) $schedule['person_id'],
                'door_id' => (int) $schedule['door_id'],
                'days' => (string) $schedule['days'],
                'start_time' => (string) $schedule['start_time'],
                'end_time' => (string) $schedule['end_time'],
            ]);
        });
        return ['data' => ['deleted' => true]];
    }

    throw ApiError::methodNotAllowed();
});
