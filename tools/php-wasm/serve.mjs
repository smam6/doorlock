#!/usr/bin/env node
/**
 * Development-only HTTP server that runs the real DoorLock PHP application
 * through PHP.wasm.
 *
 * It exists so the project can be exercised in sandboxes without a native PHP
 * or web server. Production deployments use PHP-FPM/Apache as documented in
 * docs/deployment.md. This harness is not part of release archives.
 *
 * Usage:
 *   node tools/php-wasm/serve.mjs [--root DIR] [--port 8080] [--port 8090 ...]
 *                                 [--host 0.0.0.0]
 *
 * `--port` may be repeated to expose the same application on several ports.
 *
 * The listening sockets are opened *before* PHP.wasm is initialised. PHP.wasm
 * starts an internal WebSocket proxy on an ephemeral loopback port for PHP's
 * outbound network calls; binding our public ports first prevents that proxy
 * from occupying one of them (and from being mistaken for the preview).
 */
import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';

const here = path.dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
function arg(name, fallback) {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
}
function argAll(name) {
    const values = [];
    for (let index = 0; index < args.length; index++) {
        if (args[index] === `--${name}` && args[index + 1]) {
            values.push(args[index + 1]);
        }
    }
    return values;
}

const root = path.resolve(arg('root', path.resolve(here, '../..')));
const requestedPorts = argAll('port');
const ports = [...new Set(
    (requestedPorts.length > 0 ? requestedPorts : [process.env.PORT || '8080'])
        .map((value) => Number.parseInt(value, 10))
)];
if (ports.some((value) => !Number.isInteger(value) || value < 1 || value > 65535)) {
    console.error('Every --port must be an integer between 1 and 65535.');
    process.exit(64);
}
const primaryPort = ports[0];
const host = arg('host', '0.0.0.0');
const documentRoot = root;

const MIME = {
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.html': 'text/html; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
};

function serveStatic(req, res, pathname) {
    const decoded = decodeURIComponent(pathname);
    const target = path.resolve(documentRoot, '.' + decoded);
    if (target !== documentRoot && !target.startsWith(documentRoot + path.sep)) {
        res.writeHead(403, { 'Content-Type': 'text/plain' });
        res.end('Forbidden');
        return true;
    }
    let stat;
    try {
        stat = fs.statSync(target);
    } catch {
        return false;
    }
    if (!stat.isFile()) {
        return false;
    }
    const ext = path.extname(target).toLowerCase();
    if (!MIME[ext]) {
        return false;
    }
    res.writeHead(200, {
        'Content-Type': MIME[ext],
        'Content-Length': stat.size,
        'X-Content-Type-Options': 'nosniff',
        'Cache-Control': ext === '.html' ? 'no-store' : 'public, max-age=300',
    });
    fs.createReadStream(target).pipe(res);
    return true;
}

const DENIED_PREFIXES = ['/config/', '/includes/', '/database/', '/storage/', '/tests/', '/bin/', '/tools/', '/.git/', '/firmware/', '/legacy/'];

/** @type {PHPRequestHandler|null} */
let phpRequestHandler = null;

async function handleRequest(req, res) {
    const parsed = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    const pathname = parsed.pathname;

    if (DENIED_PREFIXES.some((prefix) => pathname.startsWith(prefix))) {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('Not Found');
        return;
    }

    if (req.method === 'GET' || req.method === 'HEAD') {
        if (serveStatic(req, res, pathname)) {
            return;
        }
    }

    const chunks = [];
    let size = 0;
    const limit = 1024 * 1024;
    let rejected = false;
    req.on('data', (chunk) => {
        size += chunk.length;
        if (size > limit) {
            rejected = true;
            req.destroy();
            if (!res.headersSent) {
                res.writeHead(413, { 'Content-Type': 'application/json' });
                res.end('{"success":false,"error":{"code":"payload_too_large","message":"Request body is too large."}}');
            }
            return;
        }
        chunks.push(chunk);
    });
    req.on('end', async () => {
        if (rejected) {
            return;
        }
        if (!phpRequestHandler) {
            res.writeHead(503, { 'Content-Type': 'application/json', 'Retry-After': '2' });
            res.end('{"success":false,"error":{"code":"starting","message":"The application is still starting."}}');
            return;
        }
        const body = Buffer.concat(chunks);
        const headers = {};
        for (const [key, value] of Object.entries(req.headers)) {
            if (value === undefined) continue;
            headers[key] = Array.isArray(value) ? value.join(', ') : value;
        }
        if (!headers['x-forwarded-proto']) {
            headers['x-forwarded-proto'] = 'http';
        }
        const env = {};
        for (const [key, value] of Object.entries(process.env)) {
            if (typeof value === 'string') {
                env[key] = value;
            }
        }
        const targetUrl = pathname === '/'
            ? '/index.php'
            : (pathname.endsWith('/') ? pathname + 'index.php' + parsed.search : req.url);
        try {
            const response = await phpRequestHandler.request({
                url: targetUrl,
                method: req.method,
                headers,
                body: new Uint8Array(body),
                env,
            });
            res.writeHead(response.httpStatusCode, { ...response.headers });
            res.end(Buffer.from(response.bytes));
        } catch (error) {
            console.error('[serve] php request failed', error);
            if (!res.headersSent) {
                res.writeHead(500, { 'Content-Type': 'application/json' });
                res.end('{"success":false,"error":{"code":"internal_error","message":"The request could not be processed."}}');
            } else {
                res.end();
            }
        }
    });
}

/** Bind every public port before PHP.wasm starts its own loopback server. */
const servers = [];
for (const port of ports) {
    const server = http.createServer((req, res) => {
        handleRequest(req, res).catch((error) => {
            console.error('[serve] unhandled request error', error);
            if (!res.headersSent) {
                res.writeHead(500, { 'Content-Type': 'text/plain' });
            }
            res.end();
        });
    });
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(port, host, () => {
            server.off('error', reject);
            resolve();
        });
    });
    servers.push(server);
    console.log(`DoorLock dev server listening on http://${host}:${port}`);
}
console.log(`Document root: ${documentRoot}`);

let runtime;
try {
    runtime = new PHP(await loadNodeRuntime('8.4', {
        emscriptenOptions: { processId: 1 },
    }));
} catch (error) {
    console.error('[serve] failed to start PHP.wasm', error);
    process.exit(1);
}

useHostFilesystem(runtime);

phpRequestHandler = new PHPRequestHandler({
    php: runtime,
    documentRoot,
    absoluteUrl: `http://127.0.0.1:${primaryPort}`,
    // Do not keep a server-side cookie jar: every browser client must keep its
    // own session, and the Cookie header we forward must be authoritative.
    cookieStore: false,
});
console.log('PHP.wasm is ready.');

function shutdown() {
    for (const server of servers) {
        server.close();
    }
    setTimeout(() => process.exit(0), 500).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
