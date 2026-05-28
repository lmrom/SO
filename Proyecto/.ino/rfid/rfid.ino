#include <SPI.h>
#include <MFRC522.h>

#define SS_PIN 10
#define RST_PIN 9
MFRC522 mfrc522(SS_PIN, RST_PIN);

void setup() {
  Serial.begin(115200);
  SPI.begin();
  mfrc522.PCD_Init();
  pinMode(7, OUTPUT); 
  pinMode(8, OUTPUT);
  Serial.println("LISTO");
}

void loop() {

  if (Serial.available() > 0) {
    char c = Serial.read();
    if (c == '1') {
      digitalWrite(7, HIGH);
      delay(2000);
      digitalWrite(7, LOW);
    }
    else if (c == '2') { 
      digitalWrite(8, HIGH);
      delay(2000);
      digitalWrite(8, LOW);
    }
  }

  if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
    for (byte i = 0; i < mfrc522.uid.size; i++) {
      Serial.print(mfrc522.uid.uidByte[i] < 0x10 ? "0" : "");
      Serial.print(mfrc522.uid.uidByte[i], HEX);
    }
    Serial.println(); 
    mfrc522.PICC_HaltA();
  }
}