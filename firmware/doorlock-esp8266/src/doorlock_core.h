#ifndef DOORLOCK_CORE_H
#define DOORLOCK_CORE_H

/**
 * Platform-independent DoorLock controller logic.
 *
 * This header intentionally contains no Arduino code: the ESP8266 sketch
 * supplies the pin writer, the clock and the transport. Everything that
 * decides whether a door may open lives here and is covered by host tests
 * (firmware/test/test_core.cpp).
 */

#include "device_request.h"
#include "http_response.h"
#include "json_lite.h"
#include "sha256.h"

#include <stddef.h>
#include <stdint.h>

namespace doorlock {

enum class Decision : uint8_t {
    None = 0,
    Granted,
    Denied,
    AuthFailed,
    RateLimited,
    ServerError,
    Malformed,
    TransportError,
};

/** Stable, log-safe name for a decision. */
const char* decisionName(Decision decision);

struct AccessResult {
    Decision decision;
    uint16_t unlockSeconds;
    char reason[64];
    char requestId[40];
};

/**
 * Interpret a complete HTTP response.
 *
 * Only a 200 response with a JSON content type, `success: true`, exactly one
 * boolean `access` field and (when granted) `reason: "granted"` yields
 * Decision::Granted. Everything else — including duplicated fields, wrong
 * types, truncated bodies and unexpected status codes — maps to a failure
 * decision that must never energise the relay.
 */
AccessResult evaluateResponse(const HttpResponse& response, uint16_t defaultUnlockSeconds,
                              uint16_t maxUnlockSeconds);

/** Pin writer supplied by the platform: sets a digital output. */
typedef void (*PinWriter)(uint8_t pin, bool level);

/**
 * Non-blocking relay controller.
 *
 * The relay is driven to the locked level by begin() and whenever the unlock
 * interval elapses; no code path leaves it energised after a reboot, a failed
 * request or a malformed response.
 */
class RelayController {
public:
    RelayController(uint8_t pin, bool activeHigh, uint16_t maxSeconds, PinWriter writer);

    /** Must be called first in setup(): forces the locked level. */
    void begin(uint32_t nowMs);

    /** Energise for `seconds` (clamped to [1, maxSeconds]). */
    bool unlock(uint32_t nowMs, int seconds);

    /** Relock once the interval elapsed. Call from loop(). */
    void tick(uint32_t nowMs);

    void forceLock();

    bool unlocked() const { return unlocked_; }
    uint32_t remainingMs(uint32_t nowMs) const;
    uint16_t appliedSeconds() const { return appliedSeconds_; }

private:
    void write(bool energised);

    uint8_t pin_;
    bool activeHigh_;
    uint16_t maxSeconds_;
    PinWriter writer_;
    bool unlocked_;
    uint32_t unlockAtMs_;
    uint32_t durationMs_;
    uint16_t appliedSeconds_;
};

/**
 * Suppresses duplicate reads of the same card within a time window, so a card
 * held against the reader does not trigger a burst of requests and repeated
 * relay restarts.
 */
class CardDebouncer {
public:
    explicit CardDebouncer(uint32_t repeatWindowMs);
    void reset();
    bool accept(const char* cardUid, uint32_t nowMs);

private:
    char last_[40];
    uint32_t lastMs_;
    bool hasLast_;
    uint32_t windowMs_;
};

/** Clamp a requested unlock duration into the safe range. */
uint16_t clampUnlockSeconds(long requested, uint16_t defaultSeconds, uint16_t maxSeconds);

} // namespace doorlock

#endif // DOORLOCK_CORE_H
