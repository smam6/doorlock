<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Pure access-decision logic.
 *
 * This class performs no I/O so it can be exhaustively unit-tested. All data
 * is supplied by AccessService after it has been read inside a transaction.
 *
 * Documented precedence (highest first):
 *   1. Door must exist, be active and not archived.
 *   2. Device must be active and assigned to the requested door.
 *   3. Card must exist and be active (not revoked/lost), person must be active.
 *   4. An individual explicit deny blocks every grant (including temporary
 *      grants and group permissions).
 *   5. An eligible temporary grant allows access and bypasses schedules; its
 *      validity window is the restriction.
 *   6. Otherwise an individual allow or an enabled-group allow is required.
 *   7. If at least one enabled schedule exists for the person/door pair, the
 *      current local time must fall inside one of them. Disabled schedules are
 *      ignored entirely: they neither grant nor restrict access.
 *
 * Schedule day numbers: 1 = Saturday ... 7 = Friday (the numbering used by the
 * existing Persian UI). A schedule with start == end means the full day.
 * Overnight schedules belong to their start day and end on the following day.
 */
final class AccessEngine
{
    public const REASON_GRANTED = 'granted';
    public const REASON_UNKNOWN_CARD = 'unknown_card';
    public const REASON_CARD_REVOKED = 'card_revoked';
    public const REASON_CARD_LOST = 'card_lost';
    public const REASON_PERSON_DISABLED = 'person_disabled';
    public const REASON_UNKNOWN_DOOR = 'unknown_door';
    public const REASON_DOOR_INACTIVE = 'door_inactive';
    public const REASON_DEVICE_DOOR_MISMATCH = 'device_door_mismatch';
    public const REASON_DEVICE_DISABLED = 'device_disabled';
    public const REASON_NO_PERMISSION = 'no_permission';
    public const REASON_OUTSIDE_SCHEDULE = 'outside_schedule';
    public const REASON_EXPLICIT_DENY = 'explicit_deny';
    public const REASON_GRANT_EXPIRED = 'grant_expired';
    public const REASON_GRANT_EXHAUSTED = 'grant_exhausted';
    public const REASON_GRANT_REVOKED = 'grant_revoked';
    public const REASON_SERVICE_UNAVAILABLE = 'service_unavailable';

