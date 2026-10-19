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
    $user = Api::guard($request, 'persons.read', 'persons.write');

    if ($request->method() === 'GET') {
        $id = $request->query('id');
        if ($id !== null && $id !== '') {
            $person = ApiHelpers::activePerson(Validator::id($id, 'id'), true);
            $cards = Db::all('SELECT * FROM cards WHERE person_id = ? ORDER BY id', [(int) $person['id']]);
            $groups = Db::all(
                'SELECT g.id, g.name, g.enabled FROM group_members gm JOIN person_groups g ON g.id = gm.group_id WHERE gm.person_id = ? ORDER BY g.name',
                [(int) $person['id']]
            );
            return ['data' => ['person' => ApiHelpers::person($person, $cards, $groups)]];
        }

        [$page, $perPage] = Validator::pagination($request);
        $includeArchived = Validator::boolValue($request->query('include_archived', '0'), 'include_archived');
        $search = trim((string) $request->query('search', ''));
        $where = [];
        $params = [];
        if (!$includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        if ($search !== '') {
            $where[] = 'name LIKE ?';
            $params[] = ApiHelpers::like($search);
        }
        if ($request->query('enabled') !== null) {
            $where[] = 'enabled = ?';
            $params[] = Validator::boolValue($request->query('enabled'), 'enabled') ? 1 : 0;
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Db::scalar('SELECT COUNT(*) FROM persons' . $sqlWhere, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            'SELECT * FROM persons' . $sqlWhere . ' ORDER BY name, id LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );

        $ids = array_map(static fn (array $row) => (int) $row['id'], $rows);
        $cardsByPerson = [];
        $groupsByPerson = [];
        if ($ids !== []) {
            $in = implode(',', ApiHelpers::placeholders($ids));
            foreach (Db::all("SELECT * FROM cards WHERE person_id IN ({$in}) ORDER BY id", $ids) as $card) {
                $cardsByPerson[(int) $card['person_id']][] = $card;
            }
            foreach (Db::all(
                "SELECT gm.person_id, g.id, g.name, g.enabled
                   FROM group_members gm JOIN person_groups g ON g.id = gm.group_id
                  WHERE gm.person_id IN ({$in}) ORDER BY g.name",
                $ids
            ) as $group) {
                $groupsByPerson[(int) $group['person_id']][] = $group;
            }
        }

        $persons = array_map(static function (array $row) use ($cardsByPerson, $groupsByPerson): array {
            return ApiHelpers::person(
                $row,
                $cardsByPerson[(int) $row['id']] ?? [],
                $groupsByPerson[(int) $row['id']] ?? []
            );
        }, $rows);

        return ['data' => ['persons' => $persons], 'meta' => ApiHelpers::pageMeta($page, $perPage, $total)];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $name = Validator::string($input['name'] ?? null, 'name', 1, 100);
        $notes = Validator::nullableString($input['notes'] ?? null, 'notes', 255);
        $enabled = array_key_exists('enabled', $input) ? Validator::boolValue($input['enabled'], 'enabled') : true;
        $cardNumber = null;
        if (isset($input['card_number']) && $input['card_number'] !== '') {
            $cardNumber = Validator::cardNumber($input['card_number']);
        }
        $now = Util::toDb(Util::utcNow());

        try {
            $personId = Db::tx(function () use ($name, $notes, $enabled, $cardNumber, $now, $user): int {
                $personId = Db::insert(
                    'INSERT INTO persons (name, enabled, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$name, $enabled ? 1 : 0, $notes, $now, $now]
                );
                if ($cardNumber !== null) {
                    Db::insert(
                        'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                        [$personId, $cardNumber, 'active', $now, $now]
                    );
                }
                Audit::record('persons.create', 'person', (string) $personId, null, [
                    'name' => $name,
                    'enabled' => $enabled,
                    'notes' => $notes,
                    'card_number' => $cardNumber,
                ]);
                return $personId;
            });
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                throw new ApiError('conflict', 'This card number is already assigned.', 409, ['card_number' => 'Already in use.']);
            }
            throw $e;
        }

        $person = ApiHelpers::activePerson($personId, true);
        $cards = Db::all('SELECT * FROM cards WHERE person_id = ?', [$personId]);
        return ['status' => 201, 'data' => ['person' => ApiHelpers::person($person, $cards)]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $person = ApiHelpers::activePerson($id, true);
        $changes = [];
        $after = [];
        if (array_key_exists('name', $input)) {
            $changes['name'] = Validator::string($input['name'], 'name', 1, 100);
            $after['name'] = $changes['name'];
        }
        if (array_key_exists('enabled', $input)) {
            $changes['enabled'] = Validator::boolValue($input['enabled'], 'enabled') ? 1 : 0;
            $after['enabled'] = (bool) $changes['enabled'];
        }
        if (array_key_exists('notes', $input)) {
            $changes['notes'] = Validator::nullableString($input['notes'], 'notes', 255);
            $after['notes'] = $changes['notes'];
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
        Db::tx(function () use ($set, $params, $id, $person, $after): void {
            Db::exec('UPDATE persons SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            Audit::record('persons.update', 'person', (string) $id, [
                'name' => $person['name'],
                'enabled' => (int) $person['enabled'] === 1,
            ], $after);
        });
        $updated = ApiHelpers::activePerson($id, true);
        $cards = Db::all('SELECT * FROM cards WHERE person_id = ?', [$id]);
        return ['data' => ['person' => ApiHelpers::person($updated, $cards)]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $person = ApiHelpers::activePerson($id, true);
        if ($person['archived_at'] !== null) {
            return ['data' => ['archived' => true]];
        }
        $now = Util::toDb(Util::utcNow());
        Db::tx(function () use ($id, $now, $person): void {
            Db::exec('UPDATE persons SET archived_at = ?, enabled = 0, updated_at = ? WHERE id = ?', [$now, $now, $id]);
            Audit::record('persons.archive', 'person', (string) $id, ['name' => $person['name']], ['archived' => true]);
        });
        return ['data' => ['archived' => true]];
    }

    throw ApiError::methodNotAllowed();
});
