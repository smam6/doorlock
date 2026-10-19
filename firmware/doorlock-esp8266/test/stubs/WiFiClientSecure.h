#ifndef DOORLOCK_STUB_WIFICLIENTSECURE_H
#define DOORLOCK_STUB_WIFICLIENTSECURE_H

#include <Arduino.h>

namespace BearSSL {
class X509List {
public:
    explicit X509List(const char* pem);
};
} // namespace BearSSL

class WiFiClientSecure {
public:
    void setTimeout(unsigned long seconds);
    void setTrustAnchors(BearSSL::X509List* list);
    void setFingerprint(const uint8_t* fingerprint);
    bool connect(const char* host, uint16_t port);
    bool connected();
    size_t write(const uint8_t* data, size_t length);
    void flush();
    int available();
    int read();
    void stop();
};

void configTime(long gmtOffsetSeconds, int daylightOffsetSeconds, const char* server1,
                const char* server2 = nullptr, const char* server3 = nullptr);

#endif // DOORLOCK_STUB_WIFICLIENTSECURE_H
