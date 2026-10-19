# Engineering audit report

This report covers the ten mandatory problem areas and the additional defects
found while rewriting DoorLock 2.0. Every statement is backed by a test or a
command recorded in `docs/test-report.md`.

Legend for status:

- **Resolved** — implemented and verified by the referenced tests.
- **Resolved (logic); hardware unverified** — code and host tests pass, but
  physical behaviour cannot be measured in this environment.
- **Blocked** — needs tooling or hardware that is unavailable here.

---

## Issue 1 — Incomplete or incorrect authorization API

**Original problem.** `api/check-access.php` evaluated conditions but not every
path produced a well-formed response; the firmware also treated some failures
as success. Result: backend and controller could disagree about a decision.

**Root cause.** Ad-hoc `echo`/`exit` branches, no shared response writer, no
validation layer, and a firmware that drove the relay from the HTTP status
alone.

**Affected files.** `api/check-access.php`, `includes/access_service.php`,
`includes/device_api.php`, `includes/access_engine.php`,
`firmware/doorlock-esp8266/*`.

**Changes.**

- `DeviceApi::run()` wraps every device request: exactly one JSON response per
  request, output buffering, `X-Request-Id` on every path, policy denials as
  HTTP 200 with `access: false`, authentication problems as 401, malformed
  payloads as 400, database failures as 503.
- `AccessService::authorize()` performs the decision, grant consumption and
  access-log insert in a single transaction; any exception rolls back and
  yields `service_unavailable` without granting.
- `AccessEngine::decide()` is a pure function with explicit precedence and
  reason codes; it validates door/device/card/person state, permissions,
  groups, schedules and grants.
- Firmware `evaluateResponse()` accepts only HTTP 200 + JSON +
  `success:true` + exactly one boolean `access` + `reason:"granted"`.

**Benefit.** One contract, no fail-open path, explicit denial vs. technical
failure, no internal details in responses.

**Tests.** AS-001…AS-011, HTTP-013…HTTP-015, FW-004…FW-009, FW-E2E-05…FW-E2E-17.

**Remaining limitation.** Timing side channels are not assessed; not
practically exploitable for an access decision.

---

## Issue 2 — Communication security and device authentication

**Original problem.** HTTP-only device calls, credentials embedded in the
Arduino sketches, admin login without rate limiting or CSRF protection, and a
hardcoded `admin/1234` backdoor in `api/auth.php`.

**Root cause.** The system was written as a LAN demo; security was not part of
the design. Session handling used raw `session_start()` without cookie flags.
The Arduino proxy accepted unauthenticated requests to any URL.

**Affected files.** `api/auth.php`, `includes/config.php`, `includes/auth.php`,
`includes/security.php`, `includes/rate_limit.php`, `includes/device_auth.php`,
all `api/*.php`, `firmware/doorlock-esp8266/*`, `config/config.example.php`.

**Changes.**

- Passwords use `password_hash()` (bcrypt cost 12) with a 12-character policy
  that rejects the username and common passwords; the backdoor is gone.
- Login is rate-limited per username and per IP with lockout, backed by the
  `login_attempts` table (survives restarts and works across PHP-FPM workers).
- Sessions: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, regenerated
  on login/password change, idle and absolute timeouts, `session_version`
  invalidates sessions on deactivation/password reset.
- CSRF token required for every write, plus same-origin enforcement using
  `Origin`/`Referer` and `app_url`/`allowed_origins`.
- Device requests are HMAC-SHA256 signed over method, action, device id,
  timestamp, nonce, request id and the SHA-256 of the body; keys are derived
  from `app_key` with a versioned KDF and never stored in the database.
- Replay protection via a unique `(device_id, nonce)` row with TTL, ±120 s
  clock-skew window, per-device rate limit and key rotation that purges nonces.
- Firmware pins TLS: a CA bundle or certificate fingerprint is mandatory; there
  is no insecure mode. Wi-Fi and device secrets live in git-ignored `config.h`.

**Benefit.** An attacker without the device key cannot forge a grant; a
captured request cannot be replayed; a stolen admin password is rate-limited
and its session is revocable.

**Tests.** DEV-001…DEV-011, AUTH-001…AUTH-008, HTTP-002…HTTP-004,
HTTP-014…HTTP-015, FW-E2E-12…FW-E2E-17.

**Remaining limitation.** No MFA for administrators (documented). Physical
theft of a controller exposes its key; key rotation is the mitigation.

---

## Issue 3 — Conflicting firmware and unsafe relay timing

**Original problem.** Three sketches with different relay polarity, one
blocking `delay(5000)` inside `openDoor()`, and a second timer that depended on
variables that were never updated. Relay could stay energised or chatter.

