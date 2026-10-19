/**
 * DoorLock ESP8266 controller — canonical production firmware.
 *
 * Flow: RFID card -> signed HTTPS request -> server decision -> relay pulse.
 *
 * Safety properties (see firmware/README.md and docs/security.md):
 *   - The relay is driven to its locked level before anything else in setup()
 *     and is de-energised again on every failure path.
 *   - The relay is only energised when the server returns HTTP 200, a JSON
 *     body with `success: true`, exactly one boolean `access` field set to
 *     true, and `reason: "granted"`. Timeouts, malformed responses, TLS
 *     errors and HTTP errors never unlock.
 *   - Unlocking is non-blocking: the RFID reader, Wi-Fi stack and watchdog
 *     keep running for the whole pulse, and repeated scans restart (never
 *     stack) the interval.
 *   - TLS certificate verification is mandatory. There is no "insecure"
 *     option in this firmware.
 *   - Configuration with placeholder values puts the controller into a safe
 *     mode where the relay stays locked and the status LED blinks.
 *
 * Build: Arduino IDE 2.x or arduino-cli with the ESP8266 core 3.1.x and the
 * MFRC522 library 1.4.x. Copy config.example.h to config.h first.
 */

#include <ESP8266WiFi.h>
#include <WiFiClientSecure.h>
#include <SPI.h>
#include <time.h>

#if __has_include(<MFRC522.h>)
#include <MFRC522.h>
#define DOORLOCK_HAS_RFID 1
#endif

#if __has_include("config.h")
#include "config.h"
#else
#include "config.example.h"
#endif

#include "src/doorlock_core.h"

using namespace doorlock;

