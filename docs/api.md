# HTTP API

Two APIs share the same deployment:

- **Admin API** (`/api/*.php`, except `check-access.php` and `health.php`) —
  session-cookie authentication, role-based authorization, CSRF for writes.
- **Device API** (`/api/check-access.php`) — HMAC-signed requests from door
  controllers. No session, no CSRF token, no cookies.

All responses are `application/json; charset=utf-8` (except CSV export) and
carry `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, a restrictive `Content-Security-Policy` and an
`X-Request-Id`.

## Admin API

### Session bootstrap

`GET /api/auth.php` returns, without requiring a session:

```json
{
  "success": true,
  "data": {
    "authenticated": false,
    "user": null,
    "csrf_token": "…",
    "permissions": [],
    "server_time": "2026-10-19T08:00:00Z",
    "site_timezone": "Asia/Tehran"
  },
  "request_id": "…"
}
```

`POST /api/auth.php` requires the CSRF token and a same-origin request.

| Body | Effect |
| --- | --- |
| `{"action":"login","username":"…","password":"…"}` | Starts a session, rotates the id and the CSRF token. |
| `{"action":"logout"}` | Destroys the session. |
| `{"action":"change_password","current_password":"…","new_password":"…"}` | Requires a logged-in user; rotates the CSRF token. |

Successful login/change responses include the new `csrf_token`. Every
subsequent write must send it as `X-CSRF-Token` (or the `csrf_token` form
field for the legacy `logout.php` form).

### Common conventions

- Pagination: `page` (1-based), `per_page` (1–200, default 50). Response
  `meta`: `{page, per_page, total, total_pages}`.
- Writes use `Content-Type: application/json`, `Origin` (or `Referer`) matching
  the configured origin, and `X-CSRF-Token`.
- Timestamps in requests without an explicit offset
  (`YYYY-MM-DDTHH:MM[:SS]`) are interpreted in `app_timezone`; values with
  `Z`/`±HH:MM` are converted from UTC.
- Errors:

```json
{"success": false, "error": {"code": "validation_failed", "message": "…", "fields": {"name": "Required."}}, "request_id": "…"}
```

| Status | Codes |
| --- | --- |
| 400 | `invalid_json`, `invalid_request`, `invalid_request_id`, `validation_failed`, `payload_too_large` |
| 401 | `unauthenticated`, `invalid_credentials` |
| 403 | `forbidden`, `csrf_failed`, `origin_rejected`, `https_required`, `unsupported_media_type` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict`, `last_admin`, `self_modification_forbidden`, `limit_reached` |
| 413 | `payload_too_large` |
| 415 | `unsupported_media_type` |
| 429 | `rate_limited` (login or device rate limit) |
| 500 | `internal_error` |
| 503 | `service_unavailable` |

### Endpoint overview

`R` = required permission for GET, `W` = required permission for
POST/PUT/DELETE. All endpoints are under `/api/`.

| Endpoint | Methods | R / W | Purpose |
| --- | --- | --- | --- |
| `auth.php` | GET, POST | — | Session bootstrap, login, logout, password change. |
| `dashboard.php` | GET | `doors.read` | Counters, today's decisions, last 10 access events. |
| `persons.php` | GET/POST/PUT/DELETE | `persons.read` / `persons.write` | People and their cards. |
| `cards.php` | GET/POST/PUT/DELETE | `cards.read` / `cards.write` | Card assignment and status. |
| `doors.php` | GET/POST/PUT/DELETE | `doors.read` / `doors.write` | Doors, unlock duration, archive/restore. |
| `groups.php` | GET/POST/PUT/DELETE | `groups.read` / `groups.write` | Groups, members, door grants. |
| `schedules.php` | GET/POST/PUT/DELETE | `schedules.read` / `schedules.write` | Weekly time windows per person/door. |
| `permissions.php` | GET/POST/DELETE | `permissions.read` / `permissions.write` | Explicit allow/deny per person/door. |
| `grants.php` | GET/POST/DELETE | `grants.read` / `grants.write` | Temporary grants; DELETE revokes. |
| `devices.php` | GET/POST/PUT/DELETE | `devices.read` / `devices.write` | Controllers, provisioning, key rotation. |
| `users.php` | GET/POST/PUT/DELETE | `users.read` / `users.write` | Panel users (admin only). |
| `logs.php` | GET | `logs.read` | Access log, filters, CSV export. |
| `audit.php` | GET | `audit.read` | Audit trail (admin only). |
| `health.php` | GET | none | Public liveness probe. |
| `check-access.php` | POST | device HMAC | Access decision for a controller. |

