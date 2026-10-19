#ifndef DOORLOCK_JSON_LITE_H
#define DOORLOCK_JSON_LITE_H

/**
 * Minimal, allocation-free JSON field extractor.
 *
 * It is deliberately *not* a general-purpose parser. It scans a document,
 * skips strings correctly and reports the value of the first occurrence of a
 * key at a given object depth. The device response is a flat object, so
 * requiring depth 1 prevents a nested object such as
 * {"debug":{"access":true}} from ever being mistaken for the real decision.
 */

#include <stddef.h>

namespace doorlock {

enum class JsonType { None, Bool, Number, String, Null };

struct JsonValue {
    JsonType type;
    bool boolean;
    long number;
    char text[64]; // strings truncated to 63 chars, NUL-terminated
};

struct JsonLimits {
    size_t maxDepth;
    size_t maxStringLength; // of scanned strings; deeper/longer values are skipped
};

/**
 * Find `key` inside an object at exactly `depth` object nesting levels
 * (1 = root object). Returns false when the key is absent, when the document
 * is malformed, or when the value type is not supported.
 *
 * A malformed document never yields a value, which makes the caller fail
 * closed.
 */
bool jsonFind(const char* document, size_t length, const char* key, size_t depth, JsonValue* out);

/** Count exact key occurrences at `depth`; used to reject duplicated fields. */
bool jsonCount(const char* document, size_t length, const char* key, size_t depth, size_t* count);

/** True when the whole document is a single, well-formed JSON value. */
bool jsonIsWellFormed(const char* document, size_t length);

} // namespace doorlock

#endif // DOORLOCK_JSON_LITE_H
