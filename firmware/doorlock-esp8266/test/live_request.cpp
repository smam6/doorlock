/**
 * Emit one signed device request exactly as the firmware would send it.
 *
 * Usage:
 *   live_request <unique_id> <device_key> <door_id> <card_number>
 *                <timestamp> <nonce> <request_id>
 *
 * The raw HTTP/1.1 request is written to stdout. Used by
 * tests/http/firmware_protocol_test.py to verify that the firmware's signing
 * code and the PHP backend agree on the wire format.
 */
#include "../src/doorlock_core.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

using namespace doorlock;

int main(int argc, char** argv) {
    if (argc != 8) {
        fprintf(stderr,
                "usage: %s <unique_id> <device_key> <door_id> <card_number> <timestamp> "
                "<nonce> <request_id>\n",
                argv[0]);
        return 64;
    }
    DeviceCredentials credentials;
    memset(&credentials, 0, sizeof(credentials));
    strncpy(credentials.uniqueId, argv[1], sizeof(credentials.uniqueId) - 1);
    strncpy(credentials.keyHex, argv[2], sizeof(credentials.keyHex) - 1);
    if (!isValidUniqueId(credentials.uniqueId) || !isValidKeyHex(credentials.keyHex)) {
        fprintf(stderr, "invalid credentials\n");
        return 65;
    }
    long doorId = strtol(argv[3], NULL, 10);
    if (doorId <= 0) {
        fprintf(stderr, "invalid door id\n");
        return 65;
    }
    long timestamp = strtol(argv[5], NULL, 10);
    if (timestamp <= 0) {
        fprintf(stderr, "invalid timestamp\n");
        return 65;
    }

    char body[160];
    snprintf(body, sizeof(body), "{\"door_id\":%ld,\"card_number\":\"%s\"}", doorId, argv[4]);

    char request[1024];
    size_t written = 0;
    if (!buildCheckAccessRequest("127.0.0.1", 8090, "/api/check-access.php", credentials,
                                 static_cast<uint32_t>(timestamp), argv[6], argv[7], body, request,
                                 sizeof(request), &written)) {
        fprintf(stderr, "failed to build request\n");
        return 66;
    }
    fwrite(request, 1, written, stdout);
    return 0;
}
