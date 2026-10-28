<?php
/**
 * LoxBerry Harman Kardon AVR Plugin - Main WebUI
 * Uses LoxBerry Design System (lb-* classes, no jQuery Mobile), loxberry_XL and PHP SDK.
 */

require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "loxberry_log.php";

// Localization
$L = LBSystem::readlanguage("language.ini");

function __t($key, $fallback = '') {
    global $L;
    if (!empty($L[$key])) {
        return htmlspecialchars($L[$key]);
    }
    $parts = explode('.', $key, 2);
    if (count($parts) === 2 && !empty($L[$parts[0]][$parts[1]])) {
        return htmlspecialchars($L[$parts[0]][$parts[1]]);
    }
    return htmlspecialchars($fallback ?: $key);
}

// Config file path
$cfg_file = "$lbpconfigdir/harmankardon.json";
foreach (['harmankardon.json', 'harmankardonavr.json'] as $f) {
    if (!empty($lbpconfigdir) && file_exists("$lbpconfigdir/$f")) {
        $cfg_file = "$lbpconfigdir/$f";
        break;
    }
    $local = dirname(__DIR__, 2) . "/config/$f";
    if (file_exists($local)) {
        $cfg_file = $local;
        break;
    }
}
$config = file_exists($cfg_file) ? json_decode(file_get_contents($cfg_file), true) : [];

// Read form parameter
$form = isset($_REQUEST['form']) ? $_REQUEST['form'] : 'main';

// Safe default values
$rcv_host = $config['receiver']['host'] ?? '';
$rcv_port = $config['receiver']['port'] ?? 10025;
$rcv_mac = $config['receiver']['mac'] ?? '';
$rcv_type = $config['receiver']['device_type'] ?? 'AVR';
$rcv_zone = $config['receiver']['zone'] ?? 'Main Zone';
$rcv_model = $config['receiver']['model'] ?? 'AVR 151S';
$rcv_keepalive = $config['receiver']['keep_alive_enabled'] ?? true;
$rcv_keepalive_interval = $config['receiver']['keep_alive_interval'] ?? 60;

$sources = $config['sources'] ?? ["Cable Sat", "Disc", "DVR", "Radio", "TV", "USB", "Game", "Media Server", "Home Network", "AUX"];

$mqtt_use_gateway = $config['mqtt']['use_loxberry_gateway'] ?? true;
$mqtt_topic_prefix = $config['mqtt']['topic_prefix'] ?? 'harmankardon';
$poll_active = $config['polling']['interval_active'] ?? 5;
$poll_standby = $config['polling']['interval_standby'] ?? 30;
$log_level = $config['system']['loglevel'] ?? 6;

// Query central LoxBerry system loglevel
if (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
    $sys_lvl = LBSystem::pluginloglevel('harmankardon');
    if ($sys_lvl === false || $sys_lvl === null) {
        $sys_lvl = LBSystem::pluginloglevel('harmankardonavr');
    }
    if ($sys_lvl !== false && $sys_lvl !== null && $sys_lvl >= 0) {
        $log_level = intval($sys_lvl);
    }
}

// Read LoxBerry MQTT Gateway info if function exists
$lb_mqtt_info = __t('MQTT.LABEL_USE_LB_GATEWAY', 'Integriertes Gateway');
if (function_exists('mqtt_connectiondetails')) {
    $creds = mqtt_connectiondetails();
    if (!empty($creds['brokerhost'])) {
        $lb_mqtt_info = "LoxBerry Broker: " . htmlspecialchars($creds['brokerhost']) . ":" . intval($creds['brokerport']);
    }
}

// Navigation Bar
$navbar[1]['Name']   = __t('RECEIVER.SECTION_TITLE', 'Einstellungen & Steuerung');
$navbar[1]['URL']    = 'index.php?form=main';
$navbar[1]['active'] = ($form === 'main');

$navbar[2]['Name']   = __t('MQTT_HELP.SECTION_TITLE', 'MQTT Befehle (SET) & Loxone');
$navbar[2]['URL']    = 'index.php?form=mqtt';
$navbar[2]['active'] = ($form === 'mqtt');

$navbar[3]['Name']   = __t('LOGGING.SECTION_TITLE', 'Logdatei & Diagnose');
$navbar[3]['URL']    = 'index.php?form=logs';
$navbar[3]['active'] = ($form === 'logs');

// Render LoxBerry Header
$template_title = __t('COMMON.PLUGIN_TITLE', 'Harman Kardon AVR');
$helplink = "https://wiki.loxberry.de/";
$helptemplate = "help.html";

LBWeb::lbheader($template_title, $helplink, $helptemplate, true);

