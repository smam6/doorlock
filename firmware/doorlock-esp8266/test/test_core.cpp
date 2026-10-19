/**
 * Host unit tests for the portable DoorLock controller logic.
 *
 * These compile with a normal C++11 compiler (no Arduino, no ESP8266) and
 * exercise the exact source files that are compiled into the firmware.
 *
 * Build & run:  make -C firmware/test
 */
#include "../src/doorlock_core.h"

#include <stdio.h>
#include <string.h>

using namespace doorlock;

static int g_passed = 0;
static int g_failed = 0;
static const char* g_current = "";

static void beginTest(const char* id, const char* name) {
    g_current = id;
    printf("%s  %-5s %s\n", "", id, name);
}

static void check(bool condition, const char* message) {
    if (condition) {
        g_passed++;
    } else {
        g_failed++;
        printf("FAIL  %-5s %s\n", g_current, message);
    }
}

static void checkText(const char* expected, const char* actual, const char* message) {
    if (actual != 0 && strcmp(expected, actual) == 0) {
        g_passed++;
    } else {
        g_failed++;
        printf("FAIL  %-5s %s (expected \"%s\", got \"%s\")\n", g_current, message, expected,
               actual == 0 ? "(null)" : actual);
    }
}

static void buildFixedResponse(char* out, size_t outSize, const char* statusLine,
                               const char* contentType, const char* body) {
    snprintf(out, outSize,
             "%s\r\nContent-Type: %s\r\nContent-Length: %u\r\n\r\n%s",
             statusLine, contentType, static_cast<unsigned>(strlen(body)), body);
}

static void feedAll(ResponseReader& reader, const char* text) {
    for (const char* p = text; *p != '\0'; p++) {
        if (!reader.feed(*p)) {
            break;
        }
    }
    reader.finish();
}

