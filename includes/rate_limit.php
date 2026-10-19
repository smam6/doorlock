<?php

declare(strict_types=1);

namespace DoorLock;

/** Database-backed rate limiting for login attempts and device requests. */
final class RateLimiter
{
    public static function assertLoginAllowed(string $username, string $ip): void
    {
        $windowStart = Util::toDb(Util::utcNow()->modify('-' . Config::int('security.login_window', 900) . ' seconds'));

        $userFailures = (int) Db::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= ? AND username = ?',
            [$windowStart, $username]
        );
        $ipFailures = (int) Db::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= ? AND ip = ?',
            [$windowStart, $ip]
        );

        $maxUser = Config::int('security.login_max_attempts', 5);
        $maxIp = Config::int('security.login_ip_max_attempts', 30);

        if ($userFailures >= $maxUser || $ipFailures >= $maxIp) {
            Logger::security('login_rate_limited', ['username' => $username, 'ip' => $ip]);
            throw new ApiError(
                'too_many_attempts',
                'Too many failed login attempts. Try again later.',
                429,
                [],
                ['Retry-After' => (string) Config::int('security.login_lockout', 900)]
            );
        }
    }

    public static function recordLogin(string $username, string $ip, bool $success): void
    {
        Db::exec(
            'INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?, ?, ?, ?)',
            [$username, $ip, $success ? 1 : 0, Util::toDb(Util::utcNow())]
        );
        if ($success) {
            // A successful login clears prior failures for this identity.
            Db::exec('DELETE FROM login_attempts WHERE success = 0 AND username = ?', [$username]);
        }
        // Opportunistic cleanup; never let it fail the request.
        if (random_int(1, 100) === 1) {
            try {
                $cutoff = Util::toDb(Util::utcNow()->modify('-' . Config::int('retention.login_attempt_days', 30) . ' days'));
                Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [$cutoff]);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public static function clearLoginFailures(string $username): int
    {
        return Db::exec('DELETE FROM login_attempts WHERE success = 0 AND username = ?', [$username]);
    }
}
