<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Orchestrates a device authorization request.
 *
 * The decision, single-use grant consumption and the access-log insert all
 * happen inside one database transaction. If anything fails the transaction is
 * rolled back, the device receives a 503 and the door stays locked.
 */
final class AccessService
{
    /**
     * @param array<string,mixed> $device authenticated device row
     * @param string $requestId validated correlation id
     * @return array{granted:bool,reason:string,source:string,unlock_seconds:int,request_id:string}
     */
    public static function authorize(
        array $device,
        string $body,
        string $requestId,
        string $ip,
        ?\DateTimeImmutable $now = null
    ): array {
        $payload = Util::jsonDecodeObject($body);
        $doorId = Validator::id($payload['door_id'] ?? null, 'door_id');
        $cardNumber = Validator::cardNumber($payload['card_number'] ?? null);

        $nowUtc = ($now ?? Util::utcNow())->setTimezone(new \DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone(Util::appTimezone());

        return Db::tx(function () use ($device, $doorId, $cardNumber, $requestId, $ip, $nowUtc, $nowLocal): array {
            $door = Db::one('SELECT * FROM doors WHERE id = ?', [$doorId]);
            $card = Db::one('SELECT * FROM cards WHERE card_number = ?', [$cardNumber]);
            $person = null;
            if ($card !== null) {
                $person = Db::one('SELECT * FROM persons WHERE id = ?', [(int) $card['person_id']]);
            }

            $permissions = [];
            $groupPermissions = [];
            $schedules = [];
            $grants = [];
            if ($person !== null) {
                $permissions = Db::all(
                    'SELECT door_id, effect FROM permissions WHERE person_id = ? AND door_id = ?',
                    [(int) $person['id'], $doorId]
                );
                $groupPermissions = Db::all(
                    'SELECT gp.door_id
                       FROM group_permissions gp
                       JOIN person_groups g ON g.id = gp.group_id
                       JOIN group_members gm ON gm.group_id = gp.group_id
                      WHERE gm.person_id = ? AND g.enabled = 1 AND g.archived_at IS NULL AND gp.door_id = ?',
                    [(int) $person['id'], $doorId]
                );
                $schedules = Db::all(
                    'SELECT days, start_time, end_time, enabled FROM schedules WHERE person_id = ? AND door_id = ?',
                    [(int) $person['id'], $doorId]
                );
                $grants = Db::all(
                    'SELECT id, valid_from, valid_to, max_uses, used_count, revoked_at
                       FROM temporary_grants
                      WHERE person_id = ? AND door_id = ?',
                    [(int) $person['id'], $doorId]
                );
            }

            $decision = AccessEngine::decide([
                'now_utc' => $nowUtc,
                'now_local' => $nowLocal,
                'door' => $door,
                'device' => $device,
                'card' => $card,
                'person' => $person,
                'permissions' => $permissions,
                'group_permissions' => $groupPermissions,
                'schedules' => $schedules,
                'grants' => $grants,
                'requested_door_id' => $doorId,
            ]);

            if ($decision['granted'] && $decision['grant_id'] !== null) {
                $consumed = Db::exec(
                    'UPDATE temporary_grants
                        SET used_count = used_count + 1, last_used_at = ?, updated_at = ?
                      WHERE id = ?
                        AND revoked_at IS NULL
                        AND valid_from <= ?
                        AND valid_to > ?
                        AND (max_uses IS NULL OR used_count < max_uses)',
                    [
                        Util::toDb($nowUtc),
                        Util::toDb($nowUtc),
                        $decision['grant_id'],
                        Util::toDb($nowUtc),
                        Util::toDb($nowUtc),
                    ]
                );
                if ($consumed !== 1) {
                    // A concurrent request consumed the grant first: fail closed.
                    $decision['granted'] = false;
                    $decision['reason'] = AccessEngine::REASON_GRANT_EXHAUSTED;
                    $decision['source'] = 'none';
                    $decision['grant_id'] = null;
                    $decision['unlock_seconds'] = 0;
                }
            }

            AccessLog::insert([
                'request_id' => $requestId,
                'occurred_at' => Util::toDb($nowUtc),
                'person_id' => $person !== null ? (int) $person['id'] : null,
                'person_name_snapshot' => $person !== null ? (string) $person['name'] : null,
                'card_id' => $card !== null ? (int) $card['id'] : null,
                'card_number_snapshot' => $card !== null ? (string) $card['card_number'] : $cardNumber,
                'door_id' => $door !== null ? (int) $door['id'] : null,
                'requested_door_id' => $doorId,
                'device_id' => (int) $device['id'],
                'decision' => $decision['granted'] ? 'granted' : 'denied',
                'reason' => $decision['reason'],
                'source' => $decision['source'],
                'unlock_seconds' => $decision['granted'] ? $decision['unlock_seconds'] : null,
                'ip' => $ip,
            ]);

            if ($decision['granted'] && $card !== null) {
                Db::exec(
                    'UPDATE cards SET last_used_at = ?, updated_at = ? WHERE id = ?',
                    [Util::toDb($nowUtc), Util::toDb($nowUtc), (int) $card['id']]
                );
            }

            Logger::info('access_decision', [
                'device_id' => (int) $device['id'],
                'door_id' => $doorId,
                'card_number' => $cardNumber,
                'decision' => $decision['granted'] ? 'granted' : 'denied',
                'reason' => $decision['reason'],
                'source' => $decision['source'],
            ]);

            return [
                'granted' => (bool) $decision['granted'],
                'reason' => (string) $decision['reason'],
                'source' => (string) $decision['source'],
                'unlock_seconds' => (int) $decision['unlock_seconds'],
                'request_id' => $requestId,
            ];
        });
    }
}
