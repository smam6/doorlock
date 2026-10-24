#!/usr/bin/env bash
# Capture serial output from the controller with a UTC timestamp per line.
#
# Usage:
#   tools/hardware-test/capture_serial.sh [PORT] [LOG]
#   BAUD=115200 tools/hardware-test/capture_serial.sh /dev/ttyUSB0 run-01.log
#
# With no PORT the first USB serial device is used. The log is appended to, so
# several tests can share one file, and a copy is echoed to the terminal so you
# can watch the board while you work.
#
# Stop with Ctrl-C. The terminal settings that were changed are restored on
# exit, so the port is left usable for the next run.

set -euo pipefail

BAUD="${BAUD:-115200}"
STAMP_FORMAT="%Y-%m-%dT%H:%M:%SZ"

usage() {
    cat <<'EOF'
Usage: capture_serial.sh [PORT] [LOG]

Arguments:
  PORT   Serial device, e.g. /dev/ttyUSB0 or /dev/cu.usbserial-1410.
         Defaults to the first USB serial device found.
  LOG    Log file to append to. Defaults to hardware-test-<UTC time>.log

Environment:
  BAUD   Baud rate (default 115200, matching the firmware's Serial.begin).
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

find_port() {
    local candidate
    for candidate in \
        /dev/ttyUSB* /dev/ttyACM* \
        /dev/cu.usbserial* /dev/cu.usbmodem* /dev/cu.SLAB_USBtoUART*; do
        if [[ -c "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    return 1
}

PORT="${1:-}"
if [[ -z "$PORT" ]]; then
    if ! PORT="$(find_port)"; then
        echo "error: no USB serial device found; pass the device path explicitly" >&2
        echo "hint:  ls /dev/ttyUSB* /dev/ttyACM* /dev/cu.* 2>/dev/null" >&2
        exit 1
    fi
fi

if [[ ! -c "$PORT" ]]; then
    echo "error: $PORT is not a character device" >&2
    exit 1
fi

# Linux uses -F, macOS/BSD uses -f. Both take a device and a settings list.
# Validate the port before creating a log so a bad path leaves no stray file.
if stty -F "$PORT" -g >/dev/null 2>&1; then
    stty_dev=(-F "$PORT")
elif stty -f "$PORT" -g >/dev/null 2>&1; then
    stty_dev=(-f "$PORT")
else
    echo "error: cannot configure $PORT with stty" >&2
    echo "hint:  pass a serial device, e.g. /dev/ttyUSB0 or /dev/cu.usbserial-1410" >&2
    exit 1
fi

LOG="${2:-hardware-test-$(date -u +%Y%m%dT%H%M%SZ).log}"
mkdir -p "$(dirname "$LOG")"

echo "port:  $PORT"
echo "baud:  $BAUD"
echo "log:   $LOG"
echo "stop:  Ctrl-C"
echo "----------------------------------------"

saved_stty="$(stty "${stty_dev[@]}" -g)"
restored=0
restore() {
    # INT/TERM and EXIT can both fire; only report once.
    [[ "$restored" -eq 1 ]] && return
    restored=1
    if [[ -n "$saved_stty" ]]; then
        # shellcheck disable=SC2086
        stty "${stty_dev[@]}" $saved_stty 2>/dev/null || true
    fi
    echo "----------------------------------------"
    echo "capture stopped; log written to $LOG"
}
trap restore EXIT INT TERM

stty "${stty_dev[@]}" "$BAUD" cs8 -cstopb -parenb -echo raw 2>/dev/null \
    || stty "${stty_dev[@]}" "$BAUD" raw -echo

# Prefix each line with a UTC timestamp and keep a copy on stdout.
# `read` returns non-zero on EOF, which simply ends the loop.
while IFS= read -r line; do
    line="${line%$'\r'}"   # serial consoles send CRLF; keep the log LF-only
    printf '%s %s\n' "$(date -u +"$STAMP_FORMAT")" "$line"
done < "$PORT" | tee -a "$LOG"