namespace {

// --------------------------------------------------------------- hardware
void writeRelayPin(uint8_t pin, bool energised) {
    bool level = energised ? cfg::kRelayActiveHigh : !cfg::kRelayActiveHigh;
    digitalWrite(pin, level ? HIGH : LOW);
}

// ------------------------------------------------------------------ state
RelayController relay(cfg::kRelayPin, cfg::kRelayActiveHigh, cfg::kMaxUnlockSeconds,
                      writeRelayPin);
CardDebouncer debouncer(cfg::kCardRepeatMs);
bool everConnected = false;
uint32_t lastWifiAttemptMs = 0;
uint32_t lastLedToggleMs = 0;
bool ledOn = false;
char wifiHostname[32];

#if DOORLOCK_HAS_RFID
MFRC522 rfid(cfg::kRfidSsPin, cfg::kRfidRstPin);
#endif

BearSSL::X509List* caList = nullptr;

void writeStatusLed(bool on) {
    bool level = on ? cfg::kStatusLedActiveHigh : !cfg::kStatusLedActiveHigh;
    digitalWrite(cfg::kStatusLedPin, level ? HIGH : LOW);
}

bool hasPlaceholder(const char* value) {
    return value != nullptr && strstr(value, "CHANGE_ME") != nullptr;
}

bool parseFingerprint(const char* text, uint8_t out[20]) {
    if (text == nullptr || strlen(text) != 40) {
        return false;
    }
    for (int i = 0; i < 20; i++) {
        auto nibble = [](char c) -> int {
            if (c >= '0' && c <= '9') return c - '0';
            if (c >= 'a' && c <= 'f') return c - 'a' + 10;
            if (c >= 'A' && c <= 'F') return c - 'A' + 10;
            return -1;
        };
        int high = nibble(text[i * 2]);
        int low = nibble(text[i * 2 + 1]);
        if (high < 0 || low < 0) {
            return false;
        }
        out[i] = static_cast<uint8_t>((high << 4) | low);
    }
    return true;
}

bool tlsConfigured() {
    return cfg::kTlsCaPem[0] != '\0' || cfg::kTlsFingerprint[0] != '\0';
}

bool configIsComplete() {
    if (hasPlaceholder(cfg::kWifiSsid) || hasPlaceholder(cfg::kWifiPassword) ||
        hasPlaceholder(cfg::kApiHost) || hasPlaceholder(cfg::kDeviceUniqueId) ||
        hasPlaceholder(cfg::kDeviceKeyHex)) {
        return false;
    }
    if (!isValidUniqueId(cfg::kDeviceUniqueId) || !isValidKeyHex(cfg::kDeviceKeyHex)) {
        return false;
    }
    if (cfg::kApiHost[0] == '\0' || cfg::kApiPort == 0 || cfg::kDoorId == 0) {
        return false;
    }
    if (cfg::kMaxUnlockSeconds < 1 || cfg::kMaxUnlockSeconds > 30 ||
        cfg::kDefaultUnlockSeconds < 1 ||
        cfg::kDefaultUnlockSeconds > cfg::kMaxUnlockSeconds) {
        return false;
    }
    return tlsConfigured();
}

/** Terminal state: relay locked, LED blinking, no networking. */
void enterSafeMode(const char* reason) {
    relay.forceLock();
    if (cfg::kEnableSerialLog) {
        Serial.print(F("SAFE MODE: "));
        Serial.println(reason);
    }
    for (;;) {
        ESP.wdtFeed();
        writeStatusLed(!ledOn);
        ledOn = !ledOn;
        delay(120);
    }
}

// ------------------------------------------------------------------- TLS
bool applyTls(WiFiClientSecure& client) {
    if (caList != nullptr) {
        client.setTrustAnchors(caList);
        return true;
    }
    uint8_t fingerprint[20];
    if (parseFingerprint(cfg::kTlsFingerprint, fingerprint)) {
        client.setFingerprint(fingerprint);
        return true;
    }
    return false;
}

// ------------------------------------------------------------------ time
bool clockReady() {
    time_t now = time(nullptr);
    return now > 1700000000; // 2023-11-14, comfortably after any build date
}

// --------------------------------------------------------------- network
void beginWifi() {
    WiFi.persistent(false);
    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(true);
    WiFi.setAutoConnect(true);
    snprintf(wifiHostname, sizeof(wifiHostname), "doorlock-%s", cfg::kDeviceUniqueId + 24);
    WiFi.hostname(wifiHostname);
    if (cfg::kUseStaticIp) {
        IPAddress address;
        IPAddress gateway;
        IPAddress subnet;
        IPAddress dns;
        address.fromString(cfg::kStaticIp);
        gateway.fromString(cfg::kGateway);
        subnet.fromString(cfg::kSubnet);
        dns.fromString(cfg::kDns);
        WiFi.config(address, gateway, subnet, dns);
    }
    WiFi.begin(cfg::kWifiSsid, cfg::kWifiPassword);
    lastWifiAttemptMs = millis();
    if (cfg::kEnableSerialLog) {
        Serial.print(F("Wi-Fi connecting to "));
        Serial.println(cfg::kWifiSsid);
    }
}

/** Non-blocking connection maintenance with a fixed retry back-off. */
void maintainWifi(uint32_t nowMs) {
    if (WiFi.status() == WL_CONNECTED) {
        if (!everConnected) {
            everConnected = true;
            if (cfg::kEnableSerialLog) {
                Serial.print(F("Wi-Fi connected, IP "));
                Serial.println(WiFi.localIP());
            }
        }
        return;
    }
    if (nowMs - lastWifiAttemptMs < cfg::kWifiRetryMs) {
        return;
    }
    lastWifiAttemptMs = nowMs;
    if (everConnected) {
        WiFi.reconnect();
    } else {
        WiFi.begin(cfg::kWifiSsid, cfg::kWifiPassword);
    }
}

// ------------------------------------------------------------------- RFID
/**
 * Read one card UID as uppercase hex (the format stored in the panel).
 * With the MFRC522 library absent, a UID can be typed into the serial monitor
 * so the controller can be bench-tested without the reader.
 */
bool readCard(char* out, size_t outSize) {
#if DOORLOCK_HAS_RFID
    if (!rfid.PICC_IsNewCardPresent()) {
        return false;
    }
    if (!rfid.PICC_ReadCardSerial()) {
        return false;
    }
    size_t length = rfid.uid.size;
    if (length < 4 || length > 10 || length * 2 + 1 > outSize) {
        rfid.PICC_HaltA();
        return false;
    }
    static const char* digits = "0123456789ABCDEF";
    for (size_t i = 0; i < length; i++) {
        out[i * 2] = digits[(rfid.uid.uidByte[i] >> 4) & 0x0f];
        out[i * 2 + 1] = digits[rfid.uid.uidByte[i] & 0x0f];
    }
    out[length * 2] = '\0';
    rfid.PICC_HaltA();
    return true;
#else
    static char buffer[24];
    static size_t length = 0;
    while (Serial.available() > 0) {
        char c = static_cast<char>(Serial.read());
        if (c == '\n' || c == '\r') {
            if (length == 0) {
                continue;
            }
            buffer[length] = '\0';
            length = 0;
            if (strlen(buffer) + 1 > outSize) {
                return false;
            }
            strcpy(out, buffer);
            return true;
        }
        if (length + 1 < sizeof(buffer)) {
            buffer[length++] = c;
        }
    }
    return false;
#endif
}

// --------------------------------------------------------------- request
void fillRandomHex(char* out, size_t bytes) {
    for (size_t i = 0; i < bytes; i++) {
        uint32_t value = ESP.random();
        out[i * 2] = "0123456789abcdef"[(value >> 4) & 0x0f];
        out[i * 2 + 1] = "0123456789abcdef"[value & 0x0f];
    }
    out[bytes * 2] = '\0';
}

void logResult(const char* card, const AccessResult& result) {
    if (!cfg::kEnableSerialLog) {
        return;
    }
    Serial.printf("card=%s decision=%s reason=%s request_id=%s\n", card,
                  decisionName(result.decision), result.reason,
                  result.requestId[0] != '\0' ? result.requestId : "-");
}

AccessResult requestAccess(const char* cardNumber) {
    AccessResult local;
    local.decision = Decision::TransportError;
    local.unlockSeconds = 0;
    strcpy(local.reason, "clock_not_set");
    local.requestId[0] = '\0';

    time_t now = time(nullptr);
    if (now <= 1700000000) {
        return local; // never send a request the server will reject for skew
    }

    char body[128];
    snprintf(body, sizeof(body), "{\"door_id\":%u,\"card_number\":\"%s\"}",
             static_cast<unsigned>(cfg::kDoorId), cardNumber);

    DeviceCredentials credentials;
    memset(&credentials, 0, sizeof(credentials));
    strncpy(credentials.uniqueId, cfg::kDeviceUniqueId, sizeof(credentials.uniqueId) - 1);
    strncpy(credentials.keyHex, cfg::kDeviceKeyHex, sizeof(credentials.keyHex) - 1);

    char nonce[33];
    char requestId[33];
    fillRandomHex(nonce, 16);
    fillRandomHex(requestId, 16);

    char request[1024];
    size_t written = 0;
    if (!buildCheckAccessRequest(cfg::kApiHost, cfg::kApiPort, cfg::kApiPath, credentials,
                                 static_cast<uint32_t>(now), nonce, requestId, body, request,
                                 sizeof(request), &written)) {
        local.reason[0] = '\0';
        strcpy(local.reason, "request_build_failed");
        return local;
    }

    WiFiClientSecure client;
    client.setTimeout(cfg::kHttpTimeoutMs / 1000);
    if (!applyTls(client)) {
        local.reason[0] = '\0';
        strcpy(local.reason, "tls_not_configured");
        return local;
    }

    ESP.wdtFeed();
    bool connected = client.connect(cfg::kApiHost, cfg::kApiPort);
    ESP.wdtFeed();
    if (!connected) {
        local.reason[0] = '\0';
        strcpy(local.reason, "tls_connect_failed");
        return local;
    }
    client.write(reinterpret_cast<const uint8_t*>(request), written);
    client.flush();

    ResponseReader reader;
    uint32_t deadline = millis() + cfg::kHttpTimeoutMs;
    while (static_cast<int32_t>(deadline - millis()) > 0) {
        ESP.wdtFeed();
        while (client.available() > 0) {
            int value = client.read();
            if (value < 0) {
                break;
            }
            if (!reader.feed(static_cast<char>(value))) {
                break;
            }
        }
        if (reader.done()) {
            break;
        }
        yield();
    }
    client.stop();
    reader.finish();

    AccessResult result =
        evaluateResponse(reader.response(), cfg::kDefaultUnlockSeconds, cfg::kMaxUnlockSeconds);
    if (result.decision == Decision::Malformed && !reader.response().valid) {
        strcpy(result.reason, "no_valid_response");
    }
    return result;
}

// ------------------------------------------------------------------- LED
void updateLed(uint32_t nowMs) {
    if (relay.unlocked()) {
        if (!ledOn) {
            ledOn = true;
            writeStatusLed(true);
        }
        return;
    }
    if (nowMs - lastLedToggleMs >= 1000) {
        lastLedToggleMs = nowMs;
        ledOn = !ledOn;
        writeStatusLed(ledOn);
    }
}

} // namespace

