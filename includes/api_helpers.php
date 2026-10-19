<?php

declare(strict_types=1);

namespace DoorLock;

/** Shared response serialisers and lookups for the administrative API. */
final class ApiHelpers
{
    /** @param array<string,mixed> $row */
    public static function door(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'is_active' => (int) $row['is_active'] === 1,
            'unlock_seconds' => (int) $row['unlock_seconds'],
            'archived_at' => Util::iso($row['archived_at'] ?? null),
            'created_at' => Util::iso($row['created_at'] ?? null),
            'updated_at' => Util::iso($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function card(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'person_id' => (int) $row['person_id'],
            'card_number' => (string) $row['card_number'],
            'status' => (string) $row['status'],
            'last_used_at' => Util::iso($row['last_used_at'] ?? null),
            'created_at' => Util::iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $cards
     * @param array<int,array<string,mixed>> $groups
     */
    public static function person(array $row, array $cards = [], array $groups = []): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'enabled' => (int) $row['enabled'] === 1,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'archived_at' => Util::iso($row['archived_at'] ?? null),
            'created_at' => Util::iso($row['created_at'] ?? null),
            'updated_at' => Util::iso($row['updated_at'] ?? null),
            'cards' => array_map(static fn (array $card) => self::card($card), $cards),
            'groups' => array_map(static fn (array $group) => [
                'id' => (int) $group['id'],
                'name' => (string) $group['name'],
            ], $groups),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function group(array $row, array $members = [], array $doors = []): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'enabled' => (int) $row['enabled'] === 1,
            'archived_at' => Util::iso($row['archived_at'] ?? null),
            'created_at' => Util::iso($row['created_at'] ?? null),
            'updated_at' => Util::iso($row['updated_at'] ?? null),
            'members' => $members,
            'doors' => $doors,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function schedule(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'person_id' => (int) $row['person_id'],
            'door_id' => (int) $row['door_id'],
            'days' => (string) $row['days'],
            'days_list' => AccessEngine::parseDays((string) $row['days']),
            'start_time' => substr((string) $row['start_time'], 0, 5),
            'end_time' => substr((string) $row['end_time'], 0, 5),
            'enabled' => (int) $row['enabled'] === 1,
            'person_name' => isset($row['person_name']) ? (string) $row['person_name'] : null,
            'door_name' => isset($row['door_name']) ? (string) $row['door_name'] : null,
            'created_at' => Util::iso($row['created_at'] ?? null),
            'updated_at' => Util::iso($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function grant(array $row, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Util::utcNow();
        $from = Util::fromDb((string) $row['valid_from']);
        $to = Util::fromDb((string) $row['valid_to']);
        $revoked = ($row['revoked_at'] ?? null) !== null;
        $maxUses = $row['max_uses'] !== null ? (int) $row['max_uses'] : null;
        $used = (int) $row['used_count'];
        $state = 'active';
        if ($revoked) {
            $state = 'revoked';
        } elseif ($from !== null && $now < $from) {
            $state = 'scheduled';
        } elseif ($to !== null && $now >= $to) {
            $state = 'expired';
        } elseif ($maxUses !== null && $used >= $maxUses) {
            $state = 'exhausted';
        }
        return [
            'id' => (int) $row['id'],
            'person_id' => (int) $row['person_id'],
            'door_id' => (int) $row['door_id'],
            'valid_from' => Util::iso((string) $row['valid_from']),
            'valid_to' => Util::iso((string) $row['valid_to']),
            'max_uses' => $maxUses,
            'used_count' => $used,
            'remaining_uses' => $maxUses !== null ? max(0, $maxUses - $used) : null,
            'state' => $state,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'created_by' => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'revoked_at' => Util::iso($row['revoked_at'] ?? null),
            'last_used_at' => Util::iso($row['last_used_at'] ?? null),
            'person_name' => isset($row['person_name']) ? (string) $row['person_name'] : null,
            'door_name' => isset($row['door_name']) ? (string) $row['door_name'] : null,
            'created_at' => Util::iso($row['created_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function device(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unique_id' => (string) $row['unique_id'],
            'door_id' => (int) $row['door_id'],
            'door_name' => isset($row['door_name']) ? (string) $row['door_name'] : null,
            'key_version' => (int) $row['key_version'],
            'is_active' => (int) $row['is_active'] === 1,
            'last_seen_at' => Util::iso($row['last_seen_at'] ?? null),
            'created_at' => Util::iso($row['created_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function webUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
            'is_active' => (int) $row['is_active'] === 1,
            'last_login_at' => Util::iso($row['last_login_at'] ?? null),
            'password_changed_at' => Util::iso($row['password_changed_at'] ?? null),
            'created_at' => Util::iso($row['created_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function accessLog(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'request_id' => (string) $row['request_id'],
            'occurred_at' => Util::iso((string) $row['occurred_at']),
            'decision' => (string) $row['decision'],
            'reason' => (string) $row['reason'],
            'source' => $row['source'] !== null ? (string) $row['source'] : null,
            'person_id' => $row['person_id'] !== null ? (int) $row['person_id'] : null,
            'person_name' => $row['person_name'] !== null ? (string) $row['person_name'] : null,
            'door_id' => $row['door_id'] !== null ? (int) $row['door_id'] : null,
            'door_name' => $row['door_name'] !== null ? (string) $row['door_name'] : null,
            'device_id' => $row['device_id'] !== null ? (int) $row['device_id'] : null,
            'device_name' => $row['device_name'] !== null ? (string) $row['device_name'] : null,
            'card_number_masked' => Util::maskCard($row['card_number_snapshot'] ?? null),
            'card_last4' => $row['card_number_snapshot'] !== null
                ? substr((string) $row['card_number_snapshot'], -4)
                : null,
            'unlock_seconds' => $row['unlock_seconds'] !== null ? (int) $row['unlock_seconds'] : null,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function audit(array $row): array
    {
        $decode = static function (mixed $value): mixed {
            if (!is_string($value) || $value === '') {
                return null;
            }
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        };
        return [
            'id' => (int) $row['id'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            'actor_username' => $row['actor_username_snapshot'] !== null ? (string) $row['actor_username_snapshot'] : null,
            'action' => (string) $row['action'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => $row['entity_id'] !== null ? (string) $row['entity_id'] : null,
            'before' => $decode($row['before_json'] ?? null),
            'after' => $decode($row['after_json'] ?? null),
            'request_id' => (string) $row['request_id'],
            'ip' => $row['ip'] !== null ? (string) $row['ip'] : null,
            'created_at' => Util::iso((string) $row['created_at']),
        ];
    }

    /** @return array<string,mixed> */
    public static function activePerson(int $id, bool $allowArchived = false): array
    {
        $person = Db::one('SELECT * FROM persons WHERE id = ?', [$id]);
        if ($person === null || (!$allowArchived && $person['archived_at'] !== null)) {
            throw ApiError::notFound('Person not found.');
        }
        return $person;
    }

    /** @return array<string,mixed> */
    public static function activeDoor(int $id, bool $allowArchived = false): array
    {
        $door = Db::one('SELECT * FROM doors WHERE id = ?', [$id]);
        if ($door === null || (!$allowArchived && $door['archived_at'] !== null)) {
            throw ApiError::notFound('Door not found.');
        }
        return $door;
    }

    /** @return array<string,mixed> */
    public static function activeGroup(int $id, bool $allowArchived = false): array
    {
        $group = Db::one('SELECT * FROM person_groups WHERE id = ?', [$id]);
        if ($group === null || (!$allowArchived && $group['archived_at'] !== null)) {
            throw ApiError::notFound('Group not found.');
        }
        return $group;
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    public static function idList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw ApiError::validation($field, 'This field must be a list of identifiers.');
        }
        $ids = [];
        foreach ($value as $id) {
            $ids[] = Validator::id($id, $field);
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<string,mixed> */
    public static function pageMeta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    public static function like(string $term): string
    {
        return '%' . str_replace(['%', '_'], '', $term) . '%';
    }

    /**
     * Neutralise spreadsheet formula injection in exported CSV cells.
     *
     * Excel/LibreOffice interpret a cell starting with =, +, -, @ (or a
     * leading tab/CR) as a formula. Prefixing an apostrophe forces text.
     */
    public static function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            return "'" . $text;
        }
        return $text;
    }

    /** @return array<int,string> */
    public static function placeholders(array $values): array
    {
        return array_fill(0, count($values), '?');
    }
}
