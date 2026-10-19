#include "http_response.h"

#include <string.h>

namespace doorlock {
namespace {

bool equalsIgnoreCase(const char* a, const char* b) {
    while (*a != '\0' && *b != '\0') {
        char x = *a++;
        char y = *b++;
        if (x >= 'A' && x <= 'Z') {
            x = static_cast<char>(x + ('a' - 'A'));
        }
        if (y >= 'A' && y <= 'Z') {
            y = static_cast<char>(y + ('a' - 'A'));
        }
        if (x != y) {
            return false;
        }
    }
    return *a == '\0' && *b == '\0';
}

bool startsWithIgnoreCase(const char* value, const char* prefix) {
    while (*prefix != '\0') {
        char x = *value++;
        char y = *prefix++;
        if (x >= 'A' && x <= 'Z') {
            x = static_cast<char>(x + ('a' - 'A'));
        }
        if (y >= 'A' && y <= 'Z') {
            y = static_cast<char>(y + ('a' - 'A'));
        }
        if (x != y) {
            return false;
        }
    }
    return true;
}

char* trim(char* text) {
    while (*text == ' ' || *text == '\t') {
        text++;
    }
    size_t length = strlen(text);
    while (length > 0 && (text[length - 1] == ' ' || text[length - 1] == '\t' ||
                          text[length - 1] == '\r')) {
        text[--length] = '\0';
    }
    return text;
}

long parseLong(const char* text) {
    long value = 0;
    bool digits = false;
    while (*text >= '0' && *text <= '9') {
        if (value > 100000000L) {
            return -1;
        }
        value = value * 10 + (*text - '0');
        text++;
        digits = true;
    }
    return digits ? value : -1;
}

} // namespace

void HttpResponse::reset() {
    status = 0;
    reason[0] = '\0';
    contentType[0] = '\0';
    chunked = false;
    complete = false;
    valid = false;
    overflow = false;
    bodyLength = 0;
    body[0] = '\0';
}

ResponseReader::ResponseReader(size_t maxBodyBytes) : maxBody_(maxBodyBytes) {
    if (maxBody_ > sizeof(response_.body) - 1) {
        maxBody_ = sizeof(response_.body) - 1;
    }
    reset();
}

void ResponseReader::reset() {
    state_ = State::StatusLine;
    response_.reset();
    line_[0] = '\0';
    lineLength_ = 0;
    lineOverflow_ = false;
    contentLength_ = -1;
    remaining_ = -1;
    chunkRemaining_ = 0;
    sawContentLength_ = false;
    sawChunked_ = false;
}

bool ResponseReader::fail() {
    state_ = State::Failed;
    response_.valid = false;
    response_.complete = false;
    return false;
}

void ResponseReader::appendLine(char c, char* out, size_t outSize, size_t* length) {
    if (*length + 1 < outSize) {
        out[*length] = c;
        out[*length + 1] = '\0';
    } else {
        lineOverflow_ = true;
    }
    (*length)++;
}

bool ResponseReader::commitStatusLine() {
    // "HTTP/1.1 200 OK"
    if (lineLength_ < 12 || strncmp(line_, "HTTP/", 5) != 0) {
        return fail();
    }
    const char* space = strchr(line_, ' ');
    if (space == 0) {
        return fail();
    }
    const char* code = space + 1;
    if (code[0] < '1' || code[0] > '9' || code[1] < '0' || code[1] > '9' || code[2] < '0' ||
        code[2] > '9') {
        return fail();
    }
    response_.status = (code[0] - '0') * 100 + (code[1] - '0') * 10 + (code[2] - '0');
    if (response_.status < 100 || response_.status > 599) {
        return fail();
    }
    const char* reason = code + 3;
    while (*reason == ' ') {
        reason++;
    }
    size_t reasonLength = strlen(reason);
    if (reasonLength >= sizeof(response_.reason)) {
        reasonLength = sizeof(response_.reason) - 1;
    }
    memcpy(response_.reason, reason, reasonLength);
    response_.reason[reasonLength] = '\0';
    return true;
}

bool ResponseReader::commitHeader() {
    if (lineLength_ == 0) {
        // End of headers: pick the body framing.
        if (sawChunked_) {
            response_.chunked = true;
            state_ = State::BodyChunkSize;
            lineLength_ = 0;
            return true;
        }
        if (sawContentLength_ && contentLength_ == 0) {
            response_.complete = true;
            response_.valid = true;
            state_ = State::Done;
            return true;
        }
        if (sawContentLength_ && contentLength_ > 0) {
            remaining_ = contentLength_;
            if (remaining_ > static_cast<long>(maxBody_)) {
                response_.overflow = true;
                return fail();
            }
            state_ = State::BodyFixed;
            return true;
        }
        state_ = State::BodyUntilClose;
        return true;
    }
    char* colon = strchr(line_, ':');
    if (colon == 0) {
        return fail();
    }
    *colon = '\0';
    char* name = trim(line_);
    char* value = trim(colon + 1);

    if (equalsIgnoreCase(name, "Content-Length")) {
        long parsed = parseLong(value);
        if (parsed < 0) {
            return fail();
        }
        if (sawContentLength_ && parsed != contentLength_) {
            return fail(); // conflicting lengths
        }
        contentLength_ = parsed;
        sawContentLength_ = true;
    } else if (equalsIgnoreCase(name, "Transfer-Encoding")) {
        if (startsWithIgnoreCase(value, "chunked")) {
            sawChunked_ = true;
        } else if (!equalsIgnoreCase(value, "identity")) {
            return fail(); // unknown transfer coding: cannot trust framing
        }
    } else if (equalsIgnoreCase(name, "Content-Type")) {
        size_t length = strlen(value);
        if (length >= sizeof(response_.contentType)) {
            length = sizeof(response_.contentType) - 1;
        }
        memcpy(response_.contentType, value, length);
        response_.contentType[length] = '\0';
    }
    return true;
}

bool ResponseReader::appendByte(char c) {
    if (response_.bodyLength >= maxBody_) {
        response_.overflow = true;
        return fail();
    }
    response_.body[response_.bodyLength++] = c;
    response_.body[response_.bodyLength] = '\0';
    return true;
}

bool ResponseReader::feed(char c) {
    if (done()) {
        return false;
    }
    switch (state_) {
    case State::StatusLine:
        if (c == '\n') {
            if (lineLength_ > 0 && line_[lineLength_ - 1] == '\r') {
                line_[--lineLength_] = '\0';
            }
            if (lineOverflow_ || !commitStatusLine()) {
                return fail();
            }
            state_ = State::Headers;
            lineLength_ = 0;
            lineOverflow_ = false;
            return true;
        }
        appendLine(c, line_, sizeof(line_), &lineLength_);
        if (lineLength_ > 0 && lineLength_ >= sizeof(line_) - 1) {
            return fail();
        }
        return true;

    case State::Headers:
        if (c == '\n') {
            if (lineLength_ > 0 && line_[lineLength_ - 1] == '\r') {
                line_[--lineLength_] = '\0';
            }
            if (lineOverflow_) {
                return fail();
            }
            if (!commitHeader()) {
                return false;
            }
            lineLength_ = 0;
            lineOverflow_ = false;
            return state_ == State::Failed ? false : true;
        }
        appendLine(c, line_, sizeof(line_), &lineLength_);
        if (lineLength_ >= sizeof(line_) - 1) {
            return fail();
        }
        return true;

    case State::BodyFixed:
    case State::BodyChunkData:
    case State::BodyUntilClose:
        if (!appendByte(c)) {
            return false;
        }
        if (state_ == State::BodyFixed) {
            remaining_--;
            if (remaining_ == 0) {
                response_.complete = true;
                response_.valid = true;
                state_ = State::Done;
            }
        } else if (state_ == State::BodyChunkData) {
            chunkRemaining_--;
            if (chunkRemaining_ == 0) {
                state_ = State::BodyChunkTerminator;
                lineLength_ = 0;
            }
        }
        return true;

    case State::BodyChunkSize:
        if (c == '\n') {
            if (lineLength_ > 0 && line_[lineLength_ - 1] == '\r') {
                line_[--lineLength_] = '\0';
            }
            char* text = line_;
            char* semicolon = strchr(text, ';');
            if (semicolon != 0) {
                *semicolon = '\0';
            }
            text = trim(text);
            if (*text == '\0') {
                return fail();
            }
            long size = 0;
            for (const char* digit = text; *digit != '\0'; digit++) {
                int value;
                if (*digit >= '0' && *digit <= '9') {
                    value = *digit - '0';
                } else if (*digit >= 'a' && *digit <= 'f') {
                    value = *digit - 'a' + 10;
                } else if (*digit >= 'A' && *digit <= 'F') {
                    value = *digit - 'A' + 10;
                } else {
                    return fail();
                }
                if (size > 100000L) {
                    return fail();
                }
                size = size * 16 + value;
            }
            lineLength_ = 0;
            if (size == 0) {
                state_ = State::BodyChunkTrailer;
                return true;
            }
            if (size > static_cast<long>(maxBody_) ||
                response_.bodyLength + static_cast<size_t>(size) > maxBody_) {
                response_.overflow = true;
                return fail();
            }
            chunkRemaining_ = size;
            state_ = State::BodyChunkData;
            return true;
        }
        appendLine(c, line_, sizeof(line_), &lineLength_);
        if (lineLength_ >= sizeof(line_) - 1) {
            return fail();
        }
        return true;

    case State::BodyChunkTerminator:
        // Expect exactly CRLF after the chunk data.
        if (c == '\n') {
            if (lineLength_ > 0 && line_[lineLength_ - 1] == '\r') {
                line_[--lineLength_] = '\0';
            }
            if (lineLength_ != 0) {
                return fail();
            }
            lineLength_ = 0;
            state_ = State::BodyChunkSize;
            return true;
        }
        appendLine(c, line_, sizeof(line_), &lineLength_);
        if (lineLength_ >= 2) {
            return fail();
        }
        return true;

    case State::BodyChunkTrailer:
        if (c == '\n') {
            if (lineLength_ > 0 && line_[lineLength_ - 1] == '\r') {
                line_[--lineLength_] = '\0';
            }
            if (lineLength_ == 0) {
                response_.complete = true;
                response_.valid = true;
                state_ = State::Done;
            }
            lineLength_ = 0;
            return true;
        }
        appendLine(c, line_, sizeof(line_), &lineLength_);
        if (lineLength_ >= sizeof(line_) - 1) {
            return fail();
        }
        return true;

    case State::Done:
    case State::Failed:
        return false;
    }
    return fail();
}

bool ResponseReader::finish() {
    if (state_ == State::Done) {
        return response_.valid && response_.complete;
    }
    if (state_ == State::BodyUntilClose) {
        // Connection closed: with no explicit framing this terminates the body.
        response_.complete = true;
        response_.valid = true;
        state_ = State::Done;
        return true;
    }
    return fail();
}

} // namespace doorlock
