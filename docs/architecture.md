# Architecture

DoorLock 2.0 is a plain PHP application. There is no framework, no Composer
dependency and no build step: the browser loads ES modules directly, and PHP
files are included through a single bootstrap.

## Request lifecycle

```
Browser ── index.php ──► assets/js/app.js (SPA)
                     └──► assets/js/core.js ── fetch ──► api/*.php
Device  ─────────────► api/check-access.php (HMAC-signed)
```

Every API entry point follows the same shape:

1. Define `DOORLOCK_API_REQUEST` and include `includes/bootstrap.php`.
2. `Api::run($handler)` (admin API) or `DeviceApi::run($handler)` (devices).
   These parse the request, assign a request id, buffer stray output, and turn
   exceptions into one well-formed JSON response.
3. `Api::guard($request, $readPermission, $writePermission)` authenticates the
   session and authorises the HTTP method against the role matrix. The session
   is started lazily by the guard, so the anonymous `GET /api/auth.php`
   bootstrap does not need a logged-in user.
4. State-changing methods require a matching `Origin`/`Referer` and the
   `X-CSRF-Token` header. The token is bound to the session.
5. Handlers validate input through `Validator`, use `Db::tx()` for
   multi-statement writes and call `Audit::record()` for privileged actions.

The response envelope is stable:

```json
{"success": true, "data": {}, "request_id": "…", "meta": {}}
{"success": false, "error": {"code": "…", "message": "…", "fields": {}}, "request_id": "…"}
```

`meta` is present for paginated lists (`page`, `per_page`, `total`,
`total_pages`). Every response is `Cache-Control: no-store` with `nosniff`,
`DENY` framing and a restrictive CSP. `X-Request-Id` is echoed (or generated)
and also appears in the application log.

## Modules (`includes/`)

| File | Responsibility |
| --- | --- |
| `bootstrap.php` | Defines root path, autoloads `DoorLock\*`, loads config, attaches the error handler, starts buffering. |
| `config.php` | Defaults + `config/config.local.php` + `DOORLOCK_*` environment overrides; validated on load. |
| `errors.php` | `ApiError` (safe client-facing errors) and internal exceptions (`ConfigError`, `DatabaseUnavailable`, `ConstraintViolation`). |
| `http.php` | `Request`, `Response`, `RequestContext`, `Api` (admin), security headers, JSON encoding. |
| `security.php` | Session configuration/startup, CSRF, same-origin checks, HTML headers, HTTPS enforcement. |
| `auth.php` | Login, logout, password change, session timeouts, CSRF token rotation, user lookup. |
| `rate_limit.php` | Login throttling per username and per IP; device nonce rate limiting. |
| `rbac.php` | Role matrix and permission checks (single source of truth). |
| `validate.php` | Field validators with consistent messages and `fields` errors. |
| `db.php` | PDO connection (MySQL/SQLite), transactions with retry/savepoints, error translation. |
| `sql.php` | Driver-specific SQL helpers (UTC timestamps, `LIKE`, `ON CONFLICT`/`ON DUPLICATE KEY`). |
| `migrator.php` | Applies `database/migrations/<driver>/*.sql` and records versions. |
| `logger.php` | Structured JSON lines to `storage/logs/app-YYYY-MM-DD.log`, redaction, file locking. |
| `audit.php` | Audit log writer with actor/action/entity and before/after metadata. |
| `access_engine.php` | Pure decision function; no I/O, fully unit-tested. |
| `access_service.php` | Loads the context, runs the engine, consumes grants, writes the access log — one transaction. |
| `device_auth.php` | HMAC verification, timestamp skew, nonce replay cache, per-device rate limit. |
| `device_api.php` | Device response contract (always HTTP 200 for policy denies). |
| `api_helpers.php` | Shared shaping/pagination helpers for list endpoints. |
| `cli.php` | CLI-only argument parsing and output helpers. |

## Database model

`schema_migrations` plus 15 tables:

- **Identity**: `web_users` (panel logins, roles, `session_version`),
  `persons`, `cards` (one active card per number; status
  `active|revoked|lost`).
