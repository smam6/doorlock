# DoorLock ESP8266 controller firmware

Canonical production firmware. It replaces the three inconsistent sketches
that are kept under `legacy/firmware/` for reference only.

```
doorlock-esp8266/
├── DoorLock.ino        Arduino sketch: Wi-Fi, TLS, RC522, relay wiring
├── config.example.h    configuration template (copy to config.h)
├── src/                portable logic, also compiled by the host tests
│   ├── sha256.*        SHA-256 + HMAC-SHA256
│   ├── json_lite.*     allocation-free JSON field extraction
│   ├── http_response.* bounded HTTP/1.1 parser (Content-Length, chunked)
│   ├── device_request.* canonical string, HMAC, HTTP request builder
│   └── doorlock_core.* decision evaluation, relay and debounce logic
└── test/
    ├── test_core.cpp       71 host assertions (ASAN/UBSAN)
    ├── live_request.cpp    emits a signed request for the e2e protocol test
    ├── build_sketch.sh     type-checks the sketch against API stubs
    ├── stubs/              minimal Arduino/ESP8266 API declarations
    └── Makefile
```

## Why the logic is split

An ESP8266 cannot be tested in CI, and flashing a controller for every change
is unsafe. Everything that can open a door — signature construction, response
validation, duration clamping, relay timing — is therefore plain C++11 with no
Arduino dependency. It is compiled and tested on a workstation with
AddressSanitizer and UndefinedBehaviorSanitizer, and the same files are
compiled into the firmware.

## Safety properties

1. **Locked before anything else.** `setup()` configures the relay output and
   calls `RelayController::begin()` before Wi-Fi, RFID or TLS initialisation.
2. **One decision gate.** The relay is energised only when the response is
   HTTP 200, `Content-Type: application/json`, `success: true`, exactly one
   boolean `access` field equal to `true`, and `reason: "granted"`. Duplicated
   fields, nested decoys, string `"true"`, truncated bodies, HTML error pages,
   HTTP 4xx/5xx, TLS failures and timeouts never open the door.
3. **No insecure TLS.** There is no `setInsecure()` option. Configuration must
   provide either a CA bundle or a certificate fingerprint; otherwise the
   controller enters safe mode with the relay locked.
4. **Non-blocking unlock.** The relay interval is tracked with `millis()`; the
   RFID reader, Wi-Fi stack and watchdog keep running. Repeated scans restart
   the interval instead of stacking additional time, and the duration is
   clamped to 1–30 s (both locally and by the server).
5. **Safe start-up.** A missing/placeholder configuration, an absent RC522 or
   an unset TLS anchor puts the device into safe mode: relay locked, LED
   blinking, no requests sent.
6. **Bounded work.** Each HTTP request has a deadline, the response body is
   capped at 1 KiB, header lines at 256 bytes and the request buffer at 1 KiB.
   The watchdog is fed during network waits.
7. **No secrets in logs.** Only the card UID, decision, reason and request id
   are printed. Keys and signatures are never logged.

## Hardware

| Signal | Default pin | Notes |
| --- | --- | --- |
| RC522 SDA/SS | D8 (GPIO15) | SPI chip select |
| RC522 SCK/MOSI/MISO | D5/D7/D6 | hardware SPI |
| RC522 RST | D3 (GPIO0) | keep high at boot |
| Relay IN | D1 (GPIO5) | **verify polarity** (see below) |
| Status LED | D4 (GPIO2) | onboard LED, active LOW |

Power the RC522 from 3.3 V only. Use a separate 5 V supply for the relay
board if it draws more than the ESP8266 regulator can deliver, and a flyback
diode across a DC lock solenoid.

### Relay polarity and fail-safe/fail-secure locks

`cfg::kRelayActiveHigh` describes the relay module input, not the lock:

- Most opto-isolated relay boards are **active LOW** (`false`).
- The firmware drives the locked level in `setup()` and after every unlock, so
  a controller reboot releases the relay.

A **fail-safe** (fail-open) lock unlocks when power is removed. A
**fail-secure** lock stays locked without power. The firmware cannot change
that; choose the lock type for the door's egress requirements and verify the
wiring with a multimeter before deploying. Never rely on software alone for
life-safety egress.

