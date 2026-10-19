#ifndef DOORLOCK_SHA256_H
#define DOORLOCK_SHA256_H

/**
 * Portable SHA-256 / HMAC-SHA256.
 *
 * No dynamic memory, no exceptions and no Arduino dependency so the exact
 * code that runs on the ESP8266 can also be compiled and unit-tested on a
 * workstation. Only C++11 and <stdint.h>/<stddef.h> are required.
 */

#include <stddef.h>
#include <stdint.h>

namespace doorlock {

class Sha256 {
public:
    static const size_t kDigestSize = 32;

    Sha256();
    void reset();
    void update(const void* data, size_t length);
    /** Finalise into a 32-byte digest. The object must be reset() before reuse. */
    void finish(uint8_t digest[kDigestSize]);

private:
    void transform(const uint8_t block[64]);

    uint32_t state_[8];
    uint64_t bitLength_;
    uint8_t buffer_[64];
    size_t bufferLength_;
};

void sha256(const void* data, size_t length, uint8_t digest[Sha256::kDigestSize]);
/** Lowercase hexadecimal digest, NUL-terminated (65 bytes). */
void sha256Hex(const void* data, size_t length, char out[65]);

void hmacSha256(const void* key, size_t keyLength, const void* message, size_t messageLength,
                uint8_t digest[Sha256::kDigestSize]);
/** Lowercase hexadecimal HMAC, NUL-terminated (65 bytes). */
void hmacSha256Hex(const void* key, size_t keyLength, const void* message, size_t messageLength,
                   char out[65]);

/** Constant-time comparison for equal-length buffers. */
bool constantTimeEquals(const void* a, const void* b, size_t length);

} // namespace doorlock

#endif // DOORLOCK_SHA256_H
