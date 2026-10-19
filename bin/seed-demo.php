<?php

declare(strict_types=1);

/**
 * Populate a database with demonstration data (never run on production).
 *
 * Usage: php bin/seed-demo.php [--force]
 *
 * This creates sample doors, people, cards, groups, schedules, a device and a
 * temporary grant. It does NOT create web users; use bin/create-admin.php.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Cli;
use DoorLock\Config;
use DoorLock\Db;
use DoorLock\Util;

if (Config::isProduction()) {
    Cli::err('Refusing to seed demonstration data in the production environment.');
    exit(1);
}

$existing = (int) Db::scalar('SELECT COUNT(*) FROM doors');
if ($existing > 0 && !Cli::flag('force')) {
    Cli::out('Database already contains doors; nothing to do (use --force to add anyway).');
    exit(0);
}

$now = Util::toDb(Util::utcNow());
$nowDt = Util::utcNow();

Db::tx(function () use ($now, $nowDt): void {
    $main = Db::insert(
        'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?)',
        ['درب ورودی اصلی', 'طبقه همکف', 5, $now, $now]
    );
    $server = Db::insert(
        'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?)',
        ['درب اتاق سرور', 'زیرزمین', 3, $now, $now]
    );
    $warehouse = Db::insert(
        'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?)',
        ['درب انبار', 'حیاط', 5, $now, $now]
    );

    $staff = Db::insert(
        'INSERT INTO person_groups (name, description, enabled, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
        ['کارکنان', 'دسترسی به درب اصلی و انبار', $now, $now]
    );
    $admins = Db::insert(
        'INSERT INTO person_groups (name, description, enabled, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
        ['مدیران', 'دسترسی به همه درب‌ها', $now, $now]
    );
    foreach ([$main, $server, $warehouse] as $doorId) {
        Db::exec('INSERT INTO group_permissions (group_id, door_id, created_at) VALUES (?, ?, ?)', [$admins, $doorId, $now]);
    }
    Db::exec('INSERT INTO group_permissions (group_id, door_id, created_at) VALUES (?, ?, ?)', [$staff, $main, $now]);
    Db::exec('INSERT INTO group_permissions (group_id, door_id, created_at) VALUES (?, ?, ?)', [$staff, $warehouse, $now]);

    $people = [
        ['علی احمدی', '04A1B2C3', 'کارمند'],
        ['مریم کریمی', '04B2C3D4', 'مدیر'],
        ['رضا رضایی', '04C3D4E5', 'کارمند'],
        ['سارا موسوی', '04D4E5F6', 'کارمند'],
    ];
    $personIds = [];
    foreach ($people as $person) {
        $personId = Db::insert(
            'INSERT INTO persons (name, enabled, notes, created_at, updated_at) VALUES (?, 1, ?, ?, ?)',
            [$person[0], $person[2], $now, $now]
        );
        $personIds[$person[0]] = $personId;
        Db::insert(
            'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$personId, $person[1], 'active', $now, $now]
        );
    }

    Db::exec('INSERT INTO group_members (group_id, person_id, created_at) VALUES (?, ?, ?)', [$staff, $personIds['علی احمدی'], $now]);
    Db::exec('INSERT INTO group_members (group_id, person_id, created_at) VALUES (?, ?, ?)', [$staff, $personIds['رضا رضایی'], $now]);
    Db::exec('INSERT INTO group_members (group_id, person_id, created_at) VALUES (?, ?, ?)', [$staff, $personIds['سارا موسوی'], $now]);
    Db::exec('INSERT INTO group_members (group_id, person_id, created_at) VALUES (?, ?, ?)', [$admins, $personIds['مریم کریمی'], $now]);

    // Weekday access (Saturday-Wednesday) for one person on the main door.
    Db::insert(
        'INSERT INTO schedules (person_id, door_id, days, start_time, end_time, enabled, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
        [$personIds['علی احمدی'], $main, '1,2,3,4,5', '08:00:00', '18:00:00', $now, $now]
    );
    Db::exec(
        'INSERT INTO permissions (person_id, door_id, effect, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$personIds['مریم کریمی'], $server, 'allow', $now, $now]
    );

    $grantFrom = $nowDt->modify('-1 day');
    $grantTo = $nowDt->modify('+7 days');
    Db::insert(
        'INSERT INTO temporary_grants (person_id, door_id, valid_from, valid_to, max_uses, note, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $personIds['سارا موسوی'],
            $main,
            Util::toDb($grantFrom),
            Util::toDb($grantTo),
            2,
            'دسترسی موقت آزمایشی',
            $now,
            $now,
        ]
    );

    Db::insert(
        'INSERT INTO devices (name, unique_id, door_id, key_version, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 1, 1, ?, ?)',
        ['خواننده درب اصلی', '0123456789abcdef0123456789abcdef', $main, $now, $now]
    );
});

Cli::out('Demonstration data inserted.');
Cli::out('Create an administrator with: php bin/create-admin.php --username=admin');