### People and cards

`GET persons.php` accepts `search`, `enabled` (`0|1`), `include_archived`,
`page`, `per_page`, and `id` (single record with cards).

`POST persons.php` accepts `name` (required), `notes`, `enabled` (default
true), and optionally `card_number` to create the first card atomically.
`PUT` accepts `id` plus any of `name`, `notes`, `enabled`, `card_number`
(assign a new unique card). `DELETE` archives the person, disables them and
keeps their history.

`POST cards.php`: `person_id`, `card_number` (4–64 chars, hex/ASCII),
`status` ∈ `active|revoked|lost`. A card number is globally unique and a
person may hold at most 10. `PUT` changes status; `DELETE` removes the card.

### Doors, groups, schedules

`POST doors.php`: `name`, `location`, `is_active`, `unlock_seconds` (1–30,
default 5). `PUT` accepts `toggle: true` to flip the active state and
`restore: true` to unarchive. `DELETE` archives (idempotent).

`POST groups.php`: `name`, `description`, `enabled`, `member_ids`,
`door_ids`. `PUT` accepts the same fields plus `id`; supplying `member_ids` or
`door_ids` replaces the respective set. `DELETE` archives; `restore` in a PUT
brings it back.

`POST schedules.php`: `person_id`, `door_id`, `days` (CSV or JSON list of
1–7, Saturday = 1), `start_time`, `end_time` (`HH:MM[:SS]`), `enabled`.
`start_time == end_time` means all day. `PUT` updates fields; `DELETE` removes
the schedule.

### Permissions and temporary grants

`POST permissions.php` upserts an explicit rule for `person_id` + `door_id`
with `effect` ∈ `allow|deny`. The pair is unique. `DELETE` takes `person_id`
and `door_id` (no id) and removes the rule.

`POST grants.php`: `person_id`, `door_id`, `valid_from`, `valid_to`
(max `security.max_grant_days`, default 90), optional `max_uses` and `note`.
A grant with `max_uses = 1` is consumed atomically on the first successful
use. `DELETE` takes `id` and revokes; revoking an already revoked grant is
idempotent.

`GET grants.php` accepts `person_id`, `door_id`, `state`
(`all|active|scheduled|expired|exhausted|revoked`), `page`, `per_page`.

### Devices

`GET devices.php` (`include_inactive=1`) returns controllers without secret
material. `POST` accepts `name`, `unique_id` (32 lowercase hex), `door_id`,
`is_active`; the derived `device_key` is returned only by provisioning:

```http
POST /api/devices.php
{"action":"provision","id":7}
```

```json
{"success": true, "data": {"provisioning": {
  "device_id": 7, "unique_id": "0123…def", "door_id": 1,
  "key_version": 1, "device_key": "90b5…6e26",
  "endpoint": "https://door.example.com/api/check-access.php"
}}}
```

`PUT` can rename, reassign, toggle `is_active`, or send `rotate_key: true`
(optionally `key_version`) to issue a new key and purge outstanding nonces.
`DELETE` deactivates the device (history is preserved).

### Users, logs, audit

`POST users.php`: `username`, `password`, `role` ∈ `admin|operator|viewer`,
`is_active`. `PUT` changes role, active state and/or password. The last active
administrator cannot be demoted or deactivated, and an administrator cannot
change their own role/state or deactivate themselves (`last_admin`,
`self_modification_forbidden`). Deactivation increments `session_version`,
which invalidates all of that user's sessions.

`GET logs.php` filters: `from`, `to` (timestamps), `person_id`, `door_id`,
`device_id`, `decision` ∈ `granted|denied|error`, `reason`, `request_id`,
`page`, `per_page`. `format=csv` (requires `logs.export`) returns a CSV
attachment capped at `limits.max_export_rows`.

