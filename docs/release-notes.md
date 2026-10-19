# DoorLock 2.0 — release notes and readiness assessment

## What this release is

A complete rewrite of the original DoorLock prototype:

- a single, documented access-decision engine used by both the panel and the
  controllers;
- a hardened administrative API and SPA (Persian RTL UI, ES modules, no build
  step, no CDN);
- a signed device protocol with replay protection and key rotation;
- a canonical ESP8266 firmware whose security-critical logic is unit-tested on
  a workstation;
- versioned migrations (MySQL and SQLite), an installer, retention tooling and
  a reproducible release builder;
- documentation for architecture, API, security, deployment and testing.

## Verification in this environment

| Evidence | Result |
| --- | --- |
| PHP syntax, 56 files | PASS |
| PHP unit + integration, 76 tests | PASS |
| HTTP contract, 20 tests | PASS |
| Firmware core, 71 assertions with ASAN/UBSAN | PASS |
| Firmware sketch type-check, 2 configurations | PASS |
| Firmware ↔ backend protocol, 17 tests | PASS |
| Release archive extracted, installed and served | PASS |

Full commands and per-test results: `docs/test-report.md`.

## Readiness assessment

**Level: ready for controlled testing.**

What that means:

- The backend, the protocol and the panel are implemented, documented and
  covered by executed tests.
- The system is suitable for a lab, a staging deployment or an internal pilot
  where a failure of a door controller has no safety consequence.
- It is **not** yet "ready for a supervised pilot" on a real door, and not
  production-ready, because the firmware has not been built with the ESP8266
  toolchain or exercised on hardware, and the MySQL path has not been executed
  against a live server.

### Gate to "ready for a supervised pilot"

1. Build the sketch with the ESP8266 core and resolve any compiler errors
   (the code is type-checked, not toolchain-compiled).
2. Flash one controller; verify the relay is de-energised at boot and after a
   power cut; verify the pulse length equals the door's `unlock_seconds`.
3. Verify polarity and lock type on the actual hardware; record it.
4. Confirm the door stays locked with the server powered off, with the network
   disconnected, and with a replayed request.
5. Run `tests/run-all.sh` against a staging MySQL instance.
6. Complete a manual browser pass for RTL layout, keyboard navigation and
   error states.

### Gate to production

- Complete the pilot gate and run a supervised period with two doors.
- Configure HTTPS, HSTS, backups and `bin/prune-logs.php` in cron.
- Decide on MFA or network-level protection for the admin panel.
- Define retention and access-review procedures for the access logs.
- Test restore from backup on a separate host.

## Known limitations

1. **Firmware hardware verification is missing.** No board was available; the
   ESP8266 toolchain could not be downloaded in this environment.
2. **MySQL is unexecuted.** The schema and the driver branch exist and are
   reviewed; only SQLite was executed.
3. **No MFA** for administrator accounts.
4. **Single-instance assumptions.** Rate limiting is database-backed, so it
   works across PHP-FPM workers, but session storage must be shared if several
   web servers are used.
5. **Legacy code is retained but quarantined** under `legacy/` for reference
   only; it is excluded from releases and denied by the web server.
6. **No CI pipeline.** All suites are scripted (`tests/run-all.sh`) but must be
   wired into the operator's CI.

## Upgrade / rollback

- This is a new schema (`schema_migrations`), not an in-place upgrade of the
  legacy `database.sql`. Importing legacy data requires a mapping script
  (people, cards, doors, permissions) — the schemas differ substantially and
  no automatic migration is shipped.
- Rollback: keep the previous release directory and a database dump. The
  application performs no destructive schema change at runtime; rolling back
  the code is enough unless a future migration has been applied.

## Deliverables

| Artifact | Path |
| --- | --- |
| Release archive (source, tests, firmware, docs) | `release/doorlock-2.0.0.zip` |
| Audit report (ten areas + additional findings) | `docs/audit-report.md` |
| Test report (commands, results, blocked items) | `docs/test-report.md` |
| Deployment and recovery guide | `docs/deployment.md` |
| Security model and threat analysis | `docs/security.md` |
| API contracts and reference vectors | `docs/api.md` |
| Firmware build, wiring and provisioning | `firmware/README.md` |

## Licensing

The application has no third-party runtime dependencies (no Composer
packages, no CDN assets). Development-only dependencies:

- `@php-wasm/node` (MIT) — only in `tools/php-wasm/`, excluded from releases;
- MFRC522 Arduino library (LGPL-2.1) — linked into the firmware if used; review
  LGPL obligations before distributing binaries;
- ESP8266 Arduino core (LGPL-2.1) — same consideration.

The project itself ships without an explicit license file; add the license
chosen by the owner before distributing it commercially.
