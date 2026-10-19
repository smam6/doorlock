# Deployment

## 1. Server prerequisites

- PHP 8.1+ (tested on 8.4) with PHP-FPM and: `pdo_mysql`, `mbstring`,
  `openssl`, `session`, `json`.
- MySQL 8.0+ or MariaDB 10.5+ (production), or SQLite (small single-node
  installs and development).
- Apache 2.4 with `mod_headers` / `mod_rewrite`, or nginx.
- TLS certificate (Let's Encrypt is fine).

Recommended hardening of `php.ini`:

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/doorlock-error.log
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
```

The application sets its own cookie parameters and security headers; do not
enable `session.auto_start`, because the session is started lazily.

## 2. Files and permissions

Deploy the repository content to `/var/www/doorlock` and make the document
root that directory (`index.php` is the only public entry point besides
`assets/`; API files are called directly by the SPA).

```bash
sudo useradd --system --home /var/www/doorlock --shell /usr/sbin/nologin doorlock || true
sudo chown -R doorlock:doorlock /var/www/doorlock
sudo find /var/www/doorlock -type d -exec chmod 750 {} \;
sudo find /var/www/doorlock -type f -exec chmod 640 {} \;
sudo chmod 750 /var/www/doorlock/storage/logs /var/www/doorlock/storage/tmp
```

The installer writes `config/config.local.php` with mode 0600. If you run it
as `root` or as your own user, change the owner so PHP-FPM can read it:

```bash
sudo chown doorlock:doorlock config/config.local.php
sudo chmod 600 config/config.local.php
```

`storage/tmp` holds SQLite databases and session files when configured; it
must be writable by PHP-FPM and readable by nobody else.

## 3. Database

```sql
CREATE DATABASE doorlock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'doorlock'@'127.0.0.1' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON doorlock.* TO 'doorlock'@'127.0.0.1';
FLUSH PRIVILEGES;
```

DDL rights are only needed for migrations; you may revoke
`CREATE, ALTER, INDEX, DROP, REFERENCES` afterwards. The application sets the
session time zone to UTC and strict SQL mode itself.

For SQLite, point `db.sqlite_path` at `storage/tmp/doorlock.sqlite`. The
directory must be writable; WAL mode and foreign keys are enabled
automatically.

## 4. Configuration and first administrator

```bash
sudo -u doorlock cp config/config.example.php config/config.local.php
sudo -u doorlock php bin/install.php --driver=mysql \
    --db-host=127.0.0.1 --db-name=doorlock --db-user=doorlock \
    --db-password='…' --app-url=https://door.example.com \
    --admin-user=admin
```

`bin/install.php` generates a random `app_key` if none is configured, runs the
migrations and creates the administrator, printing the generated password
once. Alternatively set everything by hand in `config/config.local.php` and
run:

```bash
php bin/migrate.php
php bin/create-admin.php --username=admin
```

`app_url` must be the exact public origin: it is used for the same-origin
check on writes. Set `trust_proxy` only when a reverse proxy overwrites
`X-Forwarded-For`.

## 5. Web server

### Apache (vhost + .htaccess)

```apache
<VirtualHost *:443>
    ServerName door.example.com
    DocumentRoot /var/www/doorlock

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/door.example.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/door.example.com/privkey.pem

    <Directory /var/www/doorlock>
        Options -Indexes -Includes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    <FilesMatch "^\.(?!well-known)">
        Require all denied
    </FilesMatch>

    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set Referrer-Policy "no-referrer"

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>

<VirtualHost *:80>
    ServerName door.example.com
    Redirect permanent / https://door.example.com/
</VirtualHost>
```

The shipped root `.htaccess` denies dotfiles, SQL/log/archive files, denies
`config/`, `includes/`, `database/`, `storage/`, `tests/`, `bin/`, `tools/`,
`firmware/` and `legacy/` with a 404, and adds security headers. The internal
directories also contain their own `Require all denied` file, so a vhost
misconfiguration cannot expose them.

### nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name door.example.com;
    root /var/www/doorlock;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/door.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/door.example.com/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Referrer-Policy "no-referrer" always;

    client_max_body_size 256k;

    # Never serve internal directories or dotfiles.
    location ~ ^/(config|includes|database|storage|tests|bin|tools|firmware|legacy)(/|$) {
        return 404;
    }
    location ~ /\. {
        return 404;
    }
    location ~* \.(sql|log|md|zip|tar|gz|ini|bak|example\.php)$ {
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }
}
```

`index.php` is the SPA shell; `api/*.php` are invoked by exact path. Do not
add a catch-all `try_files` rewrite for `/api/`, as unknown paths must 404.

## 6. HTTPS

Device requests are rejected over plain HTTP unless
`security.allow_insecure_device_http` is true. Admin writes work over HTTP but
`security.require_https_for_admin` should stay enabled in production. The
session cookie is `Secure` automatically when the request is HTTPS.

## 7. Scheduled maintenance

Daily pruning of old logs (retention values come from `config.local.php`):

```cron
15 3 * * * doorlock /usr/bin/php /var/www/doorlock/bin/prune-logs.php >> /var/log/doorlock-prune.log 2>&1
```

Configure log rotation for PHP's error log and for `storage/logs`; the
application writes one file per day (`app-YYYY-MM-DD.log`) and
`prune-logs.php` removes files older than `retention.app_log_days`.

## 8. Backups

Back up:

- the database (`mysqldump --single-transaction doorlock`),
- `config/config.local.php` (contains `app_key`; losing it invalidates all
  device keys),
- `storage/logs/` if the audit trail must be retained independently.

Restore the database and the configuration together; a different `app_key`
means every device must be re-provisioned. Verify restores periodically.

## 9. Upgrades

```bash
sudo -u doorlock git -C /var/www/doorlock pull --ff-only
sudo -u doorlock php /var/www/doorlock/bin/migrate.php --status
sudo -u doorlock php /var/www/doorlock/bin/migrate.php
sudo systemctl reload php8.4-fpm
```

Migrations are additive and recorded in `schema_migrations`; running them
twice is safe. Check `GET /api/health.php` after the upgrade and keep the
previous release directory until the new one is verified.

## 10. Monitoring and troubleshooting

- `GET /api/health.php` — 200/`ok` or 503/`unavailable`; no authentication,
  no sensitive data.
- `storage/logs/app-YYYY-MM-DD.log` — JSON lines; every request has a
  `request_id` that is also returned in the `X-Request-Id` header.
- 500 with `internal_error` — inspect the application log; details are never
  sent to the client.
- 503 `service_unavailable` — database connectivity; the access engine fails
  closed and no door opens.
- Login returns 429 — rate limit/lockout (`security.login_*`); clear the
  `login_attempts` table only after investigating.
- Devices receive `timestamp_out_of_range` — check NTP on the controller.
- Devices receive `https_required` — the controller is calling HTTP; fix the
  endpoint URL or, in an isolated lab only, enable
  `allow_insecure_device_http`.
