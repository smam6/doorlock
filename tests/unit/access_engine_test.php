<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\AccessEngine;
use DoorLock\Util;

test('ENG-001', 'Valid active card with individual permission is granted', function (): void {
    $d = AccessEngine::decide(base_context());
    Assert::true($d['granted']);
    Assert::same(AccessEngine::REASON_GRANTED, $d['reason']);
    Assert::same('individual', $d['source']);
    Assert::same(5, $d['unlock_seconds']);
}, ['engine']);

test('ENG-002', 'Unknown card is denied with unknown_card', function (): void {
    $c = base_context();
    $c['card'] = null;
    $c['person'] = null;
    $d = AccessEngine::decide($c);
    Assert::false($d['granted']);
    Assert::same(AccessEngine::REASON_UNKNOWN_CARD, $d['reason']);
});

test('ENG-003', 'Revoked card is denied', function (): void {
    $c = base_context();
    $c['card']['status'] = 'revoked';
    Assert::same(AccessEngine::REASON_CARD_REVOKED, AccessEngine::decide($c)['reason']);
});

test('ENG-004', 'Lost card is denied with a distinct reason', function (): void {
    $c = base_context();
    $c['card']['status'] = 'lost';
    Assert::same(AccessEngine::REASON_CARD_LOST, AccessEngine::decide($c)['reason']);
});

test('ENG-005', 'Disabled person is denied even with permission', function (): void {
    $c = base_context();
    $c['person']['enabled'] = 0;
    Assert::same(AccessEngine::REASON_PERSON_DISABLED, AccessEngine::decide($c)['reason']);
});

test('ENG-006', 'Archived person is denied', function (): void {
    $c = base_context();
    $c['person']['archived_at'] = '2026-01-01 00:00:00.000';
    Assert::same(AccessEngine::REASON_PERSON_DISABLED, AccessEngine::decide($c)['reason']);
});

test('ENG-007', 'Unknown door is denied', function (): void {
    $c = base_context();
    $c['door'] = null;
    Assert::same(AccessEngine::REASON_UNKNOWN_DOOR, AccessEngine::decide($c)['reason']);
});

test('ENG-008', 'Inactive door is denied', function (): void {
    $c = base_context();
    $c['door']['is_active'] = 0;
    Assert::same(AccessEngine::REASON_DOOR_INACTIVE, AccessEngine::decide($c)['reason']);
});

test('ENG-009', 'Archived door is denied', function (): void {
    $c = base_context();
    $c['door']['archived_at'] = '2026-01-01 00:00:00.000';
    Assert::same(AccessEngine::REASON_DOOR_INACTIVE, AccessEngine::decide($c)['reason']);
});

test('ENG-010', 'Device assigned to a different door is denied', function (): void {
    $c = base_context();
    $c['device']['door_id'] = 2;
    Assert::same(AccessEngine::REASON_DEVICE_DOOR_MISMATCH, AccessEngine::decide($c)['reason']);
});

test('ENG-011', 'Inactive device is denied', function (): void {
    $c = base_context();
    $c['device']['is_active'] = 0;
    Assert::same(AccessEngine::REASON_DEVICE_DISABLED, AccessEngine::decide($c)['reason']);
});

test('ENG-012', 'No permission is denied', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    Assert::same(AccessEngine::REASON_NO_PERMISSION, AccessEngine::decide($c)['reason']);
});

test('ENG-013', 'Explicit deny overrides an individual allow', function (): void {
    $c = base_context();
    $c['permissions'] = [['door_id' => 1, 'effect' => 'deny']];
    Assert::same(AccessEngine::REASON_EXPLICIT_DENY, AccessEngine::decide($c)['reason']);
});

test('ENG-014', 'Explicit deny overrides a group allow', function (): void {
    $c = base_context();
    $c['permissions'] = [['door_id' => 1, 'effect' => 'deny']];
    $c['group_permissions'] = [['door_id' => 1]];
    Assert::same(AccessEngine::REASON_EXPLICIT_DENY, AccessEngine::decide($c)['reason']);
});

test('ENG-015', 'Group permission grants access when no individual permission exists', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    $c['group_permissions'] = [['door_id' => 1]];
    $d = AccessEngine::decide($c);
    Assert::true($d['granted']);
    Assert::same('group', $d['source']);
});

test('ENG-016', 'Enabled schedule outside its window denies base permission', function (): void {
    $c = base_context();
    $c['schedules'] = [[
        'days' => '3', 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'enabled' => 1,
    ]];
    $c['now_local'] = new \DateTimeImmutable('2026-05-04 12:00:00', Util::appTimezone());
    $c['now_utc'] = $c['now_local']->setTimezone(new \DateTimeZone('UTC'));
    Assert::same(AccessEngine::REASON_OUTSIDE_SCHEDULE, AccessEngine::decide($c)['reason']);
});

test('ENG-017', 'Enabled schedule inside its window grants base permission', function (): void {
    $c = base_context();
    $c['schedules'] = [[
        'days' => '3', 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'enabled' => 1,
    ]];
    Assert::true(AccessEngine::decide($c)['granted']);
});

test('ENG-018', 'Disabled schedule is ignored and does not restrict access', function (): void {
    $c = base_context();
    $c['schedules'] = [[
        'days' => '3', 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'enabled' => 0,
    ]];
    $c['now_local'] = new \DateTimeImmutable('2026-05-04 23:00:00', Util::appTimezone());
    $c['now_utc'] = $c['now_local']->setTimezone(new \DateTimeZone('UTC'));
    $d = AccessEngine::decide($c);
    Assert::true($d['granted']);
    Assert::same('individual', $d['source']);
});

