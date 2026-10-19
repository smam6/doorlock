# Security model

DoorLock controls physical access, so the guiding rule is **fail closed**:
anything the application cannot verify results in "locked", never "open".

This document describes the threats that were in scope, the controls that
implement the mitigation, and what remains an operational responsibility.

## Assets

1. The door-opening decision (integrity is more important than availability).
2. Device keys and the application key.
3. Administrator credentials and sessions.
4. Personal data in `persons`/`cards` and the access/audit history.

## Threats and controls

| Threat | Control |
| --- | --- |
| SQL injection | PDO prepared statements everywhere (`Db`), no interpolated user input; identifiers are fixed literals. |
| Stored/reflected XSS | All UI rendering uses `textContent`/attribute assignment, never `innerHTML` with server data; CSP on both HTML and JSON responses. |
| CSRF | Per-session token (`X-CSRF-Token`) for every write plus same-origin `Origin`/`Referer` validation. Login and password change are protected too. |
| Session fixation | `session_regenerate_id(true)` on login and privilege/password changes; strict mode; HttpOnly, SameSite=Lax, Secure-when-HTTPS cookies. |
| Session theft via XSS | CSP (`default-src 'self'`, no inline script), no token in `localStorage`, short idle/absolute timeouts, `session_version` invalidates sessions on deactivation/password change. |
| Brute force / credential stuffing | Per-username and per-IP limits with DB-backed counters and lockout; uniform error message; timing-safe password verification (`password_verify`). |
| Privilege escalation | Central `Rbac` matrix, `Api::guard` on every endpoint, admin-only user/audit/device/doors management, self-demotion and last-admin protections. |
| IDOR | Every update/delete re-loads the row and checks ownership/state; list filters are validated; no client-supplied SQL fragments. |
| Device impersonation | HMAC-SHA256 over method, path, device id, timestamp, nonce, request id and body hash; per-device derived key; `hash_equals`. |
| Replay | Nonces stored with TTL and a unique constraint; a replay returns 401 `replayed_request`. |
| Clock manipulation | Timestamp must be within `security.device_clock_skew` (default 120 s). |
| Unsigned/plaintext device traffic | HTTPS required by default; plain HTTP is rejected unless explicitly enabled. |
| Grant reuse | Single-use grants consumed with a conditional `UPDATE` inside the access transaction; concurrent consumption loses and is logged as `grant_exhausted`. |
| Error-based information leak | `ApiError` exposes only safe messages; PDO/stack traces are logged, never returned; generic 500/503 bodies. |
| Log injection | JSON-encoded log lines; control characters escaped by `json_encode`. |
| Path traversal / direct file access | No user-controlled include paths; `.htaccess` (and nginx rules) deny config, includes, database, storage, tests, bin, tools, legacy; dotfiles denied. |
| Clickjacking | `X-Frame-Options: DENY` and `frame-ancestors 'none'`. |
| MIME sniffing | `X-Content-Type-Options: nosniff` on HTML and API responses. |
| Unsafe output of CSV | CSV cells are quoted; spreadsheet formula injection is neutralised by prefixing `'` to values starting with `=`, `+`, `-` or `@`. |
| Weak passwords | Minimum length 12, rejects the username, common passwords and low-entropy patterns; `password_hash` with bcrypt cost 12. |
| Request smuggling/oversized bodies | Body limit `max_body_bytes` enforced before JSON parsing; `Content-Type: application/json` required for JSON endpoints. |
| Open redirect | `logout.php` only redirects to a fixed relative path; no user-supplied return URL. |
| SSRF | The old Arduino HTTP proxy (hardcoded private IP, no auth) was removed and kept only under `legacy/`, which the web server denies. |

## Cryptography

- Passwords: `password_hash(PASSWORD_BCRYPT, ['cost' => 12])`, verified with
  `password_verify`; hashes are never returned by the API.
- Device keys: `HMAC-SHA256(app_key, "doorlock-device-key-v1\n" + unique_id +
  "\n" + key_version)`, stored as lowercase hex, returned only by the
  provisioning action, rotated with `rotate_key`.
