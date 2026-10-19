#ifndef DOORLOCK_DEVICE_REQUEST_H
#define DOORLOCK_DEVICE_REQUEST_H

/**
 * Construction of a signed device request (the wire format documented in
 * docs/api.md). Pure string handling, no sockets, so it can be tested on a
 * workstation and reused by any controller implementation.
 */

#include <stddef.h>
#include <stdint.h>

namespace doorlock {

struct DeviceCredentials {
    char uniqueId[33]; // 32 lowercase hex characters
    char keyHex[65];   // 64 lowercase hex characters
};

bool isValidUniqueId(const char* uniqueId);
bool isValidKeyHex(const char* keyHex);

/**
 * Canonical string:
 *   DOORLOCK1 \n POST \n check-access \n <uid> \n <timestamp> \n <nonce> \n
 *   <requestId> \n <sha256 hex of body>
 *
 * Returns false (and leaves `out` empty) when any component is invalid.
 */
bool buildCanonicalString(const char* uniqueId, uint32_t timestamp, const char* nonce,
                          const char* requestId, const char* body, char* out, size_t outSize);

/** Lowercase hex HMAC-SHA256 of the canonical string. */
bool signCanonicalString(const char* keyHex, const DeviceCredentials& credentials,
                         const char* canonical, char* signatureOut);

/**
 * Build a complete HTTP/1.1 POST request, including Content-Length,
 * Connection: close and the signed headers. Fails if the buffer is too small.
 */
bool buildCheckAccessRequest(const char* host, uint16_t port, const char* path,
                             const DeviceCredentials& credentials, uint32_t timestamp,
                             const char* nonce, const char* requestId, const char* body,
                             char* out, size_t outSize, size_t* writtenOut);

/** Fill `out` (NUL terminated) with lowercase hex from random bytes. */
void hexEncode(const uint8_t* bytes, size_t length, char* out);

} // namespace doorlock

#endif // DOORLOCK_DEVICE_REQUEST_H
