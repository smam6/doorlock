<?php

declare(strict_types=1);

namespace DoorLock;

/** Session, CSRF, origin and HTTP security controls. */
final class Security
{
    public static function isHttps(): bool
    {
        if (Util::isCli()) {
            $https = $_SERVER['HTTPS'] ?? '';
            if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
                return true;
            }
            if (Config::bool('trust_proxy')) {
                $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
                return $proto === 'https';
            }
            return false;
        }
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (Config::bool('trust_proxy')) {
            $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto === 'https') {
                return true;
            }
        }
        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (Util::isCli()) {
            // Sessions are meaningless on the CLI; controllers do not use them.
            return;
        }
        $cookieSecureConfig = (string) Config::get('session.cookie_secure', 'auto');
        $secure = match ($cookieSecureConfig) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => self::isHttps(),
        };
        $savePath = Config::get('session.save_path');
        if (is_string($savePath) && $savePath !== '') {
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0770, true);
            }
            ini_set('session.save_path', $savePath);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) Config::int('session.absolute_timeout', 28800));

        session_name((string) Config::get('session.name', 'doorlock_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
            $_SESSION['csrf_token'] = Util::randomHex(32);
        }
        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(Request $request): void
    {
        self::startSession();
        $expected = $_SESSION['csrf_token'] ?? null;
        $provided = $request->header('x-csrf-token');
        if (!is_string($expected) || !is_string($provided) || !hash_equals($expected, $provided)) {
            Logger::security('csrf_rejected', ['path' => $request->path()]);
            throw new ApiError('csrf_failed', 'The security token is missing or invalid. Reload the page and try again.', 403);
        }
    }

    public static function requireSameOrigin(Request $request): void
    {
        $origin = $request->header('origin');
        $referer = $request->header('referer');
        $source = $origin ?? $referer;
        if ($source === null || $source === '') {
            return; // Non-browser clients still need the CSRF token.
        }
        if ($origin === 'null') {
            throw new ApiError('origin_rejected', 'Cross-origin request rejected.', 403);
        }
        $host = parse_url($source, PHP_URL_HOST);
        $port = parse_url($source, PHP_URL_PORT);
        if (!is_string($host) || $host === '') {
            throw new ApiError('origin_rejected', 'Cross-origin request rejected.', 403);
        }
        $sourceHost = strtolower($port !== null ? "{$host}:{$port}" : $host);
        $requestHost = $request->host();
        $allowed = array_map('strtolower', (array) Config::get('allowed_origins', []));
        if ($sourceHost !== $requestHost && !in_array($sourceHost, $allowed, true)) {
            Logger::security('origin_rejected', ['origin_host' => $sourceHost, 'request_host' => $requestHost]);
            throw new ApiError('origin_rejected', 'Cross-origin request rejected.', 403);
        }
    }

    public static function requireHttpsForAdmin(): void
    {
        if (Config::bool('security.require_https_for_admin') && !self::isHttps()) {
            throw new ApiError('https_required', 'Administrative access requires HTTPS.', 403);
        }
    }

    public static function destroySession(): void
    {
        if (Util::isCli()) {
            $_SESSION = [];
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => (bool) $params['secure'],
                    'httponly' => (bool) $params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]);
            }
            session_destroy();
        }
    }

    public static function applyHtmlHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header(
            "Content-Security-Policy: default-src 'self'; " .
            "script-src 'self'; " .
            "style-src 'self'; " .
            "font-src 'self'; " .
            "img-src 'self' data:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self'; form-action 'self'; object-src 'none'"
        );
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
