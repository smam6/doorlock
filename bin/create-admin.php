<?php

declare(strict_types=1);

/**
 * Create an administrative web user.
 *
 * Usage:
 *   php bin/create-admin.php --username=admin --role=admin [--password=...]
 *
 * If no password is supplied a strong random password is generated and printed
 * once. There are no default credentials.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Audit;
use DoorLock\Cli;
use DoorLock\ConstraintViolation;
use DoorLock\Db;
use DoorLock\Util;
use DoorLock\Validator;

$options = Cli::parse()['options'];
if (isset($options['help'])) {
    Cli::out('Usage: php bin/create-admin.php --username=NAME [--role=admin|operator|viewer] [--password=VALUE]');
    exit(0);
}

try {
    $username = Validator::username($options['username'] ?? '');
    $role = Validator::role($options['role'] ?? 'admin');
    $provided = isset($options['password']) && $options['password'] !== true ? (string) $options['password'] : null;
    $password = $provided ?? rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    Validator::password($password, $username);

    $now = Util::toDb(Util::utcNow());
    Db::tx(function () use ($username, $role, $password, $now): void {
        $id = Db::insert(
            'INSERT INTO web_users (username, password_hash, role, is_active, password_changed_at, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $role, $now, $now, $now]
        );
        Audit::record('users.create', 'web_user', (string) $id, null, [
            'username' => $username,
            'role' => $role,
            'source' => 'cli',
        ], ['actor_username' => 'cli']);
    });

    Cli::out('Administrator created.');
    Cli::out('  username: ' . $username);
    Cli::out('  role:     ' . $role);
    if ($provided === null) {
        Cli::out('  password: ' . $password);
        Cli::out('Store this password now; it will not be shown again.');
    }
    exit(0);
} catch (ConstraintViolation $e) {
    if ($e->kind === 'unique') {
        Cli::err('A user with that username already exists.');
        exit(1);
    }
    Cli::err('Database constraint error.');
    exit(1);
} catch (Throwable $e) {
    Cli::err('Unable to create administrator: ' . $e->getMessage());
    exit(1);
}
