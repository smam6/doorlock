<?php

declare(strict_types=1);

/**
 * Apply database migrations.
 *
 * Usage:
 *   php bin/migrate.php [--status] [--dry-run] [--driver=mysql|sqlite]
 *
 * Connection settings come from config/config.local.php or DOORLOCK_DB_*.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Cli;
use DoorLock\Config;
use DoorLock\ConfigError;
use DoorLock\Db;
use DoorLock\DatabaseUnavailable;
use DoorLock\Migrator;

$options = Cli::parse()['options'];
if (isset($options['help'])) {
    Cli::out('Usage: php bin/migrate.php [--status] [--dry-run] [--driver=mysql|sqlite]');
    exit(0);
}
$driver = Cli::option('driver');
if ($driver !== null) {
    if (!in_array($driver, ['mysql', 'sqlite'], true)) {
        Cli::err('--driver must be mysql or sqlite');
        exit(64);
    }
    try {
        Config::override(['db' => ['driver' => $driver]]);
    } catch (ConfigError $e) {
        Cli::err('Configuration error: ' . $e->getMessage());
        exit(78);
    }
    Db::disconnect();
}

try {
    $migrator = new Migrator(DOORLOCK_ROOT);
    if (Cli::flag('status')) {
        $rows = $migrator->status();
        Cli::out('Driver: ' . Db::driver());
        foreach ($rows as $row) {
            Cli::out(sprintf('%-16s %s', $row['version'], $row['applied'] ? 'applied' : 'pending'));
        }
        if ($rows === []) {
            Cli::out('No migrations found.');
        }
        exit(0);
    }
    $result = $migrator->migrate(Cli::flag('dry-run'), static fn (string $line) => Cli::out($line));
    Cli::out(sprintf(
        'Migrations complete: %d applied, %d already present.',
        count($result['applied']),
        count($result['skipped'])
    ));
    exit(0);
} catch (DatabaseUnavailable $e) {
    Cli::err('Database unavailable. Check configuration and connectivity.');
    exit(69);
} catch (ConfigError $e) {
    Cli::err('Configuration error: ' . $e->getMessage());
    exit(78);
} catch (Throwable $e) {
    Cli::err('Migration failed: ' . $e->getMessage());
    exit(1);
}
