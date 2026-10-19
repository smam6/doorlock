#!/usr/bin/env bash
# Type-check the ESP8266 sketch on a workstation using API stubs.
#
# This is NOT a firmware build: it compiles the real sketch source against
# minimal stubs of the Arduino/ESP8266 API to catch type and syntax errors in
# our own code. Building a flashable binary requires the ESP8266 Arduino core
# (see firmware/README.md).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
sketch_dir="$(cd "$(dirname "$here")" && pwd)"
out_dir="${TMPDIR:-/tmp}/doorlock-sketch-check"
mkdir -p "$out_dir"

common=(-x c++ -std=c++17 -Wall -Wextra -Werror -Wno-unused-parameter
        -I "$sketch_dir" -include Arduino.h -c)
src=("$sketch_dir/DoorLock.ino" "$sketch_dir"/src/*.cpp)

echo "== sketch check without RFID library (serial fallback) =="
for file in "${src[@]}"; do
    g++ "${common[@]}" -I "$here/stubs" "$file" -o "$out_dir/$(basename "${file%.*}").o"
done
echo "OK: DoorLock.ino compiles with the serial fallback"

echo "== sketch check with MFRC522 stub =="
for file in "${src[@]}"; do
    g++ "${common[@]}" -I "$here/stubs/rfid" -I "$here/stubs" "$file" -o "$out_dir/rfid_$(basename "${file%.*}").o"
done
echo "OK: DoorLock.ino compiles with the RC522 code path"

echo "SKETCH CHECK PASSED (host stubs; not an ESP8266 binary)"
