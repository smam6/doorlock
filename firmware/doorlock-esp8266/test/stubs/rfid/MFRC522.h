#ifndef DOORLOCK_STUB_MFRC522_H
#define DOORLOCK_STUB_MFRC522_H

#include <Arduino.h>

class MFRC522 {
public:
    enum Register { VersionReg = 0x37 };

    struct Uid {
        uint8_t size;
        uint8_t uidByte[10];
    } uid;

    MFRC522(uint8_t chipSelectPin, uint8_t resetPin);
    void PCD_Init();
    uint8_t PCD_ReadRegister(uint8_t registerAddress);
    bool PICC_IsNewCardPresent();
    bool PICC_ReadCardSerial();
    void PICC_HaltA();
};

#endif // DOORLOCK_STUB_MFRC522_H
