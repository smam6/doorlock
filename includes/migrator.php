<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Ordered, checksummed SQL migrations.
 *
 * Each driver has its own directory. Applied versions are recorded in
 * schema_migrations; a changed checksum for an already-applied migration is a
 * hard error so deployed databases are never silently altered.
 */
final class Migrator
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return array<int,array{version:string,path:string,checksum:string,applied:bool}> */
    public function status(): array
    {
        $driver = Db::driver();
        $dir = $this->root . '/database/migrations/' . $driver;
        $this->ensureTable();
        $applied = [];
        foreach (Db::all('SELECT version, checksum FROM schema_migrations') as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $result = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new \RuntimeException('Unable to read migration ' . $file);
            }
            if (isset($applied[$version]) && !hash_equals($applied[$version], $checksum)) {
                throw new \RuntimeException(
                    "Migration {$version} has changed after being applied. Refusing to continue."
                );
            }
            $result[] = [
                'version' => $version,
                'path' => $file,
                'checksum' => $checksum,
                'applied' => isset($applied[$version]),
            ];
        }
        return $result;
    }

    /**
     * @param callable(string):void $log
     * @return array{applied:array<int,string>,skipped:array<int,string>}
     */
    public function migrate(bool $dryRun, callable $log): array
    {
        $appliedNow = [];
        $skipped = [];
        foreach ($this->status() as $migration) {
            if ($migration['applied']) {
                $skipped[] = $migration['version'];
                continue;
            }
            $sql = file_get_contents($migration['path']);
            if ($sql === false) {
                throw new \RuntimeException('Unable to read ' . $migration['path']);
            }
            $statements = SqlScript::split($sql);
            if ($dryRun) {
                $log("[dry-run] would apply {$migration['version']} (" . count($statements) . ' statements)');
                continue;
            }
            $apply = function () use ($statements, $migration): void {
                foreach ($statements as $statement) {
                    Db::pdo()->exec($statement);
                }
                Db::exec(
                    'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, ?)',
                    [$migration['version'], $migration['checksum'], Util::toDb(Util::utcNow())]
                );
            };
            if (Db::driver() === 'mysql') {
                // MySQL DDL performs implicit commits, so wrapping the whole
                // file in a transaction would be misleading. The version row is
                // only written after every statement succeeds.
                $apply();
            } else {
                Db::tx($apply);
            }
            $appliedNow[] = $migration['version'];
            $log("applied {$migration['version']}");
        }
        return ['applied' => $appliedNow, 'skipped' => $skipped];
    }

    public function ensureTable(): void
    {
        if (Db::driver() === 'sqlite') {
            Db::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version TEXT PRIMARY KEY,
                    checksum TEXT NOT NULL,
                    applied_at TEXT NOT NULL
                )'
            );
            return;
        }
        Db::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME(3) NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