- **Authority**: `persons` flags, `permissions` (per person/door `allow` or
  `deny`), `person_groups`, `group_members`, `group_permissions`,
  `schedules` (days + local start/end), `temporary_grants`.
- **Physical**: `doors` (unlock duration), `devices` (one per controller,
  `device_key` derived from the app key, `key_version`), `device_nonces`.
- **Observability**: `access_logs` (decision, reason, snapshots, request id),
  `audit_logs`, `login_attempts`.

Foreign keys use `ON DELETE CASCADE` where a child cannot outlive its parent.
Delete endpoints are soft (`archived_at`, `is_active`) for people, cards,
groups and doors, so history and audit entries remain meaningful.

Timestamps are stored as UTC `Y-m-d H:i:s.v` strings. SQLite and MySQL use the
same textual representation, which keeps ordering and comparisons portable.
`Util::localNow()` converts to `app_timezone` for schedules and grants.

## Access decision

`AccessEngine::decide()` receives a fully loaded context and returns
`{granted, reason, source, grant_id, unlock_seconds}`. Precedence:

1. Door exists, is not archived, is active.
2. Device exists, is active, is assigned to the requested door.
3. Card exists and is `active`.
4. Person exists, is enabled, is not archived.
5. An explicit individual `deny` wins over everything below.
6. A valid temporary grant (window, uses left, not revoked) allows access and
   bypasses schedules. If several are valid, the earliest `valid_to` wins.
7. Otherwise an individual `allow` or an enabled group allow is required.
8. If enabled schedules exist for the person/door pair, at least one must
   match the local time; schedules only restrict, they never grant.

Schedule matching: `start == end` means all day on the selected days; a window
whose end is before its start carries over midnight and belongs to the start
day. Start is inclusive, end exclusive. Weekdays are 1 = Saturday … 7 = Friday
(`Util::weekdayNumber`).

Temporary grants are consumed with a conditional `UPDATE ... WHERE used_count
< max_uses`, inside the same transaction as the access log. If the row is not
updated (a concurrent request won), the decision is downgraded to
`grant_exhausted` and the door stays locked.

Technical failures never grant: `DatabaseUnavailable` produces HTTP 503 with
`service_unavailable`, and the device API's deny path logs the attempt.

## Device protocol

`api/check-access.php` requires:

```
X-Device-Id: <32 hex characters>
X-Timestamp: <unix seconds>
X-Nonce: <unique per request, ≤128 chars>
X-Signature: <lowercase hex HMAC-SHA256>
X-Request-Id: <optional correlation id>
```

Canonical string:

```
DOORLOCK1
POST
check-access
<device unique_id>
<timestamp>
<nonce>
<request_id or empty>
<sha256 hex of raw body>
```

The HMAC key is the device key:

```
device_key = lowerhex(HMAC-SHA256(app_key, "doorlock-device-key-v1\n" + unique_id + "\n" + key_version))
```

The hexadecimal string itself is used as the signing key (not its decoded
bytes). Signatures are compared with `hash_equals`; timestamps outside the
configured skew are rejected; nonces are stored with a TTL and a replay
returns `401 replayed_request`. Bodies are limited and must be JSON with
`door_id` and `card_number`.

See `docs/api.md` for the full contract and reference vectors.

## Failure behaviour

- Configuration errors abort the request before any output; details go to the
  log, the client sees a generic 500.
- Database errors raise exceptions; there is no fallback that could allow
  access. The access transaction rolls back on any exception.
- Logging failures are swallowed after writing to `php-error.log`, because a
  full disk must not turn a deny into an allow.
- Stray output (warnings, notices) is captured by the output buffer and
  discarded, so JSON responses stay valid even with `display_errors=On`.

## Frontend

`assets/js/app.js` is the shell and router; `core.js` holds state, API access,
formatting and the login screen. Views live in `assets/js/views/`:

| Module | Views |
| --- | --- |
| `views/core.js` | dashboard, persons, doors |
| `views/access.js` | groups, schedules, permissions, grants, logs |
| `views/admin.js` | devices, users, audit, profile |

The SPA never stores the CSRF token in `localStorage`: it fetches
`GET /api/auth.php` on load, keeps the token in memory and re-fetches it after
login/logout. All requests are same-origin relative URLs.