**Root cause.** Copy-paste variants, no single canonical implementation, no
state machine, and no separation between logic and hardware access.

**Affected files.** `RFID_thingPod/*.ino` (moved to `legacy/firmware/`),
`firmware/doorlock-esp8266/*`.

**Changes.**

- One canonical firmware. Legacy sketches moved to `legacy/`, documented as
  insecure/incompatible, denied by the web server and excluded from releases.
- `RelayController` is a non-blocking state machine driven by `millis()`:
  `begin()` forces the locked level before Wi-Fi, `tick()` re-locks exactly at
  the end of the interval, unsigned arithmetic survives wrap-around, and
  `forceLock()` is used on every failure path.
- Polarity is explicit (`cfg::kRelayActiveHigh`), and both levels are derived
  from it in one function.
- Repeated scans restart (never stack) the pulse; `CardDebouncer` drops the
  same card within a configurable window.
- Duration clamped to 1–30 s in firmware *and* server; the server value wins.
- Safe mode when configuration, TLS trust or the RC522 is missing.
- No blocking `delay()` in the unlock path; watchdog fed during network waits.

**Benefit.** The door cannot remain unlocked after a reboot, a lost Wi-Fi
connection, a malformed response or a repeated scan.

**Tests.** FW-010…FW-013 (relay timing, wrap-around, reboot, debounce),
FW-E2E-05/11 (server duration honoured).

**Remaining limitation.** **Hardware unverified.** No ESP8266/RC522/relay/lock
is available; the sketch has not been compiled with the ESP8266 toolchain
(the sandbox cannot download it). Electrical polarity, lock type and pulse
timing must be verified on the bench.

---

## Issue 4 — Schedule boundaries and non-atomic single-use grants

**Original problem.** Day-of-week handling used `FIND_IN_SET` on strings with
inconsistent numbering, `BETWEEN` failed for windows crossing midnight, disabled
schedules could still allow access, and single-use grants were read then
updated without a condition — two concurrent scans could both succeed.

**Root cause.** Time logic spread across SQL and PHP; grant consumption was not
transactional.

**Affected files.** `includes/access_engine.php`, `includes/access_service.php`,
`includes/util.php`, `api/schedules.php`, `api/grants.php`, both migrations.

**Changes.**

- Weekdays are integers 1…7 (Saturday = 1) and validated on write;
  `Util::weekdayNumber()` is the single conversion.
- `scheduleMatches()` implements all-day (`start == end`), same-day
  (start inclusive, end exclusive) and overnight windows that belong to the
  start day, using the site timezone.
- Disabled schedules are filtered out and can never grant; they only restrict
  an existing individual/group entitlement.
- Grants are consumed with a conditional `UPDATE ... WHERE revoked_at IS NULL
  AND valid_from <= now AND valid_to > now AND (max_uses IS NULL OR used_count
  < max_uses)` inside the access transaction. If zero rows change, the decision
  is downgraded to `grant_exhausted`.
- Explicit deny wins over grants and allows; grants bypass schedules; the
  earliest-expiring eligible grant is chosen.
- Indexes on `(person_id, door_id)` and validity columns support the queries.

**Benefit.** Deterministic decisions at boundaries; a single-use grant can be
spent exactly once even under concurrency.

**Tests.** SCH-001…SCH-014, ENG-001…ENG-016, AS-001…AS-011, HTTP-013.

**Remaining limitation.** Concurrency was exercised sequentially on SQLite;
a true parallel two-request race against MySQL should be repeated during the
pilot (the conditional update is correct by construction, but the row-lock
behaviour differs between engines).

---

## Issue 5 — Database error handling and API contracts

**Original problem.** `includes/functions.php` returned `[]`/`0` on failure, so
endpoints reported success with empty data; errors leaked SQL text; each
endpoint used its own response shape.

**Root cause.** No database abstraction with exceptions, no response envelope,
no shared validation.

**Affected files.** `includes/db.php`, `includes/errors.php`,
`includes/http.php`, `includes/api_helpers.php`, every endpoint.

**Changes.**

- `Db` uses PDO prepared statements, `ERRMODE_EXCEPTION`, no emulated prepares,
  MySQL strict mode and UTC session time; failures raise
  `DatabaseUnavailable`, constraint violations raise `ConstraintViolation`
  with a classification (`unique`/`foreign`/`check`).
- `Db::tx()` wraps multi-step writes with savepoints and deadlock retry.
- One JSON envelope for success and errors, one set of status codes, one
  pagination contract with a hard maximum of 200 rows.
- Database details and stack traces are logged, never returned; stray output is
  discarded by the output buffer.
- Validation is centralised in `Validator` with field-level errors.

