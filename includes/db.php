<?php

declare(strict_types=1);

namespace DoorLock;

use PDO;
use PDOException;

/**
 * Thin, consistent PDO wrapper.
 *
 * Design rules:
 *  - Prepared statements only; no string interpolation of user data.
 *  - Every failure raises an exception. A database error can never be
 *    mistaken for an empty result set.
 *  - Internal exception text is logged, never returned to clients.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = '';
    private static int $savepointCounter = 0;

    public static function init(): void
    {
        if (self::$pdo instanceof PDO) {
            return;
        }
        $config = Config::get('db');
        $driver = (string) $config['driver'];
        self::$driver = $driver;

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = $config['sqlite_path'] ?? null;
                $dsn = $config['dsn'] ?? ($path !== null ? 'sqlite:' . $path : null);
                if ($dsn === null) {
                    throw new ConfigError('SQLite is selected but no database path is configured.');
                }
                if ($path !== null && $path !== ':memory:') {
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0770, true);
                    }
                    if (!is_file($path)) {
                        @touch($path);
                        @chmod($path, 0660);
                    }
                }
                self::$pdo = new PDO($dsn, null, null, $options);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA busy_timeout = 5000');
                try {
                    self::$pdo->exec('PRAGMA journal_mode = WAL');
                    self::$pdo->exec('PRAGMA synchronous = NORMAL');
                } catch (PDOException) {
                    // Read-only or in-memory databases may reject WAL; not fatal.
                }
            } else {
                $dsn = $config['dsn'] ?? sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['host'],
                    (int) $config['port'],
                    $config['name']
                );
                self::$pdo = new PDO($dsn, (string) $config['user'], (string) ($config['password'] ?? ''), $options);
                self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                self::$pdo->exec("SET SESSION time_zone = '+00:00'");
                self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
                self::$pdo->exec("SET SESSION innodb_lock_wait_timeout = 10");
            }
        } catch (ConfigError $e) {
            throw $e;
        } catch (PDOException $e) {
            throw self::wrap($e, 'connect');
        }
    }

    public static function pdo(): PDO
    {
        self::init();
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver !== '' ? self::$driver : (string) Config::get('db.driver', 'mysql');
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $statement = self::run($sql, $params);
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll();
        return $rows;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $statement = self::run($sql, $params);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $statement = self::run($sql, $params);
        return $statement->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run a callback atomically.
     *
     * Nested calls use savepoints so an inner failure cannot silently commit
     * partial work of the outer transaction.
     */
    public static function tx(callable $callback, int $attempts = 3): mixed
    {
        $pdo = self::pdo();
        $nested = $pdo->inTransaction();
        $savepoint = null;

        if ($nested) {
            $savepoint = 'sp_' . (++self::$savepointCounter);
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                if (!$nested) {
                    $pdo->beginTransaction();
                }
                $result = $callback();
                if ($nested) {
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } else {
                    $pdo->commit();
                }
                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($nested) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                    // Preserve the original failure.
                }
                if (!$nested && $attempt < $attempts && self::isRetryable($e)) {
                    usleep(50000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function isDuplicate(PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' && ($driverCode === 1062 || $driverCode === 19 || str_contains($e->getMessage(), 'UNIQUE'));
    }

    public static function isForeignKey(PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' && ($driverCode === 1451 || $driverCode === 1452 || $driverCode === 19);
    }

    private static function run(string $sql, array $params): \PDOStatement
    {
        try {
            $statement = self::pdo()->prepare($sql);
            foreach (array_values($params) as $index => $value) {
                $position = $index + 1;
                $type = match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                };
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }
                $statement->bindValue($position, $value, $type);
            }
            $statement->execute();
            return $statement;
        } catch (PDOException $e) {
            $wrapped = self::wrap($e, 'query', $sql);
            if ($wrapped instanceof DatabaseUnavailable) {
                throw $wrapped;
            }
            throw $wrapped;
        }
    }

    private static function wrap(PDOException $e, string $phase, ?string $sql = null): \RuntimeException
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        Logger::error('database_failure', [
            'phase' => $phase,
            'sql' => $sql,
            'sql_state' => $sqlState,
            'driver_code' => $driverCode,
            'exception' => $e,
        ]);

        if ($sqlState === '23000') {
            $kind = ($driverCode === 1062 || $driverCode === 19) ? 'unique' : 'constraint';
            if ($sql !== null && stripos($sql, 'FOREIGN KEY') === false && $kind === 'constraint') {
                $kind = 'check';
            }
            return new ConstraintViolation('A database constraint rejected the operation.', $kind, $e);
        }

        return new DatabaseUnavailable('The database is temporarily unavailable.', 0, $e);
    }

    private static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof PDOException && !$e instanceof DatabaseUnavailable) {
            return false;
        }
        $sqlState = $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? '') : '';
        $message = $e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? '');
        return $sqlState === '40001'
            || $sqlState === '40P01'
            || stripos($message, 'deadlock') !== false
            || stripos($message, 'database is locked') !== false
            || stripos($message, 'busy') !== false;
    }

    /** Testing/maintenance helper. */
    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$savepointCounter = 0;
    }
}
