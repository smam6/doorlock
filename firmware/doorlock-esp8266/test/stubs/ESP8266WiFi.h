#ifndef DOORLOCK_STUB_WIFI_H
#define DOORLOCK_STUB_WIFI_H

#include <Arduino.h>

#define WL_CONNECTED 3
#define WIFI_STA 1
#define WIFI_OFF 0

class IPAddress {
public:
    bool fromString(const char* text);
};

class WiFiStub {
public:
    bool persistent(bool enabled);
    bool mode(int mode);
    bool setAutoReconnect(bool enabled);
    bool setAutoConnect(bool enabled);
    bool hostname(const char* name);
    bool config(IPAddress address, IPAddress gateway, IPAddress subnet, IPAddress dns);
    void begin(const char* ssid, const char* password);
    void reconnect();
    int status();
    IPAddress localIP();
};

extern WiFiStub WiFi;

#endif // DOORLOCK_STUB_WIFI_H
