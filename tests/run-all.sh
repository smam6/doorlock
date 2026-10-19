#!/usr/bin/env bash
# Run every test suite that the current environment can execute.
#
#   tests/run-all.sh                     static + unit/integration + firmware
#   DOORLOCK_TEST_BASE_URL=… DOORLOCK_TEST_ADMIN=… \
#   DOORLOCK_TEST_ADMIN_PASSWORD=… tests/run-all.sh
#                                        also HTTP contract and firmware/backend e2e
#
# Uses a native `php` when available and the development PHP.wasm runtime
# otherwise. Firmware tests need g++ (and python3 for the protocol test).
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if command -v php >/dev/null 2>&1; then
    PHP=(php)
else
    PHP=(node tools/php-wasm/php.mjs)
fi

failed=0
run() {
    local name="$1"; shift
    echo
    echo "===== $name ====="
    if "$@"; then
        echo "----- $name: PASS"
    else
        echo "----- $name: FAIL"
        failed=1
    fi
}

run "PHP syntax" "${PHP[@]}" tests/lint.php
run "Unit and integration" "${PHP[@]}" tests/run.php

if [ -f firmware/doorlock-esp8266/test/Makefile ]; then
    run "Firmware core (host)" make -C firmware/doorlock-esp8266/test test
    run "Firmware sketch (stub compile)" firmware/doorlock-esp8266/test/build_sketch.sh
fi

if [ -n "${DOORLOCK_TEST_BASE_URL:-}" ]; then
    run "HTTP contract" "${PHP[@]}" tests/http/api_test.php
    if command -v python3 >/dev/null 2>&1; then
        run "Firmware/backend protocol" python3 tests/http/firmware_protocol_test.py
    else
        echo "(firmware/backend protocol skipped: python3 not installed)"
    fi
else
    echo
    echo "(HTTP suites skipped: set DOORLOCK_TEST_BASE_URL and credentials)"
fi

echo
if [ "$failed" -eq 0 ]; then
    echo "ALL EXECUTED SUITES PASSED"
else
    echo "ONE OR MORE SUITES FAILED"
fi
exit "$failed"
