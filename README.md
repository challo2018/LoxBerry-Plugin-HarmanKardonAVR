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

### Befehle (an das Plugin gesendet)

| Topic | Werte | Funktion |
|---|---|---|
| `harmankardon/set/power` | `ON`, `OFF`, `TOGGLE`, `SLEEP` | Einschalten (inkl. WOL), Ausschalten oder Standby |
| `harmankardon/set/volume` | `UP`, `DOWN`, `+5`, `-5`, `0-100` | Lautstärke schrittweise oder absolut anpassen |
| `harmankardon/set/mute` | `true`, `false`, `toggle` | Stummschaltung ein-/ausschalten |
| `harmankardon/set/source` | `TV`, `Game`, `Radio`, `Disc`, `AUX`, etc. | Eingangsquelle umschalten |
| `harmankardon/set/key` | `up`, `down`, `left`, `right`, `ok`, `back`, `home`, `info`, `options`, `play`, `pause` | Beliebige Fernbedienungstaste |
| `harmankardon/set/wol` | `1` | Sendet Wake-on-LAN Magic Packet |
| `harmankardon/set/zone` | `Main Zone`, `Zone 2` | Zielzone wechseln |
| `harmankardon/set/heartbeat` | `1` | Sendet sofortiges Heartbeat-Keepalive |

---

## Loxone Miniserver Integration

### 1. Status im Miniserver empfangen (Virtuelle Eingänge)

1. Im LoxBerry das **MQTT Gateway** aktivieren.
2. In der **Loxone Config** einen **Virtuellen Texteingang** anlegen:
   * Name: `AVR Power`
   * Verbindung / MQTT Topic: `harmankardon/status/power`
3. Für die Eingangsquelle einen weiteren Virtuellen Texteingang:
   * Name: `AVR Quelle`
   * MQTT Topic: `harmankardon/status/source`

### 2. Befehle an den Receiver senden (Virtuelle Ausgänge)

1. Einen **Virtuellen Ausgang** anlegen:
   * Adresse: `http://localhost:1883` (bzw. MQTT Gateway des LoxBerry)
2. **Virtuelle Ausgangsbefehle** anlegen:
   * **Power Toggle:**
     * Topic: `harmankardon/set/power`
     * Befehl bei EIN: `TOGGLE`
   * **Quelle TV (Heimkino):**
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
