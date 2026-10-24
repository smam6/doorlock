# DoorLock 2.0

Access-control panel for a small office or building: people, RFID cards,
doors, groups, schedules, temporary grants, audit logs and ESP8266-based door
controllers. The interface is in Persian (RTL); the API and the source are in
English.

This is a security-focused rewrite of the original PHP/MySQL application. It
is self-contained: no Composer packages, no framework and no CDN assets.

## Features

- **Access engine with one decision path** used by the admin panel and the
  devices. Rules, in order: active door → active assigned device → active card
  → enabled person → explicit deny → eligible temporary grant → individual or
  group allow → enabled schedule.
- **Temporary grants** with validity window, optional use limit, atomic
  single-use consumption and explicit revoke.
- **HMAC-signed device API** with replay protection (nonce cache), clock-skew
  limits, per-device rate limiting and automatic key rotation.
- **Canonical ESP8266 firmware** (`firmware/doorlock-esp8266/`): pinned TLS,
  non-blocking relay state machine, fail-closed response validation, RC522
  reader, watchdog-safe networking. Its security-critical parts are plain C++
  and are unit-tested on a workstation.
- **Role-based access control**: `admin`, `operator`, `viewer`.
- **Session security**: HttpOnly/SameSite cookies, idle and absolute timeouts,
  session-id rotation on login, CSRF tokens and same-origin enforcement for
  every state-changing request, login rate limiting and lockout.
- **Audit trail** for every privileged action and an access-log table that can
  be filtered by person, door, device, decision, reason and request id.
- **Encrypted-at-rest friendly**: passwords use `password_hash()`; device keys
  are derived from the application key with HMAC-SHA256 and are never stored
  in plaintext.
- **No SQL string concatenation**: every query is parameterised; all errors
  fail closed and are logged without returning internal details.
- Works with **MySQL 8** in production and **SQLite** for development and the
  test suite.

## Requirements

- PHP **8.1+** with `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `openssl`,
  `session`, `json`.
- MySQL 8.0+ / MariaDB 10.5+ for production, or SQLite 3.35+.
- A web server (Apache 2.4 or nginx) with PHP-FPM.
- HTTPS in production. Device traffic is rejected without HTTPS unless it is
  explicitly allowed in the configuration.

## Quick start

```bash
# 1. Fetch the code and create the local configuration.
cp config/config.example.php config/config.local.php
chmod 600 config/config.local.php
$EDITOR config/config.local.php        # set app_key, app_url and db.*

# 2. Create the schema and the first administrator.
php bin/migrate.php
php bin/create-admin.php --username=admin

# 3. Optional demo data (never on a production database).
php bin/seed-demo.php
```

`bin/install.php` performs steps 1–2 interactively:

```bash
php bin/install.php --driver=mysql \
    --db-host=127.0.0.1 --db-name=doorlock --db-user=doorlock \
    --db-password='…' --app-url=https://door.example.com \
    --admin-user=admin
```

It generates `app_key` and the admin password when they are not supplied,
writes `config/config.local.php` with mode 0600 and runs the migrations.

Point the document root at the project directory. `index.php` serves the SPA
and `api/*.php` the JSON API. The client never talks to PHP through a second
origin: everything is relative to `app_url`.

`bin/seed-demo.php` loads doors, people, cards, groups and a sample device;
it never creates web users. Create the first administrator with
`bin/create-admin.php` (or let `bin/install.php` do it).

## CLI

| Command | Purpose |
| --- | --- |
| `php bin/install.php --help` | Guided installation (config, migrations, admin). |
| `php bin/migrate.php` | Apply pending migrations. `--status`, `--dry-run`, `--driver=sqlite`. |
| `php bin/create-admin.php --username=…` | Create an administrator without printing the password. |
| `php bin/seed-demo.php` | Load demo people, cards, doors, devices, schedules. |
| `php tests/run.php` | Unit and integration tests (SQLite). |
| `php tests/lint.php` | Parse every PHP file. |
| `make -C firmware/doorlock-esp8266/test test` | Firmware logic tests (ASAN/UBSAN). |
| `tests/run-all.sh` | Every suite the environment supports. |

## Directory layout

```
api/                 JSON endpoints (one file per resource)
assets/              CSS and ES modules for the SPA
bin/                 CLI: install, migrate, create-admin, seed-demo, prune, release
firmware/            ESP8266 controller firmware and its host tests
config/              config.example.php; config.local.php is generated
database/migrations/ MySQL and SQLite schemas
docs/                Architecture, API, deployment, security, testing
includes/            Namespaced application code (DoorLock\)
legacy/              Original code kept for reference; never deployed
storage/             Logs, session files and the development SQLite database
tests/               Test runner, fixtures, unit/integration/HTTP suites
tools/php-wasm/      Development-only PHP runtime (excluded from releases)
```

## Security notes

- Keep `config/config.local.php`, `storage/` and `.git/` out of the web root's
  reachable paths. Apache `.htaccess` files and the nginx example in
  `docs/deployment.md` deny them by default.
- The device API requires HTTPS by default. Only set
  `allow_insecure_device_http` for an isolated lab network.
- Rotate `app_key` only together with re-provisioning every device: device
  keys are derived from it.
- Flash only `firmware/doorlock-esp8266/`. The legacy sketches are insecure
  and incompatible. The canonical firmware still requires an ESP8266 build and
  a hardware bench test before deployment; see `firmware/README.md`.

See `docs/security.md` for the threat model and the controls that implement
it, and `SECURITY.md` for how to report a vulnerability.

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — request flow, modules,
  database model, access decision.
- [`docs/api.md`](docs/api.md) — admin API, device API, error format.
- [`docs/deployment.md`](docs/deployment.md) — Apache/nginx, MySQL, TLS,
  permissions, backups, upgrades.
- [`docs/security.md`](docs/security.md) — threat model and audit notes.
- [`docs/testing.md`](docs/testing.md) — how to run the suites and what they
  cover.

## Licence

This project is proprietary software. Copyright (c) 2026 seyed mohammad amin
mosavi. All rights reserved. See [`LICENSE`](LICENSE) for the full terms.

The source code is publicly visible on GitHub for transparency and review
purposes only. It is **not** open source. No use, modification, distribution,
sublicensing, or commercial exploitation is permitted without prior written
permission from the copyright holder.
