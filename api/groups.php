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
    Api::guard($request, 'groups.read', 'groups.write');

    if ($request->method() === 'GET') {
        $id = $request->query('id');
        if ($id !== null && $id !== '') {
            $group = ApiHelpers::activeGroup(Validator::id($id, 'id'), true);
            return ['data' => ['group' => ApiHelpers::group(
                $group,
                groupMembersList((int) $group['id']),
                groupDoorsList((int) $group['id'])
            )]];
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
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Db::scalar('SELECT COUNT(*) FROM person_groups' . $sqlWhere, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            'SELECT * FROM person_groups' . $sqlWhere . ' ORDER BY name, id LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );

        $ids = array_map(static fn (array $row) => (int) $row['id'], $rows);
        $membersByGroup = [];
        $doorsByGroup = [];
        if ($ids !== []) {
            $in = implode(',', ApiHelpers::placeholders($ids));
            foreach (Db::all(
                "SELECT gm.group_id, p.id, p.name, p.enabled
                   FROM group_members gm JOIN persons p ON p.id = gm.person_id
                  WHERE gm.group_id IN ({$in}) ORDER BY p.name",
                $ids
            ) as $member) {
                $membersByGroup[(int) $member['group_id']][] = [
                    'id' => (int) $member['id'],
                    'name' => (string) $member['name'],
                    'enabled' => (int) $member['enabled'] === 1,
                ];
            }
            foreach (Db::all(
                "SELECT gp.group_id, d.id, d.name FROM group_permissions gp JOIN doors d ON d.id = gp.door_id
                  WHERE gp.group_id IN ({$in}) ORDER BY d.name",
                $ids
            ) as $door) {
                $doorsByGroup[(int) $door['group_id']][] = [
                    'id' => (int) $door['id'],
                    'name' => (string) $door['name'],
                ];
            }
        }

        $groups = array_map(static function (array $row) use ($membersByGroup, $doorsByGroup): array {
            return ApiHelpers::group(
                $row,
                $membersByGroup[(int) $row['id']] ?? [],
                $doorsByGroup[(int) $row['id']] ?? []
            );
        }, $rows);

        return ['data' => ['groups' => $groups], 'meta' => ApiHelpers::pageMeta($page, $perPage, $total)];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $name = Validator::string($input['name'] ?? null, 'name', 1, 100);
        $description = Validator::nullableString($input['description'] ?? null, 'description', 255);
        $enabled = array_key_exists('enabled', $input) ? Validator::boolValue($input['enabled'], 'enabled') : true;
        $memberIds = array_key_exists('member_ids', $input) ? ApiHelpers::idList($input['member_ids'], 'member_ids') : [];
        $doorIds = array_key_exists('door_ids', $input) ? ApiHelpers::idList($input['door_ids'], 'door_ids') : [];
        $now = Util::toDb(Util::utcNow());

        groupAssertReferences($memberIds, $doorIds);

        try {
            $groupId = Db::tx(function () use ($name, $description, $enabled, $memberIds, $doorIds, $now): int {
                $groupId = Db::insert(
                    'INSERT INTO person_groups (name, description, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$name, $description, $enabled ? 1 : 0, $now, $now]
                );
                groupReplaceMembers($groupId, $memberIds, $now);
                groupReplaceDoors($groupId, $doorIds, $now);
                Audit::record('groups.create', 'group', (string) $groupId, null, [
                    'name' => $name,
                    'description' => $description,
                    'enabled' => $enabled,
                    'member_ids' => $memberIds,
                    'door_ids' => $doorIds,
                ]);
                return $groupId;
            });
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                throw new ApiError('conflict', 'A group with this name already exists.', 409, ['name' => 'Already in use.']);
            }
            throw $e;
        }

        $group = Db::one('SELECT * FROM person_groups WHERE id = ?', [$groupId]);
        return ['status' => 201, 'data' => ['group' => ApiHelpers::group($group ?? [], groupMembersList($groupId), groupDoorsList($groupId))]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $group = ApiHelpers::activeGroup($id, true);

        $changes = [];
        $after = [];
        if (array_key_exists('name', $input)) {
            $changes['name'] = Validator::string($input['name'], 'name', 1, 100);
            $after['name'] = $changes['name'];
        }
        if (array_key_exists('description', $input)) {
            $changes['description'] = Validator::nullableString($input['description'], 'description', 255);
            $after['description'] = $changes['description'];
        }
        if (array_key_exists('enabled', $input)) {
            $changes['enabled'] = Validator::boolValue($input['enabled'], 'enabled') ? 1 : 0;
            $after['enabled'] = (bool) $changes['enabled'];
        }
        if (array_key_exists('restore', $input) && Validator::boolValue($input['restore'], 'restore')) {
            $changes['archived_at'] = null;
            $after['archived'] = false;
        }

        $memberIds = array_key_exists('member_ids', $input) ? ApiHelpers::idList($input['member_ids'], 'member_ids') : null;
        $doorIds = array_key_exists('door_ids', $input) ? ApiHelpers::idList($input['door_ids'], 'door_ids') : null;
        if ($memberIds !== null || $doorIds !== null) {
            groupAssertReferences($memberIds ?? [], $doorIds ?? []);
        }

        if ($changes === [] && $memberIds === null && $doorIds === null) {
            throw ApiError::validation('id', 'No supported fields were supplied.');
        }

        $now = Util::toDb(Util::utcNow());
        $changes['updated_at'] = $now;

        try {
            Db::tx(function () use ($changes, $id, $group, $after, $memberIds, $doorIds, $now): void {
                $set = [];
                $params = [];
                foreach ($changes as $column => $value) {
                    $set[] = $column . ' = ?';
                    $params[] = $value;
                }
                $params[] = $id;
                Db::exec('UPDATE person_groups SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
                if ($memberIds !== null) {
                    Db::exec('DELETE FROM group_members WHERE group_id = ?', [$id]);
                    groupReplaceMembers($id, $memberIds, $now);
                }
                if ($doorIds !== null) {
                    Db::exec('DELETE FROM group_permissions WHERE group_id = ?', [$id]);
                    groupReplaceDoors($id, $doorIds, $now);
                }
                Audit::record('groups.update', 'group', (string) $id, [
                    'name' => (string) $group['name'],
                    'enabled' => (int) $group['enabled'] === 1,
                ], $after + ['member_ids' => $memberIds, 'door_ids' => $doorIds]);
            });
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                throw new ApiError('conflict', 'A group with this name already exists.', 409, ['name' => 'Already in use.']);
            }
            throw $e;
        }

        $updated = Db::one('SELECT * FROM person_groups WHERE id = ?', [$id]);
        return ['data' => ['group' => ApiHelpers::group($updated ?? [], groupMembersList($id), groupDoorsList($id))]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $group = ApiHelpers::activeGroup($id, true);
        if ($group['archived_at'] !== null) {
            return ['data' => ['archived' => true]];
        }
        $now = Util::toDb(Util::utcNow());
        Db::tx(function () use ($id, $now, $group): void {
            Db::exec('UPDATE person_groups SET archived_at = ?, enabled = 0, updated_at = ? WHERE id = ?', [$now, $now, $id]);
            Audit::record('groups.archive', 'group', (string) $id, ['name' => (string) $group['name']], ['archived' => true]);
        });
        return ['data' => ['archived' => true]];
    }

    throw ApiError::methodNotAllowed();
});

/** @return array<int,array<string,mixed>> */
function groupMembersList(int $groupId): array
{
    return array_map(static fn (array $row) => [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'enabled' => (int) $row['enabled'] === 1,
    ], Db::all(
        'SELECT p.id, p.name, p.enabled FROM group_members gm JOIN persons p ON p.id = gm.person_id WHERE gm.group_id = ? ORDER BY p.name',
        [$groupId]
    ));
}

/** @return array<int,array<string,mixed>> */
function groupDoorsList(int $groupId): array
{
    return array_map(static fn (array $row) => [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
    ], Db::all(
        'SELECT d.id, d.name FROM group_permissions gp JOIN doors d ON d.id = gp.door_id WHERE gp.group_id = ? ORDER BY d.name',
        [$groupId]
    ));
}

/** @param array<int,int> $memberIds @param array<int,int> $doorIds */
function groupAssertReferences(array $memberIds, array $doorIds): void
{
    foreach ($memberIds as $personId) {
        ApiHelpers::activePerson($personId);
    }
    foreach ($doorIds as $doorId) {
        ApiHelpers::activeDoor($doorId);
    }
}

/** @param array<int,int> $memberIds */
function groupReplaceMembers(int $groupId, array $memberIds, string $now): void
{
    foreach ($memberIds as $personId) {
        Db::exec('INSERT INTO group_members (group_id, person_id, created_at) VALUES (?, ?, ?)', [$groupId, $personId, $now]);
    }
}

/** @param array<int,int> $doorIds */
function groupReplaceDoors(int $groupId, array $doorIds, string $now): void
{
    foreach ($doorIds as $doorId) {
        Db::exec('INSERT INTO group_permissions (group_id, door_id, created_at) VALUES (?, ?, ?)', [$groupId, $doorId, $now]);
    }
}
