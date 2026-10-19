<?php

declare(strict_types=1);

/**
 * DoorLock configuration template.
 *
 * Copy this file to config/config.local.php and adjust the values, then run
 * `php bin/migrate.php` and `php bin/create-admin.php`. Keep the real file
 * outside version control: .gitignore and config/.htaccess already protect it.
 *
 * Every key can also be supplied through a DOORLOCK_* environment variable
 * (see includes/config.php), which takes precedence over this file.
 */

return [
    // production | staging | development. Development relaxes some defaults
    // (for example it permits plain HTTP for the admin panel on localhost).
    'app_env' => 'production',

    // Timezone used for schedules, temporary grants and timestamps shown in
    // the UI. Stored timestamps are always UTC.
    'app_timezone' => 'Asia/Tehran',

    // 32+ random bytes, hex (64+ chars) or base64: prefix. Generate with:
    //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // Rotating this value invalidates every issued device key.
    'app_key' => 'replace-with-64-hex-characters',

    // Public base URL of the installation. Used for same-origin checks.
    'app_url' => 'https://door.example.com',

    // Set to true only when the application sits behind a trusted reverse
    // proxy that overwrites X-Forwarded-For / X-Forwarded-Proto.
    'trust_proxy' => false,

    // In addition to app_url, other origins allowed to send same-origin writes
    // (normally empty).
    'allowed_origins' => [],

    'max_body_bytes' => 65536,

    'log_dir' => 'storage/logs',
    'log_level' => 'info', // debug | info | warning | error

    'session' => [
        'name' => 'doorlock_sid',
        // null = PHP's configured directory. The directory must exist, be
        // writable by PHP-FPM and must not be web-accessible.
        'save_path' => null,
        'idle_timeout' => 1800,
        'absolute_timeout' => 28800,
        // 'auto' sets Secure whenever the request is HTTPS.
        'cookie_secure' => 'auto',
    ],

    'db' => [
        'driver' => 'mysql', // mysql | sqlite
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'doorlock',
        'user' => 'doorlock',
        'password' => 'replace-me',
        'sqlite_path' => null,
        // Optional full PDO DSN; overrides host/port/name.
        'dsn' => null,
    ],

    'security' => [
        'login_max_attempts' => 5,
        'login_ip_max_attempts' => 30,
        'login_window' => 900,
        'login_lockout' => 900,

        // Device request signing window and replay cache lifetime.
        'device_clock_skew' => 120,
        'device_nonce_ttl' => 600,
        'device_rate_limit_per_minute' => 60,

        // Keep both true in production: device traffic must be HTTPS.
        'require_https_for_devices' => true,
        'allow_insecure_device_http' => false,

        // Refuse admin sessions over plain HTTP (enable only for local tests).
        'require_https_for_admin' => true,

        'min_password_length' => 12,
        'max_grant_days' => 90,
    ],

    'limits' => [
        'default_page_size' => 50,
        'max_page_size' => 200,
        'max_export_rows' => 10000,
        'max_schedules_per_pair' => 50,
        'max_cards_per_person' => 10,
    ],

    'retention' => [
        'access_log_days' => 365,
        'audit_log_days' => 730,
        'login_attempt_days' => 30,
                'app_log_days' => 30,
    ],
];