**Benefit.** Impossible to report success for a failed write; clients can
distinguish empty results from errors.

**Tests.** VAL-001…VAL-012, HTTP-010, HTTP-019, plus every integration test
that asserts a failed constraint leaves state unchanged.

**Remaining limitation.** MySQL-specific paths were not executed (no MySQL
server available); the SQLite schema and driver branch are tested.

---

## Issue 6 — Authorization boundaries and administrative security

**Original problem.** Any logged-in user could call administrative endpoints;
there were no roles; the frontend hid buttons but the API did not check.

**Root cause.** Authentication was conflated with authorization.

**Affected files.** `includes/rbac.php`, `includes/http.php`,
`includes/auth.php`, all `api/*.php`, `assets/js/*`.

**Changes.**

- Explicit role matrix (`admin`, `operator`, `viewer`) with 21 permissions;
  `Api::guard()` is called by every endpoint and enforces read vs. write
  permission server-side.
- Admin-only: users, audit, doors/devices management. Operator: people, cards,
  groups, schedules, permissions, grants. Viewer: read-only.
- Last-active-administrator protection and self-demotion/deactivation guards.
- Every privileged action writes an audit entry with actor, entity and
  before/after values; secrets are never included.
- The SPA receives the permission list only to hide forbidden actions; it is
  never trusted.

**Benefit.** A viewer or operator cannot escalate through crafted requests.

**Tests.** HTTP-007 (viewer cannot write or list users), HTTP-017 (audit),
HTTP-018 (self-deactivation), AUTH-*, AS-* (server-side enforcement).

**Remaining limitation.** Role granularity is intentionally coarse; there is no
per-door delegated administration.

---

## Issue 7 — Database integrity, constraints and migrations

**Original problem.** `database.sql` lacked foreign keys and uniqueness
constraints, used a placeholder password hash, and there was no migration
history; deleting a person orphaned cards and permissions.

**Root cause.** A single hand-written schema with no versioning.

**Affected files.** `database/migrations/{mysql,sqlite}/001_initial.sql`,
`includes/migrator.php`, `bin/migrate.php`, `bin/install.php`.

**Changes.**

- 15 tables with primary keys, foreign keys (`ON DELETE CASCADE` /
  `SET NULL` where history must survive), unique constraints on
  `cards.card_number`, `devices.unique_id`, `permissions(person_id, door_id)`,
  `group_permissions(group_id, door_id)`, `person_groups.name`,
  `web_users.username`, and `device_nonces(device_id, nonce)`.
- Soft deletes (`archived_at`, `is_active`) preserve history; users are
  deactivated, never deleted.
- `schema_migrations` records applied versions; migrations are idempotent and
  verified by running them twice.
- Installer refuses to overwrite a configuration without `--force` and writes
  it with mode 0600.
- `bin/prune-logs.php` implements retention with `--dry-run`.

**Benefit.** Referential integrity is enforced by the database, not convention;
upgrades are reproducible.

**Tests.** Migration applied twice in the install test; AS/AUTH suites rely on
constraints; HTTP-006 exercises archive semantics; installer E2E from the
release archive (`docs/test-report.md`, REL-01…REL-03).

**Remaining limitation.** The MySQL migration was not executed against a live
MySQL 8 server.

---

## Issue 8 — Audit logging and operational diagnostics

**Original problem.** `api/logs.php` returned unbounded rows, access logs were
not written for several failure paths, and logs contained SQL errors and stack
traces.

**Root cause.** Logging was incidental rather than a defined schema.

**Affected files.** `includes/logger.php`, `includes/audit.php`,
`includes/access_service.php`, `includes/device_api.php`, `api/logs.php`,
`api/audit.php`, `bin/prune-logs.php`.

**Changes.**

- `access_logs` row for every decision and every authentication/technical
  failure, with request id, device, door, card/person snapshots, reason,
  decision, IP and UTC timestamp.
- `audit_logs` for administrative changes with before/after JSON.
- Structured JSON application logs with levels, request correlation and
  redaction of secrets; log failures never affect the decision.
- List endpoints are paginated and indexed; CSV export is capped and escapes
  spreadsheet formulas (`ApiHelpers::csvCell`, VAL-012).
- Retention CLI covers database rows and log files, with a dry-run mode.

**Benefit.** Incident investigation is possible from the request id alone.

**Tests.** HTTP-016 (logs + CSV), HTTP-017 (audit), VAL-012 (CSV escaping),
AS-* (access rows), FW-E2E-09 (correlation id end to end).

**Remaining limitation.** Audit rows are deletable by the database user;
tamper-evident storage (WORM/shipping) is an operational choice.

---

## Issue 9 — Automated testing and failure recovery

