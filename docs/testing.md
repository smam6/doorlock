# Testing

Four suites cover the stack. Everything listed here was executed in the
development environment; results are in `docs/test-report.md`.

| Suite | Command | Checks | Layer |
| --- | --- | --- | --- |
| PHP syntax | `php tests/lint.php` | 56 files | Static |
| Unit + integration | `php tests/run.php` | 76 tests | Logic + SQLite integration |
| HTTP contract | `php tests/http/api_test.php` | 20 tests | Running server |
| Firmware core | `make -C firmware/doorlock-esp8266/test test` | 71 assertions | C++ under ASAN/UBSAN |
| Firmware sketch | `firmware/doorlock-esp8266/test/build_sketch.sh` | 2 compile targets | Sketch type-check |
| Firmware ↔ backend | `python3 tests/http/firmware_protocol_test.py` | 17 tests | Cross-language protocol |

`tests/run-all.sh` runs every suite the environment supports:

```bash
tests/run-all.sh

DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090 \
DOORLOCK_TEST_ADMIN=admin \
DOORLOCK_TEST_ADMIN_PASSWORD='…' \
tests/run-all.sh
```

The script prefers a native `php` and falls back to the development PHP.wasm
runtime (`node tools/php-wasm/php.mjs`). Without `DOORLOCK_TEST_BASE_URL` the
two HTTP suites are skipped and reported as skipped, never as passed.

## 1. PHP syntax

`tests/lint.php` runs `token_get_all(..., TOKEN_PARSE)` over every PHP file in
`api/`, `bin/`, `config/`, `includes/` and `tests/`, which is the equivalent of
`php -l` and works where spawning a second PHP binary is impossible.

## 2. Unit and integration suite

```bash
php tests/run.php                 # everything
php tests/run.php --list          # ids, descriptions, tags
php tests/run.php --tag=engine    # one tag
php tests/run.php --filter=SCH-   # one group
php tests/run.php --json=/tmp/report.json
```

The runner creates a fresh SQLite database in the system temp directory
(`DOORLOCK_TEST_DB_PATH` to choose a path), applies the SQLite migration and
seeds deterministic fixtures through `tests/helpers.php`. `reset_data()`
returns the seeded rows so tests do not seed twice.

Fixtures:

- users `admin`, `operator`, `viewer`, `disabled` (the last is inactive);
- doors `main` (5 s), `server` (3 s), `inactive`;
- persons `alice` (enabled), `bob` (disabled), `carol`;
- cards `04AABBCC` (alice), `04DDEEFF` (bob), `04CCDDEE` (revoked, carol),
  `04FFEEDD` (lost);
- group `Staff` with alice and carol, granting the `main` door;
- devices `0123…def` → `main`, `fedc…210` → `server`.

| Prefix | File | Focus |
| --- | --- | --- |
| `DEV-` | `unit/device_auth_test.php` | Canonical string, key derivation, HMAC vector, skew, replay, rate limit. |
| `SCH-` | `unit/schedule_test.php` | Weekdays, all-day and overnight windows, boundaries, `days` parsing, timezone. |
| `VAL-` | `unit/validation_test.php` | Field validators, pagination bounds, request ids, SQL splitting, card masking, CSV escaping. |
| `ENG-` | `unit/access_engine_test.php` | Decision precedence and every reason code. |
| `AS-` | `integration/access_service_test.php` | End-to-end decisions against the database, grant consumption, access-log rows. |
| `AUTH-` | `integration/auth_test.php` | Login, lockout, session versioning, password change, CSRF rotation. |

## 3. HTTP contract suite

Exercises the deployed application through a real web server.

```bash
# Any server that can run the application:
php -S 127.0.0.1:8080 -t /path/to/doorlock
# or, without PHP installed (development harness):
node tools/php-wasm/serve.mjs --port 8090 --host 0.0.0.0

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

The tests create data with a random suffix. They are safe to re-run against a
development database but must not be pointed at production.

## 4. Firmware core (host)

```bash
make -C firmware/doorlock-esp8266/test test
```

Compiles `firmware/doorlock-esp8266/src/*.cpp` with
`-std=c++11 -Wall -Wextra -Werror -fsanitize=address,undefined` and runs 71
assertions:

| Group | Asserts |
| --- | --- |
| FW-001 … FW-003 | SHA-256 (FIPS vectors), HMAC-SHA256 (RFC 4231), the documented device signature vector, HTTP request bytes |
| FW-004 … FW-009 | Response classification, chunked decoding, malformed input, ambiguous JSON, duration clamping |
| FW-010 … FW-014 | Relay start state, interval, wrap-around, reboot, debounce, framing limits |
| FW-015 | Request builder rejects invalid credentials and short buffers |

These are unit tests of the real firmware sources, not a simulation of a
different implementation.

## 5. Firmware sketch type-check

```bash
firmware/doorlock-esp8266/test/build_sketch.sh
```

Compiles `DoorLock.ino` against minimal Arduino/ESP8266 API stubs, once with
the serial fallback and once with the MFRC522 code path, using
`-std=c++17 -Wall -Wextra -Werror`.

This proves the sketch has no type or syntax errors in *our* code. It is not a
firmware build: the real ESP8266 toolchain is not available in this
environment (see `docs/test-report.md`). The ESP8266 build remains `BLOCKED`
and must be performed before deployment.

## 6. Firmware ↔ backend protocol

```bash
DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090 \
DOORLOCK_TEST_ADMIN=admin \
DOORLOCK_TEST_ADMIN_PASSWORD='…' \
python3 tests/http/firmware_protocol_test.py
```

The test builds `live_request`, which signs a request using the firmware's own
C++ code, sends those bytes over a socket to the PHP backend and checks:

- provisioning returns a usable 64-hex key;
- a signed request for an authorised card is granted with the door's
  `unlock_seconds` and the firmware's request id;
- replay, wrong key, stale timestamp and a disabled device are rejected;
- unknown card and explicit deny return HTTP 200 with `access: false`.

This is the compatibility test between the two halves of the product. It
requires `g++` and `python3` (development-only dependencies).

## Manual smoke test after deployment

1. `curl -fsS https://door.example.com/api/health.php` → `"status":"ok"`.
2. Log in and confirm the dashboard counts.
3. Create a door, person, schedule and permission; confirm the SPA renders them.
4. Provision a device and send one signed request (see `docs/api.md`).
5. Replay it: expect `401 replayed_request`.
6. Log out and confirm `/api/persons.php` returns 401.

## Adding tests

`tests/TestRunner.php` exposes `test($id, $description, $callback)` in the
`DoorLock\Tests` namespace plus `Assert::same/notSame/true/false/null/contains/
count/throws`. Tests must be independent: call `reset_data()` when they write.
