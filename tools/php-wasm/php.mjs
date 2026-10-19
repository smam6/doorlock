#!/usr/bin/env node
/**
 * Development-only PHP CLI adapter built on PHP.wasm.
 *
 * This lets the project's PHP test-suite and maintenance scripts run inside
 * sandboxes that do not provide a native `php` binary. It is NOT used in
 * production deployments and is not included in release archives.
 *
 * Usage:
 *   node tools/php-wasm/php.mjs path/to/script.php
 *   node tools/php-wasm/php.mjs --code '<?php echo PHP_VERSION;'
 *
 * All environment variables are forwarded to PHP. Output is streamed to
 * stdout/stderr and the PHP exit code becomes the process exit code.
 */
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import path from 'node:path';

const argv = process.argv.slice(2);
let mode = 'script';
let target = null;
let scriptArgs = [];

if (argv[0] === '--code') {
    mode = 'code';
    target = argv[1] ?? '';
    scriptArgs = argv.slice(2);
} else if (argv.length > 0) {
    target = path.resolve(argv[0]);
    scriptArgs = argv.slice(1);
} else {
    console.error('usage: php.mjs <script.php> [args...] | --code <php-code>');
    process.exit(64);
}

try {
    const php = new PHP(await loadNodeRuntime('8.4', {
        emscriptenOptions: { processId: 1 },
    }));
    useHostFilesystem(php);

    const env = { ...process.env };
    // Pass CLI arguments through an environment variable; php-wasm's CLI mode
    // does not expose argv directly.
    env.DOORLOCK_CLI_ARGS = JSON.stringify(scriptArgs);
    env.DOORLOCK_PHP_WASM = '1';
    env.DOORLOCK_CLI = '1';

    const options = mode === 'code'
        ? { code: target, env }
        : { scriptPath: target, env };

    let response;
    try {
        response = await php.run(options);
    } catch (error) {
        // php-wasm throws PHPExecutionFailureError for non-zero exit codes;
        // the response (including stdout/stderr) is still available.
        if (error && error.response) {
            response = error.response;
        } else {
            throw error;
        }
    }

    const stdout = response.text ?? '';
    if (stdout) process.stdout.write(stdout);
    if (response.errors) process.stderr.write(response.errors);

    const exitCode = typeof response.exitCode === 'number' ? response.exitCode : 0;
    process.exit(exitCode);
} catch (error) {
    console.error(error?.stack || String(error));
    process.exit(70);
}
