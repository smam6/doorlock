# Test report

All results below were produced by executing the commands shown. Nothing in
this report is inferred from reading the code.

## Environment

| Component | Version / availability |
| --- | --- |
| PHP | 8.4.25 (PHP.wasm build; SAPI `wasm`) — PDO drivers `mysql`, `sqlite` |
| Native PHP | not installed in the sandbox |
| Node.js | v22.22.3 |
| g++ | Debian 12.2.0 |
| Python | 3.11.2 |
| SQLite | via PDO `pdo_sqlite` |
| MySQL server/client | **not available** |
| ESP8266 toolchain (arduino-cli / esp8266 core) | **not available** — the release assets are unreachable from the sandbox |
| ESP8266 / RC522 / relay / lock hardware | **not available** |
| Web server used for HTTP tests | `tools/php-wasm/serve.mjs` on `0.0.0.0:8090` |

## Summary

| Suite | Command | Checks | Result |
| --- | --- | --- | --- |
| PHP syntax | `php tests/lint.php` | 56 files | **PASS** (0 failures) |
| Unit + integration | `php tests/run.php` | 76 tests | **PASS** (76 / 0 / 0) |
| HTTP contract | `php tests/http/api_test.php` | 20 tests | **PASS** (20 / 0) |
| Firmware core | `make -C firmware/doorlock-esp8266/test test` | 15 groups / 71 assertions | **PASS** (71 / 0) |
| Firmware sketch type-check | `firmware/doorlock-esp8266/test/build_sketch.sh` | 2 configurations | **PASS** |
| Firmware ↔ backend protocol | `python3 tests/http/firmware_protocol_test.py` | 17 tests | **PASS** (17 / 0) |
| Release verification | see REL-01…REL-04 | 4 checks | **PASS** |

`tests/run-all.sh` executes the first six (the two HTTP suites when
`DOORLOCK_TEST_BASE_URL` is set) and exits non-zero on any failure.

## Exact commands and output

```text
$ node tools/php-wasm/php.mjs tests/lint.php
LINT 56 file(s) checked | 0 failure(s)

$ node tools/php-wasm/php.mjs tests/run.php
TOTAL 76 | PASS 76 | FAIL 0 | BLOCKED 0 | 2.79s

$ node tools/php-wasm/php.mjs tests/http/api_test.php      # DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090
HTTP TOTAL 20 | PASS 20 | FAIL 0

$ make -C firmware/doorlock-esp8266/test test
FIRMWARE TOTAL 71 | PASS 71 | FAIL 0

$ firmware/doorlock-esp8266/test/build_sketch.sh
OK: DoorLock.ino compiles with the serial fallback
OK: DoorLock.ino compiles with the RC522 code path
SKETCH CHECK PASSED (host stubs; not an ESP8266 binary)

$ python3 tests/http/firmware_protocol_test.py
FIRMWARE E2E TOTAL 17 | PASS 17 | FAIL 0
```

## Detailed coverage

### Unit and integration (76 tests)

| Group | Count | Covers |
| --- | --- | --- |
| `DEV-` device authentication | 11 | key derivation, canonical string, known-answer HMAC, skew, replay, nonce format, rate limit |
| `SCH-` schedules | 14 | weekday numbers, all-day, overnight, inclusive/exclusive boundaries, timezone conversion |
| `VAL-` validation | 12 | field validators, pagination bounds, request ids, SQL splitting, card masking, CSV escaping |
| `ENG-` access engine | 16 | precedence and every reason code, disabled entities, group fallback, grants |
| `AS-` access service | 11 | database-backed decisions, grant consumption, access-log rows and snapshots |
| `AUTH-` authentication | 8 | login, lockout, session versioning, password change, CSRF rotation |

Representative outcomes: `AS-006` proves a single-use grant is spent exactly
once and a second request returns `grant_exhausted`; `SCH-009` proves an
overnight window belongs to its start day; `AUTH-005` proves a deactivated
user's existing session stops working.

### HTTP contract (20 tests)

`HTTP-001` health, `002` anonymous CSRF bootstrap, `003` missing CSRF
rejected, `004` login rotates the token, `005` paginated listing, `006`
person/card lifecycle, `007` viewer is read-only and cannot list users, `008`
cross-origin write rejected, `009` group with members and doors, `010`
invalid weekday rejected (HTTP 400, field error), `011` permission upsert
(201 then 200), `012` device registration + provisioning, `013` single-use
grant consumed once, `014` replayed nonce rejected, `015` bad signature
rejected, `016` logs + CSV export, `017` audit entries, `018`
self-deactivation blocked, `019` unauthenticated access denied, `020` logout
invalidates the session.

### Firmware core (71 assertions, ASAN + UBSAN)

