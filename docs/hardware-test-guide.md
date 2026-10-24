# Hardware test guide

Bench procedure for the physical door controller: ESP8266 (NodeMCU / Wemos D1
mini), RC522 RFID reader, relay module and lock.

This guide exists because the automated suites stop at the host boundary. The
firmware core is unit-tested under ASan/UBSan and the sketch is type-checked
against API stubs, but **neither of those proves anything about a real board,
a real relay or a real lock**. Everything below must be executed on hardware by
a person who can see the lock and reach the power switch.

Two of the three release gates depend on this document:

- **Gate A** — a real `arduino-cli` compile for `esp8266:esp8266:nodemcuv2`.
- **Gate C** — a browser/HTTPS and hardware bench pass.

Until a run of this guide is recorded in
[`hardware-test-results-template.md`](hardware-test-results-template.md), both
gates stay **BLOCKED**. Host-stub results are not evidence for either.

## Safety first

The lock is a physical access control device. A mistake here can leave a door
permanently open or permanently locked.

1. **Bench test with the lock disconnected.** Measure everything on the relay
   contacts with a multimeter before the lock is wired in. Section 3 exists for
   exactly this reason.
2. **Know the mechanism.** A *fail-safe* (fail-unlocked) strike releases when
   power is removed; a *fail-secure* (fail-locked) strike holds when power is
   removed. Your choice determines what a power cut does to the door. Record
   which one you are testing before you start.
3. **Never leave the rig unattended while energised.** A relay held closed by a
   bug keeps the door open.
4. **Do not test on a live door** until every test in this guide passes on the
   bench.
5. Keep the 12 V lock supply and the 3.3 V logic supply separate. Do not power
   the lock from the ESP8266 regulator, and do not feed 12 V into the RC522.

## 1. Wiring verification

Power off before checking any of this. Verify with a multimeter in continuity
mode, not by eye.

### ESP8266 to RC522 (SPI)

The RC522 is a 3.3 V part. Its `VCC` goes to the 3.3 V rail, never to 5 V.

| RC522 pin | NodeMCU pin | GPIO | Notes |
| --- | --- | --- | --- |
| `SDA` / `SS` | `D8` | 15 | `kRfidSsPin` in `config.h` |
| `SCK` | `D5` | 14 | hardware SPI |
| `MOSI` | `D7` | 13 | hardware SPI |
| `MISO` | `D6` | 12 | hardware SPI |
| `RST` | `D3` | 0 | `kRfidRstPin` in `config.h` |
| `GND` | `GND` | — | common ground with the relay board |
| `3.3V` | `3V3` | — | **not** `VIN`/`5V` |
| `IRQ` | — | — | not connected; the firmware polls |

Checks:

- [ ] Continuity from RC522 `GND` to ESP8266 `GND`.
- [ ] 3.3 V measured at RC522 `VCC` with the board powered (not 5 V).
- [ ] No continuity between the 3.3 V rail and the 5 V rail.
- [ ] `SS` and `RST` match the pin numbers compiled into `config.h`. A mismatch
      looks like "reader not detected" with no other symptom.

### Relay module

| Relay pin | ESP8266 pin | GPIO | Notes |
| --- | --- | --- | --- |
| `IN` / `SIG` | `D1` | 5 | `kRelayPin` in `config.h` |
| `VCC` | `5V` (`VIN`) | — | most opto-isolated boards need 5 V |
| `GND` | `GND` | — | common ground with the ESP8266 |

- [ ] Relay `VCC` on 5 V, coil side only. The ESP8266 GPIO drives the opto input,
      not the coil.
- [ ] Common ground between ESP8266, relay board and RC522.
- [ ] Signal wire on the pin named by `kRelayPin` (default `D1` / GPIO 5).

### Lock mechanism

- [ ] Mechanism type recorded: **fail-safe** or **fail-secure**.
- [ ] Lock supply voltage and current measured and within the relay contact
      rating printed on the module.
- [ ] The relay is switching the **low side or the supply**, whichever your
      wiring diagram specifies — not both.
- [ ] Flyback diode present across a DC solenoid lock (or the lock module has
      one built in). Without it the coil spike will eventually kill the relay
      contact or reset the ESP8266.

## 2. Relay polarity and idle state — measure before connecting the lock

`kRelayActiveHigh` defaults to `false` because most opto-isolated boards are
active LOW. Getting this backwards means the relay is energised at boot, which
on a fail-safe lock means the door is open whenever the board has power.

Do this with the lock **disconnected**. Use
[`tools/hardware-test/relay_polarity_test.ino`](../tools/hardware-test/relay_polarity_test.ino),
which toggles the relay at 2 seconds on / 3 seconds off and holds the locked
level from boot.

- [ ] Flash the polarity sketch. The relay must be **idle (locked) immediately
      after reset**, including during the bootloader window.
- [ ] Listen and watch: clicks should be 2 s apart, evenly spaced, with no
      chatter and no double-clicks.
- [ ] Multimeter across the relay contacts: the contact that is closed while
      idle is the **locked** contact. Confirm that the sketch's "locked"
      statement matches the physical state.
- [ ] If the relay is energised at idle instead of released, flip
      `kRelayActiveHigh` in the sketch, reflash, and measure again.
- [ ] Record the measured idle state and the final value of `kRelayActiveHigh`.
      The same value goes into `config.h` for the real firmware.
- [ ] Leave it powered for 10 minutes at idle and confirm the relay does not get
      hot. A warm coil at idle means it is energised when it should not be.

