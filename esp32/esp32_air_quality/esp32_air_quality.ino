#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ctype.h>
#include <stdlib.h>
#include <string.h>
#include "secrets.h"

LiquidCrystal_I2C lcd(0x27, 16, 2);

// ================= Wi-Fi / SUPABASE =================
const bool ENABLE_WIFI = true;    // kinahanglan true aron i-upload ang readings sa Supabase


const char* API_URL = "https://hkcrlikntizjmrtonwuc.supabase.co/rest/v1/rpc/aqm_ingest_reading";
const char* THRESHOLDS_URL = "https://hkcrlikntizjmrtonwuc.supabase.co/rest/v1/aqm_threshold_settings?select=good_max,moderate_max,hazardous_max&setting_id=eq.true&limit=1";


const int ZONE_ID = 2;
const int DEVICE_ID = 6;
const unsigned long UPLOAD_INTERVAL_MS = 1000;

// ---- CONFIG ----
const int SENSOR_PIN = 34;
const int SENSOR_SAMPLE_COUNT = 16;
const int SENSOR_ID = 3;  // Registered MQ-2 sensor ID for Device ID 6
const int PIEZO_PIN = 23;  // D23 / GPIO 23

const int LED_RED = 18;
const int LED_YELLOW = 19;
const int LED_GREEN = 25;

const int DEFAULT_GOOD_MAX = 300;
const int DEFAULT_MODERATE_MAX = 350;
const int DEFAULT_HAZARDOUS_MAX = 500;
const unsigned long THRESHOLD_REFRESH_INTERVAL_MS = 60000;
const unsigned long GOOD_BEEP_INTERVAL_MS = 2000;
const unsigned long GOOD_BEEP_DURATION_MS = 100;
const int TONE_GOOD = 1000;
const int TONE_MODERATE   = 1200;
const int TONE_HAZARD     = 2500;
const int TONE_VERY_HAZARD = 3000;
// -----------------------------------------------------

enum Level { GOOD, MODERATE, HAZARDOUS, VERY_HAZARDOUS };
Level level = GOOD;

int currentHz = 0;                 // 0 = hilom
unsigned long lastSensorMs = 0;

// Gi-share sa loop() ug sa upload task
portMUX_TYPE readingMux = portMUX_INITIALIZER_UNLOCKED;
volatile int sharedRaw = 0;
volatile uint8_t sharedLevel = GOOD;
volatile bool sharedReady = false;
// Capture in the sensor loop, including while HTTPS blocks the upload task.
int sharedModerateRaw = 0;
uint32_t sharedModerateVersion = 0;
int sharedGoodMax = DEFAULT_GOOD_MAX;
int sharedModerateMax = DEFAULT_MODERATE_MAX;
int sharedHazardousMax = DEFAULT_HAZARDOUS_MAX;

// ---------- Status LEDs ----------
void setLed(bool green, bool yellow, bool red) {
  digitalWrite(LED_GREEN, green);
  digitalWrite(LED_YELLOW, yellow);
  digitalWrite(LED_RED, red);
}

void updateLed() {
  switch (level) {
    case GOOD:      setLed(1, 0, 0); break;   // green
    case MODERATE:  setLed(0, 1, 0); break;   // yellow
    case HAZARDOUS:
    case VERY_HAZARDOUS: setLed(0, 0, 1); break;   // red
  }
}

void ledSelfTest() {
  setLed(1, 0, 0); delay(300);
  setLed(0, 1, 0); delay(300);
  setLed(0, 0, 1); delay(300);
  setLed(0, 0, 0);
}

// ---------- Buzzer ----------
void setTone(int hz) {
  if (hz == currentHz) return;
  ledcWriteTone(PIEZO_PIN, hz);
  currentHz = hz;
}

// MODERATE: beep-beep (ON 150ms, OFF 150ms, ON 150ms, OFF 900ms)
void moderatePattern() {
  const unsigned long cycle = 1350;
  unsigned long t = millis() % cycle;
  if (t < 150 || (t >= 300 && t < 450)) setTone(TONE_MODERATE);
  else setTone(0);
}

