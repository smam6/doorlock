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
 *
 * Entry timestamps are normalised, so the same tree produces the same bytes.
 * Set SOURCE_DATE_EPOCH to pin a different timestamp; the default is
 * 1980-01-01T00:00:00Z.
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
    '.github',
    'release',
    'dist',
    'legacy',
    'tools',
    'storage/logs',
    'storage/tmp',
];

$excludedFiles = [
    '.gitignore',
    'config/config.local.php',
    'firmware/doorlock-esp8266/config.h',
    'firmware/doorlock-esp8266/test/test_core',
    'firmware/doorlock-esp8266/test/live_request',
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
        || str_ends_with($name, '.o')
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

/**
 * Timestamp stamped onto every archive entry.
 *
 * ZipArchive records each file's modification time, and "now" for entries
 * created from a string, so two builds of an identical tree used to differ.
 * SOURCE_DATE_EPOCH is honoured when it is set, otherwise the earliest date
 * the ZIP (DOS) format can represent is used.
 */
function releaseTimestamp(): int
{
    $raw = getenv('SOURCE_DATE_EPOCH');
    if (is_string($raw) && preg_match('/^\d{1,11}$/', trim($raw)) === 1) {
        return max(315532800, min(4354799999, (int) trim($raw)));
    }
    return 315532800; // 1980-01-01T00:00:00Z
}

/**
 * Rewrite the modification time of every entry to one fixed value.
 *
 * The central directory is authoritative, so it is walked to collect each
 * local header offset; both the local header and the central record are
 * patched in place. Only four bytes per header change, so no offset moves.
 */
function normaliseArchiveTimestamps(string $path, int $timestamp): bool
{
    $data = file_get_contents($path);
    if (!is_string($data)) {
        return false;
    }
    $length = strlen($data);
    $eocd = false;
    for ($i = $length - 22; $i >= max(0, $length - 65557); $i--) {
        if (substr($data, $i, 4) === "PK\x05\x06") {
            $eocd = $i;
            break;
        }
    }
    if ($eocd === false) {
        return false;
    }
    $entryCount = unpack('v', substr($data, $eocd + 10, 2))[1];
    $directoryOffset = unpack('V', substr($data, $eocd + 16, 4))[1];

    // DOS date and time: 2 second resolution, years counted from 1980.
    $utc = new DateTimeImmutable('@' . $timestamp);
    $utc = $utc->setTimezone(new DateTimeZone('UTC'));
    $year = min(2107, max(1980, (int) $utc->format('Y')));
    $stamp = pack(
        'vv',
        ((int) $utc->format('H') << 11) | ((int) $utc->format('i') << 5) | ((int) $utc->format('s') >> 1),
        (($year - 1980) << 9) | ((int) $utc->format('n') << 5) | (int) $utc->format('j')
    );

    $offset = $directoryOffset;
    for ($entry = 0; $entry < $entryCount; $entry++) {
        if (substr($data, $offset, 4) !== "PK\x01\x02") {
            return false;
        }
        $nameLength = unpack('v', substr($data, $offset + 28, 2))[1];
        $extraLength = unpack('v', substr($data, $offset + 30, 2))[1];
        $commentLength = unpack('v', substr($data, $offset + 32, 2))[1];
        $localOffset = unpack('V', substr($data, $offset + 42, 4))[1];
        if (substr($data, $localOffset, 4) !== "PK\x03\x04") {
            return false;
        }
        $data = substr_replace($data, $stamp, $localOffset + 10, 4);
        $data = substr_replace($data, $stamp, $offset + 12, 4);
        $offset += 46 + $nameLength + $extraLength + $commentLength;
    }

    return file_put_contents($path, $data) === $length;
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

$timestamp = releaseTimestamp();
if (!normaliseArchiveTimestamps($output, $timestamp)) {
    @unlink($output);
    Cli::err('Unable to normalise archive timestamps.');
    exit(1);
}

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