if ($form === 'mqtt') {
    ?>
    <div class="lb-content">
        <style>
        .card-box {
            background: #ffffff;
            border: 1px solid #dcdde1;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .card-header-bar {
            border-bottom: 2px solid #f1f2f6;
            padding-bottom: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-header-bar h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #2f3640;
        }
        .mqtt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
            margin-bottom: 15px;
        }
        .mqtt-table th, .mqtt-table td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #edf0f2;
        }
        .mqtt-table th {
            background: #f8f9fa;
            color: #4b6584;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        .mqtt-table tr:hover {
            background: #fcfcfc;
        }
        .code-topic {
            font-family: monospace, Consolas, Courier;
            font-weight: 600;
            color: #0984e3;
            background: #ebf5fb;
            padding: 3px 7px;
            border-radius: 4px;
            font-size: 0.88rem;
        }
        .code-payload {
            font-family: monospace, Consolas, Courier;
            background: #f1f2f6;
            color: #2f3542;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .btn-test {
            background: #007aff;
            color: #ffffff;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-test:hover {
            background: #0056b3;
        }
        .info-callout {
            background: #e8f4fd;
            border-left: 4px solid #007aff;
            padding: 14px 18px;
            border-radius: 0 6px 6px 0;
            margin-bottom: 20px;
            font-size: 0.92rem;
            color: #1e3799;
        }
        .toast-msg {
            display: none;
            position: fixed;
            bottom: 25px;
            right: 25px;
            background: #2ed573;
            color: #fff;
            padding: 14px 22px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            font-weight: 600;
        }
        </style>

        <div id="toastMessage" class="toast-msg"></div>

        <div class="info-callout">
            <strong>MQTT Präfix:</strong> Aktuell ist für deinen Receiver das Präfix <span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?></span> konfiguriert.
            Alle nachfolgenden Topics sind mit diesem Präfix einsatzbereit für die Loxone Config.
        </div>

        <!-- SET Befehle -->
        <div class="card-box">
            <div class="card-header-bar">
                <h2>📥 MQTT SET-Befehle (Befehle an den Receiver senden)</h2>
            </div>
            <p style="font-size: 0.9rem; color: #576574; margin-top: 0;">
                Sende diese Befehle über einen <strong>Virtuellen Ausgangsbefehl</strong> im Loxone Miniserver an das LoxBerry MQTT Gateway:
            </p>

            <table class="mqtt-table">
                <thead>
                    <tr>
                        <th style="width: 28%;"><?php echo __t('MQTT_HELP.COL_TOPIC', 'MQTT Topic'); ?></th>
                        <th style="width: 25%;"><?php echo __t('MQTT_HELP.COL_VALUES', 'Werte / Payloads'); ?></th>
                        <th><?php echo __t('MQTT_HELP.COL_DESC', 'Funktion / Beschreibung'); ?></th>
                        <th style="width: 80px; text-align: center;"><?php echo __t('MQTT_HELP.COL_ACTION', 'Test'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/power</span></td>
                        <td>
                            <span class="code-payload">ON</span>, <span class="code-payload">OFF</span>, <span class="code-payload">TOGGLE</span>, <span class="code-payload">SLEEP</span>
                        </td>
                        <td>
                            Schaltet den Receiver ein (sendet bei Bedarf automatisch ein WOL-Paket), aus oder versetzt ihn in Standby/Sleep.
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testMqtt('power', 'toggle')">Toggle</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/volume</span></td>
                        <td>
                            <span class="code-payload">UP</span>, <span class="code-payload">DOWN</span>, <span class="code-payload">+5</span>, <span class="code-payload">-5</span>, <span class="code-payload">0-100</span>
                        </td>
                        <td>
                            Lautstärkeregelung. Unterstützt relative Schritte (<span class="code-payload">UP</span>, <span class="code-payload">+1</span>, <span class="code-payload">+5</span>, <span class="code-payload">-5</span>) oder absolute Zielwerte (z.B. <span class="code-payload">35</span>).
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testMqtt('volume', 'up')">Vol +</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/mute</span></td>
                        <td>
                            <span class="code-payload">toggle</span>, <span class="code-payload">true</span>, <span class="code-payload">false</span>, <span class="code-payload">1</span>, <span class="code-payload">0</span>
                        </td>
                        <td>
                            Stummschaltung umschalten (<span class="code-payload">toggle</span>) oder gezielt aktivieren bzw. deaktivieren.
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testMqtt('mute', 'toggle')">Mute</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/source</span></td>
                        <td>
                            <span class="code-payload">TV</span>, <span class="code-payload">Game</span>, <span class="code-payload">Radio</span>, <span class="code-payload">Disc</span>, <span class="code-payload">AUX</span>, <span class="code-payload">Cable Sat</span>, <span class="code-payload">Media Server</span>
                        </td>
                        <td>
                            Schaltet die aktive Eingangsquelle des Receivers um. Entspricht der Quellenanzeige auf dem Receiver.
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testMqtt('source', 'TV')">TV</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/key</span></td>
                        <td>
                            <span class="code-payload">up</span>, <span class="code-payload">down</span>, <span class="code-payload">left</span>, <span class="code-payload">right</span>, <span class="code-payload">ok</span>, <span class="code-payload">back</span>, <span class="code-payload">home</span>, <span class="code-payload">options</span>, <span class="code-payload">info</span>, <span class="code-payload">play</span>, <span class="code-payload">pause</span>, <span class="code-payload">previous</span>, <span class="code-payload">next</span>
                        </td>
                        <td>
                            Sendet einen Tastenbefehl der Fernbedienung (z.B. D-Pad Steuerkreuz, Menütasten oder Audiowiedergabe).
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testMqtt('key', 'info')">Info</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/wol</span></td>
                        <td>
                            <span class="code-payload">1</span>
                        </td>
                        <td>
                            Sendet direkt ein Wake-on-LAN Magic Packet an die konfigurierte MAC-Adresse des Receivers.
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testWol()">WOL</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/zone</span></td>
                        <td>
                            <span class="code-payload">Main Zone</span>, <span class="code-payload">Zone 2</span>
                        </td>
                        <td>
                            Wechselt die aktive Zielzone für anschließende Steuerbefehle.
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testMqtt('zone', 'Main Zone')">Zone</button>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/heartbeat</span></td>
                        <td>
                            <span class="code-payload">1</span>
                        </td>
                        <td>
                            Sendet manuell einen Keepalive-Heartbeat (<span class="code-payload">heart-alive</span>) an den Receiver.
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn-test" onclick="testHeartbeat()">Ping</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- STATUS Rückmeldungen -->
        <div class="card-box">
            <div class="card-header-bar">
                <h2>📤 MQTT STATUS-Topics (Rückmeldungen vom Receiver)</h2>
            </div>
            <p style="font-size: 0.9rem; color: #576574; margin-top: 0;">
                Diese Topics werden vom Plugin automatisch veröffentlicht und können in Loxone über <strong>Virtuelle Eingänge</strong> erfasst werden:
            </p>

            <table class="mqtt-table">
                <thead>
                    <tr>
                        <th style="width: 28%;"><?php echo __t('MQTT_HELP.COL_TOPIC', 'MQTT Topic'); ?></th>
                        <th style="width: 25%;">Datentyp / Werte</th>
                        <th>Bedeutung / Verwendung im Miniserver</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/online</span></td>
                        <td><span class="code-payload">true</span> / <span class="code-payload">false</span></td>
                        <td>Gibt an, ob der Receiver im Netzwerk erreichbar ist (digitaler Virtueller Eingang).</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/power</span></td>
                        <td><span class="code-payload">ON</span> / <span class="code-payload">OFF</span> / <span class="code-payload">STANDBY</span></td>
                        <td>Einschaltzustand des Receivers (virtueller Texteingang).</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/volume</span></td>
                        <td><span class="code-payload">0</span> – <span class="code-payload">100</span></td>
                        <td>Aktueller geschätzter Lautstärkepegel (analoger Virtueller Eingang).</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/mute</span></td>
                        <td><span class="code-payload">true</span> / <span class="code-payload">false</span></td>
                        <td>Stummschaltungszustand aktiv oder inaktiv (digitaler Virtueller Eingang).</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/source</span></td>
                        <td>Text (z.B. <span class="code-payload">TV</span>, <span class="code-payload">Game</span>)</td>
                        <td>Name der aktuell gewählten Eingangsquelle (virtueller Texteingang).</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/zone</span></td>
                        <td><span class="code-payload">Main Zone</span> / <span class="code-payload">Zone 2</span></td>
                        <td>Aktuell ausgewählte Zone.</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/daemon</span></td>
                        <td><span class="code-payload">online</span> / <span class="code-payload">offline</span></td>
                        <td>Status des Hintergrunddienstes auf dem LoxBerry (MQTT Last Will).</td>
                    </tr>
                    <tr>
                        <td><span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/json</span></td>
                        <td>JSON Object</td>
                        <td>Gesamter Gerätestatus als komprimiertes JSON für den Loxone JSON-Parser.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Loxone Config Schnell-Anleitung -->
        <div class="card-box">
            <div class="card-header-bar">
                <h2>🏠 Schnell-Anleitung: Loxone Config Einbindung</h2>
            </div>
            <div style="font-size: 0.95rem; line-height: 1.6; color: #2f3542;">
                <ol style="padding-left: 20px;">
                    <li><strong>Virtueller Ausgang für Steuerbefehle:</strong>
                        <ul style="margin-top: 4px; margin-bottom: 8px;">
                            <li>Adresse: <span class="code-payload">/dev/tcp/127.0.0.1/1883</span> (über MQTT Gateway) oder direkt über HTTP/MQTT Ausgang.</li>
                        </ul>
                    </li>
                    <li><strong>Virtueller Ausgangsbefehl (z.B. für Heimkino Ein / Aus):</strong>
                        <ul style="margin-top: 4px; margin-bottom: 8px;">
                            <li>Befehl bei EIN: Topic: <span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/power</span> mit Wert <span class="code-payload">ON</span></li>
                            <li>Befehl bei AUS: Topic: <span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/power</span> mit Wert <span class="code-payload">OFF</span></li>
                        </ul>
                    </li>
                    <li><strong>Virtueller Eingang (Status Power):</strong>
                        <ul style="margin-top: 4px; margin-bottom: 8px;">
                            <li>In LoxBerry MQTT Gateway das Topic <span class="code-topic"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/power</span> an den Miniserver weiterleiten lassen.</li>
                            <li>In Loxone Config einen Virtuellen Texteingang mit dem Namen <span class="code-payload"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>_status_power</span> anlegen.</li>
                        </ul>
                    </li>
                </ol>
            </div>
        </div>
    </div>

    <script>
    function showToast(msg, isError = false) {
        const toast = document.getElementById('toastMessage');
        toast.innerText = msg;
        toast.style.background = isError ? '#eb4d4b' : '#2ed573';
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 4000);
    }

    function testMqtt(type, val) {
        fetch('ajax.php?action=' + encodeURIComponent(type) + '&cmd=' + encodeURIComponent(val) + '&source=' + encodeURIComponent(val) + '&key=' + encodeURIComponent(val))
            .then(r => r.json())
            .then(res => {
                showToast('Testbefehl gesendet: ' + type + ' -> ' + val);
            })
            .catch(err => {
                showToast('Fehler beim Senden: ' + err, true);
            });
    }

    function testWol() {
        fetch('ajax.php?action=wol')
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showToast('⚡ Wake-on-LAN Magic Packet erfolgreich gesendet!');
                } else {
                    showToast('WOL fehlgeschlagen: ' + (res.error || 'Fehler'), true);
                }
            });
    }

    function testHeartbeat() {
        fetch('ajax.php?action=heartbeat')
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showToast('💓 Heartbeat Ping erfolgreich gesendet!');
                } else {
                    showToast('Heartbeat fehlgeschlagen', true);
                }
            });
    }
    </script>
    <?php
    LBWeb::lbfooter();
    exit;
}

