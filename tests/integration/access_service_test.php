<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\AccessEngine;
use DoorLock\AccessService;
use DoorLock\Db;
use DoorLock\Util;

/**
 * End-to-end access decisions against a real (SQLite) schema: engine data
 * loading, grant consumption, access logging and transactional behaviour.
 */

function decision(array $seed, string $card, string $doorKey, string $deviceKey, string $when): array
{
    $now = new \DateTimeImmutable($when, Util::appTimezone());
    return AccessService::authorize(
        device_row($seed['devices'][$deviceKey]),
        access_body($card, $seed['doors'][$doorKey]),
        'req-' . bin2hex(random_bytes(4)),
        '127.0.0.1',
        $now
    );
}

function last_log(): array
{
    $row = Db::one('SELECT * FROM access_logs ORDER BY id DESC LIMIT 1');
    if ($row === null) {
        throw new \RuntimeException('Expected an access log row');
    }
    return $row;
}

function add_schedule(int $personId, int $doorId, string $days, string $start, string $end): void
{
    $now = Util::toDb(Util::utcNow());
    Db::exec(
        'INSERT INTO schedules (person_id, door_id, days, start_time, end_time, enabled, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
        [$personId, $doorId, $days, $start, $end, $now, $now]
    );
}

function add_grant(int $personId, int $doorId, string $from, string $to, ?int $maxUses = null): int
{
    $now = Util::toDb(Util::utcNow());
    return Db::insert(
        'INSERT INTO temporary_grants (person_id, door_id, valid_from, valid_to, max_uses, used_count, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?)',
        [$personId, $doorId, $from, $to, $maxUses, $now, $now]
    );
}

test('AS-001', 'Individual allow grants access and records the decision', function (): void {
    $seed = reset_data();
    $result = decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 10:00:00');
    Assert::true($result['granted']);
    Assert::same(AccessEngine::REASON_GRANTED, $result['reason']);
    Assert::same('individual', $result['source']);
    Assert::same(5, $result['unlock_seconds']);
    $log = last_log();
    Assert::same('granted', $log['decision']);
    Assert::same((string) $seed['persons']['alice'], (string) $log['person_id']);
    Assert::same('Alice Example', $log['person_name_snapshot']);
    Assert::same((string) $seed['doors']['main'], (string) $log['door_id']);
});

test('AS-002', 'Disabled person is denied before any permission is considered', function (): void {
    $seed = reset_data();
    $result = decision($seed, '04DDEEFF', 'main', 'main', '2026-05-04 10:00:00');
    Assert::false($result['granted']);
    Assert::same(AccessEngine::REASON_PERSON_DISABLED, $result['reason']);
    Assert::same('denied', last_log()['decision']);
});

test('AS-003', 'Revoked and lost cards are denied with distinct reasons', function (): void {
    $seed = reset_data();
    Assert::same(AccessEngine::REASON_CARD_REVOKED, decision($seed, '04CCDDEE', 'main', 'main', '2026-05-04 10:00:00')['reason']);
    Assert::same(AccessEngine::REASON_CARD_LOST, decision($seed, '04FFEEDD', 'main', 'main', '2026-05-04 10:00:00')['reason']);
});

test('AS-004', 'Unknown card is denied but still logged', function (): void {
    $seed = reset_data();
    $result = decision($seed, 'DEADBEEF', 'main', 'main', '2026-05-04 10:00:00');
    Assert::same(AccessEngine::REASON_UNKNOWN_CARD, $result['reason']);
    $log = last_log();
    Assert::same('DEADBEEF', $log['card_number_snapshot']);
    Assert::null($log['person_id']);
});

test('AS-005', 'Inactive door and device/door mismatch fail closed', function (): void {
    $seed = reset_data();
    Assert::same(
        AccessEngine::REASON_DOOR_INACTIVE,
        decision($seed, '04AABBCC', 'inactive', 'main', '2026-05-04 10:00:00')['reason']
    );
    Assert::same(
        AccessEngine::REASON_DEVICE_DOOR_MISMATCH,
        decision($seed, '04AABBCC', 'server', 'main', '2026-05-04 10:00:00')['reason']
    );
});

