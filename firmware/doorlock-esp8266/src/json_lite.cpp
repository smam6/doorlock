#include "json_lite.h"

#include <string.h>

namespace doorlock {
namespace {

const size_t kMaxScanDepth = 16;

struct Scanner {
    const char* p;
    const char* end;

    bool skipWhitespace() {
        while (p < end) {
            char c = *p;
            if (c == ' ' || c == '\t' || c == '\r' || c == '\n') {
                p++;
            } else {
                break;
            }
        }
        return p < end;
    }

    /** Copy a string token starting at '"'. Returns false if unterminated. */
    bool readString(char* out, size_t outSize, size_t* outLength = 0) {
        if (p >= end || *p != '"') {
            return false;
        }
        p++;
        size_t written = 0;
        while (p < end) {
            char c = *p++;
            if (c == '"') {
                if (out != 0 && outSize > 0) {
                    out[written < outSize ? written : outSize - 1] = '\0';
                }
                if (outLength != 0) {
                    *outLength = written;
                }
                return true;
            }
            if (c == '\\') {
                if (p >= end) {
                    return false;
                }
                char esc = *p++;
                switch (esc) {
                case '"': c = '"'; break;
                case '\\': c = '\\'; break;
                case '/': c = '/'; break;
                case 'b': c = '\b'; break;
                case 'f': c = '\f'; break;
                case 'n': c = '\n'; break;
                case 'r': c = '\r'; break;
                case 't': c = '\t'; break;
                case 'u':
                    // Skip four hex digits; surrogate pairs are irrelevant for
                    // the ASCII keys and values used by the device API.
                    for (int i = 0; i < 4; i++) {
                        if (p >= end) {
                            return false;
                        }
                        p++;
                    }
                    c = '?';
                    break;
                default:
                    return false;
                }
            }
            if (out != 0 && outSize > 0 && written + 1 < outSize) {
                out[written] = c;
            }
            written++;
        }
        return false;
    }

    bool skipValue(size_t depth);

    bool skipContainer(char open, char close, size_t depth) {
        if (p >= end || *p != open) {
            return false;
        }
        p++;
        skipWhitespace();
        if (p < end && *p == close) {
            p++;
            return true;
        }
        while (p < end) {
            if (open == '{') {
                char key[2];
                if (!readString(key, sizeof(key))) {
                    return false;
                }
                if (!skipWhitespace() || *p != ':') {
                    return false;
                }
                p++;
            }
            if (!skipValue(depth + 1)) {
                return false;
            }
            if (!skipWhitespace()) {
                return false;
            }
            if (*p == ',') {
                p++;
                if (!skipWhitespace()) {
                    return false;
                }
                continue;
            }
            if (*p == close) {
                p++;
                return true;
            }
            return false;
        }
        return false;
    }
};

bool Scanner::skipValue(size_t depth) {
    if (depth > kMaxScanDepth || !skipWhitespace()) {
        return false;
    }
    char c = *p;
    if (c == '"') {
        return readString(0, 0);
    }
    if (c == '{') {
        return skipContainer('{', '}', depth);
    }
    if (c == '[') {
        return skipContainer('[', ']', depth);
    }
    if (strncmp(p, "true", 4) == 0) {
        p += 4;
        return true;
    }
    if (strncmp(p, "false", 5) == 0) {
        p += 5;
        return true;
    }
    if (strncmp(p, "null", 4) == 0) {
        p += 4;
        return true;
    }
    // Number: -?digits(.digits)?([eE][+-]?digits)?
    const char* start = p;
    if (p < end && *p == '-') {
        p++;
    }
    bool digits = false;
    while (p < end && *p >= '0' && *p <= '9') {
        p++;
        digits = true;
    }
    if (p < end && *p == '.') {
        p++;
        while (p < end && *p >= '0' && *p <= '9') {
            p++;
        }
    }
    if (p < end && (*p == 'e' || *p == 'E')) {
        p++;
        if (p < end && (*p == '+' || *p == '-')) {
            p++;
        }
        while (p < end && *p >= '0' && *p <= '9') {
            p++;
        }
    }
    return digits && p != start;
}

/** Walk the document calling a visitor for (key, depth) pairs inside objects. */
template <typename Visitor>
bool walk(const char* document, size_t length, Visitor& visitor) {
    if (document == 0 || length == 0) {
        return false;
    }
    Scanner scanner;
    scanner.p = document;
    scanner.end = document + length;
    if (!scanner.skipWhitespace() || *scanner.p != '{') {
        return false;
    }
    size_t depth = 0;
    bool ok = true;
    bool expectKey = false;
    char key[64];

    // Manual walk so the visitor can inspect each key/value pair.
    if (*scanner.p == '{') {
        depth = 1;
        scanner.p++;
        scanner.skipWhitespace();
        if (scanner.p < scanner.end && *scanner.p == '}') {
            scanner.p++;
            return true;
        }
        expectKey = true;
    }
    while (ok && scanner.p < scanner.end) {
        if (expectKey) {
            if (!scanner.readString(key, sizeof(key))) {
                ok = false;
                break;
            }
            if (!scanner.skipWhitespace() || *scanner.p != ':') {
                ok = false;
                break;
            }
            scanner.p++;
            scanner.skipWhitespace();
            if (!visitor.onMember(key, depth, scanner.p, scanner.end)) {
                ok = false;
                break;
            }
            // The visitor must not consume; we skip the value ourselves.
            if (!scanner.skipValue(depth)) {
                ok = false;
                break;
            }
            expectKey = false;
        } else {
            if (!scanner.skipWhitespace()) {
                ok = false;
                break;
            }
            if (*scanner.p == ',') {
                scanner.p++;
                scanner.skipWhitespace();
                expectKey = true;
                continue;
            }
            if (*scanner.p == '}') {
                scanner.p++;
                depth--;
                if (depth == 0) {
                    break;
                }
                // Continue in the parent object (end of a nested object value).
                continue;
            }
            ok = false;
        }
    }
    if (!ok || depth != 0) {
        return false;
    }
    if (!scanner.skipWhitespace()) {
        return true; // trailing whitespace/end
    }
    return scanner.p == scanner.end;
}

struct FindVisitor {
    const char* key;
    size_t wantDepth;
    JsonValue* out;
    bool found;