| Group | Asserts | Evidence |
| --- | --- | --- |
| FW-001 SHA-256 | 3 | FIPS 180 vectors (`""`, `"abc"`, two-block message) |
| FW-002 HMAC-SHA256 | 2 | RFC 4231 cases 2 and 6 (131-byte key) |
| FW-003 signing | 6 | documented device vector, canonical string bytes, signature, request headers |
| FW-004/005 responses | 8 | granted and denied parsed exactly |
| FW-006 chunked | 3 | two-chunk body decoded byte-by-byte |
| FW-007 malformed | 6 | truncated, HTML, HTTP 500, string `"true"`, whitespace, garbage — none granted |
| FW-008 ambiguity | 6 | duplicate `access`, `success:false`, missing field, reason mismatch, string seconds, nested decoy |
| FW-009 clamping | 6 | default, zero, negative, 500, zero maximum, maximum above 30 |
| FW-010…FW-012 relay | 15 | locked at boot, exact relock, restart of interval, wrap-around, forceLock, reboot |
| FW-013 debounce | 6 | first/repeat/expiry/other-card/empty/reset |
| FW-014 framing | 3 | oversized body, conflicting lengths, non-HTTP payload |
| FW-015 builder | 3 | short buffer, malformed key, uppercase uid/short key |

Built with `-std=c++11 -Wall -Wextra -Werror -fsanitize=address,undefined`;
any memory or UB error aborts the run, so a PASS also means the tested paths
are clean under those sanitizers.

### Firmware ↔ backend protocol (17 tests)

Requests are generated by the firmware's own C++ code and sent over a socket
to the PHP application.

| ID | Check | Result |
| --- | --- | --- |
| FW-E2E-01 | device registered through the admin API | PASS |
| FW-E2E-02 | provisioning returns a 64-hex key | PASS |
| FW-E2E-03 | person and card created | PASS |
| FW-E2E-04 | explicit allow permission | PASS |
| FW-E2E-05 | firmware-signed request accepted | PASS |
| FW-E2E-06 | response is JSON | PASS |
| FW-E2E-07 | access granted for an authorised card | PASS |
| FW-E2E-08 | reason `granted` | PASS |
| FW-E2E-09 | response echoes the firmware request id | PASS |
| FW-E2E-10 | duration within 1–30 s | PASS |
| FW-E2E-11 | duration equals the door configuration | PASS |
| FW-E2E-12 | replay rejected (401 `replayed_request`) | PASS |
| FW-E2E-13 | wrong key rejected (401) | PASS |
| FW-E2E-14 | stale timestamp rejected (`timestamp_out_of_range`) | PASS |
| FW-E2E-15 | unknown card → 200 `access:false`, `unknown_card` | PASS |
| FW-E2E-16 | explicit deny → 200 `access:false`, `explicit_deny` | PASS |
| FW-E2E-17 | disabled device rejected (401) | PASS |

### Release artifact verification

| ID | Check | Result | Evidence |
| --- | --- | --- | --- |
| REL-01 | archive builds and excludes secrets/dev files | PASS | `Built …/doorlock-2.0.0.zip (104 files, 193.3 KiB)`; `locateName` check for `config/config.local.php`, `tools/php-wasm/serve.mjs`, `legacy/` fails as required |
| REL-02 | archive extracts completely | PASS | `unzip -q` into a clean directory → 106 files; `config/` contains only `config.example.php` and `.htaccess` |
| REL-03 | installer runs from the extracted copy | PASS | `bin/install.php --driver=sqlite …` → `Migrations: 1 applied`, `Administrator created: releaseadmin`, `config.local.php` mode 0600 |
| REL-04 | extracted copy serves the application | PASS | `GET /` 200, health `ok`, login 200, dashboard 200, `POST /api/doors.php` 201, `config/config.local.php`, `storage/`, `tests/`, `firmware/` all 404 |

## Blocked and not-run tests

| Item | Why | How to run later |
| --- | --- | --- |
| ESP8266 firmware compilation | `arduino-cli` and the ESP8266 core archives cannot be downloaded (GitHub release assets unreachable) | `arduino-cli core install esp8266:esp8266 && arduino-cli compile --fqbn esp8266:esp8266:nodemcuv2 firmware/doorlock-esp8266` |
| Flashing and bench test | No board, reader, relay or lock in the sandbox | Follow `firmware/README.md` → "Verification status" checklist |
| Relay polarity / lock timing measurement | Requires the hardware above | Measure with a multimeter and a stopwatch; record in the installation notes |
| MySQL integration | No MySQL server or client; `pdo_mysql` exists but no server to connect to | `php bin/migrate.php --status` and the HTTP suite against a staging MySQL instance |
| Real-browser UI test | No browser engine available; the SPA was exercised through jsdom | Manual pass for layout/RTL/keyboard on Chrome and Firefox |
| TLS with a real certificate | No public hostname/certificate in the sandbox; protocol tested over HTTP with the documented dev override | Run `tests/http/api_test.php` against an HTTPS staging URL with a valid certificate |
| Load / performance | Out of scope for the functional audit; no load tool installed | Exercise with the deployment's expected concurrency before the pilot |

## Notes on honesty of results

- The PHP suite runs on SQLite. `pdo_mysql` is present but no server exists,
  so the MySQL schema is **unverified by execution**.
- The firmware sketch is **type-checked against API stubs**, not compiled with
  the real toolchain. No statement in this repository claims otherwise.
- Physical relay behaviour is **not verified**. The relay logic is verified in
  the host tests and in the protocol test at the software boundary only.
