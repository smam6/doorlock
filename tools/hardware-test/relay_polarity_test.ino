/*
 * Relay polarity and idle-state test.
 *
 * Deliberately NOT the DoorLock firmware: no Wi-Fi, no TLS, no RFID, no
 * server. This sketch only drives the relay pin so the polarity of the relay
 * module can be measured with a multimeter before a lock is ever connected.
 *
 * Procedure and acceptance criteria: docs/hardware-test-guide.md, section 2.
 *
 * Safety:
 *   - The relay is driven to its locked level in setup(), before Serial.
 *   - The relay is released (locked) whenever the cycle limit is reached, so
 *     an unattended board never sits energised.
 *   - Cycles stop after kCycleLimit; press reset to run another batch.
 *
 * Flash with the lock DISCONNECTED. Set kRelayPin and kRelayActiveHigh to the
 * values you intend to use in config.h, then confirm the physical relay state
 * matches the log.
 */

#include <Arduino.h>

namespace {

// Must match config.h before this result is trusted.
constexpr uint8_t kRelayPin = 5;          // D1 on NodeMCU / Wemos D1 mini
constexpr bool kRelayActiveHigh = false;  // most opto-isolated boards are active LOW

// Slow and deliberate: easy to time by hand, easy to see, gentle on the relay.
constexpr uint32_t kUnlockMs = 2000;
constexpr uint32_t kLockedMs = 3000;

// Stop after this many pulses so the rig is never left cycling unattended.
constexpr uint16_t kCycleLimit = 20;

uint16_t cycles = 0;

/** energised == true releases the lock (closes the relay); false locks it. */
void driveRelay(bool energised) {
    const bool level = energised ? kRelayActiveHigh : !kRelayActiveHigh;
    digitalWrite(kRelayPin, level ? HIGH : LOW);
}

}  // namespace

void setup() {
    // Lock before anything else, exactly like the real firmware.
    pinMode(kRelayPin, OUTPUT);
    driveRelay(false);

    Serial.begin(115200);
    Serial.println();
    Serial.println("relay_polarity_test");
    Serial.printf("relay pin: %u\n", static_cast<unsigned>(kRelayPin));
    Serial.printf("kRelayActiveHigh: %s\n", kRelayActiveHigh ? "true" : "false");
    Serial.printf("pulse: %lu ms unlocked, %lu ms locked\n",
                  static_cast<unsigned long>(kUnlockMs),
                  static_cast<unsigned long>(kLockedMs));
    Serial.printf("cycle limit: %u\n", static_cast<unsigned>(kCycleLimit));
    Serial.println("state: LOCKED (relay released) - measure now");
}

void loop() {
    if (cycles >= kCycleLimit) {
        driveRelay(false);
        delay(1000);
        return;
    }

    cycles++;
    Serial.printf("cycle %u: UNLOCK (relay energised)\n", static_cast<unsigned>(cycles));
    driveRelay(true);
    delay(kUnlockMs);

    driveRelay(false);
    Serial.printf("cycle %u: LOCKED (relay released)\n", static_cast<unsigned>(cycles));
    delay(kLockedMs);

    if (cycles >= kCycleLimit) {
        Serial.println("done: relay left LOCKED; reset to run another batch");
    }
}