test('ENG-019', 'Temporary grant allows access and bypasses schedules', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    $c['schedules'] = [[
        'days' => '3', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'enabled' => 1,
    ]];
    $c['now_local'] = new \DateTimeImmutable('2026-05-04 23:00:00', Util::appTimezone());
    $c['now_utc'] = $c['now_local']->setTimezone(new \DateTimeZone('UTC'));
    $c['grants'] = [[
        'id' => 42,
        'valid_from' => Util::toDb($c['now_utc']->modify('-1 hour')),
        'valid_to' => Util::toDb($c['now_utc']->modify('+1 hour')),
        'max_uses' => null,
        'used_count' => 0,
        'revoked_at' => null,
    ]];
    $d = AccessEngine::decide($c);
    Assert::true($d['granted']);
    Assert::same('temporary', $d['source']);
    Assert::same(42, $d['grant_id']);
});

test('ENG-020', 'Expired temporary grant does not grant access', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    $c['grants'] = [[
        'id' => 7,
        'valid_from' => Util::toDb($c['now_utc']->modify('-3 hours')),
        'valid_to' => Util::toDb($c['now_utc']->modify('-1 hour')),
        'max_uses' => null,
        'used_count' => 0,
        'revoked_at' => null,
    ]];
    Assert::same(AccessEngine::REASON_GRANT_EXPIRED, AccessEngine::decide($c)['reason']);
});

test('ENG-021', 'Exhausted single-use grant does not grant access', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    $c['grants'] = [[
        'id' => 8,
        'valid_from' => Util::toDb($c['now_utc']->modify('-1 hour')),
        'valid_to' => Util::toDb($c['now_utc']->modify('+1 hour')),
        'max_uses' => 1,
        'used_count' => 1,
        'revoked_at' => null,
    ]];
    Assert::same(AccessEngine::REASON_GRANT_EXHAUSTED, AccessEngine::decide($c)['reason']);
});

test('ENG-022', 'Revoked temporary grant does not grant access', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    $c['grants'] = [[
        'id' => 9,
        'valid_from' => Util::toDb($c['now_utc']->modify('-1 hour')),
        'valid_to' => Util::toDb($c['now_utc']->modify('+1 hour')),
        'max_uses' => null,
        'used_count' => 0,
        'revoked_at' => Util::toDb($c['now_utc']->modify('-10 minutes')),
    ]];
    Assert::same(AccessEngine::REASON_GRANT_REVOKED, AccessEngine::decide($c)['reason']);
});

test('ENG-023', 'Expired grant does not block an otherwise valid base permission', function (): void {
    $c = base_context();
    $c['grants'] = [[
        'id' => 10,
        'valid_from' => Util::toDb($c['now_utc']->modify('-3 hours')),
        'valid_to' => Util::toDb($c['now_utc']->modify('-1 hour')),
        'max_uses' => null,
        'used_count' => 0,
        'revoked_at' => null,
    ]];
    $d = AccessEngine::decide($c);
    Assert::true($d['granted']);
    Assert::same('individual', $d['source']);
});

test('ENG-024', 'Explicit deny blocks an eligible temporary grant', function (): void {
    $c = base_context();
    $c['permissions'] = [['door_id' => 1, 'effect' => 'deny']];
    $c['grants'] = [[
        'id' => 11,
        'valid_from' => Util::toDb($c['now_utc']->modify('-1 hour')),
        'valid_to' => Util::toDb($c['now_utc']->modify('+1 hour')),
        'max_uses' => null,
        'used_count' => 0,
        'revoked_at' => null,
    ]];
    Assert::same(AccessEngine::REASON_EXPLICIT_DENY, AccessEngine::decide($c)['reason']);
});

test('ENG-025', 'Unlock duration is clamped to the safe 1-30 second range', function (): void {
    $c = base_context();
    $c['door']['unlock_seconds'] = 300;
    Assert::same(30, AccessEngine::decide($c)['unlock_seconds']);
    $c['door']['unlock_seconds'] = 0;
    Assert::same(1, AccessEngine::decide($c)['unlock_seconds']);
});

test('ENG-026', 'Denied decisions never carry an unlock duration', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    Assert::same(0, AccessEngine::decide($c)['unlock_seconds']);
});

test('ENG-027', 'Temporary grant at the exact validity boundaries', function (): void {
    $c = base_context();
    $c['permissions'] = [];
    $from = $c['now_utc'];
    $c['grants'] = [[
        'id' => 12,
        'valid_from' => Util::toDb($from),
        'valid_to' => Util::toDb($from->modify('+1 hour')),
        'max_uses' => null,
        'used_count' => 0,
        'revoked_at' => null,
    ]];
    Assert::true(AccessEngine::decide($c)['granted'], 'valid_from is inclusive');

    $c['grants'][0]['valid_from'] = Util::toDb($from->modify('-1 hour'));
    $c['grants'][0]['valid_to'] = Util::toDb($from);
    Assert::same(AccessEngine::REASON_GRANT_EXPIRED, AccessEngine::decide($c)['reason'], 'valid_to is exclusive');
});

test('ENG-028', 'Unknown device id in context still evaluates the card', function (): void {
    $c = base_context();
    $c['device'] = null;
    Assert::true(AccessEngine::decide($c)['granted']);
});
