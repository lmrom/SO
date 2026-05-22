#include "esp_camera.h"
#include <WiFi.h>

// --- Configuración de Red ---
const char* ssid = "POCO F6 Pro";
const char* password = "a12345678";

// --- Configuración pines AI Thinker ---
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

WiFiServer server(80);

String leerLineaHTTP(WiFiClient& client, uint32_t timeoutMs) {
  String line = "";
  uint32_t start = millis();
  while ((millis() - start) < timeoutMs) {
    while (client.available()) {
      char c = client.read();
      if (c == '\r') continue;
      if (c == '\n') return line;
      line += c;
    }
    delay(1);
  }
  return line;
}

void drenarHeaders(WiFiClient& client, uint32_t timeoutMs) {
  uint32_t start = millis();
  while ((millis() - start) < timeoutMs) {
    String line = leerLineaHTTP(client, timeoutMs);
    if (line.length() == 0) return;
  }
}

void responderTexto(WiFiClient& client, const String& status, const String& body, const String& contentType) {
  client.println("HTTP/1.1 " + status);
  client.println("Content-Type: " + contentType);
  client.println("Content-Length: " + String(body.length()));
  client.println("Connection: close");
  client.println();
  client.print(body);
  client.flush();
}

void setup() {
  Serial.begin(115200);
  
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size = FRAMESIZE_VGA; 
  config.jpeg_quality = 12;
  config.fb_count = 1;

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) { return; }

  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) { delay(500); }
  
  server.begin();
  Serial.println(WiFi.localIP()); 
}

void loop() {
  WiFiClient client = server.available();
  if (client) {
    String requestLine = leerLineaHTTP(client, 1200);
    if (requestLine.length() == 0) {
      client.stop();
      return;
    }
    drenarHeaders(client, 1200);

    bool esCapture = requestLine.startsWith("GET /capture") || requestLine.startsWith("GET / ");
    if (!esCapture) {
      responderTexto(client, "404 Not Found", "{\"ok\":false,\"reason\":\"not_found\"}", "application/json");
      delay(5);
      client.stop();
      return;
    }

    camera_fb_t * fb = esp_camera_fb_get();
    if (fb) {
      esp_camera_fb_return(fb);
    }

    fb = esp_camera_fb_get(); 
    if (!fb) {
      Serial.println("Fallo al capturar la imagen fresca");
      responderTexto(client, "503 Service Unavailable", "{\"ok\":false,\"reason\":\"capture_failed\"}", "application/json");
      delay(5);
      client.stop();
      return; 
    }

    client.println("HTTP/1.1 200 OK");
    client.println("Content-Type: image/jpeg");
    client.println("Content-Length: " + String(fb->len));
    client.println("Connection: close");
    client.println();

    size_t sent = client.write(fb->buf, fb->len);
    if (sent != fb->len) {
      Serial.printf("Envio incompleto: %u/%u\n", (unsigned)sent, (unsigned)fb->len);
    }
    
    esp_camera_fb_return(fb);
    client.flush();
    delay(8);
    client.stop(); 
  }
}
