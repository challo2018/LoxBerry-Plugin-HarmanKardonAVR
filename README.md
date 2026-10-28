# LoxBerry-Plugin-HarmanKardonAVR

LoxBerry Plugin zur Steuerung und Statusabfrage von **Harman Kardon AV-Receivern** (AVR & BDS Serien, z. B. AVR 151S, AVR 161S, AVR 171, AVR 370, BDS 277, BDS 577) über lokales Netzwerk (Port 10025) und Bereitstellung aller Daten per **MQTT** für den Loxone Miniserver.

Das Plugin bildet das perfekte Gegenstück zum [LoxBerry-Plugin-PanasonicTV](https://github.com/challo2018/LoxBerry-Plugin-PanasonicTV) für ein vollautomatisiertes Heimkino-Erlebnis.

---

## Funktionen

* 🔄 **Bidirektionale MQTT-Anbindung:**
  * Automatische Anbindung an das integrierte **LoxBerry MQTT Gateway**.
  * Veröffentlicht Power-Status, Lautstärkenschätzung, Stummschaltung, aktive Eingangsquelle und Online-Status.
  * Verarbeitet Steuerbefehle für Power, Lautstärke, Mute, Quellenauswahl, Tastenmakros und Zonen.
* ⚡ **Wake-on-LAN (WOL):**
  * Zuverlässiges Aufwecken des Receivers aus dem Standby über die LAN-Schnittstelle.
* 💓 **Netzwerk-Keepalive (Heartbeat):**
  * Sendet konfigurierbare `heart-alive` Keepalive-Pakete an den Receiver, um zu verhindern, dass die AVR-Firmware ihre Netzwerkschnittstelle im Leerlauf abschaltet.
* 🎛️ **Unterstützung für alle Receiver-Befehle:**
  * Power: `ON`, `OFF`, `SLEEP`, `TOGGLE`
  * Lautstärke: `UP`, `DOWN`, `+5`, `-5`, absolute Werte
  * Stummschaltung: `toggle`, `true`, `false`
  * Quellenwahl: `Cable Sat`, `Disc`, `DVR`, `Radio`, `TV`, `USB`, `Game`, `Media Server`, `Home Network`, `AUX`, `Source A`–`Source D`
  * D-Pad Navigation: `up`, `down`, `left`, `right`, `ok`, `back`, `home`, `info`, `options`
  * Mediensteuerung: `play`, `pause`, `previous`, `next`, `forward`, `reverse`
  * Mehrzonen: `Main Zone` und `Zone 2`
  * Geräte-Serien: `AVR` und `BDS`
* 🎮 **Live-Fernbedienung im LoxBerry Webfrontend:**
  * Responsive WebUI basierend auf dem modernen LoxBerry Design System (ohne jQuery Mobile).
  * Status-Badges, D-Pad, Schnellwahltasten für Quellen, Live-Test und Diagnose-Logs.
  * Automatische Erkennung von IP und MAC-Adresse im Heimnetzwerk.

---

## MQTT Topics

### Status (vom Plugin an MQTT gesendet)

| Topic | Typ / Werte | Beschreibung |
|---|---|---|
| `harmankardon/status/online` | `true` / `false` | Receiver im Netzwerk erreichbar |
| `harmankardon/status/power` | `ON` / `OFF` / `STANDBY` | Einschaltzustand |
| `harmankardon/status/volume` | `0` – `100` | Lautstärkelevel (Schätzung) |
| `harmankardon/status/mute` | `true` / `false` | Stummschaltung aktiv |
| `harmankardon/status/source` | Text (z. B. `TV`, `Game`, `Radio`) | Aktive Eingangsquelle |
| `harmankardon/status/zone` | `Main Zone` / `Zone 2` | Aktive Zone |
| `harmankardon/status/daemon` | `online` / `offline` | Status des Hintergrunddienstes (LWT) |
| `harmankardon/status/json` | JSON Object | Gesamter Zustand für Miniserver JSON-Parser |

### Befehle (an das Plugin gesendet via MQTT)

| Topic | Mögliche Werte / Payloads | Funktion / Details |
|---|---|---|
| `harmankardon/set/power` | `ON`, `OFF`, `TOGGLE`, `SLEEP` | **Power-Steuerung:** Bei `ON` wird (falls der Receiver im Standby ist) automatisch vorher ein WOL-Magic-Packet gesendet, um das LAN-Interface aufzuwecken. |
| `harmankardon/set/volume` | `UP`, `DOWN`, `+1`, `-1`, `+5`, `-5`, `0-100` | **Lautstärkeregelung:** Entweder relative Einzelschritte (`UP`, `DOWN`), Mehrfachschritte (`+5`, `-5`) oder direkte absolute Zielwerte (z. B. `35`). |
| `harmankardon/set/mute` | `toggle`, `true`, `false`, `1`, `0` | **Stummschaltung:** `toggle` wechselt den Zustand; `true` / `1` aktiviert Stumm; `false` / `0` hebt Stummschaltung auf. |
| `harmankardon/set/source` | `TV`, `Game`, `Radio`, `Disc`, `AUX`, `Cable Sat`, `DVR`, `Media Server`, etc. | **Eingangsquelle:** Schaltet den AV-Receiver auf den angegebenen Eingang um. |
| `harmankardon/set/key` | `up`, `down`, `left`, `right`, `ok`, `back`, `home`, `options`, `info`, `play`, `pause`, `previous`, `next`, `forward`, `reverse` | **Tastenbefehle:** Simuliert Tastendrücke der Fernbedienung (D-Pad Navigation, Menüs, Playback-Steuerung). |
| `harmankardon/set/wol` | `1` | **Wake-on-LAN:** Sendet ein Magic Packet an die konfigurierte MAC-Adresse (Ports 9 und 7). |
| `harmankardon/set/zone` | `Main Zone`, `Zone 2` | **Zonenauswahl:** Wechselt die Zielzone für nachfolgende Steuerbefehle. |
| `harmankardon/set/heartbeat` | `1` | **Keepalive:** Sendet sofort einen Keepalive-Ping (`heart-alive`) an den Receiver. |

---

## Loxone Miniserver Integration

### 1. Status im Miniserver empfangen (Virtuelle Eingänge)

1. Im LoxBerry das **MQTT Gateway** aktivieren.
2. In der **Loxone Config** Virtuelle Eingänge anlegen:
   * **Power-Status (Digital/Text):**
     * Name: `AVR Power`
     * Verbindung / MQTT Topic: `harmankardon/status/power`
   * **Aktive Eingangsquelle (Text):**
     * Name: `AVR Quelle`
     * Verbindung / MQTT Topic: `harmankardon/status/source`
   * **Lautstärke (Analog):**
     * Name: `AVR Lautstaerke`
     * Verbindung / MQTT Topic: `harmankardon/status/volume`
   * **Stumm / Mute (Digital):**
     * Name: `AVR Mute`
     * Verbindung / MQTT Topic: `harmankardon/status/mute`
   * **Online / Erreichbar (Digital):**
     * Name: `AVR Online`
     * Verbindung / MQTT Topic: `harmankardon/status/online`

### 2. Befehle an den Receiver senden (Virtuelle Ausgänge)

1. Im Loxone Miniserver einen **Virtuellen Ausgang** für MQTT anlegen (falls noch nicht vorhanden):
   * Adresse: `http://localhost:1883` (bzw. IP deines LoxBerry)
2. **Virtuelle Ausgangsbefehle** anlegen:
   * **Receiver Ein:**
     * Topic: `harmankardon/set/power`
     * Befehl bei EIN: `ON`
   * **Receiver Aus:**
     * Topic: `harmankardon/set/power`
     * Befehl bei EIN: `OFF`
   * **Power Toggle:**
     * Topic: `harmankardon/set/power`
     * Befehl bei EIN: `TOGGLE`
   * **Quelle TV (Heimkino):**
     * Topic: `harmankardon/set/source`
     * Befehl bei EIN: `TV`
   * **Quelle Game / Konsole:**
     * Topic: `harmankardon/set/source`
     * Befehl bei EIN: `Game`
   * **Quelle Radio:**
     * Topic: `harmankardon/set/source`
     * Befehl bei EIN: `Radio`
   * **Lauter (+5 Schritte):**
     * Topic: `harmankardon/set/volume`
     * Befehl bei EIN: `+5`
   * **Leiser (-5 Schritte):**
     * Topic: `harmankardon/set/volume`
     * Befehl bei EIN: `-5`
   * **Stumm umschalten (Toggle):**
     * Topic: `harmankardon/set/mute`
     * Befehl bei EIN: `toggle`
     * Topic: `harmankardon/set/source`
     * Befehl bei EIN: `TV`
   * **Lauter (+5):**
     * Topic: `harmankardon/set/volume`
     * Befehl bei EIN: `+5`
   * **Stumm umschalten:**
     * Topic: `harmankardon/set/mute`
     * Befehl bei EIN: `toggle`

---

## Zusammenspiel mit dem Panasonic TV Plugin

Wenn du deinen Harman Kardon AVR zusammen mit einem Panasonic Fernseher betreibst, kannst du in Loxone eine gemeinsame Heimkino-Szene erstellen:
1. `panasonictv/set/power: ON` &rarr; Fernseher einschalten
2. `harmankardon/set/power: ON` &rarr; Receiver einschalten
3. `harmankardon/set/source: TV` &rarr; Receiver auf TV-Eingang stellen
4. `panasonictv/set/audio_output: home_theater` &rarr; Fernseher automatisch auf externen Harman Kardon Ton ausgeben lassen

---

## Installation & Entwicklung

Das Plugin wird als ZIP-Archiv gepackt und über die LoxBerry Plugin-Verwaltung installiert:
```bash
zip -r LoxBerry-Plugin-HarmanKardonAVR.zip *
```
Das Installationsskript `postinstall.sh` richtet automatisch ein Python Virtual Environment ein und installiert benötigte Pakete (`paho-mqtt`).
