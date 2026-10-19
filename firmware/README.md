# Firmware

This directory intentionally contains no buildable firmware.

The original ESP8266 sketches (`RFID_thingPod/*.ino`) have been moved to
`legacy/firmware/`. They are **not compatible** with the DoorLock 2.0 device
API and must not be flashed onto a production controller: they embed Wi-Fi
credentials, use a blocking relay delay and do not sign requests.

A replacement controller must implement the contract documented in
`docs/api.md` ("Device API"): HTTPS, HMAC-SHA256 request signing with a
provisioned per-device key, a monotonic timestamp, a unique nonce per request,
and a non-blocking relay pulse whose duration comes from `unlock_seconds` in
the response.

The backend side of that contract is implemented and covered by tests; the
embedded firmware rewrite is a separate workstream that requires an ESP8266
toolchain and hardware-in-the-loop testing.
