# Testing

Three suites cover different layers. All of them run with plain PHP; no test
framework or Composer dependency is required.

| Suite | Command | Count | What it proves |
| --- | --- | --- | --- |
| Static | `php tests/lint.php` | 55 files | Every PHP file parses (`TOKEN_PARSE`, equivalent to `php -l`). |
| Unit + integration | `php tests/run.php` | 76 tests | Access rules, validation, HMAC, database behaviour, sessions. |
| HTTP contract | `php tests/http/api_test.php` | 20 tests | The real stack over HTTP: CSRF, roles, CRUD, device signing, logout. |

## Unit and integration suite

```bash
php tests/run.php                 # everything
php tests/run.php --list          # ids, descriptions, tags
php tests/run.php --tag=engine    # one tag
php tests/run.php --filter=SCH-   # one group
php tests/run.php --json=/tmp/report.json
```

The runner creates a fresh SQLite database in the system temp directory
(`DOORLOCK_TEST_DB_PATH` to choose a path), loads the SQLite migration, and
seeds a deterministic fixture set through `tests/helpers.php`.

Fixtures (`reset_data()` returns the seeded rows):

- users `admin`, `operator`, `viewer`, `disabled` (password
  `CorrectHorseBatteryStaple9!`), plus `disabled` is inactive;
- doors `main` (5 s), `server` (3 s), `inactive`;
- persons `alice` (enabled), `bob` (disabled), `carol`;
- cards `04AABBCC` (alice), `04DDEEFF` (bob), `04CCDDEE` (revoked, carol),
  `04FFEEDD` (lost);
- group `Staff` with alice and carol, granting the `main` door;
- devices `0123…def` → `main`, `fedc…210` → `server`.

| Prefix | File | Focus |
| --- | --- | --- |
| `DEV-` | `unit/device_auth_test.php` | Canonical string, known-answer HMAC vector, skew, replay, rate limit. |
| `SCH-` | `unit/schedule_test.php` | Weekday numbers, all-day windows, overnight windows, boundaries, `days` parsing. |
| `VAL-` | `unit/validation_test.php` | Field validators, pagination bounds, request ids, SQL splitting, card masking, CSV escaping. |
| `ENG-` | `unit/access_engine_test.php` | Decision precedence and every reason code. |
| `AS-` | `integration/access_service_test.php` | End-to-end decisions against the database, grant consumption, access-log rows, snapshots. |
| `AUTH-` | `integration/auth_test.php` | Login, lockout, session versioning, password change, CSRF token rotation. |

The suite is deterministic: it freezes "now" where the engine accepts an
explicit timestamp, and resets the database before each integration test
through `reset_data()`.

## HTTP contract suite

The suite exercises the deployed application through a real web server.

```bash
# 1. Start any server that can run the application.
php -S 127.0.0.1:8080 -t /path/to/doorlock          # native PHP
# or, without PHP installed (development harness):
node tools/php-wasm/serve.mjs --port 8090 --host 0.0.0.0

# 2. Point the tests at it.
export DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090
export DOORLOCK_TEST_ADMIN=admin
export DOORLOCK_TEST_ADMIN_PASSWORD='…'
php tests/http/api_test.php
```

Coverage (HTTP-001 … HTTP-020): health, anonymous CSRF bootstrap, CSRF
rejection, login + token rotation, paginated listing, person/card lifecycle,
viewer read-only enforcement, cross-origin rejection, groups, field
validation, permission upsert, device provisioning, single-use grant
consumption, nonce replay, bad signature, log/CSV export, audit entries,
self-deactivation protection, unauthenticated access and logout.

Notes:

- The tests create data with a random suffix; they are safe to re-run against
  a development database but should not be pointed at production.
- Each client keeps its own cookie jar. The development server must not share
  sessions between clients — `tools/php-wasm/serve.mjs` passes
  `cookieStore: false` for exactly this reason.
- Tests that need a **native** PHP/MySQL deployment are the only way to
  validate the MySQL dialect; see the caveat in `docs/security.md`.

## Manual smoke test

After a deployment:

1. `curl -fsS https://door.example.com/api/health.php` → `"status":"ok"`.
2. Open the panel, log in as the administrator, and confirm the dashboard
   shows counts.
3. Create a door, a person, a schedule and a permission; confirm the SPA
   renders the new rows.
4. Provision a device and keep the `device_key`; send a signed request with
   `unlock_seconds` from the door configuration.
5. Replay the same request: expect `401 replayed_request`.
6. Log out and confirm `/api/persons.php` returns 401.

## Browser check

Because there is no headless browser in the development container, the SPA is
verified with jsdom: `assets/js/core.js` is imported, `bootstrapSession()` and
`login()` are called, and every view module is rendered against the live API.
This catches template/runtime errors (undefined helpers, wrong response
shapes) without a real browser. A real browser pass is still required before
release for CSS/RTL layout and keyboard navigation.

## Adding tests

`tests/TestRunner.php` exposes `test($id, $description, $callback)` inside the
`DoorLock\Tests` namespace, plus `Assert::same/true/false/null/contains/count/
throws`. Tests must be independent: call `reset_data()` when they write.
