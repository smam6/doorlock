#!/usr/bin/env python3
"""End-to-end protocol test: firmware signing code <-> PHP backend.

The request bytes are produced by the *firmware's own* C++ implementation
(firmware/doorlock-esp8266/src) through the `live_request` helper, then sent
over a raw socket to a running instance of the application. This proves that
the firmware and the server agree on the wire format, not just that each side
passes its own tests.

Prerequisites:
  * a running server (native PHP or tools/php-wasm/serve.mjs),
  * g++ (to build the helper),
  * an administrator account.

Usage:
  DOORLOCK_TEST_BASE_URL=http://127.0.0.1:8090 \
  DOORLOCK_TEST_ADMIN=admin \
  DOORLOCK_TEST_ADMIN_PASSWORD='…' \
  python3 tests/http/firmware_protocol_test.py
"""

from __future__ import annotations

import http.cookiejar
import json
import os
import random
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE_URL = os.environ.get("DOORLOCK_TEST_BASE_URL", "http://127.0.0.1:8090").rstrip("/")
ADMIN = os.environ.get("DOORLOCK_TEST_ADMIN", "admin")
PASSWORD = os.environ.get("DOORLOCK_TEST_ADMIN_PASSWORD", "")
HELPER = ROOT / "firmware" / "doorlock-esp8266" / "test" / "live_request"

PASS = 0
FAIL = 0


def check(condition: bool, test_id: str, description: str, detail: str = "") -> None:
    global PASS, FAIL
    if condition:
        PASS += 1
        print(f"PASS  {test_id}  {description}")
    else:
        FAIL += 1
        print(f"FAIL  {test_id}  {description}" + (f" — {detail}" if detail else ""))


class Session:
    def __init__(self) -> None:
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ""

    def request(self, method: str, path: str, payload: dict | None = None) -> tuple[int, dict]:
        url = BASE_URL + path
        data = None
        headers = {"Accept": "application/json"}
        if payload is not None:
            data = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
            headers["Origin"] = BASE_URL
            if self.csrf:
                headers["X-CSRF-Token"] = self.csrf
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with self.opener.open(request, timeout=20) as response:
                status = response.status
                body = response.read()
        except urllib.error.HTTPError as error:
            status = error.code
            body = error.read()
        try:
            decoded = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            decoded = {"raw": body[:200].decode("utf-8", "replace")}
        return status, decoded

    def bootstrap(self) -> None:
        _, payload = self.request("GET", "/api/auth.php")
        self.csrf = payload.get("data", {}).get("csrf_token", "")

    def login(self) -> None:
        self.bootstrap()
        status, payload = self.request(
            "POST", "/api/auth.php", {"action": "login", "username": ADMIN, "password": PASSWORD}
        )
        if status != 200:
            raise SystemExit(f"login failed: HTTP {status} {payload}")
        self.csrf = payload["data"]["csrf_token"]


def build_helper() -> None:
    if HELPER.exists():
        return
    subprocess.run(
        ["make", "-C", str(HELPER.parent), "live_request"],
        check=True,
        stdout=subprocess.DEVNULL,
    )


def signed_request(unique_id: str, key: str, door_id: int, card: str, nonce: str,
                   request_id: str, timestamp: int | None = None) -> bytes:
    if timestamp is None:
        timestamp = int(time.time())
    result = subprocess.run(
        [str(HELPER), unique_id, key, str(door_id), card, str(timestamp), nonce, request_id],
        check=True,
        capture_output=True,
    )
    return result.stdout


def send_raw(payload: bytes) -> tuple[int, dict[str, str], bytes]:
    parsed = urllib.parse.urlparse(BASE_URL)
    host = parsed.hostname or "127.0.0.1"
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    with socket.create_connection((host, port), timeout=15) as connection:
        connection.sendall(payload)
        chunks = []
        while True:
            block = connection.recv(4096)
            if not block:
                break
            chunks.append(block)
    raw = b"".join(chunks)
    head, _, body = raw.partition(b"\r\n\r\n")
    lines = head.decode("iso-8859-1").split("\r\n")
    status = int(lines[0].split(" ")[1])
    headers = {}
    for line in lines[1:]:
        if ":" in line:
            name, value = line.split(":", 1)
            headers[name.strip().lower()] = value.strip()
    if headers.get("transfer-encoding", "").lower() == "chunked":
        body = decode_chunked(body)
    return status, headers, body


def decode_chunked(body: bytes) -> bytes:
    output = b""
    while True:
        line, _, rest = body.partition(b"\r\n")
        size = int(line.split(b";")[0] or b"0", 16)
        if size == 0:
            return output
        output += rest[:size]
        body = rest[size + 2:]


def json_body(body: bytes) -> dict:
    try:
        return json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return {}


def random_nonce() -> str:
    return "%032x" % random.getrandbits(128)