void updateBuzzer() {
  static unsigned long lastGoodBeepMs = 0;
  static bool goodBeepActive = false;
  static bool wasGood = false;

  if (level == GOOD) {
    unsigned long now = millis();
    if (!wasGood) {
      lastGoodBeepMs = now - GOOD_BEEP_INTERVAL_MS;
      wasGood = true;
    }

    if (goodBeepActive && now - lastGoodBeepMs >= GOOD_BEEP_DURATION_MS) {
      setTone(0);
      goodBeepActive = false;
    }
    if (!goodBeepActive && now - lastGoodBeepMs >= GOOD_BEEP_INTERVAL_MS) {
      setTone(TONE_GOOD);
      lastGoodBeepMs = now;
      goodBeepActive = true;
    }
    return;
  }

  wasGood = false;
  goodBeepActive = false;
  switch (level) {
    case MODERATE:  moderatePattern();          break;
    case HAZARDOUS: setTone(TONE_HAZARD);       break;  // padayon
    case VERY_HAZARDOUS: setTone(TONE_VERY_HAZARD); break;
    case GOOD:      break;
  }
}

Level classifyReading(int value, int goodMax, int moderateMax, int hazardousMax) {
  if (value <= goodMax) return GOOD;
  if (value <= moderateMax) return MODERATE;
  if (value <= hazardousMax) return HAZARDOUS;
  return VERY_HAZARDOUS;
}

bool readJsonInteger(const String& json, const char* key, int& value) {
  const char* keyPosition = strstr(json.c_str(), key);
  if (keyPosition == nullptr) return false;
  const char* cursor = strchr(keyPosition, ':');
  if (cursor == nullptr) return false;
  cursor++;
  while (*cursor != '\0' && isspace(static_cast<unsigned char>(*cursor))) cursor++;
  char* end = nullptr;
  long parsed = strtol(cursor, &end, 10);
  if (end == cursor || parsed < 0 || parsed > 600) return false;
  while (*end != '\0' && isspace(static_cast<unsigned char>(*end))) end++;
  if (*end != ',' && *end != '}' && *end != ']') return false;
  value = static_cast<int>(parsed);
  return true;
}

bool fetchThresholds() {
  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  http.setTimeout(10000);
  if (!http.begin(client, THRESHOLDS_URL)) {
    Serial.println("Threshold refresh failed: unable to open HTTPS connection.");
    return false;
  }
  http.addHeader("apikey", SUPABASE_PUBLISHABLE_KEY);
  int code = http.GET();
  if (code < 200 || code >= 300) {
    Serial.printf("Threshold refresh failed (HTTP %d).\n", code);
    http.end();
    return false;
  }

  String response = http.getString();
  http.end();
  int goodMax;
  int moderateMax;
  int hazardousMax;
  if (!readJsonInteger(response, "\"good_max\"", goodMax)
      || !readJsonInteger(response, "\"moderate_max\"", moderateMax)
      || !readJsonInteger(response, "\"hazardous_max\"", hazardousMax)
      || goodMax >= moderateMax || moderateMax >= hazardousMax) {
    Serial.println("Threshold refresh failed: Supabase returned invalid limits.");
    return false;
  }

  portENTER_CRITICAL(&readingMux);
  sharedGoodMax = goodMax;
  sharedModerateMax = moderateMax;
  sharedHazardousMax = hazardousMax;
  portEXIT_CRITICAL(&readingMux);
  Serial.printf("Thresholds updated: Good through %d, Moderate through %d, Hazardous through %d.\n", goodMax, moderateMax, hazardousMax);
  return true;
}

int readSensorRaw() {
  uint32_t total = 0;
  int lowest = 4095;
  int highest = 0;

  for (int sample = 0; sample < SENSOR_SAMPLE_COUNT; sample++) {
    int value = analogRead(SENSOR_PIN);
    total += value;
    if (value < lowest) lowest = value;
    if (value > highest) highest = value;
    delayMicroseconds(200);
  }

  return (total - lowest - highest) / (SENSOR_SAMPLE_COUNT - 2);
}

const char* levelName(Level value) {
  switch (value) {
    case MODERATE: return "MODERATE";
    case HAZARDOUS: return "HAZARDOUS";
    case VERY_HAZARDOUS: return "VERY HAZ";
    default: return "GOOD";
  }
}

void showReading(int value, Level sensorLevel) {
  char line[17];
  lcd.setCursor(0, 0);
  lcd.print("                ");
  lcd.setCursor(0, 0);
  snprintf(line, sizeof(line), "MQ-2 AIR QUALITY");
  lcd.print(line);

  const char* shortStatus;
  switch (sensorLevel) {
    case MODERATE: shortStatus = "MOD"; break;
    case HAZARDOUS: shortStatus = "HAZ"; break;
    case VERY_HAZARDOUS: shortStatus = "V.HAZ"; break;
    default: shortStatus = "GOOD"; break;
  }
  lcd.setCursor(0, 1);
  lcd.print("                ");
  lcd.setCursor(0, 1);
  snprintf(line, sizeof(line), "AQ: %d %s", value, shortStatus);
  lcd.print(line);
}