test('AS-006', 'An explicit deny overrides an individual allow and a group allow', function (): void {
    $seed = reset_data();
    $now = Util::toDb(Util::utcNow());
    Db::exec(
        "UPDATE permissions SET effect = 'deny', updated_at = ? WHERE person_id = ? AND door_id = ?",
        [$now, $seed['persons']['alice'], $seed['doors']['main']]
    );
    Assert::same(
        AccessEngine::REASON_EXPLICIT_DENY,
        decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 10:00:00')['reason']
    );
});

test('AS-007', 'An enabled schedule restricts an otherwise allowed person', function (): void {
    $seed = reset_data();
    add_schedule($seed['persons']['alice'], $seed['doors']['main'], '3', '09:00:00', '11:00:00');
    Assert::same(
        AccessEngine::REASON_OUTSIDE_SCHEDULE,
        decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 12:00:00')['reason']
    );
    Assert::true(decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 10:00:00')['granted']);
});

test('AS-008', 'A group grant is enough on its own and is reported as source=group', function (): void {
    $seed = reset_data();
    Db::exec(
        'DELETE FROM permissions WHERE person_id = ? AND door_id = ?',
        [$seed['persons']['alice'], $seed['doors']['server']]
    );
    $result = decision($seed, '04AABBCC', 'server', 'server', '2026-05-04 10:00:00');
    Assert::true($result['granted']);
    Assert::same('group', $result['source']);
});

test('AS-009', 'An active temporary grant bypasses schedules and is consumed atomically', function (): void {
    $seed = reset_data();
    Db::exec('DELETE FROM permissions WHERE person_id = ? AND door_id = ?', [$seed['persons']['alice'], $seed['doors']['main']]);
    add_schedule($seed['persons']['alice'], $seed['doors']['main'], '3', '09:00:00', '11:00:00');
    $grantId = add_grant(
        $seed['persons']['alice'],
        $seed['doors']['main'],
        '2026-05-04 00:00:00',
        '2026-05-05 00:00:00',
        2
    );
    $first = decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 12:00:00');
    Assert::true($first['granted']);
    Assert::same('temporary', $first['source']);
    Assert::same($grantId, Db::one('SELECT id FROM temporary_grants WHERE id = ?', [$grantId])['id']);
    Assert::same(1, (int) Db::scalar('SELECT used_count FROM temporary_grants WHERE id = ?', [$grantId]));

    $second = decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 12:01:00');
    Assert::true($second['granted']);
    Assert::same(2, (int) Db::scalar('SELECT used_count FROM temporary_grants WHERE id = ?', [$grantId]));

    $third = decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 12:02:00');
    Assert::false($third['granted']);
    Assert::same(AccessEngine::REASON_GRANT_EXHAUSTED, $third['reason']);
});

test('AS-010', 'A future grant does not grant access early', function (): void {
    $seed = reset_data();
    Db::exec('DELETE FROM permissions WHERE person_id = ? AND door_id = ?', [$seed['persons']['alice'], $seed['doors']['main']]);
    add_grant($seed['persons']['alice'], $seed['doors']['main'], '2026-05-06 00:00:00', '2026-05-07 00:00:00', 1);
    $result = decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 12:00:00');
    Assert::false($result['granted']);
    Assert::same(AccessEngine::REASON_NO_PERMISSION, $result['reason']);
});

test('AS-011', 'A revoked grant is reported distinctly when it is the only entitlement', function (): void {
    $seed = reset_data();
    Db::exec('DELETE FROM permissions WHERE person_id = ? AND door_id = ?', [$seed['persons']['alice'], $seed['doors']['main']]);
    $grantId = add_grant($seed['persons']['alice'], $seed['doors']['main'], '2026-05-01 00:00:00', '2026-05-09 00:00:00', 1);
    Db::exec('UPDATE temporary_grants SET revoked_at = ? WHERE id = ?', [Util::toDb(Util::utcNow()), $grantId]);
    Assert::same(
        AccessEngine::REASON_GRANT_REVOKED,
        decision($seed, '04AABBCC', 'main', 'main', '2026-05-04 12:00:00')['reason']
    );
});