Only after this section passes may the lock be connected.

## 3. Unlock pulse duration

The server returns the unlock duration per decision (`kDefaultUnlockSeconds`,
capped by `kMaxUnlockSeconds` = 30 s).

- [ ] Connect a logic analyser, oscilloscope or a second multimeter to the relay
      signal, or time it manually with a stopwatch.
- [ ] Trigger an authorised unlock from the panel and measure the pulse.
- [ ] Measured duration equals the value returned by the server, within ±100 ms.
- [ ] Request a duration above the cap (for example 120 s) and confirm the relay
      releases at 30 s, not at 120 s.
- [ ] Confirm the relay releases when the pulse completes, without needing a
      second request.

## 4. Boot and reset behaviour

The firmware locks the relay in `setup()` before it touches Wi-Fi, NTP or TLS.

- [ ] With the lock connected, press reset 10 times. The relay must never pulse
      open during boot.
- [ ] Pull the power and restore it: same result.
- [ ] Hold the board in reset for 5 seconds while watching the relay contact:
      it must stay in the locked state.
- [ ] With `config.h` deliberately broken (leave a `CHANGE_ME` placeholder), boot
      the board. It must enter safe mode, hold the relay locked and blink the
      status LED. It must not attempt to reach the network.
- [ ] Restore the real `config.h` afterwards.

## 5. Fail-closed tests

Each of these must end with **no unlock**. If any of them opens the relay, stop
and fix the firmware before continuing.

### 5.1 Network loss

- [ ] Boot the board and confirm it reaches the server (a scan of an authorised
      card unlocks the door once, as a control).
- [ ] Disable the access point / pull the Wi-Fi antenna / block the MAC on the
      router so the board cannot associate.
- [ ] Present an authorised card. Wait at least 10 s.
- [ ] Confirm: no relay pulse, and the status LED indicates the offline state.
- [ ] Re-enable the network and confirm recovery without a power cycle.

### 5.2 Server failure

- [ ] Stop the backend (or firewall port 443 from the controller's address).
- [ ] Present an authorised card. Wait longer than `kHttpTimeoutMs` (4 s).
- [ ] Confirm: no relay pulse. The request must time out, not fall back to
      "allow".
- [ ] Restart the backend and confirm the next authorised scan works.

### 5.3 Invalid TLS

- [ ] Point `kApiHost` at a host serving a certificate that does **not** match
      the configured CA or fingerprint (a second host, a self-signed cert, or a
      deliberately wrong fingerprint).
- [ ] Present an authorised card.
- [ ] Confirm: no relay pulse, and the serial log shows a TLS failure.
- [ ] Confirm the firmware source contains no `setInsecure()` call:
      `grep -rn "setInsecure" firmware/` returns nothing.

### 5.4 Malformed response

- [ ] Stand up a stub that answers the signed request with: invalid JSON, valid
      JSON missing `allowed`, `allowed` as a string instead of a boolean, and a
      response with a bad signature if the protocol carries one.
- [ ] For each case, present an authorised card.
- [ ] Confirm: no relay pulse in any case. A parse failure must be treated as
      deny.

### 5.5 Replayed request

- [ ] Capture one valid signed request (serial log, or a proxy such as
      `mitmproxy` with the CA installed).
- [ ] Replay it byte-for-byte with the same nonce and timestamp.
- [ ] Confirm: the server answers `401 replayed_request`, and the relay does not
      pulse.
- [ ] Repeat with a fresh nonce but an old timestamp; confirm `401`.

### 5.6 Repeated scan / debounce

Debounce window is `kCardRepeatMs` (default 3000 ms).

- [ ] Present the same card 10 times in 5 seconds.
- [ ] Confirm: at most one unlock, and no relay chatter (no rapid
      click-click-click).
- [ ] Present a *different* authorised card inside the window; confirm the
      debouncer is per-card, not global.
- [ ] Present an unknown card rapidly; confirm no unlock and no relay action.

## 6. Resilience tests

### 6.1 Watchdog recovery

- [ ] Force a hang. Easiest reproducible ways: hold a card against the reader
      while the network is black-holed, or attach a debugger and pause the loop.
- [ ] Confirm the board reboots (serial banner repeats) and the relay is locked
      after recovery.
- [ ] Confirm the reboot was caused by the watchdog, not by a manual reset.

### 6.2 Power loss during unlock

- [ ] Trigger an authorised unlock.
- [ ] While the relay is still energised, cut power completely.
- [ ] Restore power and watch the relay from the instant power returns.
- [ ] Confirm: the relay comes up **locked** and stays locked until a new
      authorised decision arrives.
- [ ] Repeat with a fail-safe strike: note that the door physically opens while
      unpowered. That is the mechanism, not a firmware fault — record it so the
      site owner understands the trade-off.

## 7. Recording results

Fill in [`hardware-test-results-template.md`](hardware-test-results-template.md)
as you go, not afterwards. For every row keep the evidence: a serial log line
from `tools/hardware-test/capture_serial.sh`, a photo of the multimeter, or a
screenshot of the server response.

A test with no evidence is `BLOCKED`, not `PASS`.

## 8. What this does not cover

- **Gate A** (toolchain compile) is a separate step; see
  `firmware/README.md`. This guide assumes a flashable binary already exists.
- **Gate B** (MySQL 8, concurrency, backup/restore) is server-side and has no
  hardware component.
- Long-term soak, RF range, and enclosure/thermal behaviour are deployment
  concerns outside the release gates.
