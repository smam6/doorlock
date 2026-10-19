#ifndef DOORLOCK_CONFIG_EXAMPLE_H
#define DOORLOCK_CONFIG_EXAMPLE_H

/**
 * DoorLock controller configuration template.
 *
 * Copy this file to `config.h` in the same directory and fill in the values.
 * `config.h` contains secrets and is ignored by Git and excluded from release
 * archives. The firmware refuses to run while any value still starts with
 * CHANGE_ME, and it never opens the door because of a missing configuration.
 *
 * Values marked "provisioning" come from the panel:
 *   Devices -> Provision  (device_key is shown exactly once).
 */

namespace cfg {

// ---------------------------------------------------------------- Wi-Fi
// Use a dedicated SSID/VLAN for controllers when possible. The ESP8266 only
// supports 2.4 GHz networks.
constexpr char kWifiSsid[] = "CHANGE_ME_WIFI_SSID";
constexpr char kWifiPassword[] = "CHANGE_ME_WIFI_PASSWORD";
// Optional: fixed IP configuration. Leave kUseStaticIp false for DHCP.
constexpr bool kUseStaticIp = false;
constexpr char kStaticIp[] = "192.168.1.50";
constexpr char kGateway[] = "192.168.1.1";
constexpr char kSubnet[] = "255.255.255.0";
constexpr char kDns[] = "192.168.1.1";

// --------------------------------------------------------------- Backend
// Host name only (no scheme, no path). HTTPS is mandatory.
constexpr char kApiHost[] = "CHANGE_ME_DOORLOCK_HOST";
constexpr uint16_t kApiPort = 443;
constexpr char kApiPath[] = "/api/check-access.php";

// Device identity and key (provisioning). unique_id is 32 lowercase hex
// characters; device_key is 64 lowercase hex characters.
constexpr char kDeviceUniqueId[] = "CHANGE_ME_DEVICE_UNIQUE_ID";
constexpr char kDeviceKeyHex[] = "CHANGE_ME_DEVICE_KEY";

// Door this controller is assigned to (the server also enforces the binding).
constexpr uint8_t kDoorId = 1;

// ------------------------------------------------------------------- TLS
// Configure exactly one of the following. A CA bundle is preferred; the
// fingerprint is an acceptable fallback for a self-signed certificate.
// The firmware never calls setInsecure().
constexpr char kTlsCaPem[] = "";        // "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----\n"
constexpr char kTlsFingerprint[] = "";  // 40 hex characters (SHA-1 of the server certificate)

// ---------------------------------------------------------------- Hardware
// Relay output. Verify the polarity of your module with a multimeter before
// trusting these values; see firmware/README.md.
constexpr uint8_t kRelayPin = 5;        // D1 on NodeMCU/Wemos D1 mini
constexpr bool kRelayActiveHigh = false; // most opto-isolated boards are active LOW
constexpr uint16_t kDefaultUnlockSeconds = 5;
constexpr uint16_t kMaxUnlockSeconds = 30; // hard ceiling enforced by the firmware

// RFID reader (RC522) on the hardware SPI bus.
constexpr uint8_t kRfidSsPin = 15;   // D8
constexpr uint8_t kRfidRstPin = 0;   // D3

// Status LED (onboard LED on D4 is active LOW on most boards).
constexpr uint8_t kStatusLedPin = 2;
constexpr bool kStatusLedActiveHigh = false;

// --------------------------------------------------------------- Behaviour
constexpr uint16_t kHttpTimeoutMs = 4000;  // hard deadline per request
constexpr uint32_t kWifiRetryMs = 5000;    // reconnect back-off
constexpr uint32_t kCardRepeatMs = 3000;   // ignore the same card for this long
constexpr bool kEnableSerialLog = true;

} // namespace cfg

#endif // DOORLOCK_CONFIG_EXAMPLE_H