    /**
     * @param array<string,mixed> $ctx
     * @return array{granted:bool,reason:string,source:string,grant_id:?int,unlock_seconds:int}
     */
    public static function decide(array $ctx): array
    {
        /** @var \DateTimeImmutable $nowUtc */
        $nowUtc = $ctx['now_utc'];
        /** @var \DateTimeImmutable $nowLocal */
        $nowLocal = $ctx['now_local'];
        $door = $ctx['door'] ?? null;
        $device = $ctx['device'] ?? null;
        $card = $ctx['card'] ?? null;
        $person = $ctx['person'] ?? null;
        $requestedDoorId = isset($ctx['requested_door_id']) ? (int) $ctx['requested_door_id'] : null;

        $deny = static function (string $reason): array {
            return [
                'granted' => false,
                'reason' => $reason,
                'source' => 'none',
                'grant_id' => null,
                'unlock_seconds' => 0,
            ];
        };

        if (!is_array($door)) {
            return $deny(self::REASON_UNKNOWN_DOOR);
        }
        if ((int) ($door['is_active'] ?? 0) !== 1 || ($door['archived_at'] ?? null) !== null) {
            return $deny(self::REASON_DOOR_INACTIVE);
        }
        if (is_array($device)) {
            if ((int) ($device['is_active'] ?? 0) !== 1) {
                return $deny(self::REASON_DEVICE_DISABLED);
            }
            if ($requestedDoorId !== null && (int) ($device['door_id'] ?? 0) !== $requestedDoorId) {
                return $deny(self::REASON_DEVICE_DOOR_MISMATCH);
            }
        }
        if (!is_array($card)) {
            return $deny(self::REASON_UNKNOWN_CARD);
        }
        $status = (string) ($card['status'] ?? 'revoked');
        if ($status === 'lost') {
            return $deny(self::REASON_CARD_LOST);
        }
        if ($status !== 'active') {
            return $deny(self::REASON_CARD_REVOKED);
        }
        if (!is_array($person)) {
            return $deny(self::REASON_UNKNOWN_CARD);
        }
        if ((int) ($person['enabled'] ?? 0) !== 1 || ($person['archived_at'] ?? null) !== null) {
            return $deny(self::REASON_PERSON_DISABLED);
        }

        $unlockSeconds = self::unlockSeconds($door);
        $allow = [
            'granted' => true,
            'reason' => self::REASON_GRANTED,
            'source' => 'individual',
            'grant_id' => null,
            'unlock_seconds' => $unlockSeconds,
        ];

        // 4. Explicit individual deny blocks every other rule.
        foreach (($ctx['permissions'] ?? []) as $permission) {
            if (($permission['effect'] ?? '') === 'deny') {
                return $deny(self::REASON_EXPLICIT_DENY);
            }
        }

        // 5. Eligible temporary grants bypass schedules.
        $grants = is_array($ctx['grants'] ?? null) ? $ctx['grants'] : [];
        $eligible = [];
        $sawExpired = false;
        $sawExhausted = false;
        $sawRevoked = false;
        foreach ($grants as $grant) {
            if (($grant['revoked_at'] ?? null) !== null) {
                $sawRevoked = true;
                continue;
            }
            $from = Util::fromDb((string) $grant['valid_from']);
            $to = Util::fromDb((string) $grant['valid_to']);
            if ($from === null || $to === null) {
                continue;
            }
            if ($nowUtc < $from) {
                // Not yet valid: ignore it. Another grant may still allow access.
                continue;
            }
            if ($nowUtc >= $to) {
                $sawExpired = true;
                continue;
            }
            $maxUses = $grant['max_uses'] !== null ? (int) $grant['max_uses'] : null;
            $used = (int) ($grant['used_count'] ?? 0);
            if ($maxUses !== null && $used >= $maxUses) {
                $sawExhausted = true;
                continue;
            }
            $eligible[] = $grant;
        }
        if ($eligible !== []) {
            usort($eligible, static function (array $a, array $b): int {
                $cmp = strcmp((string) $a['valid_to'], (string) $b['valid_to']);
                return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
            });
            $chosen = $eligible[0];
            $allow['source'] = 'temporary';
            $allow['grant_id'] = (int) $chosen['id'];
            return $allow;
        }

        // 6. Base entitlement: individual allow or enabled-group allow.
        $individualAllowed = false;
        foreach (($ctx['permissions'] ?? []) as $permission) {
            if (($permission['effect'] ?? '') === 'allow') {
                $individualAllowed = true;
                break;
            }
        }
        $groupAllowed = false;
        foreach (($ctx['group_permissions'] ?? []) as $permission) {
            if ($requestedDoorId !== null && (int) ($permission['door_id'] ?? 0) !== $requestedDoorId) {
                continue;
            }
            $groupAllowed = true;
            break;
        }

        if (!$individualAllowed && !$groupAllowed) {
            if ($sawExhausted) {
                return $deny(self::REASON_GRANT_EXHAUSTED);
            }
            if ($sawExpired) {
                return $deny(self::REASON_GRANT_EXPIRED);
            }
            if ($sawRevoked) {
                return $deny(self::REASON_GRANT_REVOKED);
            }
            return $deny(self::REASON_NO_PERMISSION);
        }
        $allow['source'] = $individualAllowed ? 'individual' : 'group';

        // 7. Enabled schedules restrict the base entitlement.
        $schedules = is_array($ctx['schedules'] ?? null) ? $ctx['schedules'] : [];
        $enabledSchedules = array_values(array_filter(
            $schedules,
            static fn ($schedule) => (int) ($schedule['enabled'] ?? 0) === 1
        ));
        if ($enabledSchedules !== []) {
            $matched = false;
            foreach ($enabledSchedules as $schedule) {
                if (self::scheduleMatches($schedule, $nowLocal)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return $deny(self::REASON_OUTSIDE_SCHEDULE);
            }
        }

        return $allow;
    }

    /** @param array<string,mixed> $schedule */
    public static function scheduleMatches(array $schedule, \DateTimeImmutable $nowLocal): bool
    {
        if ((int) ($schedule['enabled'] ?? 0) !== 1) {
            return false;
        }
        $days = self::parseDays((string) ($schedule['days'] ?? ''));
        if ($days === []) {
            return false;
        }
        $start = self::seconds((string) ($schedule['start_time'] ?? ''));
        $end = self::seconds((string) ($schedule['end_time'] ?? ''));
        if ($start === null || $end === null) {
            return false;
        }
        $now = ((int) $nowLocal->format('G')) * 3600 + ((int) $nowLocal->format('i')) * 60 + (int) $nowLocal->format('s');
        $today = Util::weekdayNumber($nowLocal);

        if ($start === $end) {
            return in_array($today, $days, true);
        }
        if ($start < $end) {
            return in_array($today, $days, true) && $now >= $start && $now < $end;
        }
        // Overnight: the window starts on a selected day and ends the next day.
        if (in_array($today, $days, true) && $now >= $start) {
            return true;
        }
        $yesterday = $today === 1 ? 7 : $today - 1;
        return in_array($yesterday, $days, true) && $now < $end;
    }

    /** @return array<int,int> */
    public static function parseDays(string $days): array
    {
        $result = [];
        foreach (Util::csvToArray($days) as $day) {
            if (preg_match('/^[1-7]$/', $day) === 1) {
                $result[(int) $day] = (int) $day;
            }
        }
        $values = array_values($result);
        sort($values, SORT_NUMERIC);
        return $values;
    }

    private static function seconds(string $time): ?int
    {
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $m) !== 1) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $second = (int) ($m[3] ?? 0);
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        return $hour * 3600 + $minute * 60 + $second;
    }

    /** @param array<string,mixed> $door */
    private static function unlockSeconds(array $door): int
    {
        $value = (int) ($door['unlock_seconds'] ?? 5);
        return Util::clampInt($value, 1, 30);
    }
}
