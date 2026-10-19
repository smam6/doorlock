/**
 * Minimal Arduino/ESP8266 API stubs used only to type-check the sketch on a
 * workstation. They emulate signatures, not behaviour; the real firmware is
 * built with the ESP8266 Arduino core (see firmware/README.md).
 */
#ifndef DOORLOCK_STUB_ARDUINO_H
#define DOORLOCK_STUB_ARDUINO_H

#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define HIGH 1
#define LOW 0
#define OUTPUT 1
#define INPUT 0
#define F(text) text

void pinMode(uint8_t pin, uint8_t mode);
void digitalWrite(uint8_t pin, uint8_t value);
void delay(unsigned long milliseconds);
unsigned long millis();
void yield();

class IPAddress;

class SerialStub {
public:
    void begin(unsigned long baud);
    void println();
    void println(const char* text);
    void print(const char* text);
    template <typename T> void println(const T& value) { (void)value; }
    void printf(const char* format, ...);
    int available();
    int read();
};

class EspStub {
public:
    void wdtFeed();
    uint32_t random();
};

extern SerialStub Serial;
extern EspStub ESP;

#endif // DOORLOCK_STUB_ARDUINO_H