    bool onMember(const char* name, size_t depth, const char* valueStart, const char* valueEnd) {
        if (found || strcmp(name, key) != 0 || depth != wantDepth) {
            return true; // continue scanning
        }
        Scanner value;
        value.p = valueStart;
        value.end = valueEnd;
        value.skipWhitespace();
        if (value.p >= value.end) {
            return true;
        }
        JsonValue result;
        result.type = JsonType::None;
        result.boolean = false;
        result.number = 0;
        result.text[0] = '\0';

        char c = *value.p;
        if (c == 't' && strncmp(value.p, "true", 4) == 0) {
            result.type = JsonType::Bool;
            result.boolean = true;
            result.text[0] = 't';
            result.text[1] = '\0';
        } else if (c == 'f' && strncmp(value.p, "false", 5) == 0) {
            result.type = JsonType::Bool;
            result.boolean = false;
            result.text[0] = 'f';
            result.text[1] = '\0';
        } else if (c == 'n' && strncmp(value.p, "null", 4) == 0) {
            result.type = JsonType::Null;
        } else if (c == '"') {
            if (!value.readString(result.text, sizeof(result.text))) {
                return true;
            }
            result.type = JsonType::String;
        } else if (c == '-' || (c >= '0' && c <= '9')) {
            bool negative = false;
            if (c == '-') {
                negative = true;
                value.p++;
            }
            long number = 0;
            bool digits = false;
            while (value.p < value.end && *value.p >= '0' && *value.p <= '9') {
                if (number < 100000000L) {
                    number = number * 10 + (*value.p - '0');
                }
                value.p++;
                digits = true;
            }
            if (!digits) {
                return true;
            }
            result.type = JsonType::Number;
            result.number = negative ? -number : number;
        } else {
            return true; // unsupported value: leave found=false
        }
        *out = result;
        found = true;
        return true;
    }
};

struct CountVisitor {
    const char* key;
    size_t wantDepth;
    size_t count;

    bool onMember(const char* name, size_t depth, const char*, const char*) {
        if (depth == wantDepth && strcmp(name, key) == 0) {
            count++;
        }
        return true;
    }
};

} // namespace

bool jsonFind(const char* document, size_t length, const char* key, size_t depth, JsonValue* out) {
    if (out == 0 || key == 0) {
        return false;
    }
    FindVisitor visitor;
    visitor.key = key;
    visitor.wantDepth = depth;
    visitor.out = out;
    visitor.found = false;
    if (!walk(document, length, visitor)) {
        return false;
    }
    return visitor.found;
}

bool jsonCount(const char* document, size_t length, const char* key, size_t depth, size_t* count) {
    if (count == 0 || key == 0) {
        return false;
    }
    CountVisitor visitor;
    visitor.key = key;
    visitor.wantDepth = depth;
    visitor.count = 0;
    if (!walk(document, length, visitor)) {
        return false;
    }
    *count = visitor.count;
    return true;
}

bool jsonIsWellFormed(const char* document, size_t length) {
    if (document == 0 || length == 0) {
        return false;
    }
    Scanner scanner;
    scanner.p = document;
    scanner.end = document + length;
    if (!scanner.skipWhitespace()) {
        return false;
    }
    if (!scanner.skipValue(1)) {
        return false;
    }
    if (!scanner.skipWhitespace()) {
        return true;
    }
    return scanner.p == scanner.end;
}

} // namespace doorlock
