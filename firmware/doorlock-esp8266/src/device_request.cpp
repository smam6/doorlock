#include "device_request.h"

#include "sha256.h"

#include <stdio.h>
#include <string.h>

namespace doorlock {
namespace {

bool isLowerHex(const char* text) {
    for (const char* p = text; *p != '\0'; p++) {
        if (!((*p >= '0' && *p <= '9') || (*p >= 'a' && *p <= 'f'))) {
            return false;
        }
    }
    return true;
}

} // namespace

bool isValidUniqueId(const char* uniqueId) {
    if (uniqueId == 0) {
        return false;
    }
    size_t length = strlen(uniqueId);
    return length == 32 && isLowerHex(uniqueId);
}

bool isValidKeyHex(const char* keyHex) {
    if (keyHex == 0) {
        return false;
    }
    return strlen(keyHex) == 64 && isLowerHex(keyHex);
}

bool buildCanonicalString(const char* uniqueId, uint32_t timestamp, const char* nonce,
                          const char* requestId, const char* body, char* out, size_t outSize) {
    if (out == 0 || outSize == 0) {
        return false;
    }
    out[0] = '\0';
    if (!isValidUniqueId(uniqueId) || nonce == 0 || strlen(nonce) < 8 || strlen(nonce) > 128 ||
        requestId == 0 || strlen(requestId) < 8 || strlen(requestId) > 64 || body == 0) {
        return false;
    }

    char bodyHash[65];
    sha256Hex(body, strlen(body), bodyHash);

    char timestampText[16];
    snprintf(timestampText, sizeof(timestampText), "%lu", static_cast<unsigned long>(timestamp));

    const char* parts[8] = {"DOORLOCK1", "POST", "check-access", uniqueId, timestampText, nonce,
                            requestId, bodyHash};
    size_t required = 0;
    for (int i = 0; i < 8; i++) {
        required += strlen(parts[i]) + (i < 7 ? 1 : 0);
    }
    if (required + 1 > outSize) {
        return false;
    }
    size_t offset = 0;
    for (int i = 0; i < 8; i++) {
        size_t length = strlen(parts[i]);
        memcpy(out + offset, parts[i], length);
        offset += length;
        if (i < 7) {
            out[offset++] = '\n';
        }
    }
    out[offset] = '\0';
    return true;
}

bool signCanonicalString(const char* keyHex, const DeviceCredentials& credentials,
                         const char* canonical, char* signatureOut) {
    if (signatureOut == 0 || !isValidKeyHex(keyHex) || canonical == 0 ||
        !isValidUniqueId(credentials.uniqueId)) {
        return false;
    }
    hmacSha256Hex(keyHex, strlen(keyHex), canonical, strlen(canonical), signatureOut);
    return true;
}

bool buildCheckAccessRequest(const char* host, uint16_t port, const char* path,
                             const DeviceCredentials& credentials, uint32_t timestamp,
                             const char* nonce, const char* requestId, const char* body,
                             char* out, size_t outSize, size_t* writtenOut) {
    if (out == 0 || outSize == 0 || host == 0 || path == 0 || body == 0) {
        return false;
    }
    out[0] = '\0';
    if (writtenOut != 0) {
        *writtenOut = 0;
    }
    if (!isValidUniqueId(credentials.uniqueId) || !isValidKeyHex(credentials.keyHex)) {
        return false;
    }
    if (strlen(body) > 512) {
        return false; // the request body is a small fixed object
    }
    char canonical[400];
    if (!buildCanonicalString(credentials.uniqueId, timestamp, nonce, requestId, body, canonical,
                              sizeof(canonical))) {
        return false;
    }
    char signature[65];
    if (!signCanonicalString(credentials.keyHex, credentials, canonical, signature)) {
        return false;
    }

    char hostHeader[128];
    if (port == 443 || port == 80) {
        snprintf(hostHeader, sizeof(hostHeader), "%s", host);
    } else {
        snprintf(hostHeader, sizeof(hostHeader), "%s:%u", host, static_cast<unsigned>(port));
    }

    int written = snprintf(
        out, outSize,
        "POST %s HTTP/1.1\r\n"
        "Host: %s\r\n"
        "User-Agent: DoorLock-ESP8266/2.0\r\n"
        "Accept: application/json\r\n"
        "Content-Type: application/json\r\n"
        "Content-Length: %u\r\n"
        "Connection: close\r\n"
        "X-Device-Id: %s\r\n"
        "X-Timestamp: %lu\r\n"
        "X-Nonce: %s\r\n"
        "X-Request-Id: %s\r\n"
        "X-Signature: %s\r\n"
        "\r\n"
        "%s",
        path, hostHeader, static_cast<unsigned>(strlen(body)),
        credentials.uniqueId, static_cast<unsigned long>(timestamp), nonce, requestId, signature,
        body);
    if (written < 0 || static_cast<size_t>(written) >= outSize) {
        out[0] = '\0';
        return false;
    }
    if (writtenOut != 0) {
        *writtenOut = static_cast<size_t>(written);
    }
    return true;
}

void hexEncode(const uint8_t* bytes, size_t length, char* out) {
    static const char* hex = "0123456789abcdef";
    for (size_t i = 0; i < length; i++) {
        out[i * 2] = hex[(bytes[i] >> 4) & 0x0fu];
        out[i * 2 + 1] = hex[bytes[i] & 0x0fu];
    }
    out[length * 2] = '\0';
}

} // namespace doorlock
