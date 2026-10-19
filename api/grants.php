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
    $user = Api::guard($request, 'grants.read', 'grants.write');

    if ($request->method() === 'GET') {
        [$page, $perPage] = Validator::pagination($request);
        $now = Util::toDb(Util::utcNow());
        $where = [];
        $params = [];
        if ($request->query('person_id') !== null && $request->query('person_id') !== '') {
            $where[] = 'g.person_id = ?';
            $params[] = Validator::id($request->query('person_id'), 'person_id');
        }
        if ($request->query('door_id') !== null && $request->query('door_id') !== '') {
            $where[] = 'g.door_id = ?';
            $params[] = Validator::id($request->query('door_id'), 'door_id');
        }
        $state = $request->query('state', 'all') ?? 'all';
        switch ($state) {
            case 'all':
                break;
            case 'active':
                $where[] = 'g.revoked_at IS NULL AND g.valid_from <= ? AND g.valid_to > ? AND (g.max_uses IS NULL OR g.used_count < g.max_uses)';
                $params[] = $now;
                $params[] = $now;
                break;
            case 'scheduled':
                $where[] = 'g.revoked_at IS NULL AND g.valid_from > ?';
                $params[] = $now;
                break;
            case 'expired':
                $where[] = 'g.revoked_at IS NULL AND g.valid_to <= ?';
                $params[] = $now;
                break;
            case 'exhausted':
                $where[] = 'g.revoked_at IS NULL AND g.max_uses IS NOT NULL AND g.used_count >= g.max_uses';
                break;
            case 'revoked':
                $where[] = 'g.revoked_at IS NOT NULL';
                break;
            default:
                throw ApiError::validation('state', 'State must be one of all, active, scheduled, expired, exhausted, revoked.');
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = ' FROM temporary_grants g JOIN persons p ON p.id = g.person_id JOIN doors d ON d.id = g.door_id';
        $total = (int) Db::scalar('SELECT COUNT(*)' . $from . $sqlWhere, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            'SELECT g.*, p.name AS person_name, d.name AS door_name' . $from . $sqlWhere
            . ' ORDER BY g.valid_to DESC, g.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );
        return [
            'data' => ['grants' => array_map(static fn (array $row) => ApiHelpers::grant($row), $rows)],
            'meta' => ApiHelpers::pageMeta($page, $perPage, $total),
        ];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $personId = Validator::id($input['person_id'] ?? null, 'person_id');
        $doorId = Validator::id($input['door_id'] ?? null, 'door_id');
        $person = ApiHelpers::activePerson($personId);
        $door = ApiHelpers::activeDoor($doorId);
        $validFrom = Validator::dateTime($input['valid_from'] ?? null, 'valid_from');
        $validTo = Validator::dateTime($input['valid_to'] ?? null, 'valid_to');
        if ($validTo <= $validFrom) {
            throw ApiError::validation('valid_to', 'The end of the grant must be after its start.');
        }
        $maxDays = Config::int('security.max_grant_days', 90);
        if ($validTo->getTimestamp() - $validFrom->getTimestamp() > $maxDays * 86400) {
            throw ApiError::validation('valid_to', 'Temporary grants cannot exceed ' . $maxDays . ' days.');
        }
        $maxUses = null;
        if (array_key_exists('max_uses', $input) && $input['max_uses'] !== null) {
            $maxUses = Validator::intValue($input['max_uses'], 'max_uses', 1, 100000);
        }
        $note = Validator::nullableString($input['note'] ?? null, 'note', 255);
        $now = Util::toDb(Util::utcNow());

        $grantId = Db::tx(function () use ($personId, $doorId, $validFrom, $validTo, $maxUses, $note, $now, $user, $person, $door): int {
            $grantId = Db::insert(
                'INSERT INTO temporary_grants
                    (person_id, door_id, valid_from, valid_to, max_uses, used_count, note, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)',
                [
                    $personId,
                    $doorId,
                    Util::toDb($validFrom),
                    Util::toDb($validTo),
                    $maxUses,
                    $note,
                    (int) $user['id'],
                    $now,
                    $now,
                ]
            );
            Audit::record('grants.create', 'grant', (string) $grantId, null, [
                'person_name' => (string) $person['name'],
                'door_name' => (string) $door['name'],
                'valid_from' => Util::iso(Util::toDb($validFrom)),
                'valid_to' => Util::iso(Util::toDb($validTo)),
                'max_uses' => $maxUses,
            ]);
            return $grantId;
        });

        $grant = Db::one(
            'SELECT g.*, p.name AS person_name, d.name AS door_name FROM temporary_grants g
               JOIN persons p ON p.id = g.person_id JOIN doors d ON d.id = g.door_id WHERE g.id = ?',
            [$grantId]
        );
        return ['status' => 201, 'data' => ['grant' => ApiHelpers::grant($grant ?? [])]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $grant = Db::one('SELECT * FROM temporary_grants WHERE id = ?', [$id]);
        if ($grant === null) {
            throw ApiError::notFound('Grant not found.');
        }
        if ($grant['revoked_at'] !== null) {
            return ['data' => ['revoked' => true]];
        }
        $now = Util::toDb(Util::utcNow());
        Db::tx(function () use ($id, $now, $grant, $user): void {
            Db::exec(
                'UPDATE temporary_grants SET revoked_at = ?, revoked_by = ?, updated_at = ? WHERE id = ? AND revoked_at IS NULL',
                [$now, (int) $user['id'], $now, $id]
            );
            Audit::record('grants.revoke', 'grant', (string) $id, [
                'person_id' => (int) $grant['person_id'],
                'door_id' => (int) $grant['door_id'],
                'used_count' => (int) $grant['used_count'],
            ], ['revoked' => true]);
        });
        return ['data' => ['revoked' => true]];
    }

    throw ApiError::methodNotAllowed();
});
