#ifndef DOORLOCK_HTTP_RESPONSE_H
#define DOORLOCK_HTTP_RESPONSE_H

/**
 * Incremental, bounded HTTP/1.1 response reader.
 *
 * It is fed one byte at a time from the socket, never allocates, and treats
 * anything unexpected as a parse failure. The caller must not act on a
 * response unless valid() is true *and* complete() is true.
 *
 * Supported framing: Content-Length, Transfer-Encoding: chunked, and
 * read-until-close when neither header is present. Oversized headers or
 * bodies fail the parse instead of exhausting memory.
 */

#include <stddef.h>
#include <stdint.h>

namespace doorlock {

struct HttpResponse {
    int status;
    char reason[48];
    char contentType[64];
    bool chunked;
    bool complete;
    bool valid;
    bool overflow;
    size_t bodyLength;
    char body[1025];

    void reset();
};

class ResponseReader {
public:
    explicit ResponseReader(size_t maxBodyBytes = 1024);

    void reset();
    /** Feed one byte. Returns false once parsing has finished or failed. */
    bool feed(char c);
    /** Signal end of input. Returns true when the response is complete/valid. */
    bool finish();
    bool done() const { return state_ == State::Done || state_ == State::Failed; }
    const HttpResponse& response() const { return response_; }

private:
    enum class State {
        StatusLine,
        Headers,
        BodyFixed,
        BodyChunkSize,
        BodyChunkData,
        BodyChunkTerminator,
        BodyChunkTrailer,
        BodyUntilClose,
        Done,
        Failed,
    };

    bool commitStatusLine();
    bool commitHeader();
    bool fail();
    bool appendByte(char c);
    void appendLine(char c, char* out, size_t outSize, size_t* length);

    State state_;
    size_t maxBody_;
    HttpResponse response_;

    char line_[256];
    size_t lineLength_;
    bool lineOverflow_;
    long contentLength_;
    long remaining_;
    long chunkRemaining_;
    bool sawContentLength_;
    bool sawChunked_;
};

} // namespace doorlock

#endif // DOORLOCK_HTTP_RESPONSE_H
