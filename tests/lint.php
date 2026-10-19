<?php

declare(strict_types=1);

/**
 * Syntax check for every PHP file in the project.
 *
 * Uses token_get_all(..., TOKEN_PARSE), which runs PHP's parser over the file
 * without executing it. This is the equivalent of `php -l` and works under
 * PHP.wasm where spawning a separate `php` binary is not possible.
 *
 * Usage: php tests/lint.php
 */

$root = dirname(__DIR__);
$directories = ['api', 'bin', 'config', 'includes', 'tests'];
$files = [];

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$failures = 0;
$checked = 0;
foreach ($files as $file) {
    $relative = substr($file, strlen($root) + 1);
    try {
        token_get_all((string) file_get_contents($file), TOKEN_PARSE);
        $checked++;
    } catch (ParseError $error) {
        $failures++;
        fwrite(STDERR, sprintf("FAIL %s: %s on line %d\n", $relative, $error->getMessage(), $error->getLine()));
    }
}

printf("LINT %d file(s) checked | %d failure(s)\n", $checked, $failures);
exit($failures === 0 ? 0 : 1);