## Configuration

```bash
cd firmware/doorlock-esp8266
cp config.example.h config.h
$EDITOR config.h      # Wi-Fi, host, device id/key, pin map, TLS anchor
```

`config.h` contains secrets and is git-ignored and excluded from releases.
The firmware refuses to start while any value still contains `CHANGE_ME`.

TLS trust: prefer `cfg::kTlsCaPem` (PEM of your CA or the server certificate).
For a self-signed certificate you may instead set `cfg::kTlsFingerprint` to
the 40-hex SHA-1 fingerprint:

```bash
openssl s_client -connect door.example.com:443 -servername door.example.com </dev/null 2>/dev/null \
  | openssl x509 -noout -fingerprint -sha1
```

Fingerprints pin the exact certificate; you must reflash when it rotates.
A CA bundle tolerates normal certificate renewal.

## Build and flash

Arduino IDE:

1. Install the **ESP8266** board package (Boards Manager URL
   `https://arduino.esp8266.com/stable/package_esp8266com_index.json`), core
   **3.1.x**.
2. Install **MFRC522** by GithubCommunity (1.4.x) from Library Manager.
3. Board: *NodeMCU 1.0 (ESP-12E Module)* or your board; Flash size 4 MB.
4. Open `DoorLock.ino`, select the port, upload.

arduino-cli:

```bash
arduino-cli core install esp8266:esp8266
arduino-cli lib install MFRC522
arduino-cli compile --fqbn esp8266:esp8266:nodemcuv2 firmware/doorlock-esp8266
arduino-cli upload  --fqbn esp8266:esp8266:nodemcuv2 -p /dev/ttyUSB0 firmware/doorlock-esp8266
```

Without the MFRC522 library the sketch still compiles and accepts a card UID
typed into the serial monitor (115200 baud) — useful for bench tests without
the reader.

## Provisioning and key rotation

1. In the panel: **Devices → New device**, enter the 32-hex `unique_id`
   printed by the controller at first boot (or generate one) and assign a door.
2. **Provision** shows `device_key` once. Put it in `config.h` as
   `kDeviceKeyHex` and flash.
3. To rotate: **Devices → Rotate key**, copy the new key, reflash. The old key
   stops working immediately and outstanding nonces are purged.
4. To revoke: set the device **inactive**. Requests are rejected with 401 and
   the door stays locked.

The device key is derived from the server's `app_key`; rotating `app_key`
invalidates every device and requires re-provisioning all controllers.

## Testing

```bash
# portable logic (ASAN + UBSAN), 71 assertions
make -C firmware/doorlock-esp8266/test test

# type-check the sketch against API stubs (not an ESP8266 binary)
firmware/doorlock-esp8266/test/build_sketch.sh

# firmware <-> backend protocol, against a running server
DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090 \
DOORLOCK_TEST_ADMIN=admin \
DOORLOCK_TEST_ADMIN_PASSWORD='…' \
python3 tests/http/firmware_protocol_test.py
```

The protocol test builds `live_request`, which produces request bytes with the
firmware's own C++ code, and sends them to the PHP backend over a socket. It
verifies grant, replay rejection, wrong key, stale timestamp, unknown card,
explicit deny and a disabled device.

## Verification status

| Item | Status |
| --- | --- |
| Portable core compiled with g++ 12 (C++11, `-Wall -Wextra -Werror`) | PASS |
| Core unit tests, 71 assertions under ASAN/UBSAN | PASS |
| Request bytes accepted by the PHP backend (17 e2e checks) | PASS |
| Sketch type-checked against Arduino/ESP8266 API stubs | PASS |
| Compiled with the real ESP8266 toolchain | **BLOCKED** — the sandbox cannot download the ESP8266 core (`objects.githubusercontent.com` is unreachable) |
| Flashed and tested on physical hardware | **BLOCKED** — no ESP8266, RC522, relay or lock available |
| Relay polarity / lock timing measured electrically | **BLOCKED** — depends on the hardware above |

Do not claim hardware readiness until the last three items are completed by
someone with the hardware. The software-side contract and the decision logic
are covered by the tests listed above.