// ============================================================== Arduino
void setup() {
    // 1. Lock the relay before anything can block or fail.
    pinMode(cfg::kRelayPin, OUTPUT);
    relay.begin(millis());

    pinMode(cfg::kStatusLedPin, OUTPUT);
    writeStatusLed(false);

    if (cfg::kEnableSerialLog) {
        Serial.begin(115200);
        Serial.println();
        Serial.println(F("DoorLock controller 2.0"));
    }

    // 2. Refuse to run without valid configuration or a trust anchor.
    if (!configIsComplete()) {
        enterSafeMode("configuration incomplete (config.h missing, placeholders, or no TLS trust anchor)");
    }
    if (cfg::kTlsCaPem[0] != '\0') {
        caList = new BearSSL::X509List(cfg::kTlsCaPem);
    }

    // 3. RFID reader.
#if DOORLOCK_HAS_RFID
    SPI.begin();
    rfid.PCD_Init();
    delay(50);
    uint8_t version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
    if (version == 0x00 || version == 0xFF) {
        enterSafeMode("RC522 reader not detected on SPI");
    }
#endif

    // 4. Network.
    beginWifi();
    configTime(0, 0, "pool.ntp.org", "time.nist.gov"); // UTC epoch for signatures

    lastLedToggleMs = millis();
}

void loop() {
    uint32_t nowMs = millis();
    relay.tick(nowMs);
    updateLed(nowMs);

    if (WiFi.status() != WL_CONNECTED) {
        maintainWifi(nowMs);
        return;
    }
    if (!clockReady()) {
        ESP.wdtFeed(); // NTP pending; keep the watchdog happy without blocking
        return;
    }

    char card[32];
    if (!readCard(card, sizeof(card))) {
        delay(10);
        return;
    }
    if (!debouncer.accept(card, nowMs)) {
        return; // same card still held against the reader
    }

    AccessResult result = requestAccess(card);
    if (result.decision == Decision::Granted) {
        relay.unlock(millis(), result.unlockSeconds);
    }
    logResult(card, result);
}