int main() {
    printf("DoorLock firmware core tests\n");

    // ---------------------------------------------------------------- SHA-256
    beginTest("FW-001", "SHA-256 matches the FIPS 180 test vectors");
    {
        char hex[65];
        sha256Hex("", 0, hex);
        checkText("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", hex,
                  "empty string");
        sha256Hex("abc", 3, hex);
        checkText("ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad", hex, "abc");
        const char* longText =
            "abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq";
        sha256Hex(longText, strlen(longText), hex);
        checkText("248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1", hex,
                  "two-block message");
    }

    // ------------------------------------------------------------------- HMAC
    beginTest("FW-002", "HMAC-SHA256 matches RFC 4231 vectors");
    {
        char hex[65];
        hmacSha256Hex("Jefe", 4, "what do ya want for nothing?", 28, hex);
        checkText("5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843", hex,
                  "RFC 4231 case 2");
        unsigned char key[20];
        memset(key, 0xaa, sizeof(key));
        const char* data = "Test Using Larger Than Block-Size Key - Hash Key First";
        unsigned char longKey[131];
        memset(longKey, 0xaa, sizeof(longKey));
        hmacSha256Hex(longKey, sizeof(longKey), data, strlen(data), hex);
        checkText("60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54", hex,
                  "RFC 4231 case 6 (131-byte key)");
    }

    // ------------------------------------------------------- device key vector
    beginTest("FW-003", "Signing reproduces the documented reference vector");
    {
        DeviceCredentials credentials;
        strcpy(credentials.uniqueId, "0123456789abcdef0123456789abcdef");
        strcpy(credentials.keyHex, "90b55e88d48719b10338ed254fc8a8988d7ec7234f0a9513689679bff61d6e26");
        const char* body = "{\"card_number\":\"04AABBCC\",\"door_id\":1}";
        char canonical[512];
        check(buildCanonicalString(credentials.uniqueId, 1760000000u, "a1b2c3d4e5f60718",
                                   "req-12345678", body, canonical, sizeof(canonical)),
              "canonical string built");
        checkText(
            "DOORLOCK1\nPOST\ncheck-access\n0123456789abcdef0123456789abcdef\n1760000000\n"
            "a1b2c3d4e5f60718\nreq-12345678\n"
            "61b21984380594570a087c8abed7570ff3313b6ad91eec12a155bd0c9d7618b7",
            canonical, "canonical string");
        char signature[65];
        check(signCanonicalString(credentials.keyHex, credentials, canonical, signature),
              "signature computed");
        checkText("8e5eae175623653c888746d38bea984e1944c36eeca518ea876ec382fb1f65d3", signature,
                  "signature");
        char request[1024];
        size_t written = 0;
        check(buildCheckAccessRequest("door.example.com", 443, "/api/check-access.php", credentials,
                                      1760000000u, "a1b2c3d4e5f60718", "req-12345678", body,
                                      request, sizeof(request), &written),
              "HTTP request built");
        check(strstr(request, "X-Signature: 8e5eae175623653c888746d38bea984e1944c36eeca518ea876ec382fb1f65d3") != 0,
              "signature header present");
        char expectedLength[40];
        snprintf(expectedLength, sizeof(expectedLength), "Content-Length: %u",
                 static_cast<unsigned>(strlen(body)));
        check(strstr(request, expectedLength) != 0, "content length matches body");
        check(strstr(request, "Connection: close") != 0, "connection closed after response");
    }

    // -------------------------------------------------------- response parsing
    beginTest("FW-004", "A granted JSON response is accepted");
    {
        const char* body =
            "{\"success\":true,\"access\":true,\"decision\":\"granted\","
            "\"reason\":\"granted\",\"request_id\":\"abc12345\",\"unlock_seconds\":5}";
        char raw[512];
        buildFixedResponse(raw, sizeof(raw), "HTTP/1.1 200 OK",
                           "application/json; charset=utf-8", body);
        ResponseReader reader;
        feedAll(reader, raw);
        AccessResult result = evaluateResponse(reader.response(), 5, 30);
        check(result.decision == Decision::Granted, "decision granted");
        check(result.unlockSeconds == 5, "unlock seconds 5");
        checkText("granted", result.reason, "reason");
        checkText("abc12345", result.requestId, "request id");
    }

    beginTest("FW-005", "A denial is reported as a denial, not a failure");
    {
        const char* body =
            "{\"success\":true,\"access\":false,\"decision\":\"denied\","
            "\"reason\":\"outside_schedule\",\"request_id\":\"abc12345\"}";
        char raw[512];
        buildFixedResponse(raw, sizeof(raw), "HTTP/1.1 200 OK", "application/json", body);
        ResponseReader reader;
        feedAll(reader, raw);
        AccessResult result = evaluateResponse(reader.response(), 5, 30);
        check(result.decision == Decision::Denied, "decision denied");
        checkText("outside_schedule", result.reason, "reason preserved");
        check(result.unlockSeconds == 0, "no unlock seconds");
    }

    beginTest("FW-006", "Chunked responses are decoded byte by byte");
    {
        const char* part1 = "{\"success\":true,\"access\":true,";
        const char* part2 = "\"reason\":\"granted\",\"request_id\":\"zz\"}";
        char raw[512];
        snprintf(raw, sizeof(raw),
                 "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                 "Transfer-Encoding: chunked\r\n\r\n%x\r\n%s\r\n%x\r\n%s\r\n0\r\n\r\n",
                 static_cast<unsigned>(strlen(part1)), part1,
                 static_cast<unsigned>(strlen(part2)), part2);
        ResponseReader reader;
        feedAll(reader, raw);
        check(reader.response().valid, "chunked response valid");
        check(reader.response().complete, "chunked response complete");
        AccessResult result = evaluateResponse(reader.response(), 5, 30);
        check(result.decision == Decision::Granted, "chunked grant accepted");
    }

    beginTest("FW-007", "Malformed and truncated responses never grant");
    {
        const char* cases[] = {
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 40\r\n\r\n{\"success\":true,\"access\":tr",
            "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 20\r\n\r\n{\"access\":true}xxxxx",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json\r\nContent-Length: 38\r\n\r\n{\"success\":true,\"access\":true,\"x\":1}",
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 26\r\n\r\n{\"success\":true,\"access\":\"true\"}",
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 26\r\n\r\n{\"success\":true,\"access\":true }",
            "not an http response at all",
        };
        for (size_t i = 0; i < sizeof(cases) / sizeof(cases[0]); i++) {
            ResponseReader reader;
            feedAll(reader, cases[i]);
            AccessResult result = evaluateResponse(reader.response(), 5, 30);
            if (result.decision == Decision::Granted) {
                printf("FAIL  FW-007 case %u granted unexpectedly\n", static_cast<unsigned>(i));
                g_failed++;
            } else {
                g_passed++;
            }
        }
    }

    beginTest("FW-008", "Ambiguous or contradictory JSON is rejected");
    {
        struct Case {
            const char* body;
            const char* label;
        } cases[] = {
            {"{\"success\":true,\"access\":true,\"access\":false,\"reason\":\"granted\"}", "duplicate access"},
            {"{\"success\":false,\"access\":true,\"reason\":\"granted\"}", "success false"},
            {"{\"success\":true,\"reason\":\"granted\"}", "access missing"},
            {"{\"success\":true,\"access\":true,\"reason\":\"no_permission\"}", "grant without granted reason"},
            {"{\"success\":true,\"access\":true,\"reason\":\"granted\",\"unlock_seconds\":\"5\"}", "string seconds"},
            {"{\"success\":true,\"access\":true,\"reason\":\"granted\",\"debug\":{\"access\":true}}", "nested decoy only grants outer"},
        };
        for (size_t i = 0; i < sizeof(cases) / sizeof(cases[0]); i++) {
            char raw[512];
            snprintf(raw, sizeof(raw),
                     "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %u\r\n\r\n%s",
                     static_cast<unsigned>(strlen(cases[i].body)), cases[i].body);
            ResponseReader reader;
            feedAll(reader, raw);
            AccessResult result = evaluateResponse(reader.response(), 5, 30);
            bool expectedGrant = strcmp(cases[i].label, "nested decoy only grants outer") == 0;
            if (expectedGrant) {
                check(result.decision == Decision::Granted, "nested decoy does not block real grant");
            } else if (result.decision == Decision::Granted) {
                printf("FAIL  FW-008 %s was granted\n", cases[i].label);
                g_failed++;
            } else {
                g_passed++;
            }
        }
    }

    beginTest("FW-009", "Unlock duration is clamped into the safe range");
    {
        check(clampUnlockSeconds(5, 5, 30) == 5, "normal value");
        check(clampUnlockSeconds(0, 5, 30) == 5, "zero falls back to default");
        check(clampUnlockSeconds(-3, 4, 30) == 4, "negative falls back to default");
        check(clampUnlockSeconds(500, 5, 30) == 30, "large value clamps to maximum");
        check(clampUnlockSeconds(1, 5, 0) == 1, "zero maximum clamps to 1");
        check(clampUnlockSeconds(9, 5, 60) == 9, "maximum above 30 is capped at 30");
    }

    // --------------------------------------------------------------- relay
    beginTest("FW-010", "Relay starts locked and follows the interval");
    {
        bool level = true;
        PinWriter writer = [](uint8_t, bool value) { (*(bool*)0) = value; };
        (void)writer;
        static bool lastLevel;
        static int transitions;
        transitions = 0;
        PinWriter capture = [](uint8_t, bool value) {
            lastLevel = value;
            transitions++;
        };
        RelayController relay(5, false, 30, capture);
        relay.begin(0);
        check(!relay.unlocked(), "locked after begin");
        check(lastLevel == false, "output driven to locked level");

        relay.unlock(1000, 5);
        check(relay.unlocked(), "unlocked after grant");
        check(lastLevel == true, "output energised");
        relay.tick(5999);
        check(relay.unlocked(), "still unlocked just before the interval");
        relay.tick(6000);
        check(!relay.unlocked(), "relocked exactly at the interval");
        check(lastLevel == false, "output returned to locked level");

        // Repeated scans extend the window but never stack.
        relay.unlock(10000, 5);
        relay.unlock(12000, 5);
        check(relay.remainingMs(12000) == 5000, "second scan restarts the interval");
        relay.tick(16999);
        check(relay.unlocked(), "still unlocked at 4.999 s");
        relay.tick(17000);
        check(!relay.unlocked(), "single relock at the end");
        (void)level;
        (void)transitions;
    }

    beginTest("FW-011", "Relay timing survives millis() wrap-around");
    {
        static bool lastLevel;
        PinWriter capture = [](uint8_t, bool value) { lastLevel = value; };
        RelayController relay(5, true, 30, capture);
        uint32_t start = 0xffffff00u; // 256 ms before wrap
        relay.begin(start);
        check(lastLevel == false, "active-high locked level is LOW");
        relay.unlock(start, 1);
        check(lastLevel == true, "active-high unlocked level is HIGH");
        relay.tick(start + 500u); // wraps
        check(relay.unlocked(), "unlocked across the wrap");
        relay.tick(start + 1000u);
        check(!relay.unlocked(), "relocked after the interval across the wrap");
    }

    beginTest("FW-012", "forceLock and begin always de-energise");
    {
        static bool lastLevel;
        PinWriter capture = [](uint8_t, bool value) { lastLevel = value; };
        RelayController relay(5, true, 30, capture);
        relay.begin(0);
        relay.unlock(0, 10);
        check(relay.unlocked(), "unlocked");
        relay.forceLock();
        check(!relay.unlocked() && lastLevel == false, "forceLock relocks immediately");
        relay.unlock(100, 10);
        relay.begin(200); // simulated reboot
        check(!relay.unlocked() && lastLevel == false, "reboot starts locked");
    }

    beginTest("FW-013", "Card debouncing suppresses repeated reads");
    {
        CardDebouncer debouncer(3000);
        check(debouncer.accept("04AABBCC", 1000), "first read accepted");
        check(!debouncer.accept("04AABBCC", 2000), "repeat inside the window rejected");
        check(debouncer.accept("04AABBCC", 4000), "repeat after the window accepted");
        check(debouncer.accept("04DDEEFF", 4100), "different card accepted");
        check(!debouncer.accept("", 5000), "empty identifier rejected");
        debouncer.reset();
        check(debouncer.accept("04AABBCC", 5100), "accepted after reset");
    }

    beginTest("FW-014", "HTTP framing errors and overflow are rejected");
    {
        ResponseReader small(64);
        const char* raw =
            "HTTP/1.1 200 OK\r\nContent-Length: 500\r\n\r\n{\"success\":true}";
        feedAll(small, raw);
        check(!small.response().valid, "oversized body rejected");

        ResponseReader conflicting;
        const char* bad =
            "HTTP/1.1 200 OK\r\nContent-Length: 10\r\nContent-Length: 20\r\n\r\n0123456789";
        feedAll(conflicting, bad);
        check(!conflicting.response().valid, "conflicting content lengths rejected");

        ResponseReader garbage;
        feedAll(garbage, "<html>502 Bad Gateway</html>");
        check(!garbage.response().valid, "non-HTTP payload rejected");
    }

    beginTest("FW-015", "Request builder rejects invalid credentials and short buffers");
    {
        DeviceCredentials credentials;
        strcpy(credentials.uniqueId, "0123456789abcdef0123456789abcdef");
        strcpy(credentials.keyHex, "90b55e88d48719b10338ed254fc8a8988d7ec7234f0a9513689679bff61d6e26");
        char request[256];
        size_t written = 0;
        check(!buildCheckAccessRequest("door.example.com", 443, "/api/check-access.php",
                                       credentials, 1700000000u, "nonce12345", "req12345",
                                       "{\"door_id\":1}", request, sizeof(request), &written),
              "rejects a buffer that is too small");

        DeviceCredentials badKey = credentials;
        strcpy(badKey.keyHex, "NOT-HEX");
        check(!buildCheckAccessRequest("door.example.com", 443, "/api/check-access.php", badKey,
                                       1700000000u, "nonce12345", "req12345", "{}", request,
                                       sizeof(request), &written),
              "rejects a malformed key");
        check(!isValidUniqueId("0123456789ABCDEF0123456789ABCDEF"), "uppercase uid rejected");
        check(!isValidKeyHex("abc"), "short key rejected");
    }

    printf("--------------------------------------------------------------------------------\n");
    printf("FIRMWARE TOTAL %d | PASS %d | FAIL %d\n", g_passed + g_failed, g_passed, g_failed);
    return g_failed == 0 ? 0 : 1;
}
