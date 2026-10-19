<?php

declare(strict_types=1);

/**
 * Delete old operational data according to the retention configuration.
 *
 * Usage:
 *   php bin/prune-logs.php [--dry-run] [--access-days=N] [--audit-days=N]
 *                          [--login-days=N] [--app-log-days=N]
 *
 * Defaults come from config/config.local.php (`retention.*`). Application log
 * files use a separate default of 30 days; 0 disables pruning for a category.
 * Run this from cron, e.g. daily at 03:15.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Cli;
use DoorLock\Config;
use DoorLock\Db;
use DoorLock\Logger;
use DoorLock\Util;

$options = Cli::parse()['options'];
if (isset($options['help'])) {
    Cli::out('Usage: php bin/prune-logs.php [--dry-run] [--access-days=N] [--audit-days=N] [--login-days=N] [--app-log-days=N]');
    exit(0);
}

$dryRun = Cli::flag('dry-run');

/** Read a non-negative day count, falling back to configuration. */
function prune_days(string $option, string $configKey, int $fallback): int
{
    $raw = Cli::option($option);
    if ($raw !== null) {
        if (preg_match('/^\d+$/', $raw) !== 1) {
            Cli::err('--' . $option . ' must be a non-negative integer.');
            exit(64);
        }
        return (int) $raw;
    }
    return Config::int($configKey, $fallback);
}

$accessDays = prune_days('access-days', 'retention.access_log_days', 365);
$auditDays = prune_days('audit-days', 'retention.audit_log_days', 730);
$loginDays = prune_days('login-days', 'retention.login_attempt_days', 30);
$appLogDays = prune_days('app-log-days', 'retention.app_log_days', 30);

$targets = [
    ['access_logs', 'occurred_at', $accessDays],
    ['audit_logs', 'created_at', $auditDays],
    ['login_attempts', 'attempted_at', $loginDays],
];

$total = 0;
try {
    foreach ($targets as [$table, $column, $days]) {
        if ($days === 0) {
            Cli::out(sprintf('%-16s retention disabled', $table));
            continue;
        }
        $cutoff = Util::toDb(Util::utcNow()->modify('-' . $days . ' days'));
        $sql = sprintf('DELETE FROM %s WHERE %s < ?', $table, $column);
        if ($dryRun) {
            $count = (int) Db::scalar(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s < ?', $table, $column),
                [$cutoff]
            );
        } else {
            $count = Db::exec($sql, [$cutoff]);
        }
        $total += $count;
        Cli::out(sprintf(
            '%-16s %s %d row(s) older than %d day(s) (before %s)',
            $table,
            $dryRun ? 'would delete' : 'deleted',
            $count,
            $days,
            $cutoff
        ));
    }
} catch (Throwable $e) {
    Cli::err('Pruning failed: ' . $e->getMessage());
    exit(1);
}

$removedFiles = 0;
if ($appLogDays > 0) {
    $logDir = (string) Config::get('log_dir', 'storage/logs');
    if (!str_starts_with($logDir, '/')) {
        $logDir = DOORLOCK_ROOT . '/' . $logDir;
    }
    $cutoff = time() - $appLogDays * 86400;
    foreach (glob($logDir . '/app-*.log') ?: [] as $file) {
        if (!preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $match)) {
            continue;
        }
        $timestamp = strtotime($match[1] . ' 00:00:00 UTC');
        if ($timestamp === false || $timestamp >= $cutoff) {
            continue;
        }
        if (!$dryRun) {
            @unlink($file);
        }
        $removedFiles++;
        Cli::out(sprintf('log file         %s %s', $dryRun ? 'would delete' : 'deleted', basename($file)));
    }
} else {
    Cli::out('log files        retention disabled');
}

if (!$dryRun) {
    Logger::info('retention_pruned', [
        'rows' => $total,
        'log_files' => $removedFiles,
        'access_days' => $accessDays,
        'audit_days' => $auditDays,
        'login_days' => $loginDays,
        'app_log_days' => $appLogDays,
    ]);
}

Cli::out(sprintf(
    '%s %d row(s) and %d log file(s).',
    $dryRun ? 'Would remove' : 'Removed',
    $total,
    $removedFiles
));
exit(0);
