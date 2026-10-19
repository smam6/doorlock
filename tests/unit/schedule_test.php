<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\AccessEngine;
use DoorLock\Util;

function schedule(string $days, string $start, string $end, int $enabled = 1): array
{
    return ['days' => $days, 'start_time' => $start, 'end_time' => $end, 'enabled' => $enabled];
}

function local(string $value): \DateTimeImmutable
{
    return new \DateTimeImmutable($value, Util::appTimezone());
}

test('SCH-001', 'Same-day window includes start and excludes end', function (): void {
    $s = schedule('3', '09:00:00', '17:00:00'); // Monday
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-04 09:00:00')));
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-04 16:59:59')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-04 08:59:59')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-04 17:00:00')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-05 10:00:00')));
});

test('SCH-002', 'Overnight window matches the evening and the next morning', function (): void {
    $s = schedule('7', '22:00:00', '02:00:00'); // Friday night
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-08 22:00:00')));
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-08 23:59:59')));
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-09 00:00:00')));
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-09 01:59:59')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-09 02:00:00')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-07 23:00:00')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-08 21:59:59')));
});

test('SCH-003', 'Overnight window crossing the Saturday/Friday week boundary', function (): void {
    // Saturday 23:00 -> Sunday 03:00 (day 1), matching Sunday 02:00.
    $s = schedule('1', '23:00:00', '03:00:00');
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-09 23:30:00'))); // Saturday
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-10 02:00:00'))); // Sunday
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-10 03:01:00')));
});

test('SCH-004', 'Start equal to end means the full selected day', function (): void {
    $s = schedule('2', '00:00:00', '00:00:00'); // Sunday
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-10 00:00:00')));
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-10 23:59:59')));
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-11 00:00:00')));
});

test('SCH-005', 'Disabled schedule never matches', function (): void {
    $s = schedule('3', '09:00:00', '17:00:00', 0);
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-04 10:00:00')));
});

test('SCH-006', 'Multiple selected days match independently', function (): void {
    $s = schedule('1,3,7', '08:00:00', '12:00:00');
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-04 09:00:00'))); // Monday = 3
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-08 09:00:00'))); // Friday = 7
    Assert::true(AccessEngine::scheduleMatches($s, local('2026-05-09 09:00:00'))); // Saturday = 1
    Assert::false(AccessEngine::scheduleMatches($s, local('2026-05-06 09:00:00'))); // Wednesday = 5
});

test('SCH-007', 'Weekday numbering matches the Persian UI convention', function (): void {
    Assert::same(1, Util::weekdayNumber(local('2026-05-09 12:00:00'))); // Saturday
    Assert::same(2, Util::weekdayNumber(local('2026-05-10 12:00:00'))); // Sunday
    Assert::same(3, Util::weekdayNumber(local('2026-05-04 12:00:00'))); // Monday
    Assert::same(4, Util::weekdayNumber(local('2026-05-05 12:00:00'))); // Tuesday
    Assert::same(5, Util::weekdayNumber(local('2026-05-06 12:00:00'))); // Wednesday
    Assert::same(6, Util::weekdayNumber(local('2026-05-07 12:00:00'))); // Thursday
    Assert::same(7, Util::weekdayNumber(local('2026-05-08 12:00:00'))); // Friday
});

test('SCH-008', 'Spring-forward DST transition uses wall-clock time', function (): void {
    $tz = new \DateTimeZone('Europe/Amsterdam');
    $s = ['days' => '2', 'start_time' => '01:30:00', 'end_time' => '03:30:00', 'enabled' => 1];
    // 2026-03-29 is a Sunday (day 2); 02:00-03:00 does not exist locally.
    Assert::true(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-03-29 01:45:00', $tz)));
    Assert::true(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-03-29 03:15:00', $tz)));
    Assert::false(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-03-29 04:00:00', $tz)));
});

test('SCH-009', 'Fall-back DST transition keeps the overnight window correct', function (): void {
    $tz = new \DateTimeZone('Europe/Amsterdam');
    // Saturday 22:00 -> Sunday 04:00. 2026-10-25 is the Sunday on which
    // 02:00-03:00 occurs twice locally.
    $s = ['days' => '1', 'start_time' => '22:00:00', 'end_time' => '04:00:00', 'enabled' => 1];
    Assert::true(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-10-24 23:00:00', $tz)));
    Assert::true(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-10-25 01:30:00', $tz)));
    Assert::true(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-10-25 03:30:00', $tz)));
    Assert::false(AccessEngine::scheduleMatches($s, new \DateTimeImmutable('2026-10-25 04:00:00', $tz)));
});

test('SCH-010', 'Malformed schedule data never matches', function (): void {
    Assert::false(AccessEngine::scheduleMatches(['days' => 'x', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'enabled' => 1], local('2026-05-04 09:30:00')));
    Assert::false(AccessEngine::scheduleMatches(['days' => '3', 'start_time' => '25:00:00', 'end_time' => '26:00:00', 'enabled' => 1], local('2026-05-04 09:30:00')));
    Assert::false(AccessEngine::scheduleMatches(['days' => '', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'enabled' => 1], local('2026-05-04 09:30:00')));
});

test('SCH-011', 'Schedule day parsing is sorted and de-duplicated', function (): void {
    Assert::same([1, 3, 7], AccessEngine::parseDays('7,3,1,3'));
    Assert::same([], AccessEngine::parseDays(''));
    Assert::same([2], AccessEngine::parseDays('2,9,0'));
});
