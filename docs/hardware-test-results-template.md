# Hardware test results

Copy this file to a dated name, for example
`docs/hardware-test-results-2026-11-02.md`, fill it in while testing, and
attach it to the PR or issue that closes the hardware gates.

Procedure: [`hardware-test-guide.md`](hardware-test-guide.md).

## Session

| Field | Value |
| --- | --- |
| Date (UTC) | |
| Operator | |
| Board | e.g. NodeMCU v3 / Wemos D1 mini |
| ESP8266 core version | e.g. `3.1.2` |
| MFRC522 library version | e.g. `1.4.11` |
| Firmware commit | `git rev-parse HEAD` |
| Lock mechanism | fail-safe / fail-secure |
| Lock supply | e.g. 12 V DC, measured current |
| Relay module | part number, `kRelayActiveHigh` |
| Serial log file | `run-01.log` |
| Bench or door | |

## Gate A — toolchain compile

Run before any of the bench tests below.

```bash
arduino-cli core install esp8266:esp8266@3.1.2
arduino-cli lib install "MFRC522@1.4.11"
cp firmware/doorlock-esp8266/config.example.h firmware/doorlock-esp8266/config.h
# fill in placeholders; config.h is gitignored and never committed
arduino-cli compile --fqbn esp8266:esp8266:nodemcuv2 firmware/doorlock-esp8266
```

| Item | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- |
| Core and library versions | `3.1.2` and `1.4.11` pinned | | | |
| Compile exit code | `0` | | | |
| Sketch binary size | fits flash, record bytes | | | |
| RAM usage | record bytes / percentage | | | |
| Compiler warnings | none from project code | | | |

Status: **PASS** only with the compiler output attached. Host-stub
type-checking is **not** evidence for this gate.

## Bench tests

Use `PASS`, `FAIL` or `BLOCKED` in the Status column. A row with no evidence is
`BLOCKED`.

### 1. Wiring

| # | Test | Command / step | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| W1 | RC522 ground continuity | multimeter, RC522 `GND` to ESP `GND` | continuous | | | |
| W2 | RC522 supply | measure `VCC` at the reader | 3.3 V, not 5 V | | | |
| W3 | Rail isolation | 3.3 V to 5 V continuity | open circuit | | | |
| W4 | SPI pin map | `SS`=D8, `SCK`=D5, `MOSI`=D7, `MISO`=D6, `RST`=D3 | matches `config.h` | | | |
| W5 | Relay supply | measure relay `VCC` | 5 V, coil side only | | | |
| W6 | Common ground | ESP, relay, reader | all common | | | |
| W7 | Flyback diode | inspect lock driver | present | | | |

### 2. Relay polarity and idle state (lock disconnected)

| # | Test | Command / step | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| P1 | Boot state | flash `relay_polarity_test.ino`, press reset | relay idle/locked immediately | | | |
| P2 | Idle after 10 min | leave powered | relay released, not warm | | | |
| P3 | Pulse cadence | watch for 20 cycles | 2 s on / 3 s off, no chatter | | | |
| P4 | Locked contact identified | multimeter across contacts at idle | closed contact = locked | | | |
| P5 | Final `kRelayActiveHigh` | flip and re-measure if needed | value recorded here: | | | |

**Do not proceed to the lock tests until P1–P5 pass.**

### 3. Unlock pulse duration

| # | Test | Command / step | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| T1 | Nominal pulse | authorised scan, server returns 5 s | 5 s ±100 ms | | | |
| T2 | Cap enforced | request 120 s | releases at 30 s | | | |
| T3 | Single pulse | one scan | one pulse, no repeat | | | |

### 4. Boot and reset

| # | Test | Command / step | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| B1 | Reset loop | press reset 10× | no unlock pulse | | | |
| B2 | Power cycle | remove and restore power | no unlock pulse | | | |
| B3 | Reset held | hold reset 5 s | relay stays locked | | | |
| B4 | Incomplete config | leave a `CHANGE_ME` placeholder | safe mode, locked, no network | | | |

### 5. Fail-closed

| # | Test | Command / step | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| F1 | Control: authorised scan | normal network | unlocks once | | | |
| F2 | Network loss | block association, scan card | no unlock | | | |
| F3 | Network recovery | restore network, scan | unlocks again, no reboot needed | | | |
| F4 | Server down | stop backend, scan | no unlock, timeout logged | | | |
| F5 | Server restored | restart backend, scan | unlocks | | | |
| F6 | Invalid TLS | wrong CA/fingerprint, scan | no unlock, TLS error logged | | | |
| F7 | No `setInsecure` | `grep -rn setInsecure firmware/` | no matches | | | |
| F8 | Malformed JSON | stub returns garbage | no unlock | | | |
| F9 | Missing decision field | valid JSON, no `allowed` | no unlock | | | |
| F10 | Wrong type | `allowed` as string | no unlock | | | |
| F11 | Replayed request | replay captured request | 401, no unlock | | | |
| F12 | Stale timestamp | fresh nonce, old timestamp | 401, no unlock | | | |

### 6. Debounce and resilience

| # | Test | Command / step | Expected | Observed | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| D1 | Same card ×10 in 5 s | rapid present | ≤1 unlock, no chatter | | | |
| D2 | Different card in window | two authorised cards | debounce is per card | | | |
| D3 | Unknown card ×10 | rapid present | no unlock, no relay action | | | |
| R1 | Watchdog recovery | force a hang | reboot, relay locked after | | | |
| R2 | Power loss mid-unlock | cut power during pulse | boots locked, stays locked | | | |
| R3 | Fail-safe power loss | same, fail-safe strike | door opens (mechanism), record it | | | |

## Summary

| Gate | Status | Blocking reason |
| --- | --- | --- |
| A — ESP8266 compile | | |
| C — hardware bench | | |

Sign-off: name, date, and the commit the firmware was built from.
