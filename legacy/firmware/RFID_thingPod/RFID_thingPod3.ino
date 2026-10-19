#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>

#define SS_PIN D2
#define RST_PIN D1
#define RELAY_PIN D3

const char* ssid = "dlink-M960-2.4G-ab65";
const char* password = "m+9198523482+";

String serverUrl = "http://192.168.1.100/api/check-access";

MFRC522 rfid(SS_PIN, RST_PIN);

String lastUID = "";
unsigned long lastScanTime = 0;
unsigned long doorOpenTime = 0;

bool doorOpen = false;
const int DOOR_OPEN_DURATION = 5000;

WiFiClient client;

void connectWiFi()
{
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.println("Connecting to WiFi...");

  WiFi.begin(ssid, password);

  while (WiFi.status() != WL_CONNECTED)
  {
    delay(500);
    Serial.print(".");
  }

  Serial.println("");
  Serial.println("WiFi Connected");
}

String getUID()
{
  String content = "";

  for (byte i = 0; i < rfid.uid.size; i++)
  {
    content += String(rfid.uid.uidByte[i], HEX);
  }

  content.toUpperCase();

  return content;
}

bool sendCardToServer(String uid)
{
  if (WiFi.status() != WL_CONNECTED)
  {
    Serial.println("WiFi disconnected");
    connectWiFi();
    return false;
  }

  HTTPClient http;

  http.begin(client, serverUrl);
  http.addHeader("Content-Type", "application/json");

  String payload = "{\"card_number\":\"" + uid + "\"}";

  Serial.println("Sending:");
  Serial.println(payload);

  int httpResponseCode = http.POST(payload);

  if (httpResponseCode <= 0)
  {
    Serial.println("HTTP Error");
    http.end();
    return false;
  }

  String response = http.getString();

  Serial.println("Server Response:");
  Serial.println(response);

  http.end();

  if (response.indexOf("\"access\":true") >= 0)
  {
    return true;
  }

  return false;
}

void openDoor()
{
  Serial.println("Door OPEN");

  digitalWrite(RELAY_PIN, HIGH);

  doorOpen = true;
  doorOpenTime = millis();
}

void handleDoorTimer()
{
  if (doorOpen && millis() - doorOpenTime > DOOR_OPEN_DURATION)
  {
    Serial.println("Door LOCK");

    digitalWrite(RELAY_PIN, LOW);
    doorOpen = false;
  }
}

void setup()
{
  Serial.begin(115200);

  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, LOW);

  SPI.begin();
  rfid.PCD_Init();

  connectWiFi();

  Serial.println("RFID Door System Ready");
}

void loop()
{
  connectWiFi();

  handleDoorTimer();

  if (!rfid.PICC_IsNewCardPresent())
    return;

  if (!rfid.PICC_ReadCardSerial())
    return;

  String uid = getUID();

  unsigned long now = millis();

  if (uid == lastUID && now - lastScanTime < 3000)
  {
    return;
  }

  lastUID = uid;
  lastScanTime = now;

  Serial.println("Card UID:");
  Serial.println(uid);

  bool accessGranted = sendCardToServer(uid);

  if (accessGranted)
  {
    openDoor();
  }
  else
  {
    Serial.println("Access denied");
  }

  rfid.PICC_HaltA();
}
