# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.0.x | Yes |
| 1.x (original code in `legacy/`) | No — the legacy files are not deployed and receive no fixes |

The canonical controller firmware is `firmware/doorlock-esp8266/`. It has not
yet been compiled with the ESP8266 toolchain nor exercised on hardware in this
environment; see `firmware/README.md` for the verification status.

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Use GitHub's private vulnerability reporting on this repository
("Security" → "Report a vulnerability"), or contact the maintainers directly
through the address listed on the repository profile. Include:

- affected version or commit,
- the endpoint or file involved,
- reproduction steps or a proof of concept,
- impact (for example: "opens a door without a card"),
- whether the issue is already public.

You can expect an acknowledgement within 3 business days and a status update
within 10 business days. Please allow up to 90 days before public disclosure;
we will credit reporters who wish to be named.

## Scope

In scope:

- authentication, authorization and session handling (`includes/auth.php`,
  `includes/security.php`, `includes/rbac.php`),
- the access decision and its persistence (`includes/access_engine.php`,
  `includes/access_service.php`),
- the device protocol (`includes/device_auth.php`,
  `api/check-access.php`),
- input validation, SQL construction, output encoding and the admin API,
- the configuration and installer scripts in `bin/`.

Out of scope:

- the original application kept in `legacy/` (documented as insecure and
  blocked by the shipped server rules),
- the development harness in `tools/php-wasm/` (never deployed),
- findings that require an already-compromised server, database or browser,
- missing hardening that does not enable a concrete attack (for example, a
  missing header without an exploitable consequence),
- rate limiting or brute-force findings against a deployment that disabled
  the documented defaults.

## Known limitations

1. The panel has no multi-factor authentication. Deploy it behind a VPN or an
   identity-aware proxy if it is reachable from untrusted networks.
2. The legacy ESP8266 sketches in `legacy/firmware/` are insecure and
   incompatible. Use the canonical firmware in `firmware/doorlock-esp8266/`;
   it still needs an ESP8266 build and hardware validation before a pilot.
3. Relay polarity, lock type (fail-safe vs fail-secure) and wiring have not
   been verified against physical hardware. Electrical and egress safety are
   installation responsibilities; see `firmware/README.md`.
4. Automated tests run against SQLite. MySQL is supported and documented, but
   a MySQL deployment should be smoke-tested after the first installation.
5. Backups, TLS termination, NTP on controllers and log retention are
   operational responsibilities. See `docs/deployment.md`.
6. Physical security (relay wiring, lock selection, tamper switches) is
   outside the software's scope.

## Security design

`docs/security.md` documents the threat model, the controls that implement
each mitigation, the role matrix, the cryptography and the verification that
was performed. Read it before deploying.