- Signatures: HMAC-SHA256, lowercase hex, compared with `hash_equals`.
- Randomness: `random_bytes()` for keys, tokens, request ids and nonces.
- The application key must be at least 32 bytes (64 hex characters or
  `base64:`-encoded) and is validated at startup.

Key management is operational: keep `app_key` in `config/config.local.php`
(mode 0600), never in Git, and back it up with the database. Rotating it
invalidates every device key.

## Authorization matrix

| Permission | admin | operator | viewer |
| --- | :-: | :-: | :-: |
| persons/cards/groups/schedules/permissions/grants read+write | ✓ | ✓ | read only |
| doors read | ✓ | ✓ | ✓ |
| doors write | ✓ | – | – |
| devices read | ✓ | ✓ | ✓ |
| devices write | ✓ | – | – |
| logs read | ✓ | ✓ | ✓ |
| logs export | ✓ | ✓ | – |
| audit read | ✓ | – | – |
| users read/write | ✓ | – | – |

The same matrix is sent to the browser on `GET /api/auth.php` so the UI can
hide forbidden actions, but the server enforces it independently.

## Privacy

`access_logs` contains card numbers and person name snapshots. They are
necessary for the audit trail of a physical access system but are personal
data:

- keep the database and backups encrypted at rest;
- set `retention.access_log_days` / `audit_log_days` to the period required by
  your policy and run `bin/prune-logs.php` from cron;
- restrict `logs.read` to staff who need it;
- stored snapshots are intentional: they preserve the name/card as they were
  at the time of the decision.

## Residual risks and operational requirements

1. **No multi-factor authentication.** Administrator accounts are
   password-only. Put the panel behind a VPN or an identity-aware proxy if the
   network is untrusted.
2. **The controller firmware has not been validated on hardware.** The
   canonical sketch (`firmware/doorlock-esp8266/`) implements the signed
   protocol, pins TLS, keeps the relay locked by default and is covered by host
   tests, but it has not been compiled with the ESP8266 toolchain nor flashed
   onto a board in this environment. Relay polarity, lock timing and electrical
   safety must be verified by the installer before a pilot. The legacy sketches
   under `legacy/firmware/` are insecure and must not be flashed.
3. **A compromised web host can open doors.** Protect the server, restrict
   `config/` and `storage/`, and monitor the application log. Database-level
   deletion of audit rows is possible for the DB user; use backups or
   log shipping if the trail must be tamper-evident.
4. **Same-origin validation compares host, not scheme.** An `http://` page on
   the same host could submit a request, but it still cannot read the CSRF
   token, so writes fail. Serve HTTPS with HSTS.
5. **Rate limits are per application instance.** With several PHP-FPM
   servers sharing one database the counters still work because they live in
   the database; a reverse proxy/WAF should add an outer limit for
   volumetric attacks.
6. **Test coverage ran on SQLite.** The MySQL schema is provided and the SQL
   layer uses per-driver branches, but production MySQL should be validated
   with `bin/migrate.php --status` and a smoke test after deployment. See
   `docs/testing.md`.
7. **No automated certificate or backup monitoring.** Health checks cover the
   database only; monitor TLS expiry and backup jobs separately.

## Verification performed

- 75 unit/integration tests (`tests/run.php`): access precedence, schedule
  windows (including overnight and all-day), validation, HMAC vectors,
  service-level grant consumption and authentication/session behaviour.
- 20 HTTP contract tests (`tests/http/api_test.php`): CSRF, same-origin,
  login rotation, role separation, CRUD, device signing/replay, CSV export,
  audit, self-deactivation and logout, run against a real HTTP server.
- A browser-simulation pass (jsdom) that logs in through the SPA and renders
  every view with live data.
- 71 firmware assertions (SHA-256/HMAC vectors, response classification,
  relay timing under wrap-around) compiled with ASAN and UBSAN, plus a
  type-check of the sketch against Arduino API stubs.
- A firmware ↔ backend protocol test that sends requests signed by the
  firmware's own C++ code to the running PHP application (17 checks).
- Static review of every PHP file (`tests/lint.php` parses all of them) and of
  the SQLite/MySQL schemas.
