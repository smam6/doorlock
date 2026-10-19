<?php

declare(strict_types=1);

/**
 * Build a deployable ZIP archive.
 *
 * Usage:
 *   php bin/build-release.php [--output=release/doorlock-2.0.0.zip] [--force]
 *
 * The archive contains the application, assets, migrations and documentation.
 * It deliberately excludes local configuration, runtime data, development
 * tooling, tests, the legacy tree and VCS metadata.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Cli;

if (!class_exists('ZipArchive')) {
    Cli::err('The PHP zip extension is required to build the archive.');
    exit(69);
}

$root = DOORLOCK_ROOT;
$output = Cli::option('output', $root . '/release/doorlock-2.0.0.zip');
if ($output === null) {
    $output = $root . '/release/doorlock-2.0.0.zip';
}
if (!str_starts_with($output, '/')) {
    $output = $root . '/' . $output;
}

$excludedDirectories = [
    '.git',
    'release',
    'dist',
    'legacy',
    'tests',
    'tools',
    'storage/logs',
    'storage/tmp',
];

$excludedFiles = [
    '.gitignore',
    'config/config.local.php',
    'config/config.example.php.bak',
];

$skipFile = static function (string $relative) use ($excludedFiles): bool {
    if (in_array($relative, $excludedFiles, true)) {
        return true;
    }
    $name = basename($relative);
    return str_ends_with($name, '.sqlite')
        || str_ends_with($name, '.sqlite-wal')
        || str_ends_with($name, '.sqlite-shm')
        || str_ends_with($name, '.zip')
        || $name === '.DS_Store';
};

$skipDirectory = static function (string $relative) use ($excludedDirectories): bool {
    foreach ($excludedDirectories as $excluded) {
        if ($relative === $excluded || str_starts_with($relative, $excluded . '/')) {
            return true;
        }
    }
    return false;
};

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $item) {
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    if ($item->isDir()) {
        continue;
    }
    if ($skipDirectory($relative) || $skipFile($relative)) {
        continue;
    }
    $files[] = $relative;
}
sort($files);

if ($files === []) {
    Cli::err('Nothing to package.');
    exit(1);
}

if (is_file($output)) {
    if (!Cli::flag('force')) {
        Cli::err('Refusing to overwrite ' . $output . ' (use --force).');
        exit(1);
    }
    @unlink($output);
}
$directory = dirname($output);
if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
    Cli::err('Unable to create ' . $directory);
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    Cli::err('Unable to create ' . $output);
    exit(1);
}
foreach ($files as $relative) {
    $zip->addFile($root . '/' . $relative, $relative);
}
$zip->addFromString('storage/logs/.gitkeep', '');
$zip->addFromString('storage/tmp/.gitkeep', '');
$zip->close();

$size = (int) filesize($output);
Cli::out(sprintf('Built %s (%d files, %.1f KiB)', $output, count($files), $size / 1024));
foreach (['config/config.local.php', 'tools/php-wasm/serve.mjs', 'legacy/'] as $forbidden) {
    if ($zip->open($output) === true) {
        if ($zip->locateName($forbidden) !== false) {
            Cli::err('ERROR: archive unexpectedly contains ' . $forbidden);
            $zip->close();
            exit(1);
        }
        $zip->close();
    }
}
Cli::out('Archive verified: no local configuration, dev tooling or legacy files.');
exit(0);