def main() -> int:
    if not PASSWORD:
        print("DOORLOCK_TEST_ADMIN_PASSWORD is required", file=sys.stderr)
        return 2
    build_helper()
    session = Session()
    session.login()

    # ---------------------------------------------------------- fixtures
    status, payload = session.request("GET", "/api/doors.php")
    doors = payload.get("data", {}).get("doors", [])
    active = [door for door in doors if door.get("is_active") and not door.get("archived_at")]
    if status != 200 or not active:
        print("no active door available", file=sys.stderr)
        return 2
    door = active[0]
    door_id = door["id"]
    door_unlock = door["unlock_seconds"]

    unique_id = "%032x" % random.getrandbits(128)
    status, payload = session.request(
        "POST", "/api/devices.php",
        {"name": f"Firmware test {unique_id[:8]}", "unique_id": unique_id, "door_id": door_id},
    )
    check(status == 201, "FW-E2E-01", "device registered through the admin API", f"HTTP {status}")
    device_id = payload.get("data", {}).get("device", {}).get("id")
    if not device_id:
        print(f"device creation failed: {payload}", file=sys.stderr)
        return 1

    status, payload = session.request("POST", "/api/devices.php",
                                      {"action": "provision", "id": device_id})
    provisioning = payload.get("data", {}).get("provisioning", {})
    device_key = provisioning.get("device_key", "")
    check(status == 200 and re.fullmatch(r"[a-f0-9]{64}", device_key) is not None,
          "FW-E2E-02", "provisioning returns a 64-hex device key", f"HTTP {status}")

    card = "%08X" % random.getrandbits(32)
    status, payload = session.request("POST", "/api/persons.php",
                                      {"name": f"Firmware person {card}", "card_number": card})
    person_id = payload.get("data", {}).get("person", {}).get("id")
    check(status in (200, 201) and person_id is not None, "FW-E2E-03",
          "person and card created", f"HTTP {status} {payload}")
    if not person_id:
        return 1

    status, _ = session.request("POST", "/api/permissions.php",
                                {"person_id": person_id, "door_id": door_id, "effect": "allow"})
    check(status in (200, 201), "FW-E2E-04", "explicit allow permission granted", f"HTTP {status}")

    # -------------------------------------------------- signed grant
    nonce = random_nonce()
    request_id = random_nonce()
    payload_bytes = signed_request(unique_id, device_key, door_id, card, nonce, request_id)
    status, headers, body = send_raw(payload_bytes)
    decoded = json_body(body)
    check(status == 200, "FW-E2E-05", "firmware-signed request accepted", f"HTTP {status} {body[:120]}")
    check(headers.get("content-type", "").startswith("application/json"),
          "FW-E2E-06", "response is JSON")
    check(decoded.get("access") is True, "FW-E2E-07", "access granted for an authorised card",
          f"body={body[:160]}")
    check(decoded.get("reason") == "granted", "FW-E2E-08", "reason is granted")
    check(decoded.get("request_id") == request_id, "FW-E2E-09",
          "response echoes the firmware request id")
    check(1 <= decoded.get("unlock_seconds", 0) <= 30, "FW-E2E-10",
          "unlock duration is within the safe range", f"{decoded.get('unlock_seconds')}")
    check(decoded.get("unlock_seconds") == door_unlock, "FW-E2E-11",
          "unlock duration matches the door configuration",
          f"{decoded.get('unlock_seconds')} vs {door_unlock}")

    # -------------------------------------------------------- replay
    status, _, body = send_raw(payload_bytes)
    decoded = json_body(body)
    check(status == 401 and decoded.get("reason") == "replayed_request", "FW-E2E-12",
          "replayed firmware request rejected", f"HTTP {status} {body[:120]}")

    # ---------------------------------------------------- wrong key
    payload_bytes = signed_request(unique_id, "0" * 64, door_id, card, random_nonce(),
                                   random_nonce())
    status, _, body = send_raw(payload_bytes)
    check(status == 401, "FW-E2E-13", "signature with the wrong key rejected",
          f"HTTP {status} {body[:120]}")

    # --------------------------------------------------- clock skew
    payload_bytes = signed_request(unique_id, device_key, door_id, card, random_nonce(),
                                   random_nonce(), timestamp=int(time.time()) - 3600)
    status, _, body = send_raw(payload_bytes)
    decoded = json_body(body)
    check(status == 401 and decoded.get("reason") == "timestamp_out_of_range", "FW-E2E-14",
          "stale timestamp rejected", f"HTTP {status} {body[:120]}")

    # -------------------------------------------------- unknown card
    payload_bytes = signed_request(unique_id, device_key, door_id, "%08X" % random.getrandbits(32),
                                   random_nonce(), random_nonce())
    status, _, body = send_raw(payload_bytes)
    decoded = json_body(body)
    check(status == 200 and decoded.get("access") is False and decoded.get("reason") == "unknown_card",
          "FW-E2E-15", "unknown card denied with a policy reason",
          f"HTTP {status} {body[:160]}")

    # ------------------------------------------------- explicit deny
    session.request("POST", "/api/permissions.php",
                    {"person_id": person_id, "door_id": door_id, "effect": "deny"})
    payload_bytes = signed_request(unique_id, device_key, door_id, card, random_nonce(),
                                   random_nonce())
    status, _, body = send_raw(payload_bytes)
    decoded = json_body(body)
    check(status == 200 and decoded.get("access") is False and decoded.get("reason") == "explicit_deny",
          "FW-E2E-16", "explicit deny overrides the allow", f"HTTP {status} {body[:160]}")

    # ---------------------------------------------- disabled device
    session.request("PUT", "/api/devices.php", {"id": device_id, "is_active": False})
    payload_bytes = signed_request(unique_id, device_key, door_id, card, random_nonce(),
                                   random_nonce())
    status, _, body = send_raw(payload_bytes)
    check(status == 401, "FW-E2E-17", "requests from a disabled device are rejected",
          f"HTTP {status} {body[:120]}")

    # Restore the allow and the device so the fixture remains usable.
    session.request("PUT", "/api/devices.php", {"id": device_id, "is_active": True})
    session.request("POST", "/api/permissions.php",
                    {"person_id": person_id, "door_id": door_id, "effect": "allow"})

    print("-" * 80)
    print(f"FIRMWARE E2E TOTAL {PASS + FAIL} | PASS {PASS} | FAIL {FAIL}")
    return 0 if FAIL == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