// ---------- Upload ----------
const char* uploadStatusName(uint8_t lvl) {
  switch (lvl) {
    case MODERATE:  return "Moderate";
    case HAZARDOUS: return "Hazardous";
    case VERY_HAZARDOUS: return "Very Hazardous";
    default:        return "Good";
  }
}

bool sendReading(int raw, const char* status) {
  if (strcmp(DEVICE_TOKEN, "PASTE_YOUR_DEVICE_TOKEN") == 0) {
    Serial.println("Upload failed: set DEVICE_TOKEN to the active token for this device.");
    return false;
  }
  if (SENSOR_ID <= 0) {
    Serial.println("Upload failed: set SENSOR_ID to the registered sensor ID.");
    return false;
  }

  char body[350];
  int bodyLength = snprintf(body, sizeof(body),
      "{\"p_zone_id\":%d,\"p_device_id\":%d,\"p_sensor_id\":%d,\"p_mq135_value\":%d,\"p_air_quality_status\":\"%s\"}",
      ZONE_ID, DEVICE_ID, SENSOR_ID, raw, status);
  if (bodyLength < 0 || static_cast<size_t>(bodyLength) >= sizeof(body)) {
    Serial.println("Upload failed: reading payload could not be formatted.");
    return false;
  }

  WiFiClientSecure client;
  client.setInsecure();   // walay certificate check; ilisi ug pinned CA sa final nga deployment
  HTTPClient http;
  http.setTimeout(10000);
  if (!http.begin(client, API_URL)) {
    Serial.println("Upload: unable to open HTTPS connection");
    return false;
  }
  http.addHeader("Content-Type", "application/json");
  http.addHeader("apikey", SUPABASE_PUBLISHABLE_KEY);
  http.addHeader("x-device-token", DEVICE_TOKEN);

  int code = http.POST((uint8_t*)body, strlen(body));
  Serial.printf("Upload response: %d\n", code);
  if (code >= 200 && code < 300) {
    Serial.println("Reading saved to Supabase.");
    http.end();
    return true;
  }
  if (code > 0) Serial.println(http.getString());
  else Serial.printf("Upload transport error: %s\n", http.errorToString(code).c_str());
  http.end();
  return false;
}

// Naa sa lahi nga task para dili mag-freeze ang buzzer ug LCD
void uploadTask(void*) {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  unsigned long lastConnectTry = millis();
  unsigned long lastUpload = 0;
  unsigned long lastAttempt = 0;
  bool hasUploaded = false;
  uint32_t uploadedModerateVersion = 0;
  bool pendingHazardReading = false;
  int pendingHazardRaw = 0;
  uint8_t pendingHazardLevel = GOOD;
  uint8_t lastObservedLevel = GOOD;
  bool wasConnected = false;
  bool attemptedThresholdRefresh = false;
  unsigned long lastThresholdRefresh = 0;

  for (;;) {
    unsigned long now = millis();
    bool connected = WiFi.status() == WL_CONNECTED;
    if (connected && !wasConnected) {
      Serial.print("WiFi connected. IP: ");
      Serial.println(WiFi.localIP());
    } else if (!connected && wasConnected) {
      Serial.println("WiFi connection lost.");
    }
    wasConnected = connected;

    // Preserve a brief hazardous spike until it can be uploaded, even if the
    // sensor returns to a lower level before Wi-Fi is available.
    bool ready;
    int uploadRaw;
    uint8_t uploadLevel;
    int moderateRaw;
    uint32_t moderateVersion;
    portENTER_CRITICAL(&readingMux);
    ready = sharedReady;
    uploadRaw = sharedRaw;
    uploadLevel = sharedLevel;
    moderateRaw = sharedModerateRaw;
    moderateVersion = sharedModerateVersion;
    portEXIT_CRITICAL(&readingMux);

    if (ready) {
      if (uploadLevel >= HAZARDOUS && lastObservedLevel < HAZARDOUS) {
        pendingHazardReading = true;
        pendingHazardRaw = uploadRaw;
        pendingHazardLevel = uploadLevel;
      }
      lastObservedLevel = uploadLevel;
    }

    if (!connected) {
      if (now - lastConnectTry >= 15000) {
        Serial.printf("WiFi reconnecting (status %d).\n", WiFi.status());
        WiFi.disconnect();
        WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
        lastConnectTry = now;
      }
    } else {
      if (!attemptedThresholdRefresh || now - lastThresholdRefresh >= THRESHOLD_REFRESH_INTERVAL_MS) {
        fetchThresholds();
        attemptedThresholdRefresh = true;
        lastThresholdRefresh = now;
      }
      bool regularUploadDue = ready
          && ((!hasUploaded && (lastAttempt == 0 || now - lastAttempt >= 5000))
              || (hasUploaded && now - lastUpload >= UPLOAD_INTERVAL_MS));
      bool hazardRetryDue = pendingHazardReading
          && (lastAttempt == 0 || now - lastAttempt >= 5000);
      bool pendingModerate = moderateVersion != uploadedModerateVersion;
      bool moderateUploadDue = pendingModerate
          && (lastAttempt == 0 || now - lastAttempt >= (hasUploaded ? UPLOAD_INTERVAL_MS : 5000));

      if (regularUploadDue || hazardRetryDue || moderateUploadDue) {
        lastAttempt = now;
        bool uploadingModerate = !pendingHazardReading && pendingModerate;
        int valueToUpload = pendingHazardReading ? pendingHazardRaw : (uploadingModerate ? moderateRaw : uploadRaw);
        uint8_t levelToUpload = pendingHazardReading ? pendingHazardLevel : (uploadingModerate ? MODERATE : uploadLevel);
        if (sendReading(valueToUpload, uploadStatusName(levelToUpload))) {
          lastUpload = now;
          hasUploaded = true;
          if (pendingHazardReading) pendingHazardReading = false;
          if (uploadingModerate) uploadedModerateVersion = moderateVersion;
        } else {
          hasUploaded = false;
        }
      }
    }
    vTaskDelay(pdMS_TO_TICKS(500));
  }
}

