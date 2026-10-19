#include "doorlock_core.h"

#include <stdio.h>
#include <string.h>

namespace doorlock {
namespace {

/** Copy at most outSize-1 bytes, always NUL terminating. */
void copyText(char* out, size_t outSize, const char* text) {
    if (out == 0 || outSize == 0) {
        return;
    }
    if (text == 0) {
        out[0] = '\0';
        return;
    }
    size_t length = strlen(text);
    if (length >= outSize) {
        length = outSize - 1;
    }
    memcpy(out, text, length);
    out[length] = '\0';
}

bool contentTypeIsJson(const char* contentType) {
    if (contentType == 0) {
        return false;
    }
    const char* slash = strchr(contentType, '/');
    if (slash == 0) {
        return false;
    }
    const char* type = "application/json";
    size_t typeLength = strlen(type);
    for (size_t i = 0; i < typeLength; i++) {
        char c = contentType[i];
        if (c == '\0') {
            return false;
        }
        if (c >= 'A' && c <= 'Z') {
            c = static_cast<char>(c + ('a' - 'A'));
        }
        if (c != type[i]) {
            return false;
        }
    }
    char next = contentType[typeLength];
    return next == '\0' || next == ';' || next == ' ' || next == '+';
}

AccessResult failure(Decision decision, const char* reason) {
    AccessResult result;
    result.decision = decision;
    result.unlockSeconds = 0;
    copyText(result.reason, sizeof(result.reason), reason);
    result.requestId[0] = '\0';
    return result;
}

} // namespace

const char* decisionName(Decision decision) {
    switch (decision) {
    case Decision::Granted: return "granted";
    case Decision::Denied: return "denied";
    case Decision::AuthFailed: return "auth_failed";
    case Decision::RateLimited: return "rate_limited";
    case Decision::ServerError: return "server_error";
    case Decision::Malformed: return "malformed_response";
    case Decision::TransportError: return "transport_error";
    case Decision::None:
    default: return "none";
    }
}

uint16_t clampUnlockSeconds(long requested, uint16_t defaultSeconds, uint16_t maxSeconds) {
    if (maxSeconds < 1) {
        maxSeconds = 1;
    }
    if (maxSeconds > 30) {
        maxSeconds = 30;
    }
    long value = requested;
    if (value <= 0) {
        value = defaultSeconds > 0 ? defaultSeconds : 1;
    }
    if (value > maxSeconds) {
        value = maxSeconds;
    }
    if (value < 1) {
        value = 1;
    }
    return static_cast<uint16_t>(value);
}

AccessResult evaluateResponse(const HttpResponse& response, uint16_t defaultUnlockSeconds,
                              uint16_t maxUnlockSeconds) {
    // Non-200 responses never describe an authorization grant.
    if (response.status != 200) {
        char reason[32];
        switch (response.status) {
        case 400:
        case 415:
            return failure(Decision::Malformed, "bad_request");
        case 401:
        case 403:
        case 409:
            return failure(Decision::AuthFailed, "device_rejected");
        case 429:
            return failure(Decision::RateLimited, "rate_limited");
        case 500:
        case 502:
        case 503:
        case 504:
            return failure(Decision::ServerError, "server_error");
        default:
            snprintf(reason, sizeof(reason), "http_%d", response.status);
            return failure(Decision::Malformed, reason);
        }
    }
    if (!response.valid || !response.complete) {
        return failure(Decision::Malformed, "incomplete_response");
    }
    if (!contentTypeIsJson(response.contentType)) {
        return failure(Decision::Malformed, "not_json");
    }
    if (response.bodyLength == 0) {
        return failure(Decision::Malformed, "empty_body");
    }
    if (!jsonIsWellFormed(response.body, response.bodyLength)) {
        return failure(Decision::Malformed, "invalid_json");
    }

    JsonValue success;
    JsonValue access;
    if (!jsonFind(response.body, response.bodyLength, "success", 1, &success) ||
        success.type != JsonType::Bool || !success.boolean) {
        return failure(Decision::Malformed, "success_not_true");
    }
    size_t accessCount = 0;
    if (!jsonCount(response.body, response.bodyLength, "access", 1, &accessCount) ||
        accessCount != 1) {
        // Missing or duplicated decision field: ambiguous, therefore denied.
        return failure(Decision::Malformed, "ambiguous_access");
    }
    if (!jsonFind(response.body, response.bodyLength, "access", 1, &access) ||
        access.type != JsonType::Bool) {
        return failure(Decision::Malformed, "access_not_boolean");
    }

    AccessResult result;
    result.decision = Decision::Denied;
    result.unlockSeconds = 0;
    result.reason[0] = '\0';
    result.requestId[0] = '\0';

    JsonValue requestId;
    if (jsonFind(response.body, response.bodyLength, "request_id", 1, &requestId) &&
        requestId.type == JsonType::String) {
        copyText(result.requestId, sizeof(result.requestId), requestId.text);
    }
    JsonValue reason;
    if (jsonFind(response.body, response.bodyLength, "reason", 1, &reason) &&
        reason.type == JsonType::String) {
        copyText(result.reason, sizeof(result.reason), reason.text);
    }

    if (!access.boolean) {
        if (result.reason[0] == '\0') {
            copyText(result.reason, sizeof(result.reason), "access_denied");
        }
        return result;
    }

    // Granted: the server must also say why, and the reason must be the grant.
    if (strcmp(result.reason, "granted") != 0) {
        return failure(Decision::Malformed, "grant_without_reason");
    }

    long seconds = defaultUnlockSeconds;
    JsonValue unlock;
    if (jsonFind(response.body, response.bodyLength, "unlock_seconds", 1, &unlock)) {
        if (unlock.type != JsonType::Number) {
            return failure(Decision::Malformed, "unlock_seconds_invalid");
        }
        seconds = unlock.number;
    }
    result.decision = Decision::Granted;
    result.unlockSeconds = clampUnlockSeconds(seconds, defaultUnlockSeconds, maxUnlockSeconds);
    return result;
}

RelayController::RelayController(uint8_t pin, bool activeHigh, uint16_t maxSeconds,
                                 PinWriter writer)
    : pin_(pin), activeHigh_(activeHigh), maxSeconds_(maxSeconds), writer_(writer), unlocked_(false),
      unlockAtMs_(0), durationMs_(0), appliedSeconds_(0) {
    if (maxSeconds_ < 1) {
        maxSeconds_ = 1;
    }
    if (maxSeconds_ > 30) {
        maxSeconds_ = 30;
    }
}

void RelayController::write(bool energised) {
    if (writer_ != 0) {
        writer_(pin_, energised);
    }
}

void RelayController::begin(uint32_t nowMs) {
    unlocked_ = false;
    unlockAtMs_ = nowMs;
    durationMs_ = 0;
    appliedSeconds_ = 0;
    write(false); // locked level, whatever the polarity
}

bool RelayController::unlock(uint32_t nowMs, int seconds) {
    uint16_t applied = clampUnlockSeconds(seconds, 1, maxSeconds_);
    appliedSeconds_ = applied;
    durationMs_ = static_cast<uint32_t>(applied) * 1000u;
    unlockAtMs_ = nowMs;
    unlocked_ = true;
    write(true);
    return true;
}

void RelayController::tick(uint32_t nowMs) {
    if (!unlocked_) {
        return;
    }
    uint32_t elapsed = nowMs - unlockAtMs_; // unsigned arithmetic is wrap-safe
    if (elapsed >= durationMs_) {
        unlocked_ = false;
        appliedSeconds_ = 0;
        write(false);
    }
}

void RelayController::forceLock() {
    unlocked_ = false;
    durationMs_ = 0;
    appliedSeconds_ = 0;
    write(false);
}

uint32_t RelayController::remainingMs(uint32_t nowMs) const {
    if (!unlocked_) {
        return 0;
    }
    uint32_t elapsed = nowMs - unlockAtMs_;
    if (elapsed >= durationMs_) {
        return 0;
    }
    return durationMs_ - elapsed;
}

CardDebouncer::CardDebouncer(uint32_t repeatWindowMs)
    : lastMs_(0), hasLast_(false), windowMs_(repeatWindowMs) {
    last_[0] = '\0';
}

void CardDebouncer::reset() {
    last_[0] = '\0';
    lastMs_ = 0;
    hasLast_ = false;
}

bool CardDebouncer::accept(const char* cardUid, uint32_t nowMs) {
    if (cardUid == 0 || cardUid[0] == '\0') {
        return false;
    }
    bool same = hasLast_ && strcmp(last_, cardUid) == 0;
    if (same) {
        uint32_t elapsed = nowMs - lastMs_;
        if (elapsed < windowMs_) {
            return false; // duplicate read within the debounce window
        }
    }
    copyText(last_, sizeof(last_), cardUid);
    lastMs_ = nowMs;
    hasLast_ = true;
    return true;
}

} // namespace doorlock
