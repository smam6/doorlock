<?php

declare(strict_types=1);

namespace DoorLock;

/** Web-user authentication and session lifecycle. */
final class Auth
{
    /**
     * A fixed bcrypt hash used to equalise timing when a username does not
     * exist. It is not a credential for any account.
     */
    private const DUMMY_HASH = '$2y$12$bOs/KavL0dOvT4FaR4QuXOWGU6WJK14fEn/9ENBYzDnEgQzYFvqQG';

    /** @var array<string,mixed>|null */
    private static ?array $cachedUser = null;

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        if (!Util::isCli()) {
            Security::startSession();
            if (session_status() !== PHP_SESSION_ACTIVE) {
                return null;
            }
        }
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $now = time();
        $idle = Config::int('session.idle_timeout', 1800);
        $absolute = Config::int('session.absolute_timeout', 28800);
        $lastSeen = (int) ($_SESSION['last_seen'] ?? 0);
        $createdAt = (int) ($_SESSION['created_at'] ?? 0);
        if ($lastSeen <= 0 || $createdAt <= 0 || ($now - $lastSeen) > $idle || ($now - $createdAt) > $absolute) {
            self::expire('session_timeout');
            return null;
        }
        $fingerprint = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (isset($_SESSION['fingerprint']) && !hash_equals((string) $_SESSION['fingerprint'], $fingerprint)) {
            Logger::security('session_fingerprint_mismatch', ['user_id' => $_SESSION['user_id']]);
            self::expire('session_invalid');
            return null;
        }
        $user = Db::one(
            'SELECT id, username, role, is_active, session_version, last_login_at, created_at FROM web_users WHERE id = ?',
            [(int) $_SESSION['user_id']]
        );
        if ($user === null || (int) $user['is_active'] !== 1 || !in_array((string) $user['role'], Rbac::ROLES, true)) {
            self::expire('session_invalid');
            return null;
        }
        if ((int) ($_SESSION['session_version'] ?? 0) !== (int) $user['session_version']) {
            Logger::security('session_version_mismatch', ['user_id' => (int) $user['id']]);
            self::expire('session_invalid');
            return null;
        }
        $_SESSION['last_seen'] = $now;
        $_SESSION['role'] = (string) $user['role'];
        RequestContext::setUserId((int) $user['id']);
        $user['id'] = (int) $user['id'];
        $user['is_active'] = (int) $user['is_active'];
        self::$cachedUser = $user;
        return $user;
    }

    /** @return array<string,mixed> */
    public static function requireUser(): array
    {
        Security::requireHttpsForAdmin();
        $user = self::user();
        if ($user === null) {
            throw new ApiError('unauthenticated', 'Authentication required.', 401);
        }
        return $user;
    }

    /** @return array<string,mixed> */
    public static function login(string $username, string $password, string $ip): array
    {
        Security::requireHttpsForAdmin();
        RateLimiter::assertLoginAllowed($username, $ip);

        $user = Db::one('SELECT * FROM web_users WHERE username = ?', [$username]);
        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $verified = password_verify($password, $hash);

        if (!is_array($user) || !$verified || (int) $user['is_active'] !== 1) {
            RateLimiter::recordLogin($username, $ip, false);
            Logger::security('login_failed', ['username' => $username, 'ip' => $ip]);
            throw new ApiError('invalid_credentials', 'Invalid username or password.', 401);
        }

        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            Db::exec(
                'UPDATE web_users SET password_hash = ?, updated_at = ? WHERE id = ?',
                [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), Util::toDb(Util::utcNow()), (int) $user['id']]
            );
        }

        Security::startSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        $_SESSION['role'] = (string) $user['role'];
        $_SESSION['created_at'] = time();
        $_SESSION['last_seen'] = time();
        $_SESSION['session_version'] = (int) $user['session_version'];
        $_SESSION['fingerprint'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $_SESSION['csrf_token'] = Util::randomHex(32);
        self::$cachedUser = null;

        Db::exec('UPDATE web_users SET last_login_at = ?, updated_at = ? WHERE id = ?', [
            Util::toDb(Util::utcNow()),
            Util::toDb(Util::utcNow()),
            (int) $user['id'],
        ]);
        RateLimiter::recordLogin($username, $ip, true);
        RequestContext::setUserId((int) $user['id']);
        Audit::record('auth.login', 'web_user', (string) $user['id']);
        Logger::security('login_success', ['user_id' => (int) $user['id'], 'ip' => $ip]);

        return self::publicUser($user);
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            Audit::record('auth.logout', 'web_user', (string) $user['id']);
        }
        Security::destroySession();
        self::$cachedUser = null;
    }

    public static function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        $row = Db::one('SELECT password_hash FROM web_users WHERE id = ?', [(int) $user['id']]);
        if ($row === null || !password_verify($currentPassword, (string) $row['password_hash'])) {
            RateLimiter::recordLogin((string) $user['username'], 'session', false);
            throw new ApiError('invalid_credentials', 'Current password is incorrect.', 401);
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        Db::exec(
            'UPDATE web_users SET password_hash = ?, password_changed_at = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
            [$hash, Util::toDb(Util::utcNow()), Util::toDb(Util::utcNow()), (int) $user['id']]
        );
        Audit::record('auth.password_changed', 'web_user', (string) $user['id']);
        Security::startSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $fresh = Db::one('SELECT session_version FROM web_users WHERE id = ?', [(int) $user['id']]);
        $_SESSION['session_version'] = (int) ($fresh['session_version'] ?? 1);
        self::$cachedUser = null;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
            'is_active' => (int) $user['is_active'],
            'last_login_at' => Util::iso($user['last_login_at'] ?? null),
        ];
    }

    public static function csrfToken(): string
    {
        return Security::csrfToken();
    }

    private static function expire(string $reason): void
    {
        Logger::security('session_expired', ['reason' => $reason]);
        Security::destroySession();
        self::$cachedUser = null;
    }
}
