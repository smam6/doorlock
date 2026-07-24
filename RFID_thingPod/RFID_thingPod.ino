#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>

const char* ssid = "DESKTOP-JQ3JE3Q 5149";       // نام شبکه WiFi خود را وارد کنید
const char* password = "7243]3Dj"; // رمز عبور WiFi خود را وارد کنید

// --- تنظیمات سرور (مقادیر را وارد کنید) ---
const char* serverName = "http://10.81.44.244/api.php"; // آدرس IP کامپیوتر سرور و مسیر فایل api را وارد کنید

// --- تعریف پین‌ها برای ESP8266 ---
#define SS_PIN D4   // پین SDA به D4
#define RST_PIN D3  // پین RST به D3
#define RELAY_PIN D8 // پین کنترل رله به D8

MFRC522 mfrc522(SS_PIN, RST_PIN);
WiFiClient wifiClient;

void setup() {
  Serial.begin(115200);
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, HIGH); // رله را غیرفعال کنید (معمولاً LOW فعال است، بسته به ماژول تنظیم کنید)

  SPI.begin();
  mfrc522.PCD_Init();
  Serial.println("RFID Door Lock System - ESP8266");
  Serial.println("Connecting to WiFi...");

  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi connected. IP address: ");
  Serial.println(WiFi.localIP());
  Serial.println("Ready. Scan your RFID tag...");
}

void loop() {
  if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial()) {
    delay(50);
    return;
  }

  // خواندن UID کارت
  String uidString = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) {
      uidString += "0";
    }
    uidString += String(mfrc522.uid.uidByte[i], HEX);
  }
  uidString.toUpperCase();
  Serial.print("UID Card: ");
  Serial.println(uidString);

  // ارسال UID به سرور
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(wifiClient, serverName);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String httpRequestData = "uid=" + uidString + "&action=check";
    Serial.print("Sending to server: ");
    Serial.println(httpRequestData);

    int httpResponseCode = http.POST(httpRequestData);
    String response = http.getString();

    Serial.print("HTTP Response code: ");
    Serial.println(httpResponseCode);
    Serial.print("Server response: ");
    Serial.println(response);

    if (httpResponseCode == 200) {
      if (response.indexOf("GRANTED") >= 0) {
        Serial.println("Access GRANTED. Opening door...");
        digitalWrite(RELAY_PIN, LOW); // فعال کردن رله (بسته به نوع ماژول ممکن است HIGH باشد)
        delay(5000); // باز ماندن قفل به مدت 3 ثانیه
        digitalWrite(RELAY_PIN, HIGH); // غیرفعال کردن رله
      } else {
        Serial.println("Access DENIED.");
      }
    } else {
      Serial.println("Error in server communication.");
    }
    http.end();
  } else {
    Serial.println("WiFi not connected.");
  }

  mfrc522.PICC_HaltA();
  delay(1000);
}