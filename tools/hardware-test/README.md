# Hardware test helpers

Small, single-purpose tools for the bench procedure in
[`docs/hardware-test-guide.md`](../../docs/hardware-test-guide.md). They are
**not** part of the firmware and are never shipped: `tools/` is excluded from
release archives by `bin/build-release.php`.

Nothing in this directory replaces a real test. They exist so the physical
tests can be repeated, timed and evidenced.

| File | Purpose |
| --- | --- |
| `relay_polarity_test.ino` | Drives the relay slowly so polarity and idle state can be measured before a lock is connected. |
| `capture_serial.sh` | Logs serial output with a UTC timestamp per line, for evidence. |

## relay_polarity_test.ino

A standalone sketch. It contains no Wi-Fi, no TLS, no RFID and no DoorLock
logic — only the relay pin.

Before flashing, set the two constants at the top:

```cpp
constexpr uint8_t kRelayPin = 5;          // D1 on NodeMCU / Wemos D1 mini
constexpr bool kRelayActiveHigh = false;  // most opto-isolated boards are active LOW
```

Behaviour:

- the relay is driven to its **locked** level in `setup()`, before `Serial`;
- 20 cycles of 2 s unlocked / 3 s locked, then it stops with the relay released;
- 20 cycles then a stop is deliberate, so an unattended rig never keeps
  clicking.

Flash it with the lock **disconnected**, then follow section 2 of the guide.
If the relay is energised at idle, flip `kRelayActiveHigh`, reflash and measure
again. The value that measures correctly is the value that belongs in
`config.h`.

Compiling: this sketch is host type-checked against the same API stubs as the
real firmware, which catches syntax and type errors but produces no ESP8266
binary.

```bash
g++ -x c++ -std=c++17 -Wall -Wextra -Werror -Wno-unused-parameter \
    -I firmware/doorlock-esp8266/test/stubs -include Arduino.h \
    -c tools/hardware-test/relay_polarity_test.ino -o /tmp/relay.o
```

For a flashable build use `arduino-cli` as described in `firmware/README.md`.

## capture_serial.sh

```bash
tools/hardware-test/capture_serial.sh                     # first USB serial device
tools/hardware-test/capture_serial.sh /dev/ttyUSB0        # explicit device
tools/hardware-test/capture_serial.sh /dev/ttyUSB0 run-01.log
BAUD=115200 tools/hardware-test/capture_serial.sh /dev/cu.usbserial-1410
```

- picks `/dev/ttyUSB*`, `/dev/ttyACM*`, `/dev/cu.usbserial*`, `/dev/cu.usbmodem*`
  or `/dev/cu.SLAB_USBtoUART*` when no device is given;
- configures the port for the given baud rate (default 115200, matching
  `Serial.begin` in the firmware);
- prefixes every line with a UTC timestamp and appends it to the log, while
  echoing to the terminal;
- restores the previous port settings on exit, so the device is usable
  afterwards.

Stop it with `Ctrl-C`. The log is the evidence to attach to a test row.

Requirements: `bash`, `stty` (coreutils on Linux, BSD `stty` on macOS). No
Python, no `socat`, no extra packages.

To try it without hardware, create a pseudo-serial pair with `socat`:

```bash
socat -d -d pty,raw,echo=0,link=/tmp/ttyA pty,raw,echo=0,link=/tmp/ttyB &
tools/hardware-test/capture_serial.sh /tmp/ttyA /tmp/demo.log
printf 'state: LOCKED\r\n' > /tmp/ttyB     # in another shell
```

## Recording results

Use [`docs/hardware-test-results-template.md`](../../docs/hardware-test-results-template.md).
A row without evidence is `BLOCKED`, not `PASS`.