**Original problem.** No automated tests existed; the only verification was
manual clicking against a live device.

**Root cause.** No test harness and no seams for testing (logic mixed with
HTTP, hardware and globals).

**Affected files.** `tests/*`, `firmware/doorlock-esp8266/src/*`,
`firmware/doorlock-esp8266/test/*`.

**Changes.**

- 76 PHP unit/integration tests (access engine, schedules, validation, HMAC
  vectors, AccessService, auth/session) on SQLite.
- 20 HTTP contract tests against a running server (CSRF, roles, CRUD, device
  signing/replay, export, audit, logout).
- 71 firmware assertions under ASAN/UBSAN over the real firmware sources,
  plus a sketch type-check against API stubs.
- 17 firmware ↔ backend protocol checks that send bytes produced by the
  firmware's own signer to the PHP API.
- `tests/run-all.sh` runs everything the environment supports and reports
  skipped suites as skipped, never as passed.

**Benefit.** Regressions in the access decision or the protocol are caught
before flashing hardware.

**Tests.** All of the above; results in `docs/test-report.md`.

**Remaining limitation.** No ESP8266 toolchain and no hardware, so firmware
compilation/flashing and physical relay tests are `BLOCKED`.

---

## Issue 10 — Production readiness, maintainability and packaging

**Original problem.** No installation path, no configuration template, no
documentation, mixed obsolete/production code, credentials in source.

**Changes.**

- Clear layout: `api/`, `assets/`, `bin/`, `config/`, `database/`,
  `docs/`, `firmware/`, `includes/`, `legacy/`, `storage/`, `tests/`,
  `tools/`.
- `bin/install.php` (config + migrations + admin), `migrate.php`,
  `create-admin.php`, `seed-demo.php`, `prune-logs.php`,
  `build-release.php`.
- Documented deployment for Apache and nginx with `.htaccess` denies and
  security headers; `docs/security.md` threat model; `docs/api.md` contracts;
  `docs/architecture.md`; `docs/testing.md`; firmware build/flash/provisioning
  guide.
- Release archive excludes local configuration, firmware `config.h`, runtime
  data, development tooling and legacy code; it is built reproducibly and
  verified by extraction and installation.

**Benefit.** A new operator can install, verify and upgrade the system from
the documentation alone.

**Tests.** REL-01…REL-04 (archive contents, extraction, installation from the
archive, live login/CRUD against the extracted copy).

**Remaining limitation.** No CI pipeline is configured; the commands are
scripted and documented but must be wired into the operator's CI.

---

## Additional findings fixed

| # | Finding | Fix | Evidence |
| --- | --- | --- | --- |
| A1 | `assets/js/app.js` defined `toggleUserStatus` twice and had wrong weekday math; `GET logout.php` allowed CSRF logout | Rewritten SPA with modules, POST-only logout with CSRF | HTTP-020, jsdom view render |
| A2 | `persons.php` selected non-existent columns and echoed SQL errors | Rewritten endpoint with validation and envelope | HTTP-005, HTTP-006 |
| A3 | `logs.php` unbounded, CSV formula injection possible | Pagination, export cap, `csvCell()` | HTTP-016, VAL-012 |
| A4 | `database.sql` had a placeholder hash usable as an account | Removed from the deploy path; schema migrated and seeds hashed with `password_hash` | AUTH-*, DEV-* |
| A5 | Firmware had hardcoded Wi-Fi credentials and relay conflicts | Canonical firmware with `config.h` (git-ignored) and one relay controller | FW-010…FW-015 |
| A6 | `api/arduino.php` was an unauthenticated proxy to a private IP (SSRF) | Removed from the deploy path; quarantined under `legacy/` and denied by the server | `curl` 404 check in REL smoke |
| A7 | Docs contained an inconsistent signature example (body did not match the published hash) | Corrected and cross-verified in PHP and C++ | FW-003, DEV-002/003 |
| A8 | `session_start()` warnings surfaced as 500 when the session directory was missing | Session directory created/validated by config and installer; errors logged, not emitted | HTTP-001…HTTP-004 |
| A9 | `X-Powered-By` leaked the PHP version | Header removed in bootstrap | header check in test report |
| A10 | CSP allowed an unused CDN | Removed `cdn.jsdelivr.net` from `style-src`/`font-src` | header check |

## Deliberate non-goals

- No multi-factor authentication, no per-door delegated admin, no mobile app.
- No Windows service wrapper or Docker image: the target is a conventional
  PHP-FPM host, documented in `docs/deployment.md`.
- No live MySQL run in this environment (no server, no client): the MySQL
  schema is provided and reviewed, but the MySQL path is explicitly listed as
  unverified.