if ($form === 'logs') {
    ?>
    <div class="lb-content">
        <style>
        .card-box {
            background: #ffffff;
            border: 1px solid #dcdde1;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .card-header-bar {
            border-bottom: 2px solid #f1f2f6;
            padding-bottom: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-header-bar h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #2f3640;
        }
        .log-box {
            background: #1e272e;
            color: #d2dae2;
            font-family: monospace, Consolas, Courier;
            font-size: 0.85rem;
            line-height: 1.45;
            padding: 15px;
            border-radius: 6px;
            height: 520px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .btn-action {
            background: #007aff;
            color: #fff;
            border: none;
            padding: 7px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
        }
        .btn-action:hover {
            opacity: 0.9;
        }
        .btn-danger {
            background: #eb4d4b;
            color: #fff;
            border: none;
            padding: 7px 14px;
            border-radius: 5px;
            cursor: pointer;
        }
        </style>

        <div class="card-box">
            <div class="card-header-bar">
                <h2><?php echo __t('LOGGING.SECTION_TITLE', 'Logdatei & Diagnose'); ?></h2>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <label style="font-size:0.9rem; cursor:pointer;">
                        <input type="checkbox" id="autoScroll" checked> <?php echo __t('LOGGING.LABEL_AUTO_SCROLL', 'Auto-Scroll'); ?>
                    </label>
                    <button type="button" class="btn-action" onclick="fetchLogs()"><?php echo __t('LOGGING.BTN_REFRESH', 'Aktualisieren'); ?></button>
                    <a href="/admin/system/tools/logfile.cgi?package=harmankardon" target="_blank" class="btn-action" style="text-decoration:none; display:inline-flex; align-items:center; gap:5px;">📂 LoxBerry Log Manager</a>
                    <button type="button" class="btn-danger" onclick="clearLogs()"><?php echo __t('LOGGING.BTN_CLEAR_LOG', 'Log leeren'); ?></button>
                </div>
            </div>
            <div id="logViewer" class="log-box">Lade Logdatei...</div>
        </div>
    </div>

    <script>
    let logTimer = null;
    function fetchLogs() {
        fetch('ajax.php?action=get_log&lines=150')
            .then(r => r.json())
            .then(data => {
                const viewer = document.getElementById('logViewer');
                if (data.lines) {
                    viewer.innerText = data.lines.join("\n");
                    if (document.getElementById('autoScroll').checked) {
                        viewer.scrollTop = viewer.scrollHeight;
                    }
                }
            })
            .catch(err => {
                document.getElementById('logViewer').innerText = 'Fehler beim Laden des Logs: ' + err;
            });
    }

    function clearLogs() {
        if (!confirm('Logdatei wirklich leeren?')) return;
        fetch('ajax.php?action=clear_log')
            .then(r => r.json())
            .then(() => fetchLogs());
    }

    document.addEventListener('DOMContentLoaded', () => {
        fetchLogs();
        logTimer = setInterval(fetchLogs, 4000);
    });
    </script>
    <?php
    LBWeb::lbfooter();
    exit;
}

// Main View
?>
<div class="lb-content">
    <style>
    .hk-grid-layout {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }
    .card-box {
        background: #ffffff;
        border: 1px solid #dcdde1;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 22px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .card-header-bar {
        border-bottom: 2px solid #f1f2f6;
        padding-bottom: 12px;
        margin-bottom: 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .card-header-bar h2 {
        margin: 0;
        font-size: 1.25rem;
        color: #2f3640;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .badge-online { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .badge-offline { background: #e2e6ea; color: #383d41; border: 1px solid #d6d8db; }
    .badge-stopped { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .badge-active { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }

    .remote-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        max-width: 380px;
        margin: 0 auto;
    }
    .remote-btn {
        background: #f1f2f6;
        border: 1px solid #ced6e0;
        border-radius: 6px;
        padding: 12px;
        font-size: 0.95rem;
        font-weight: 600;
        color: #2f3542;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
        text-align: center;
    }
    .remote-btn:hover {
        background: #e4e7eb;
        transform: translateY(-1px);
    }
    .remote-btn:active {
        transform: translateY(1px);
        background: #ced6e0;
    }
    .btn-power-on { background: #2ed573; color: #fff; border-color: #26af5f; }
    .btn-power-on:hover { background: #26af5f; }
    .btn-power-off { background: #eb4d4b; color: #fff; border-color: #d63031; }
    .btn-power-off:hover { background: #d63031; }
    .btn-action-blue { background: #007aff; color: #fff; border-color: #0056b3; }
    .btn-action-blue:hover { background: #0056b3; }
    .span-3 { grid-column: span 3; }
    .span-2 { grid-column: span 2; }

    .source-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }
    .source-btn {
        background: #f8f9fa;
        border: 1px solid #ced4da;
        border-radius: 5px;
        padding: 7px 14px;
        font-size: 0.9rem;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.15s;
    }
    .source-btn:hover {
        background: #007aff;
        color: #fff;
        border-color: #007aff;
    }
    .source-btn.active {
        background: #007aff;
        color: #fff;
        border-color: #007aff;
        font-weight: 700;
    }

    .form-row {
        margin-bottom: 16px;
    }
    .form-row label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: #333;
    }
    .form-row input[type="text"],
    .form-row input[type="number"],
    .form-row select {
        width: 100%;
        box-sizing: border-box;
        padding: 9px 12px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 0.95rem;
    }
    .form-hint {
        font-size: 0.8rem;
        color: #666;
        margin-top: 4px;
    }
    .code-badge {
        font-family: monospace;
        background: #f1f2f6;
        padding: 2px 6px;
        border-radius: 4px;
        border: 1px solid #dfe4ea;
    }
    .toast-msg {
        display: none;
        position: fixed;
        bottom: 25px;
        right: 25px;
        background: #2ed573;
        color: #fff;
        padding: 14px 22px;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        font-weight: 600;
    }
    </style>

    <div id="toastMessage" class="toast-msg"></div>

    <div class="hk-grid-layout">
        <!-- Live Status Card -->
        <div class="card-box">
            <div class="card-header-bar">
                <h2><?php echo __t('COMMON.LABEL_STATUS', 'Status'); ?></h2>
                <button type="button" class="remote-btn" style="padding:6px 12px; font-size:0.85rem;" onclick="checkStatus()">
                    🔄 <?php echo __t('COMMON.LABEL_TEST', 'Aktualisieren'); ?>
                </button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; font-size: 0.95rem;">
                <div>
                    <strong>Receiver Status:</strong><br>
                    <span id="badgeOnline" class="status-badge badge-offline">Offline</span>
                </div>
                <div>
                    <strong><?php echo __t('COMMON.LABEL_DAEMON', 'Hintergrunddienst'); ?>:</strong><br>
                    <span id="badgeDaemon" class="status-badge badge-stopped">Prüfe...</span>
                </div>
                <div>
                    <strong>Power:</strong> <span id="valPower" class="code-badge">-</span>
                </div>
                <div>
                    <strong><?php echo __t('SOURCES.LABEL_CURRENT_SOURCE', 'Quelle'); ?>:</strong> <span id="valSource" class="code-badge">-</span>
                </div>
                <div>
                    <strong>Lautstärke (Schätzung):</strong> <span id="valVolume" class="code-badge">-</span>
                </div>
                <div>
                    <strong>Stummschaltung:</strong> <span id="valMute" class="code-badge">-</span>
                </div>
                <div class="span-2">
                    <strong>Zone:</strong> <span id="valZone" class="code-badge"><?php echo htmlspecialchars($rcv_zone); ?></span>
                </div>
                <div class="span-2">
                    <strong>MQTT Topic:</strong> <span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/*</span>
                </div>
            </div>
            <div style="margin-top: 18px; display: flex; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="remote-btn" onclick="restartDaemon()"><?php echo __t('COMMON.LABEL_RESTART_DAEMON', 'Dienst neu starten'); ?></button>
                <button type="button" class="remote-btn btn-action-blue" onclick="sendWol()"><?php echo __t('COMMON.LABEL_WOL', '⚡ Wake-on-LAN'); ?></button>
                <button type="button" class="remote-btn" onclick="sendHeartbeat()"><?php echo __t('COMMON.LABEL_HEARTBEAT', '💓 Heartbeat Ping'); ?></button>
                <a href="index.php?form=mqtt" class="remote-btn" style="text-decoration:none; display:inline-block;">📖 MQTT SET-Befehle</a>
            </div>
        </div>

        <!-- Live Remote Control Card -->
        <div class="card-box">
            <div class="card-header-bar">
                <h2><?php echo __t('REMOTE.SECTION_TITLE', 'Fernbedienung Live-Test'); ?></h2>
            </div>
            
            <div class="remote-grid">
                <!-- Power row -->
                <button type="button" class="remote-btn btn-power-on" onclick="sendPower('on')"><?php echo __t('REMOTE.BTN_POWER_ON', 'Power EIN'); ?></button>
                <button type="button" class="remote-btn" onclick="sendPower('sleep')"><?php echo __t('REMOTE.BTN_SLEEP', 'Sleep'); ?></button>
                <button type="button" class="remote-btn btn-power-off" onclick="sendPower('off')"><?php echo __t('REMOTE.BTN_POWER_OFF', 'Power AUS'); ?></button>

                <!-- Volume row -->
                <button type="button" class="remote-btn" onclick="sendVolume('up')"><?php echo __t('REMOTE.BTN_VOL_UP', 'Lauter +'); ?></button>
                <button type="button" class="remote-btn" onclick="sendMute('toggle')"><?php echo __t('REMOTE.BTN_MUTE', 'MUTE'); ?></button>
                <button type="button" class="remote-btn" onclick="sendVolume('down')"><?php echo __t('REMOTE.BTN_VOL_DOWN', 'Leiser -'); ?></button>

                <!-- D-Pad -->
                <div></div>
                <button type="button" class="remote-btn" onclick="sendKey('up')">▲</button>
                <div></div>

                <button type="button" class="remote-btn" onclick="sendKey('left')">◀</button>
                <button type="button" class="remote-btn" style="background:#e8f0fe; color:#0056b3;" onclick="sendKey('ok')"><?php echo __t('REMOTE.BTN_OK', 'OK'); ?></button>
                <button type="button" class="remote-btn" onclick="sendKey('right')">▶</button>

                <div></div>
                <button type="button" class="remote-btn" onclick="sendKey('down')">▼</button>
                <div></div>

                <!-- Nav bottom -->
                <button type="button" class="remote-btn" onclick="sendKey('back')"><?php echo __t('REMOTE.BTN_BACK', 'Zurück'); ?></button>
                <button type="button" class="remote-btn" onclick="sendKey('home')"><?php echo __t('REMOTE.BTN_HOME', 'Home'); ?></button>
                <button type="button" class="remote-btn" onclick="sendKey('options')"><?php echo __t('REMOTE.BTN_OPTIONS', 'Optionen'); ?></button>

                <!-- Playback -->
                <button type="button" class="remote-btn" onclick="sendKey('previous')">⏮</button>
                <button type="button" class="remote-btn" onclick="sendKey('play')">▶ / ⏸</button>
                <button type="button" class="remote-btn" onclick="sendKey('next')">⏭</button>
            </div>

            <!-- Quick Source Selection -->
            <div style="margin-top: 20px;">
                <label style="font-weight: 600; font-size: 0.95rem;"><?php echo __t('SOURCES.SECTION_TITLE', 'Eingangsquellen'); ?>:</label>
                <div class="source-buttons">
                    <?php foreach ($sources as $src): ?>
                        <button type="button" class="source-btn" onclick="sendSource('<?php echo htmlspecialchars($src); ?>')"><?php echo htmlspecialchars($src); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Configuration Form -->
    <div class="card-box">
        <div class="card-header-bar">
            <h2><?php echo __t('RECEIVER.SECTION_TITLE', 'Einstellungen'); ?></h2>
        </div>
        <form id="cfgForm" onsubmit="saveConfig(event)">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
                
                <!-- Receiver column -->
                <div>
                    <h3 style="font-size: 1.05rem; margin-top:0; color:#2f3640;">Receiver Verbindung</h3>
                    
                    <div class="form-row">
                        <label for="rcvHost"><?php echo __t('RECEIVER.LABEL_HOST', 'IP-Adresse / Hostname'); ?>:</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="rcvHost" name="rcv_host" value="<?php echo htmlspecialchars($rcv_host); ?>" required placeholder="z.B. 192.168.1.60">
                            <button type="button" class="remote-btn" style="white-space:nowrap; padding:9px 12px;" onclick="discoverReceivers()">🔍 Suchen</button>
                        </div>
                        <div class="form-hint"><?php echo __t('RECEIVER.HINT_HOST', 'Feste IP-Adresse deines Harman Kardon Receivers.'); ?></div>
                    </div>

                    <div class="form-row">
                        <label for="rcvPort"><?php echo __t('RECEIVER.LABEL_PORT', 'Port (Standard: 10025)'); ?>:</label>
                        <input type="number" id="rcvPort" name="rcv_port" value="<?php echo htmlspecialchars($rcv_port); ?>" required>
                    </div>

                    <div class="form-row">
                        <label for="rcvMac"><?php echo __t('RECEIVER.LABEL_MAC', 'MAC-Adresse (für Wake-on-LAN)'); ?>:</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="rcvMac" name="rcv_mac" value="<?php echo htmlspecialchars($rcv_mac); ?>" placeholder="z.B. 00:1A:2B:3C:4D:5E">
                            <button type="button" class="remote-btn" style="white-space:nowrap; padding:9px 12px;" onclick="autoDetectMac()">Auto-Detect</button>
                        </div>
                        <div class="form-hint"><?php echo __t('RECEIVER.HINT_MAC', 'Ermöglicht das Aufwecken aus dem Standby per LAN.'); ?></div>
                    </div>

                    <div class="form-row">
                        <label for="rcvType"><?php echo __t('RECEIVER.LABEL_DEVICE_TYPE', 'Geräte-Serie'); ?>:</label>
                        <select id="rcvType" name="rcv_type">
                            <option value="AVR" <?php echo $rcv_type === 'AVR' ? 'selected' : ''; ?>><?php echo __t('RECEIVER.OPT_AVR', 'AVR (AV-Receiver z.B. AVR 151S, 161, 171, 370)'); ?></option>
                            <option value="BDS" <?php echo $rcv_type === 'BDS' ? 'selected' : ''; ?>><?php echo __t('RECEIVER.OPT_BDS', 'BDS (Blu-ray Heimkino System z.B. BDS 277, 375, 577)'); ?></option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="rcvZone"><?php echo __t('RECEIVER.LABEL_ZONE', 'Zone'); ?>:</label>
                        <select id="rcvZone" name="rcv_zone">
                            <option value="Main Zone" <?php echo $rcv_zone === 'Main Zone' ? 'selected' : ''; ?>><?php echo __t('RECEIVER.OPT_MAIN_ZONE', 'Main Zone (Hauptzone)'); ?></option>
                            <option value="Zone 2" <?php echo $rcv_zone === 'Zone 2' ? 'selected' : ''; ?>><?php echo __t('RECEIVER.OPT_ZONE2', 'Zone 2'); ?></option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="rcvModel"><?php echo __t('RECEIVER.LABEL_MODEL', 'Modellbezeichnung'); ?>:</label>
                        <input type="text" id="rcvModel" name="rcv_model" value="<?php echo htmlspecialchars($rcv_model); ?>" placeholder="z.B. AVR 151S">
                    </div>

                    <div class="form-row">
                        <label>
                            <input type="checkbox" id="rcvKeepalive" <?php echo $rcv_keepalive ? 'checked' : ''; ?>>
                            <?php echo __t('RECEIVER.LABEL_KEEPALIVE', 'Netzwerk-Keepalive (Heartbeat) aktivieren'); ?>
                        </label>
                        <div class="form-hint"><?php echo __t('RECEIVER.DESC_KEEPALIVE', 'Sendet regelmäßige Keepalives, damit der Receiver seine Netzwerkkarte nicht schlafen legt.'); ?></div>
                    </div>

                    <div class="form-row" id="rowKeepaliveInterval" style="<?php echo $rcv_keepalive ? '' : 'display:none;'; ?>">
                        <label for="rcvKeepaliveInterval"><?php echo __t('RECEIVER.LABEL_KEEPALIVE_INTERVAL', 'Heartbeat-Intervall (Sekunden)'); ?>:</label>
                        <input type="number" id="rcvKeepaliveInterval" name="rcv_keepalive_interval" value="<?php echo htmlspecialchars($rcv_keepalive_interval); ?>" min="15" max="300">
                    </div>
                </div>

                <!-- MQTT & Polling column -->
                <div>
                    <h3 style="font-size: 1.05rem; margin-top:0; color:#2f3640;"><?php echo __t('MQTT.SECTION_TITLE', 'MQTT & Polling'); ?></h3>
                    
                    <div class="form-row">
                        <label>
                            <input type="checkbox" id="mqttUseGateway" <?php echo $mqtt_use_gateway ? 'checked' : ''; ?> onchange="toggleCustomMqtt()">
                            <?php echo __t('MQTT.LABEL_USE_LB_GATEWAY', 'LoxBerry MQTT Gateway nutzen (empfohlen)'); ?>
                        </label>
                        <div class="form-hint"><?php echo $lb_mqtt_info; ?></div>
                    </div>

                    <div class="form-row">
                        <label for="mqttTopicPrefix"><?php echo __t('MQTT.LABEL_TOPIC_PREFIX', 'MQTT Topic-Präfix'); ?>:</label>
                        <input type="text" id="mqttTopicPrefix" name="mqtt_topic_prefix" value="<?php echo htmlspecialchars($mqtt_topic_prefix); ?>" required>
                        <div class="form-hint"><?php echo __t('MQTT.HINT_TOPIC_PREFIX', 'Standard: harmankardon (Topics: harmankardon/status/..., harmankardon/set/...)'); ?></div>
                    </div>

                    <div id="customMqttFields" style="<?php echo $mqtt_use_gateway ? 'display:none;' : ''; ?>">
                        <div class="form-row">
                            <label for="mqttHost"><?php echo __t('MQTT.LABEL_CUSTOM_BROKER', 'Eigener Broker Host'); ?>:</label>
                            <input type="text" id="mqttHost" value="<?php echo htmlspecialchars($config['mqtt']['broker_host'] ?? 'localhost'); ?>">
                        </div>
                        <div class="form-row">
                            <label for="mqttPort"><?php echo __t('MQTT.LABEL_CUSTOM_PORT', 'Eigener Broker Port'); ?>:</label>
                            <input type="number" id="mqttPort" value="<?php echo htmlspecialchars($config['mqtt']['broker_port'] ?? 1883); ?>">
                        </div>
                        <div class="form-row">
                            <label for="mqttUser">Broker Benutzername:</label>
                            <input type="text" id="mqttUser" value="<?php echo htmlspecialchars($config['mqtt']['broker_user'] ?? ''); ?>">
                        </div>
                        <div class="form-row">
                            <label for="mqttPass">Broker Passwort:</label>
                            <input type="password" id="mqttPass" value="<?php echo htmlspecialchars($config['mqtt']['broker_pass'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="pollActive"><?php echo __t('MQTT.LABEL_POLL_ACTIVE', 'Abfrageintervall wenn aktiv (Sekunden)'); ?>:</label>
                        <input type="number" id="pollActive" value="<?php echo htmlspecialchars($poll_active); ?>" min="1" max="60">
                    </div>

                    <div class="form-row">
                        <label for="pollStandby"><?php echo __t('MQTT.LABEL_POLL_STANDBY', 'Abfrageintervall im Standby (Sekunden)'); ?>:</label>
                        <input type="number" id="pollStandby" value="<?php echo htmlspecialchars($poll_standby); ?>" min="5" max="300">
                    </div>

                    <div class="form-row">
                        <label for="logLevel"><?php echo __t('LOGGING.LABEL_LOGLEVEL', 'Loglevel'); ?>:</label>
                        <select id="logLevel">
                            <option value="3" <?php echo $log_level == 3 ? 'selected' : ''; ?>>3 - Fehler (Errors)</option>
                            <option value="4" <?php echo $log_level == 4 ? 'selected' : ''; ?>>4 - Warnungen (Warnings)</option>
                            <option value="6" <?php echo $log_level == 6 ? 'selected' : ''; ?>>6 - Infos (Standard)</option>
                            <option value="7" <?php echo $log_level == 7 ? 'selected' : ''; ?>>7 - Debug (Ausführlich)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 18px; display: flex; gap: 12px;">
                <button type="submit" class="remote-btn btn-power-on" style="padding: 12px 28px; font-size: 1rem;">
                    💾 <?php echo __t('COMMON.LABEL_SAVE', 'Speichern'); ?>
                </button>
                <button type="button" class="remote-btn" onclick="checkStatus()">
                    🔍 <?php echo __t('COMMON.LABEL_TEST', 'Verbindung testen'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showToast(msg, isError = false) {
    const toast = document.getElementById('toastMessage');
    toast.innerText = msg;
    toast.style.background = isError ? '#eb4d4b' : '#2ed573';
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 4000);
}

function toggleCustomMqtt() {
    const isCustom = !document.getElementById('mqttUseGateway').checked;
    document.getElementById('customMqttFields').style.display = isCustom ? 'block' : 'none';
}

document.getElementById('rcvKeepalive').addEventListener('change', function() {
    document.getElementById('rowKeepaliveInterval').style.display = this.checked ? 'block' : 'none';
});

function checkStatus() {
    fetch('ajax.php?action=status')
        .then(r => r.json())
        .then(data => {
            const bOnline = document.getElementById('badgeOnline');
            if (data.online) {
                bOnline.innerText = 'Eingeschaltet';
                bOnline.className = 'status-badge badge-online';
            } else {
                bOnline.innerText = 'Offline / Standby';
                bOnline.className = 'status-badge badge-offline';
            }

            const bDaemon = document.getElementById('badgeDaemon');
            if (data.daemon_running) {
                bDaemon.innerText = 'Läuft';
                bDaemon.className = 'status-badge badge-online';
            } else {
                bDaemon.innerText = 'Gestoppt';
                bDaemon.className = 'status-badge badge-stopped';
            }

            document.getElementById('valPower').innerText = data.power || '-';
            document.getElementById('valSource').innerText = data.source || '-';
            document.getElementById('valVolume').innerText = (data.volume_estimate !== undefined) ? data.volume_estimate : '-';
            document.getElementById('valMute').innerText = data.mute ? 'JA (Stumm)' : 'Nein';
            if (data.zone) document.getElementById('valZone').innerText = data.zone;

            // Mark active source button
            document.querySelectorAll('.source-btn').forEach(btn => {
                if (btn.innerText.trim() === (data.source || '').trim()) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        })
        .catch(err => {
            showToast('Fehler bei Statusabfrage: ' + err, true);
        });
}

function sendPower(cmd) {
    fetch('ajax.php?action=power&cmd=' + encodeURIComponent(cmd))
        .then(r => r.json())
        .then(res => {
            showToast('Power ' + cmd.toUpperCase() + ' gesendet');
            setTimeout(checkStatus, 1000);
        });
}

function sendVolume(cmd) {
    fetch('ajax.php?action=volume&cmd=' + encodeURIComponent(cmd))
        .then(r => r.json())
        .then(res => {
            showToast('Lautstärke: ' + (res.volume !== undefined ? res.volume : cmd));
            setTimeout(checkStatus, 400);
        });
}

function sendMute(cmd) {
    fetch('ajax.php?action=mute&cmd=' + encodeURIComponent(cmd))
        .then(r => r.json())
        .then(res => {
            showToast('Stummschaltung: ' + (res.mute ? 'EIN' : 'AUS'));
            setTimeout(checkStatus, 400);
        });
}

function sendSource(src) {
    fetch('ajax.php?action=source&source=' + encodeURIComponent(src))
        .then(r => r.json())
        .then(res => {
            showToast('Quelle: ' + src);
            setTimeout(checkStatus, 500);
        });
}

function sendKey(key) {
    fetch('ajax.php?action=key&key=' + encodeURIComponent(key))
        .then(r => r.json())
        .then(res => {
            showToast('Taste ' + key.toUpperCase() + ' gesendet');
        });
}

function sendWol() {
    fetch('ajax.php?action=wol')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast('⚡ Wake-on-LAN Magic Packet an ' + res.mac + ' gesendet!');
                setTimeout(checkStatus, 2500);
            } else {
                showToast('WOL Fehler: ' + (res.error || 'Fehlgeschlagen'), true);
            }
        });
}

function sendHeartbeat() {
    fetch('ajax.php?action=heartbeat')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast('💓 Heartbeat Ping an Receiver erfolgreich!');
            } else {
                showToast('Heartbeat fehlgeschlagen (Receiver antwortet nicht)', true);
            }
        });
}

function restartDaemon() {
    fetch('ajax.php?action=restart_daemon')
        .then(r => r.json())
        .then(res => {
            showToast('Dienst neu gestartet!');
            setTimeout(checkStatus, 1500);
        });
}

function autoDetectMac() {
    const ip = document.getElementById('rcvHost').value.trim();
    if (!ip) {
        showToast('Bitte zuerst eine IP-Adresse eintragen', true);
        return;
    }
    fetch('ajax.php?action=get_mac&ip=' + encodeURIComponent(ip))
        .then(r => r.json())
        .then(data => {
            if (data.mac) {
                document.getElementById('rcvMac').value = data.mac;
                showToast('MAC-Adresse ermittelt: ' + data.mac);
            } else {
                showToast('Keine MAC-Adresse im ARP-Cache für ' + ip + ' gefunden.', true);
            }
        });
}

function discoverReceivers() {
    showToast('Suche Receiver im lokalen Netzwerk...');
    fetch('ajax.php?action=discover')
        .then(r => r.json())
        .then(data => {
            if (data.devices && data.devices.length > 0) {
                const dev = data.devices[0];
                document.getElementById('rcvHost').value = dev.ip;
                if (dev.mac) document.getElementById('rcvMac').value = dev.mac;
                showToast('Harman Kardon Receiver gefunden: ' + dev.ip + ' (' + dev.mac + ')');
            } else {
                showToast('Kein aktiver Harman Kardon Receiver mit offenem Port 10025 gefunden.', true);
            }
        });
}

function saveConfig(e) {
    e.preventDefault();
    const payload = {
        receiver: {
            host: document.getElementById('rcvHost').value.trim(),
            port: parseInt(document.getElementById('rcvPort').value, 10),
            mac: document.getElementById('rcvMac').value.trim(),
            device_type: document.getElementById('rcvType').value,
            zone: document.getElementById('rcvZone').value,
            model: document.getElementById('rcvModel').value.trim(),
            keep_alive_enabled: document.getElementById('rcvKeepalive').checked,
            keep_alive_interval: parseInt(document.getElementById('rcvKeepaliveInterval').value, 10) || 60
        },
        mqtt: {
            use_loxberry_gateway: document.getElementById('mqttUseGateway').checked,
            topic_prefix: document.getElementById('mqttTopicPrefix').value.trim(),
            broker_host: document.getElementById('mqttHost').value.trim(),
            broker_port: parseInt(document.getElementById('mqttPort').value, 10) || 1883,
            broker_user: document.getElementById('mqttUser').value.trim(),
            broker_pass: document.getElementById('mqttPass').value.trim()
        },
        polling: {
            interval_active: parseInt(document.getElementById('pollActive').value, 10) || 5,
            interval_standby: parseInt(document.getElementById('pollStandby').value, 10) || 30
        },
        system: {
            loglevel: parseInt(document.getElementById('logLevel').value, 10)
        }
    };

    fetch('ajax.php?action=save_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showToast('<?php echo __t('COMMON.LABEL_SAVED', 'Einstellungen erfolgreich gespeichert!'); ?>');
            restartDaemon();
        } else {
            showToast('Fehler beim Speichern: ' + (res.error || 'Unbekannt'), true);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    checkStatus();
    setInterval(checkStatus, 5000);
});
</script>

<?php
LBWeb::lbfooter();
