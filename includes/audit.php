<?php

declare(strict_types=1);

namespace DoorLock;

/** Append-only administrative audit trail. */
final class Audit
{
    private const SENSITIVE_KEY = '/(pass(word)?|secret|token|authorization|cookie|app_key|device_key|signature|hash)/i';

    /**
     * Record a privileged or security-relevant action.
     *
     * Callers should execute this inside the same transaction as the change it
     * describes, so a failed audit write rolls the change back.
     */
    public static function record(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $extra = []
    ): void {
        $actorId = null;
        $actorName = null;
        if (!Util::isCli() && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $actorId = (int) $_SESSION['user_id'];
            $actorName = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
        }
        if (isset($extra['actor_user_id']) && is_int($extra['actor_user_id'])) {
            $actorId = $extra['actor_user_id'];
        }
        if (isset($extra['actor_username']) && is_string($extra['actor_username'])) {
            $actorName = $extra['actor_username'];
        }

        $beforeJson = $before !== null ? self::encode($before) : null;
        $afterJson = $after !== null ? self::encode($after) : null;

        Db::exec(
            'INSERT INTO audit_logs (actor_user_id, actor_username_snapshot, action, entity_type, entity_id, before_json, after_json, request_id, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $actorId,
                $actorName,
                Util::truncate($action, 64),
                Util::truncate($entityType, 32),
                $entityId !== null ? Util::truncate($entityId, 64) : null,
                $beforeJson,
                $afterJson,
                RequestContext::requestId() ?? Util::randomHex(16),
                RequestContext::ip(),
                Util::toDb(Util::utcNow()),
            ]
        );
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        $clean = self::strip($data);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json)) {
            return '{"error":"unserializable"}';
        }
        if (strlen($json) > 8000) {
            return substr($json, 0, 7996) . '...}';
        }
        return $json;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function strip(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::strip($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}

/** Access-event writer used by the device authorization flow. */
final class AccessLog
{
    /**
     * Insert an access event.
     *
     * @param array<string,mixed> $row
     */
    public static function insert(array $row): void
    {
        Db::exec(
            'INSERT INTO access_logs
                (request_id, occurred_at, person_id, person_name_snapshot, card_id, card_number_snapshot,
                 door_id, requested_door_id, device_id, decision, reason, source, unlock_seconds, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['request_id'],
                $row['occurred_at'] ?? Util::toDb(Util::utcNow()),
                $row['person_id'] ?? null,
                $row['person_name_snapshot'] ?? null,
                $row['card_id'] ?? null,
                $row['card_number_snapshot'] ?? null,
                $row['door_id'] ?? null,
                $row['requested_door_id'] ?? null,
                $row['device_id'] ?? null,
                $row['decision'],
                $row['reason'],
                $row['source'] ?? null,
                $row['unlock_seconds'] ?? null,
                $row['ip'] ?? null,
            ]
        );
    }
}
