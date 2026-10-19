<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\AccessEngine;
use DoorLock\Config;
use DoorLock\Db;
use DoorLock\Migrator;
use DoorLock\Util;

const TEST_PASSWORD = 'CorrectHorseBatteryStaple9!';

function database_path(): string
{
    $path = getenv('DOORLOCK_TEST_DB_PATH');
    return is_string($path) && $path !== '' ? $path : sys_get_temp_dir() . '/doorlock-tests.sqlite';
}

function fresh_database(): void
{
    Db::disconnect();
    $path = database_path();
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    Config::override(['db' => ['driver' => 'sqlite', 'sqlite_path' => $path]]);
    Db::init();
    $migrator = new Migrator(DOORLOCK_ROOT);
    $migrator->migrate(false, static function (string $line): void {
        // Silent during tests.
    });
}

function reset_data(): array
{
    Db::disconnect();
    Db::init();
    $tables = [
        'device_nonces', 'access_logs', 'audit_logs', 'temporary_grants', 'schedules',
        'group_permissions', 'group_members', 'permissions', 'devices', 'cards',
        'person_groups', 'persons', 'doors', 'login_attempts', 'web_users',
    ];
    Db::pdo()->exec('PRAGMA foreign_keys = OFF');
    foreach ($tables as $table) {
        Db::pdo()->exec('DELETE FROM ' . $table);
    }
    Db::pdo()->exec('PRAGMA foreign_keys = ON');
    return seed_data();
}

/**
 * @return array<string,mixed>
 */
function seed_data(): array
{
    $now = Util::toDb(Util::utcNow());
    $hash = password_hash(TEST_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

    $admin = Db::insert(
        'INSERT INTO web_users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['admin', $hash, 'admin', 1, $now, $now]
    );
    $operator = Db::insert(
        'INSERT INTO web_users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['operator', $hash, 'operator', 1, $now, $now]
    );
    $viewer = Db::insert(
        'INSERT INTO web_users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['viewer', $hash, 'viewer', 1, $now, $now]
    );
    $disabledUser = Db::insert(
        'INSERT INTO web_users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['disabled', $hash, 'admin', 0, $now, $now]
    );

    $door1 = Db::insert(
        'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['Main entrance', 'Ground floor', 1, 5, $now, $now]
    );
    $door2 = Db::insert(
        'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['Server room', 'Basement', 1, 3, $now, $now]
    );
    $doorInactive = Db::insert(
        'INSERT INTO doors (name, location, is_active, unlock_seconds, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['Archived door', null, 0, 5, $now, $now]
    );

    $alice = Db::insert(
        'INSERT INTO persons (name, enabled, created_at, updated_at) VALUES (?, ?, ?, ?)',
        ['Alice Example', 1, $now, $now]
    );
    $bob = Db::insert(
        'INSERT INTO persons (name, enabled, created_at, updated_at) VALUES (?, ?, ?, ?)',
        ['Bob Disabled', 0, $now, $now]
    );
    $carol = Db::insert(
        'INSERT INTO persons (name, enabled, created_at, updated_at) VALUES (?, ?, ?, ?)',
        ['Carol Example', 1, $now, $now]
    );

    $cardAlice = Db::insert(
        'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$alice, '04AABBCC', 'active', $now, $now]
    );
    $cardBob = Db::insert(
        'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$bob, '04DDEEFF', 'active', $now, $now]
    );
    $cardRevoked = Db::insert(
        'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$carol, '04CCDDEE', 'revoked', $now, $now]
    );
    $cardLost = Db::insert(
        'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$carol, '04FFEEDD', 'lost', $now, $now]
    );

    Db::exec(
        'INSERT INTO permissions (person_id, door_id, effect, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$alice, $door1, 'allow', $now, $now]
    );
    Db::exec(
        'INSERT INTO permissions (person_id, door_id, effect, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$carol, $door1, 'allow', $now, $now]
    );
    Db::exec(
        'INSERT INTO permissions (person_id, door_id, effect, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$alice, $door2, 'allow', $now, $now]
    );

    $staff = Db::insert(
        'INSERT INTO person_groups (name, description, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['Staff', 'All staff members', 1, $now, $now]
    );
    Db::exec('INSERT INTO group_members (group_id, person_id, created_at) VALUES (?, ?, ?)', [$staff, $alice, $now]);
    Db::exec('INSERT INTO group_permissions (group_id, door_id, created_at) VALUES (?, ?, ?)', [$staff, $door2, $now]);

    $device1 = Db::insert(
        'INSERT INTO devices (name, unique_id, door_id, key_version, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['Reader main', '0123456789abcdef0123456789abcdef', $door1, 1, 1, $now, $now]
    );
    $device2 = Db::insert(
        'INSERT INTO devices (name, unique_id, door_id, key_version, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['Reader server', 'fedcba9876543210fedcba9876543210', $door2, 1, 1, $now, $now]
    );

    return [
        'users' => ['admin' => $admin, 'operator' => $operator, 'viewer' => $viewer, 'disabled' => $disabledUser],
        'doors' => ['main' => $door1, 'server' => $door2, 'inactive' => $doorInactive],
        'persons' => ['alice' => $alice, 'bob' => $bob, 'carol' => $carol],
        'cards' => ['alice' => $cardAlice, 'bob' => $cardBob, 'revoked' => $cardRevoked, 'lost' => $cardLost],
        'group' => $staff,
        'devices' => ['main' => $device1, 'server' => $device2],
    ];
}

/** @return array<string,mixed> */
function device_row(int $id): array
{
    $device = Db::one('SELECT * FROM devices WHERE id = ?', [$id]);
    if ($device === null) {
        throw new \RuntimeException('Seed device missing');
    }
    $device['id'] = (int) $device['id'];
    $device['door_id'] = (int) $device['door_id'];
    $device['key_version'] = (int) $device['key_version'];
    $device['is_active'] = (int) $device['is_active'];
    return $device;
}

function access_body(string $card, int $doorId): string
{
    return json_encode(['card_number' => $card, 'door_id' => $doorId], JSON_UNESCAPED_SLASHES);
}

/** @return array<string,mixed> */
function base_context(?\DateTimeImmutable $nowLocal = null): array
{
    $nowLocal ??= new \DateTimeImmutable('2026-05-04 10:00:00', Util::appTimezone());
    return [
        'now_utc' => $nowLocal->setTimezone(new \DateTimeZone('UTC')),
        'now_local' => $nowLocal,
        'door' => ['id' => 1, 'is_active' => 1, 'archived_at' => null, 'unlock_seconds' => 5],
        'device' => ['id' => 1, 'door_id' => 1, 'is_active' => 1],
        'card' => ['id' => 1, 'status' => 'active', 'card_number' => '04AABBCC', 'person_id' => 1],
        'person' => ['id' => 1, 'name' => 'Alice', 'enabled' => 1, 'archived_at' => null],
        'permissions' => [['door_id' => 1, 'effect' => 'allow']],
        'group_permissions' => [],
        'schedules' => [],
        'grants' => [],
        'requested_door_id' => 1,
    ];
}

function reason(array $context): string
{
    return AccessEngine::decide($context)['reason'];
}