`GET audit.php` filters `actor_user_id`, `action`, `entity_type`, `entity_id`,
`from`, `to` with pagination. Only administrators have `audit.read`.

### Dashboard and health

`GET dashboard.php` returns `stats` (persons, cards, doors, devices, groups,
active grants), `today` (`granted`, `denied`, `error`), `recent_access` (10
entries) and `server_time`.

`GET health.php` is public and flat:

```json
{"status":"ok","database":"ok","time":"2026-10-19T08:00:00.000Z","latency_ms":2,"request_id":"…"}
```

It returns 503 with `status: "unavailable"` when the database is unreachable.

## Device API

`POST /api/check-access.php`, body:

```json
{"door_id": 1, "card_number": "04AABBCC"}
```

Required headers:

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-Device-Id` | the device's `unique_id` (32 lowercase hex) |
| `X-Timestamp` | current Unix time in seconds |
| `X-Nonce` | unique value per request, 8–128 URL-safe characters |
| `X-Signature` | lowercase hex HMAC-SHA256 |
| `X-Request-Id` | optional correlation id; defaults to a generated value |

Canonical string (`\n` separated, no trailing newline):

```
DOORLOCK1
POST
check-access
<unique_id>
<timestamp>
<nonce>
<request_id>
<sha256 hex of the raw request body>
```

Signing key: the device key returned by provisioning. It is
`lowerhex(HMAC-SHA256(app_key, "doorlock-device-key-v1\n" + unique_id + "\n" + key_version))`,
and that hexadecimal **string** is used directly as the HMAC key.

Responses:

```json
{"success":true,"access":true,"decision":"granted","reason":"granted","request_id":"…","unlock_seconds":5}
{"success":true,"access":false,"decision":"denied","reason":"outside_schedule","request_id":"…"}
```

Policy denials are HTTP 200 with `access: false`. Authentication/technical
failures use 4xx/5xx:

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `invalid_json`, `invalid_request`, `validation_failed` | Malformed body/fields. |
| 401 | `device_auth_failed` | Unknown device, bad signature, or disabled. |
| 401 | `replayed_request` | Nonce already used. |
| 401 | `timestamp_out_of_range` | Clock skew beyond `security.device_clock_skew`. |
| 403 | `https_required` | Plain HTTP rejected by configuration. |
| 409 | `invalid_device` | Device exists but is not active/assigned. |
| 429 | `rate_limited` | More than `security.device_rate_limit_per_minute`. |
| 503 | `service_unavailable` | Database failure; the door stays locked. |

Reason codes: `granted`, `unknown_card`, `card_revoked`, `card_lost`,
`person_disabled`, `unknown_door`, `door_inactive`, `device_door_mismatch`,
`device_disabled`, `no_permission`, `outside_schedule`, `explicit_deny`,
`grant_expired`, `grant_exhausted`, `grant_revoked`, `service_unavailable`.

### Reference vector

```
app_key   = 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
unique_id = 0123456789abcdef0123456789abcdef
key_version = 1
device_key = 90b55e88d48719b10338ed254fc8a8988d7ec7234f0a9513689679bff61d6e26

body      = {"card_number":"04AABBCC","door_id":1}
body_sha256 = 61b21984380594570a087c8abed7570ff3313b6ad91eec12a155bd0c9d7618b7

timestamp = 1760000000
nonce     = a1b2c3d4e5f60718
request_id = req-12345678

canonical =
DOORLOCK1
POST
check-access
0123456789abcdef0123456789abcdef
1760000000
a1b2c3d4e5f60718
req-12345678
61b21984380594570a087c8abed7570ff3313b6ad91eec12a155bd0c9d7618b7

signature = 8e5eae175623653c888746d38bea984e1944c36eeca518ea876ec382fb1f65d3
```

The same vector (including the SHA-256 of the canonical string,
`91dbccb3925ec80be5c729b3b17d35f9567d2ea1b47ca35c4ba7dfbc280660ea`) is
recomputed independently by `tests/unit/device_auth_test.php` (PHP) and by
`firmware/test/test_core.cpp` (C++), so the firmware and the server are proven
to agree on the wire format.
