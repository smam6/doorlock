<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Request;
use DoorLock\SqlScript;
use DoorLock\Util;
use DoorLock\Validator;

test('VAL-001', 'Card numbers are normalised and validated', function (): void {
    Assert::same('04AABBCC', Util::normalizeCardNumber(' 04:aa:bb:cc '));
    Assert::same('04AABBCC', Util::normalizeCardNumber('04-AA-BB-CC'));
    Assert::same('1234567890', Util::normalizeCardNumber('1234567890'));
    Assert::null(Util::normalizeCardNumber('abc'));
    Assert::null(Util::normalizeCardNumber('04AA BBCC!!'));
});

test('VAL-002', 'Validator rejects invalid card numbers with a field error', function (): void {
    try {
        Validator::cardNumber('x');
        Assert::true(false, 'expected validation failure');
    } catch (ApiError $e) {
        Assert::same(400, $e->status);
        Assert::same('validation_failed', $e->errorCode);
        Assert::true(isset($e->fields['card_number']));
    }
});

test('VAL-003', 'Days accept CSV and arrays and return canonical form', function (): void {
    Assert::same('1,3,7', Validator::days('7,3,1,3'));
    Assert::same('2,5', Validator::days([5, 2]));
    Assert::throws(fn () => Validator::days(''), ApiError::class);
    Assert::throws(fn () => Validator::days('8'), ApiError::class);
    Assert::throws(fn () => Validator::days(['1', 'x']), ApiError::class);
});

test('VAL-004', 'Times are validated and canonicalised', function (): void {
    Assert::same('09:05:00', Validator::time('09:05', 'start_time'));
    Assert::same('23:59:59', Validator::time('23:59:59', 'end_time'));
    Assert::throws(fn () => Validator::time('24:00', 'start_time'), ApiError::class);
    Assert::throws(fn () => Validator::time('9:05', 'start_time'), ApiError::class);
});

test('VAL-005', 'Datetimes require an explicit offset unless they are clearly local', function (): void {
    $utc = Validator::dateTime('2026-05-01T12:00:00Z', 'valid_from');
    Assert::same('2026-05-01T12:00:00+00:00', $utc->format('c'));
    $offset = Validator::dateTime('2026-05-01T12:00:00+02:00', 'valid_from');
    Assert::same('2026-05-01T10:00:00+00:00', $offset->format('c'));
    $local = Validator::dateTime('2026-05-01 12:00', 'valid_from');
    Assert::same('08:30', $local->format('H:i')); // Asia/Tehran default (+03:30)
    $localIso = Validator::dateTime('2026-05-01T12:00:00', 'valid_from');
    Assert::same('08:30', $localIso->format('H:i'), 'naive ISO input is treated as site-local time');
    Assert::throws(fn () => Validator::dateTime('not-a-date', 'valid_from'), ApiError::class);
    Assert::throws(fn () => Validator::dateTime('2026-13-01T12:00:00Z', 'valid_from'), ApiError::class);
});

test('VAL-006', 'Password policy rejects short, common and username-derived passwords', function (): void {
    Assert::same('A-very-long-passphrase-42', Validator::password('A-very-long-passphrase-42'));
    Assert::throws(fn () => Validator::password('short'), ApiError::class);
    Assert::throws(fn () => Validator::password('password'), ApiError::class);
    Assert::throws(fn () => Validator::password('alicealicealice', 'alice'), ApiError::class);
});

test('VAL-007', 'IDs and booleans are strictly validated', function (): void {
    Assert::same(12, Validator::id('12'));
    Assert::throws(fn () => Validator::id('0'), ApiError::class);
    Assert::throws(fn () => Validator::id('1.5'), ApiError::class);
    Assert::throws(fn () => Validator::id('abc'), ApiError::class);
    Assert::true(Validator::boolValue('true', 'enabled'));
    Assert::false(Validator::boolValue('0', 'enabled'));
    Assert::throws(fn () => Validator::boolValue('maybe', 'enabled'), ApiError::class);
});

test('VAL-008', 'Pagination is bounded', function (): void {
    $request = new Request('GET', '/x', [], ['page' => '2', 'per_page' => '9999'], []);
    [$page, $perPage] = Validator::pagination($request);
    Assert::same(2, $page);
    Assert::same(200, $perPage);
    $request = new Request('GET', '/x', [], ['page' => '0'], []);
    [$page] = Validator::pagination($request);
    Assert::same(1, $page);
    $request = new Request('GET', '/x', [], ['per_page' => 'abc'], []);
    Assert::throws(fn () => Validator::pagination($request), ApiError::class);
});

test('VAL-009', 'Request ids must match the documented pattern', function (): void {
    Assert::true(Util::isValidRequestId('req-12345678'));
    Assert::false(Util::isValidRequestId('short'));
    Assert::false(Util::isValidRequestId('has space 123'));
    Assert::false(Util::isValidRequestId(str_repeat('a', 65)));
});

test('VAL-010', 'The SQL splitter handles strings, comments and backticks', function (): void {
    $sql = <<<'SQL'
-- comment ; not a separator
CREATE TABLE `a` (x VARCHAR(10) DEFAULT 'a;b');
/* block ; comment */
INSERT INTO t VALUES ("c;d");
SQL;
    $parts = SqlScript::split($sql);
    Assert::count(2, $parts);
    Assert::contains("DEFAULT 'a;b'", $parts[0]);
    Assert::contains('"c;d"', $parts[1]);

    $parts = SqlScript::split("SELECT 1; ; SELECT 2;");
    Assert::count(2, $parts);
});

test('VAL-011', 'Masking never exposes more than the last four characters', function (): void {
    Assert::same('****BBCC', Util::maskCard('04AABBCC'));
    Assert::same('****', Util::maskCard('1234'));
    Assert::null(Util::maskCard(null));
});

test('VAL-012', 'CSV export neutralises spreadsheet formulas', function (): void {
    Assert::same("'=1+1", ApiHelpers::csvCell('=1+1'));
    Assert::same("'+SUM(A1)", ApiHelpers::csvCell('+SUM(A1)'));
    Assert::same("'-2", ApiHelpers::csvCell('-2'));
    Assert::same("'@cmd", ApiHelpers::csvCell('@cmd'));
    Assert::same('Alice', ApiHelpers::csvCell('Alice'));
    Assert::same('', ApiHelpers::csvCell(null));
    Assert::same('12', ApiHelpers::csvCell(12));
});