// ---------- Setup / loop ----------
void setup() {
  Serial.begin(115200);
  Serial.println("Boot");
  pinMode(SENSOR_PIN, INPUT);
  analogReadResolution(12);
  analogSetPinAttenuation(SENSOR_PIN, ADC_11db);

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_YELLOW, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);
  ledSelfTest();                       // green -> yellow -> red

  ledcAttach(PIEZO_PIN, 2000, 8);
  ledcWriteTone(PIEZO_PIN, 0);

  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0);
  lcd.print("Air Quality Mon");
  lcd.setCursor(0, 1);
  lcd.print("Initializing...");
  delay(2000);
  lcd.clear();
  Serial.println("Setup done");

  if (ENABLE_WIFI) {
    Serial.printf("Supabase upload enabled for zone %d, device %d.\n", ZONE_ID, DEVICE_ID);
    Serial.printf("Sensor ID: %d. MQ-2 input: D34.\n", SENSOR_ID);
    // HTTPS nagkinahanglan og dako nga stack, 12288 aron dili mo-crash
    xTaskCreatePinnedToCore(uploadTask, "upload", 12288, NULL, 1, NULL, 0);
  } else {
    Serial.println("Supabase upload disabled. Set ENABLE_WIFI to true.");
  }
}

void loop() {
  if (millis() - lastSensorMs >= 1000) {
    lastSensorMs = millis();

    int sensorValue = map(readSensorRaw(), 0, 4095, 0, 600);
    int goodMax;
    int moderateMax;
    int hazardousMax;

    portENTER_CRITICAL(&readingMux);
    goodMax = sharedGoodMax;
    moderateMax = sharedModerateMax;
    hazardousMax = sharedHazardousMax;
    portEXIT_CRITICAL(&readingMux);
    level = classifyReading(sensorValue, goodMax, moderateMax, hazardousMax);

    portENTER_CRITICAL(&readingMux);
    if (level == MODERATE && (!sharedReady || sharedLevel != MODERATE)) {
      sharedModerateRaw = sensorValue;
      sharedModerateVersion++;
    }
    sharedRaw = sensorValue;
    sharedLevel = level;
    sharedReady = true;
    portEXIT_CRITICAL(&readingMux);

    Serial.printf("Sensor: %d | Status: %s | WiFi: %s\n",
                  sensorValue, levelName(level),
                  !ENABLE_WIFI ? "off" : (WiFi.status() == WL_CONNECTED ? "ok" : "no"));

    showReading(sensorValue, level);

    updateLed();
  }

  updateBuzzer();
}
