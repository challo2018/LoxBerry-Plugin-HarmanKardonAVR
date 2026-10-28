<?php
/**
 * AJAX Handler for Harman Kardon AVR LoxBerry Plugin
 * Compatible with PHP 7.0 - 8.x
 */

header('Content-Type: application/json; charset=UTF-8');

// Polyfills for PHP < 8.0 compatibility
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || $needle === substr($haystack, -strlen($needle));
    }
}

require_once "loxberry_system.php";
require_once "loxberry_io.php";

function get_plugin_config_file() {
    global $lbpconfigdir;
    foreach (['harmankardon', 'harmankardonavr'] as $dir) {
        foreach (['harmankardon.json', 'harmankardonavr.json'] as $f) {
            if (!empty($lbpconfigdir) && file_exists("$lbpconfigdir/$f")) {
                return "$lbpconfigdir/$f";
            }
            $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
            $std = "$lb_home/config/plugins/$dir/$f";
            if (file_exists($std)) {
                return $std;
            }
            $local = dirname(__DIR__, 2) . "/config/$f";
            if (file_exists($local)) {
                return $local;
            }
        }
    }
    return !empty($lbpconfigdir) ? "$lbpconfigdir/harmankardon.json" : (dirname(__DIR__, 2) . "/config/harmankardon.json");
}

