#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Harman Kardon AVR Control CLI & Helper Utility for LoxBerry.
Provides network communication, action dispatch, volume/mute handling,
keep-alive heartbeat, Wake-on-LAN (WOL), and network discovery.
"""

import sys
import os
import re
import json
import time
import socket
import argparse
import logging
from logging.handlers import RotatingFileHandler
import subprocess
from typing import Optional, Dict, Any, List

def get_config_path() -> str:
    for folder in ["harmankardon", "harmankardonavr"]:
        for fname in ["harmankardon.json", "harmankardonavr.json"]:
            if "LBPCONFIG" in os.environ:
                cfg = os.path.join(os.environ["LBPCONFIG"], folder, fname)
                if os.path.exists(cfg):
                    return cfg
            lb_home = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")
            lb_std = os.path.join(lb_home, "config", "plugins", folder, fname)
            if os.path.exists(lb_std):
                return lb_std
            script_dir = os.path.dirname(os.path.abspath(__file__))
            local_cfg = os.path.abspath(os.path.join(script_dir, "..", "config", fname))
            if os.path.exists(local_cfg):
                return local_cfg
    # Fallback default
    if "LBPCONFIG" in os.environ:
        return os.path.join(os.environ["LBPCONFIG"], "harmankardon", "harmankardon.json")
    script_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.abspath(os.path.join(script_dir, "..", "config", "harmankardon.json"))

def load_config() -> Dict[str, Any]:
    cfg_file = get_config_path()
    if os.path.exists(cfg_file):
        try:
            with open(cfg_file, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            pass
    return {}

def save_config(cfg: Dict[str, Any]) -> bool:
    cfg_file = get_config_path()
    try:
        os.makedirs(os.path.dirname(cfg_file), exist_ok=True)
        with open(cfg_file, "w", encoding="utf-8") as f:
            json.dump(cfg, f, indent=2, ensure_ascii=False)
        return True
    except Exception as e:
        sys.stderr.write(f"Error saving config to {cfg_file}: {e}\n")
        return False

def get_log_file() -> str:
    if "LBPLOG" in os.environ:
        d = os.path.join(os.environ["LBPLOG"], "harmankardon")
        os.makedirs(d, exist_ok=True)
        return os.path.join(d, "daemon.log")
    lb_home = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")
    d = os.path.join(lb_home, "log", "plugins", "harmankardon")
    try:
        os.makedirs(d, exist_ok=True)
        return os.path.join(d, "daemon.log")
    except Exception:
        return "/tmp/harmankardon_daemon.log"

# Setup logger
logger = logging.getLogger("hkavr_control")
logger.setLevel(logging.INFO)

def get_loxberry_plugin_loglevel(plugin_names=("harmankardon", "harmankardonavr")) -> Optional[int]:
    """Read loglevel configured by the user in the LoxBerry Plugin Management widget."""
    lb_home = os.environ.get("LBHOMEDIR", "/opt/loxberry")
    pdb_paths = [
        os.path.join(lb_home, "data", "system", "plugindatabase.json"),
        "/opt/loxberry/data/system/plugindatabase.json"
    ]
    for p in pdb_paths:
        if os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    data = json.load(f)
                plugins = data.get("plugins") or data
                if isinstance(plugins, dict):
                    for k, v in plugins.items():
                        if isinstance(v, dict):
                            name = v.get("name") or v.get("folder")
                            if name in plugin_names:
                                lvl = v.get("loglevel")
                                if lvl is not None:
                                    return int(lvl)
            except Exception:
                pass
    return None

sys_lvl = get_loxberry_plugin_loglevel()
log_cfg = load_config().get("system", {})
log_lvl_val = sys_lvl if sys_lvl is not None else log_cfg.get("loglevel", 6)
level_map = {
    0: logging.CRITICAL,
    1: logging.CRITICAL,
    2: logging.CRITICAL,
    3: logging.ERROR,
    4: logging.WARNING,
    5: logging.INFO,
    6: logging.INFO,
    7: logging.DEBUG,
    "DEBUG": logging.DEBUG,
    "INFO": logging.INFO,
    "WARNING": logging.WARNING,
    "ERROR": logging.ERROR
}
logger.setLevel(level_map.get(log_lvl_val, logging.INFO))

try:
    log_path = get_log_file()
    fh = RotatingFileHandler(log_path, maxBytes=3*1024*1024, backupCount=2, encoding="utf-8")
    fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s"))
    logger.addHandler(fh)
except Exception:
    pass

sh = logging.StreamHandler(sys.stderr)
sh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s"))
logger.addHandler(sh)


def get_mac_from_arp(ip: str) -> str:
    """Read MAC address for an IP from Linux ARP table /proc/net/arp or ip neigh."""
    if not ip:
        return ""
    if os.path.exists("/proc/net/arp"):
        try:
            with open("/proc/net/arp", "r") as f:
                for line in f:
                    parts = line.split()
                    if len(parts) >= 4 and parts[0] == ip:
                        mac = parts[3].strip()
                        if mac and mac != "00:00:00:00:00:00":
                            return mac.upper()
        except Exception:
            pass

    try:
        res = subprocess.run(["ip", "neigh", "show", ip], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=2)
        m = re.search(r"(([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2})", res.stdout)
        if m:
            return m.group(1).upper()
    except Exception:
        pass

    try:
        res = subprocess.run(["arp", "-n", ip], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=2)
        m = re.search(r"(([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2})", res.stdout)
        if m:
            return m.group(1).replace("-", ":").upper()
    except Exception:
        pass

    return ""


def send_wol(mac_address: str) -> bool:
    """Send Wake-on-LAN Magic Packet via UDP broadcast to ports 9 and 7."""
    clean_mac = re.sub(r"[^0-9a-fA-F]", "", mac_address or "")
    if len(clean_mac) != 12:
        logger.error(f"Invalid MAC address for WOL: '{mac_address}'")
        return False

    mac_bytes = bytes.fromhex(clean_mac)
    magic_packet = b"\xff" * 6 + mac_bytes * 16

    success = False
    for port in (9, 7):
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
                s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
                s.settimeout(2.0)
                s.sendto(magic_packet, ("<broadcast>", port))
                s.sendto(magic_packet, ("255.255.255.255", port))
                success = True
        except Exception as e:
            logger.warning(f"Error sending WOL to port {port}: {e}")

    if success:
        logger.info(f"Wake-on-LAN magic packet sent to {mac_address}")
    return success


class HarmanKardonClient:
    """
    Robust communication client for Harman Kardon AVR and BDS receivers.
    Supports all protocol commands discovered in Devqon/hkavr and KarimGeiger/HKAPI.
    """

    COMMAND_MAP = {
        # Power & system
        "power_on": "power-on",
        "power_off": "power-off",
        "power-on": "power-on",
        "power-off": "power-off",
        "sleep": "sleep",
        # Volume
        "volume_up": "volume-up",
        "volume_down": "volume-down",
        "volume-up": "volume-up",
        "volume-down": "volume-down",
        "mute_toggle": "mute-toggle",
        "mute-toggle": "mute-toggle",
        # Source
        "source": "source-selection",
        "source-selection": "source-selection",
        # Navigation
        "up": "up",
        "down": "down",
        "left": "left",
        "right": "right",
        "ok": "ok",
        "enter": "ok",
        "back": "back",
        "return": "back",
        "home": "home",
        "info": "info",
        "options": "options",
        # Playback
        "play": "play",
        "pause": "pause",
        "next": "next",
        "previous": "previous",
        "forward": "forward",
        "reverse": "reverse",
        # Keepalive
        "heart_alive": "heart-alive",
        "heart-alive": "heart-alive",
        "heartbeat": "heart-alive"
    }

    def __init__(self, host: str, port: int = 10025, device_type: str = "AVR", zone: str = "Main Zone", timeout: float = 2.5):
        self.host = host
        self.port = int(port) if port else 10025
        self.device_type = (device_type or "AVR").strip().upper()
        self.zone = (zone or "Main Zone").strip()
        self.timeout = timeout
        self.sock: Optional[socket.socket] = None

    def _connect(self) -> bool:
        if self.sock is not None:
            try:
                self.sock.close()
            except Exception:
                pass
            self.sock = None

        if not self.host:
            logger.warning("Harman Kardon host not configured")
            return False

        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.settimeout(self.timeout)
            s.connect((self.host, self.port))
            self.sock = s
            return True
        except (socket.timeout, ConnectionRefusedError, OSError) as e:
            logger.debug(f"Failed to connect to {self.host}:{self.port} - {e}")
            self.sock = None
            return False

    def is_reachable(self) -> bool:
        """Test if the receiver port is open and accepting TCP connections."""
        if not self.host:
            return False
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as test_sock:
                test_sock.settimeout(self.timeout)
                test_sock.connect((self.host, self.port))
                return True
        except Exception:
            return False

    def build_xml(self, command_name: str, param: str = "", zone: Optional[str] = None) -> str:
        tag = "bds" if self.device_type == "BDS" else "avr"
        target_zone = zone or self.zone
        param_clean = param if param is not None else ""
        xml = (
            '<?xml version="1.0" encoding="UTF-8"?>\r\n'
            '<harman>\r\n'
            f'    <{tag}>\r\n'
            '        <common>\r\n'
            '            <control>\r\n'
            f'                <name>{command_name}</name>\r\n'
            f'                <zone>{target_zone}</zone>\r\n'
            f'                <para>{param_clean}</para>\r\n'
            '            </control>\r\n'
            '        </common>\r\n'
            f'    </{tag}>\r\n'
            '</harman>'
        )
        return xml

    def send_action(self, action: str, param: str = "", zone: Optional[str] = None) -> bool:
        """Send command to Harman Kardon AVR over TCP socket."""
        action_key = action.strip().lower()
        cmd_name = self.COMMAND_MAP.get(action_key, action)

        xml = self.build_xml(cmd_name, param, zone)
        xml_bytes = xml.encode("utf-8")

        device_header = "POST BDS HTTP/1.1" if self.device_type == "BDS" else "POST AVR HTTP/1.1"
        req = (
            f"{device_header}\r\n"
            f"Host: :{self.port}\r\n"
            "User-Agent: Harman Kardon AVR Remote Controller /2.0\r\n"
            f"Content-Length: {len(xml_bytes)}\r\n\r\n"
        ).encode("utf-8") + xml_bytes

        # Attempt sending with 1 automatic reconnect retry
        for attempt in range(2):
            if self.sock is None:
                if not self._connect():
                    if attempt == 0:
                        continue
                    return False

            try:
                self.sock.sendall(req)
                logger.info(f"Sent action '{cmd_name}' (param='{param}', zone='{zone or self.zone}') to {self.host}")
                return True
            except (socket.error, BrokenPipeError, ConnectionResetError) as e:
                logger.warning(f"Socket send failed on attempt {attempt+1}: {e}")
                self.sock = None
                time.sleep(0.1)

        return False

    def close(self):
        if self.sock is not None:
            try:
                self.sock.close()
            except Exception:
                pass
            self.sock = None


def discover_receivers(timeout: float = 3.0) -> List[Dict[str, Any]]:
    """
    Search local network for potential Harman Kardon receivers via:
    1. ARP table lookup
    2. Quick TCP port scan on port 10025 on recently active local IPs
    """
    results: List[Dict[str, Any]] = []
    seen_ips = set()

    # Read ARP cache
    candidates = []
    if os.path.exists("/proc/net/arp"):
        try:
            with open("/proc/net/arp", "r") as f:
                for line in f:
                    parts = line.split()
                    if len(parts) >= 4 and parts[0] != "IP":
                        ip, hw_type, flags, mac = parts[0], parts[1], parts[2], parts[3]
                        if mac != "00:00:00:00:00:00" and not ip.startswith("127."):
                            candidates.append((ip, mac.upper()))
        except Exception:
            pass

    # If no ARP entries or on Windows / non-Linux, try ip neigh / arp -a
    if not candidates:
        try:
            res = subprocess.run(["arp", "-a"], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=2)
            for line in res.stdout.splitlines():
                m = re.search(r"(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\s+([0-9a-fA-F:-]{17})", line)
                if m:
                    ip = m.group(1)
                    mac = m.group(2).replace("-", ":").upper()
                    if mac != "FF:FF:FF:FF:FF:FF" and not ip.startswith("127.") and not ip.startswith("224."):
                        candidates.append((ip, mac))
        except Exception:
            pass

    # Probe port 10025 on candidate IPs
    for ip, mac in candidates:
        if ip in seen_ips:
            continue
        seen_ips.add(ip)
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                s.settimeout(0.5)
                if s.connect_ex((ip, 10025)) == 0:
                    results.append({
                        "ip": ip,
                        "mac": mac,
                        "port": 10025,
                        "description": "Harman Kardon AVR / BDS (Port 10025 open)"
                    })
        except Exception:
            pass

    return results


def main():
    parser = argparse.ArgumentParser(description="Harman Kardon AVR Control Tool for LoxBerry")
    parser.add_argument("--host", help="Receiver IP or hostname")
    parser.add_argument("--port", type=int, default=10025, help="Receiver port (default: 10025)")
    parser.add_argument("--mac", help="MAC address for WOL")
    parser.add_argument("--type", default=None, choices=["AVR", "BDS"], help="Device type (AVR or BDS)")
    parser.add_argument("--zone", default=None, help="Zone (e.g. 'Main Zone' or 'Zone 2')")

    parser.add_argument("--status", action="store_true", help="Check receiver online status")
    parser.add_argument("--power", choices=["on", "off", "toggle", "sleep", "ON", "OFF", "TOGGLE", "SLEEP"], help="Power command")
    parser.add_argument("--volume", help="Volume command: 'up', 'down', '+5', '-5', or level 0-100")
    parser.add_argument("--mute", help="Mute command: 'toggle', 'true', 'false'")
    parser.add_argument("--source", help="Select input source (e.g. 'TV', 'Game', 'Radio', 'AUX', etc.)")
    parser.add_argument("--key", help="Send navigation / remote key (up, down, left, right, ok, back, home, etc.)")
    parser.add_argument("--wol", action="store_true", help="Send Wake-on-LAN magic packet")
    parser.add_argument("--heartbeat", action="store_true", help="Send heart-alive keepalive ping")
    parser.add_argument("--discover", action="store_true", help="Search network for Harman Kardon receivers")
    parser.add_argument("--get-mac", help="Look up MAC address from ARP for specified IP")

    args = parser.parse_args()

    cfg = load_config()
    rcv_cfg = cfg.get("receiver", {})
    state_cfg = cfg.get("state", {})

    host = args.host or rcv_cfg.get("host", "")
    port = args.port or rcv_cfg.get("port", 10025)
    mac = args.mac or rcv_cfg.get("mac", "")
    device_type = args.type or rcv_cfg.get("device_type", "AVR")
    zone = args.zone or rcv_cfg.get("zone", "Main Zone")

    # Handler for --get-mac
    if args.get_mac:
        found_mac = get_mac_from_arp(args.get_mac)
        print(json.dumps({"ip": args.get_mac, "mac": found_mac}))
        sys.exit(0 if found_mac else 1)

    # Handler for --discover
    if args.discover:
        found = discover_receivers()
        print(json.dumps({"devices": found}))
        sys.exit(0)

    # Handler for --wol
    if args.wol:
        target_mac = mac or get_mac_from_arp(host)
        if not target_mac:
            print(json.dumps({"success": False, "error": "No MAC address available for Wake-on-LAN"}))
            sys.exit(1)
        ok = send_wol(target_mac)
        print(json.dumps({"success": ok, "mac": target_mac}))
        sys.exit(0 if ok else 1)

    client = HarmanKardonClient(host=host, port=port, device_type=device_type, zone=zone)

    # Handler for --status
    if args.status:
        online = client.is_reachable()
        power = "ON" if online else "OFF"
        # If mac is missing, try resolving from ARP
        resolved_mac = mac or get_mac_from_arp(host)
        result = {
            "online": online,
            "power": power,
            "host": host,
            "port": port,
            "mac": resolved_mac,
            "device_type": device_type,
            "zone": zone,
            "volume_estimate": state_cfg.get("volume_estimate", 35),
            "mute": state_cfg.get("mute", False),
            "source": state_cfg.get("source", "TV")
        }
        print(json.dumps(result))
        sys.exit(0 if online else 1)

    # Handler for --heartbeat
    if args.heartbeat:
        ok = client.send_action("heart-alive")
        print(json.dumps({"success": ok, "command": "heart-alive"}))
        sys.exit(0 if ok else 1)

    # Handler for --power
    if args.power:
        p_cmd = args.power.lower()
        if p_cmd == "on":
            # If currently offline, send WOL first to wake network interface
            if not client.is_reachable():
                wol_mac = mac or get_mac_from_arp(host)
                if wol_mac:
                    send_wol(wol_mac)
                    time.sleep(1.0)
            ok = client.send_action("power-on")
            if ok:
                state_cfg["power"] = "ON"
                cfg["state"] = state_cfg
                save_config(cfg)
        elif p_cmd == "off":
            ok = client.send_action("power-off")
            if ok:
                state_cfg["power"] = "OFF"
                cfg["state"] = state_cfg
                save_config(cfg)
        elif p_cmd == "sleep":
            ok = client.send_action("sleep")
            if ok:
                state_cfg["power"] = "STANDBY"
                cfg["state"] = state_cfg
                save_config(cfg)
        elif p_cmd == "toggle":
            # If reachable assume ON -> turn OFF; if offline or state OFF -> turn ON
            is_on = client.is_reachable() and state_cfg.get("power", "OFF") == "ON"
            if is_on:
                ok = client.send_action("power-off")
                if ok:
                    state_cfg["power"] = "OFF"
            else:
                wol_mac = mac or get_mac_from_arp(host)
                if wol_mac:
                    send_wol(wol_mac)
                    time.sleep(1.0)
                ok = client.send_action("power-on")
                if ok:
                    state_cfg["power"] = "ON"
            cfg["state"] = state_cfg
            save_config(cfg)
        else:
            ok = False

        print(json.dumps({"success": ok, "power": state_cfg.get("power")}))
        sys.exit(0 if ok else 1)

    # Handler for --volume
    if args.volume:
        vol_arg = args.volume.lower().strip()
        current_vol = state_cfg.get("volume_estimate", 35)
        ok = True

        if vol_arg in ("up", "volume-up", "volume_up"):
            ok = client.send_action("volume-up")
            current_vol = min(100, current_vol + 1)
        elif vol_arg in ("down", "volume-down", "volume_down"):
            ok = client.send_action("volume-down")
            current_vol = max(0, current_vol - 1)
        elif vol_arg.startswith("+") or vol_arg.startswith("-"):
            try:
                diff = int(vol_arg)
                steps = abs(diff)
                action = "volume-up" if diff > 0 else "volume-down"
                for _ in range(steps):
                    ok = client.send_action(action)
                    time.sleep(0.08)
                current_vol = max(0, min(100, current_vol + diff))
            except ValueError:
                ok = False
        else:
            try:
                target_vol = int(vol_arg)
                target_vol = max(0, min(100, target_vol))
                diff = target_vol - current_vol
                steps = abs(diff)
                action = "volume-up" if diff > 0 else "volume-down"
                # Send steps towards target volume
                for _ in range(steps):
                    ok = client.send_action(action)
                    time.sleep(0.08)
                current_vol = target_vol
            except ValueError:
                ok = False

        if ok:
            state_cfg["volume_estimate"] = current_vol
            cfg["state"] = state_cfg
            save_config(cfg)

        print(json.dumps({"success": ok, "volume": current_vol}))
        sys.exit(0 if ok else 1)

    # Handler for --mute
    if args.mute:
        m_arg = args.mute.lower().strip()
        cur_mute = state_cfg.get("mute", False)
        if m_arg == "toggle":
            ok = client.send_action("mute-toggle")
            if ok:
                cur_mute = not cur_mute
        elif m_arg in ("true", "1", "on"):
            ok = client.send_action("mute-toggle")
            cur_mute = True
        elif m_arg in ("false", "0", "off"):
            ok = client.send_action("mute-toggle")
            cur_mute = False
        else:
            ok = False

        if ok:
            state_cfg["mute"] = cur_mute
            cfg["state"] = state_cfg
            save_config(cfg)

        print(json.dumps({"success": ok, "mute": cur_mute}))
        sys.exit(0 if ok else 1)

    # Handler for --source
    if args.source:
        source_name = args.source.strip()
        ok = client.send_action("source-selection", param=source_name)
        if ok:
            state_cfg["source"] = source_name
            cfg["state"] = state_cfg
            save_config(cfg)
        print(json.dumps({"success": ok, "source": source_name}))
        sys.exit(0 if ok else 1)

    # Handler for --key
    if args.key:
        key_name = args.key.strip()
        ok = client.send_action(key_name)
        print(json.dumps({"success": ok, "key": key_name}))
        sys.exit(0 if ok else 1)

    parser.print_help()
    sys.exit(0)


if __name__ == "__main__":
    main()