function get_log_file_path() {
    global $lbplogdir;
    if (!empty($lbplogdir)) {
        return "$lbplogdir/daemon.log";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/log/plugins/harmankardon/daemon.log";
    if (file_exists(dirname($std))) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/daemon.log";
}

function get_python_binary() {
    global $lbpdatadir;
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $venv_python = !empty($lbpdatadir) ? "$lbpdatadir/venv/bin/python3" : "$lb_home/data/plugins/harmankardon/venv/bin/python3";
    if (file_exists($venv_python)) {
        return $venv_python;
    }
    return "python3";
}

function get_control_script() {
    global $lbpbindir;
    if (!empty($lbpbindir) && file_exists("$lbpbindir/hkavr_control.py")) {
        return "$lbpbindir/hkavr_control.py";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/bin/plugins/harmankardon/hkavr_control.py";
    if (file_exists($std)) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/bin/hkavr_control.py";
}

function get_daemon_script() {
    global $lbpbindir;
    if (!empty($lbpbindir) && file_exists("$lbpbindir/hkavr_daemon.py")) {
        return "$lbpbindir/hkavr_daemon.py";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/bin/plugins/harmankardon/hkavr_daemon.py";
    if (file_exists($std)) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/bin/hkavr_daemon.py";
}

function parse_command_json($output_array) {
    if (!is_array($output_array) || empty($output_array)) {
        return null;
    }
    for ($i = count($output_array) - 1; $i >= 0; $i--) {
        $line = trim($output_array[$i]);
        if ($line !== '' && $line[0] === '{' && substr($line, -1) === '}') {
            $data = json_decode($line, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    $full = implode("\n", $output_array);
    if (preg_match('/\{[\s\S]*\}/', $full, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function run_control_cli($args) {
    $py = get_python_binary();
    $script = get_control_script();
    if (!file_exists($script)) {
        return ['success' => false, 'error' => "CLI script not found at $script"];
    }

    $cmd = escapeshellcmd($py) . " " . escapeshellarg($script) . " " . $args . " 2>&1";
    exec($cmd, $out, $ret);

    $parsed = parse_command_json($out);
    if ($parsed !== null) {
        return $parsed;
    }
    return [
        'success' => ($ret === 0),
        'code' => $ret,
        'output' => implode("\n", $out)
    ];
}

function is_daemon_running() {
    exec("pgrep -f 'hkavr_daemon.py' 2>/dev/null", $out, $ret);
    return ($ret === 0 && !empty($out));
}

// Route action
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

switch ($action) {
    case 'status':
        $res = run_control_cli("--status");
        $daemon_running = is_daemon_running();
        $res['daemon_running'] = $daemon_running;
        echo json_encode($res);
        break;

    case 'power':
        $cmd = isset($_REQUEST['cmd']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['cmd']) : 'toggle';
        $res = run_control_cli("--power " . escapeshellarg($cmd));
        echo json_encode($res);
        break;

    case 'volume':
        $cmd = isset($_REQUEST['cmd']) ? trim($_REQUEST['cmd']) : 'up';
        $res = run_control_cli("--volume " . escapeshellarg($cmd));
        echo json_encode($res);
        break;

    case 'mute':
        $cmd = isset($_REQUEST['cmd']) ? trim($_REQUEST['cmd']) : 'toggle';
        $res = run_control_cli("--mute " . escapeshellarg($cmd));
        echo json_encode($res);
        break;

    case 'source':
        $source = isset($_REQUEST['source']) ? trim($_REQUEST['source']) : '';
        if (empty($source)) {
            echo json_encode(['success' => false, 'error' => 'Source cannot be empty']);
            break;
        }
        $res = run_control_cli("--source " . escapeshellarg($source));
        echo json_encode($res);
        break;

    case 'key':
        $key = isset($_REQUEST['key']) ? trim($_REQUEST['key']) : '';
        if (empty($key)) {
            echo json_encode(['success' => false, 'error' => 'Key cannot be empty']);
            break;
        }
        $res = run_control_cli("--key " . escapeshellarg($key));
        echo json_encode($res);
        break;

    case 'zone':
        $zone = isset($_REQUEST['zone']) ? trim($_REQUEST['zone']) : (isset($_REQUEST['cmd']) ? trim($_REQUEST['cmd']) : 'Main Zone');
        $cfg_file = get_plugin_config_file();
        $curr = file_exists($cfg_file) ? json_decode(file_get_contents($cfg_file), true) : [];
        if (!is_array($curr)) $curr = [];
        $curr['receiver']['zone'] = $zone;
        file_put_contents($cfg_file, json_encode($curr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['success' => true, 'zone' => $zone]);
        break;

    case 'wol':
        $res = run_control_cli("--wol");
        echo json_encode($res);
        break;

    case 'heartbeat':
        $res = run_control_cli("--heartbeat");
        echo json_encode($res);
        break;

    case 'discover':
        $res = run_control_cli("--discover");
        echo json_encode($res);
        break;

    case 'get_mac':
        $ip = isset($_REQUEST['ip']) ? trim($_REQUEST['ip']) : '';
        $res = run_control_cli("--get-mac " . escapeshellarg($ip));
        echo json_encode($res);
        break;

    case 'restart_daemon':
        global $lbpdaemondir;
        $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
        $daemon_script = !empty($lbpdaemondir) ? "$lbpdaemondir/daemon.sh" : "$lb_home/system/daemons/plugins/harmankardon";
        if (!file_exists($daemon_script)) {
            $daemon_script = dirname(__DIR__, 2) . "/daemon/daemon.sh";
        }

        if (file_exists($daemon_script) && is_executable($daemon_script)) {
            exec(escapeshellcmd($daemon_script) . " restart 2>&1", $out, $ret);
            echo json_encode(['success' => ($ret === 0), 'output' => implode("\n", $out)]);
        } else {
            exec("pkill -f 'hkavr_daemon.py' 2>/dev/null", $dummy);
            sleep(1);
            $py = get_python_binary();
            $d_script = get_daemon_script();
            $log = get_log_file_path();
            $cmd = "nohup " . escapeshellcmd($py) . " " . escapeshellarg($d_script) . " >> " . escapeshellarg($log) . " 2>&1 &";
            exec($cmd, $out, $ret);
            echo json_encode(['success' => true, 'output' => 'Daemon restarted directly via python.']);
        }
        break;

    case 'save_config':
        $json_input = file_get_contents('php://input');
        $new_data = json_decode($json_input, true);
        if (!is_array($new_data)) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            break;
        }

        $cfg_file = get_plugin_config_file();
        $curr = file_exists($cfg_file) ? json_decode(file_get_contents($cfg_file), true) : [];
        if (!is_array($curr)) {
            $curr = [];
        }

        if (isset($new_data['receiver'])) {
            $curr['receiver'] = array_merge($curr['receiver'] ?? [], $new_data['receiver']);
        }
        if (isset($new_data['sources'])) {
            $curr['sources'] = $new_data['sources'];
        }
        if (isset($new_data['mqtt'])) {
            $curr['mqtt'] = array_merge($curr['mqtt'] ?? [], $new_data['mqtt']);
        }
        if (isset($new_data['polling'])) {
            $curr['polling'] = array_merge($curr['polling'] ?? [], $new_data['polling']);
        }
        if (isset($new_data['system'])) {
            $curr['system'] = array_merge($curr['system'] ?? [], $new_data['system']);
        }

        @mkdir(dirname($cfg_file), 0755, true);
        $saved = file_put_contents($cfg_file, json_encode($curr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($saved !== false) {
            echo json_encode(['success' => true, 'config' => $curr]);
        } else {
            echo json_encode(['success' => false, 'error' => "Failed to write $cfg_file"]);
        }
        break;

    case 'get_log':
        $log_file = get_log_file_path();
        if (!file_exists($log_file)) {
            echo json_encode(['success' => true, 'lines' => ["(Keine Logdatei vorhanden unter $log_file)"]]);
            break;
        }
        $lines_count = isset($_REQUEST['lines']) ? intval($_REQUEST['lines']) : 100;
        if ($lines_count <= 0) $lines_count = 100;

        exec("tail -n " . escapeshellarg($lines_count) . " " . escapeshellarg($log_file) . " 2>&1", $out, $ret);
        echo json_encode(['success' => true, 'lines' => $out, 'file' => $log_file]);
        break;

    case 'clear_log':
        $log_file = get_log_file_path();
        if (file_exists($log_file)) {
            file_put_contents($log_file, "");
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
