#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Harman Kardon AVR Background Daemon for LoxBerry.
Handles 2-way MQTT communication:
- Periodically checks receiver online/standby status and publishes to MQTT
- Subscribes to MQTT set/command topics and controls the receiver
- Maintains keepalive heartbeat to prevent the receiver from sleeping its network stack
- Logs status, commands and errors to LoxBerry daemon log
"""

import sys
import os
import time
import json
import signal
import socket
import logging
from logging.handlers import RotatingFileHandler
from typing import Dict, Any, Optional

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
        except Exception as e:
            sys.stderr.write(f"Error loading config {cfg_file}: {e}\n")
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

# Setup Logger
logger = logging.getLogger("hkavr_daemon")
logger.setLevel(logging.INFO)

def update_logger_level(level_setting):
    level_map = {
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
    lvl = level_map.get(level_setting, logging.INFO)
    logger.setLevel(lvl)

log_formatter = logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s")
try:
    log_path = get_log_file()
    fh = RotatingFileHandler(log_path, maxBytes=5*1024*1024, backupCount=2, encoding="utf-8")
    fh.setFormatter(log_formatter)
    logger.addHandler(fh)
except Exception:
    pass

if sys.stdout.isatty():
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(log_formatter)
    logger.addHandler(sh)

try:
    import paho.mqtt.client as mqtt
except ImportError:
    logger.error("paho-mqtt library is missing. Run pip3 install paho-mqtt")
    sys.exit(1)

# Import client helper functions from hkavr_control
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from hkavr_control import HarmanKardonClient, send_wol, get_mac_from_arp

RUNNING = True

def signal_handler(signum, frame):
    global RUNNING
    logger.info(f"Received shutdown signal ({signum}). Exiting...")
    RUNNING = False

signal.signal(signal.SIGTERM, signal_handler)
signal.signal(signal.SIGINT, signal_handler)


def get_mqtt_gateway_credentials() -> Dict[str, Any]:
    """
    Retrieve MQTT Gateway credentials from LoxBerry system configs.
    Checks general.json, then mqttgateway.json, then general.cfg.
    """
    creds = {
        "brokerhost": "localhost",
        "brokerport": 1883,
        "brokeruser": "",
        "brokerpass": ""
    }
    lb_home = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")
    lbs_config = os.environ.get("LBSCONFIG", os.path.join(lb_home, "config", "system"))

    # 1. Primary location: general.json -> "Mqtt" section
    general_candidates = [
        os.path.join(lbs_config, "general.json"),
        os.path.join(lb_home, "config", "system", "general.json"),
    ]
    for p in general_candidates:
        if p and os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    data = json.load(f)
                mqtt_sec = data.get("Mqtt") or data.get("mqtt") or {}
                if mqtt_sec:
                    for k, v in mqtt_sec.items():
                        kl = k.lower()
                        if kl == "brokerhost": creds["brokerhost"] = str(v)
                        elif kl == "brokerport": creds["brokerport"] = int(v)
                        elif kl == "brokeruser": creds["brokeruser"] = str(v)
                        elif kl == "brokerpass": creds["brokerpass"] = str(v)
                    if creds.get("brokeruser"):
                        logger.info(f"Loaded LoxBerry MQTT credentials from {p} (user: {creds['brokeruser']})")
                        return creds
            except Exception as e:
                logger.warning(f"Error parsing {p}: {e}")

    # 2. Secondary location: mqttgateway.json -> "Main" section
    gw_candidates = [
        os.path.join(lbs_config, "mqttgateway.json"),
        os.path.join(lb_home, "config", "system", "mqttgateway.json"),
    ]
    for p in gw_candidates:
        if p and os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    data = json.load(f)
                sec = data.get("Main") or data.get("main") or data
                for k, v in sec.items():
                    kl = k.lower()
                    if kl == "brokerhost": creds["brokerhost"] = str(v)
                    elif kl == "brokerport": creds["brokerport"] = int(v)
                    elif kl == "brokeruser": creds["brokeruser"] = str(v)
                    elif kl == "brokerpass": creds["brokerpass"] = str(v)
                if creds.get("brokeruser"):
                    logger.info(f"Loaded LoxBerry MQTT Gateway credentials from {p} (user: {creds['brokeruser']})")
                    return creds
            except Exception as e:
                logger.warning(f"Error parsing {p}: {e}")

    # 3. Fallback: general.cfg (legacy INI)
    ini_candidates = [
        os.path.join(lbs_config, "general.cfg"),
        os.path.join(lb_home, "config", "system", "general.cfg"),
    ]
    for p in ini_candidates:
        if p and os.path.exists(p):
            try:
                import configparser
                cfg = configparser.ConfigParser()
                cfg.read(p)
                for s in ["MQTT", "Mqtt", "mqtt"]:
                    if cfg.has_section(s):
                        creds["brokerhost"] = cfg.get(s, "brokerhost", fallback=creds["brokerhost"])
                        creds["brokerport"] = int(cfg.get(s, "brokerport", fallback=str(creds["brokerport"])))
                        creds["brokeruser"] = cfg.get(s, "brokeruser", fallback=creds["brokeruser"])
                        creds["brokerpass"] = cfg.get(s, "brokerpass", fallback=creds["brokerpass"])
                        if creds.get("brokeruser"):
                            logger.info(f"Loaded LoxBerry MQTT credentials from {p}")
                            return creds
            except Exception as e:
                logger.warning(f"Error parsing {p}: {e}")

    return creds


class HarmanKardonDaemon:
    def __init__(self):
        self.config: Dict[str, Any] = {}
        self.client: Optional[HarmanKardonClient] = None
        self.mqtt_client: Optional[mqtt.Client] = None
        self.last_poll_time = 0.0
        self.last_heartbeat_time = 0.0
        self.config_mtime = 0.0
        
        # Current known status
        self.is_online = False
        self.power_state = "OFF"
        self.volume_estimate = 35
        self.mute_state = False
        self.current_source = "TV"
        self.current_zone = "Main Zone"
        self.last_published_state: Dict[str, Any] = {}

    def reload_config(self):
        cfg_path = get_config_path()
        try:
            mtime = os.path.getmtime(cfg_path) if os.path.exists(cfg_path) else 0
        except OSError:
            mtime = 0

        if mtime != self.config_mtime or not self.config:
            self.config_mtime = mtime
            self.config = load_config()
            update_logger_level(self.config.get("system", {}).get("loglevel", 6))

            rcv_cfg = self.config.get("receiver", {})
            host = rcv_cfg.get("host", "")
            port = rcv_cfg.get("port", 10025)
            device_type = rcv_cfg.get("device_type", "AVR")
            zone = rcv_cfg.get("zone", "Main Zone")
            
            state_cfg = self.config.get("state", {})
            self.volume_estimate = state_cfg.get("volume_estimate", 35)
            self.mute_state = state_cfg.get("mute", False)
            self.power_state = state_cfg.get("power", "OFF")
            self.current_source = state_cfg.get("source", "TV")
            self.current_zone = zone

            logger.info(f"Config loaded: AVR Host='{host}:{port}' (Type={device_type}, Zone={zone})")
            self.client = HarmanKardonClient(host=host, port=port, device_type=device_type, zone=zone)

    def get_topic_prefix(self) -> str:
        return self.config.get("mqtt", {}).get("topic_prefix", "harmankardon").rstrip("/")

    def setup_mqtt(self):
        mqtt_cfg = self.config.get("mqtt", {})
        use_gateway = mqtt_cfg.get("use_loxberry_gateway", True)

        if use_gateway:
            creds = get_mqtt_gateway_credentials()
            broker_host = creds.get("brokerhost", "localhost")
            broker_port = int(creds.get("brokerport", 1883))
            broker_user = creds.get("brokeruser", "")
            broker_pass = creds.get("brokerpass", "")
        else:
            broker_host = mqtt_cfg.get("broker_host", "localhost")
            broker_port = int(mqtt_cfg.get("broker_port", 1883))
            broker_user = mqtt_cfg.get("broker_user", "")
            broker_pass = mqtt_cfg.get("broker_pass", "")

        client_id = f"loxberry_harmankardon_{os.getpid()}"
        try:
            if hasattr(mqtt, "CallbackAPIVersion"):
                api_ver = getattr(mqtt.CallbackAPIVersion, "VERSION2", getattr(mqtt.CallbackAPIVersion, "VERSION1", None))
                if api_ver is not None:
                    self.mqtt_client = mqtt.Client(api_ver, client_id=client_id)
                else:
                    self.mqtt_client = mqtt.Client(client_id=client_id)
            else:
                self.mqtt_client = mqtt.Client(client_id=client_id)
        except Exception:
            self.mqtt_client = mqtt.Client(client_id=client_id)

        if broker_user:
            self.mqtt_client.username_pw_set(broker_user, broker_pass)

        prefix = self.get_topic_prefix()
        # Set Last Will and Testament (LWT)
        self.mqtt_client.will_set(f"{prefix}/status/daemon", payload="offline", qos=1, retain=True)

        self.mqtt_client.on_connect = self.on_mqtt_connect
        self.mqtt_client.on_message = self.on_mqtt_message
        self.mqtt_client.on_disconnect = self.on_mqtt_disconnect

        logger.info(f"Connecting to MQTT broker at {broker_host}:{broker_port}...")
        try:
            self.mqtt_client.connect(broker_host, broker_port, 60)
            self.mqtt_client.loop_start()
        except Exception as e:
            logger.error(f"Failed to connect to MQTT broker: {e}")

    def on_mqtt_connect(self, client, userdata, flags, rc, *args, **kwargs):
        is_failure = False
        if hasattr(rc, "is_failure"):
            is_failure = rc.is_failure
        elif isinstance(rc, int):
            is_failure = (rc != 0)

        if not is_failure:
            prefix = self.get_topic_prefix()
            logger.info(f"Connected to MQTT broker successfully. Prefix='{prefix}'")
            # Publish daemon online
            client.publish(f"{prefix}/status/daemon", payload="online", qos=1, retain=True)
            # Subscribe to all command topics
            sub_topic = f"{prefix}/set/#"
            client.subscribe(sub_topic)
            logger.info(f"Subscribed to topic: {sub_topic}")
            # Immediately publish current state
            self.publish_status(force=True)
        else:
            logger.error(f"MQTT connection refused by broker: {rc}")

    def on_mqtt_disconnect(self, client, userdata, *args, **kwargs):
        rc = args[0] if args else kwargs.get("reason_code", kwargs.get("rc", None))
        is_error = False
        if hasattr(rc, "is_failure"):
            is_error = rc.is_failure
        elif isinstance(rc, int):
            is_error = (rc != 0)
        if is_error:
            logger.warning(f"Unexpected MQTT disconnect (rc={rc}). Will auto-reconnect...")

    def on_mqtt_message(self, client, userdata, msg):
        prefix = self.get_topic_prefix()
        topic = msg.topic
        payload = msg.payload.decode("utf-8", errors="ignore").strip()

        logger.info(f"MQTT Command received: '{topic}' -> '{payload}'")
        sub_path = topic[len(prefix):].lstrip("/")
        parts = sub_path.split("/")

        if len(parts) >= 2 and parts[0] == "set":
            action_type = parts[1].lower()
            try:
                self.handle_command(action_type, payload)
            except Exception as e:
                logger.error(f"Error executing command '{action_type}' ({payload}): {e}")

    def handle_command(self, action_type: str, payload: str):
        if not self.client:
            logger.warning("Receiver client not initialized")
            return

        rcv_cfg = self.config.get("receiver", {})
        host = rcv_cfg.get("host", "")
        mac = rcv_cfg.get("mac", "")

        # 1. Power
        if action_type == "power":
            p = payload.upper()
            if p in ("ON", "1", "TRUE"):
                if not self.is_online:
                    wol_mac = mac or get_mac_from_arp(host)
                    if wol_mac:
                        send_wol(wol_mac)
                        time.sleep(1.0)
                if self.client.send_action("power-on"):
                    self.power_state = "ON"
            elif p in ("OFF", "0", "FALSE"):
                if self.client.send_action("power-off"):
                    self.power_state = "OFF"
            elif p == "SLEEP":
                if self.client.send_action("sleep"):
                    self.power_state = "STANDBY"
            elif p == "TOGGLE":
                if self.power_state == "ON":
                    if self.client.send_action("power-off"):
                        self.power_state = "OFF"
                else:
                    wol_mac = mac or get_mac_from_arp(host)
                    if wol_mac:
                        send_wol(wol_mac)
                        time.sleep(1.0)
                    if self.client.send_action("power-on"):
                        self.power_state = "ON"
            self.persist_state()
            self.publish_status(force=True)

        # 2. Volume
        elif action_type == "volume":
            v_cmd = payload.strip().lower()
            if v_cmd in ("up", "+1"):
                if self.client.send_action("volume-up"):
                    self.volume_estimate = min(100, self.volume_estimate + 1)
            elif v_cmd in ("down", "-1"):
                if self.client.send_action("volume-down"):
                    self.volume_estimate = max(0, self.volume_estimate - 1)
            elif v_cmd.startswith("+") or v_cmd.startswith("-"):
                try:
                    diff = int(v_cmd)
                    steps = abs(diff)
                    action = "volume-up" if diff > 0 else "volume-down"
                    for _ in range(steps):
                        self.client.send_action(action)
                        time.sleep(0.08)
                    self.volume_estimate = max(0, min(100, self.volume_estimate + diff))
                except ValueError:
                    pass
            else:
                try:
                    target_vol = max(0, min(100, int(v_cmd)))
                    diff = target_vol - self.volume_estimate
                    steps = abs(diff)
                    action = "volume-up" if diff > 0 else "volume-down"
                    for _ in range(steps):
                        self.client.send_action(action)
                        time.sleep(0.08)
                    self.volume_estimate = target_vol
                except ValueError:
                    pass
            self.persist_state()
            self.publish_status(force=True)

        # 3. Mute
        elif action_type == "mute":
            m_cmd = payload.strip().lower()
            if m_cmd in ("toggle", "2"):
                if self.client.send_action("mute-toggle"):
                    self.mute_state = not self.mute_state
            elif m_cmd in ("true", "1", "on"):
                if self.client.send_action("mute-toggle"):
                    self.mute_state = True
            elif m_cmd in ("false", "0", "off"):
                if self.client.send_action("mute-toggle"):
                    self.mute_state = False
            self.persist_state()
            self.publish_status(force=True)

        # 4. Source
        elif action_type == "source":
            if payload:
                if self.client.send_action("source-selection", param=payload):
                    self.current_source = payload
                    self.persist_state()
                    self.publish_status(force=True)

        # 5. Key (Navigation & Playback)
        elif action_type == "key":
            if payload:
                self.client.send_action(payload)

        # 6. Wake-on-LAN
        elif action_type == "wol":
            wol_mac = mac or get_mac_from_arp(host)
            if wol_mac:
                send_wol(wol_mac)
            else:
                logger.warning("No MAC address configured for Wake-on-LAN")

        # 7. Zone
        elif action_type == "zone":
            if payload:
                self.current_zone = payload
                self.client.zone = payload
                self.publish_status(force=True)

        # 8. Heartbeat
        elif action_type == "heartbeat":
            self.client.send_action("heart-alive")
            logger.info("Sent heartbeat ping via MQTT request")

    def persist_state(self):
        """Save current estimated state to config file so it survives restarts."""
        state_sec = self.config.setdefault("state", {})
        state_sec["volume_estimate"] = self.volume_estimate
        state_sec["mute"] = self.mute_state
        state_sec["power"] = self.power_state
        state_sec["source"] = self.current_source
        save_config(self.config)

    def check_receiver_online(self) -> bool:
        if not self.client or not self.client.host:
            return False
        return self.client.is_reachable()

    def publish_status(self, force: bool = False):
        if not self.mqtt_client:
            return

        prefix = self.get_topic_prefix()
        state = {
            "online": self.is_online,
            "power": self.power_state,
            "volume": self.volume_estimate,
            "mute": self.mute_state,
            "source": self.current_source,
            "zone": self.current_zone,
            "host": self.client.host if self.client else "",
            "port": self.client.port if self.client else 10025,
            "timestamp": int(time.time())
        }

        # Check if anything changed
        if not force and state == self.last_published_state:
            return

        self.last_published_state = state.copy()

        self.mqtt_client.publish(f"{prefix}/status/online", payload="true" if self.is_online else "false", qos=1, retain=True)
        self.mqtt_client.publish(f"{prefix}/status/power", payload=self.power_state, qos=1, retain=True)
        self.mqtt_client.publish(f"{prefix}/status/volume", payload=str(self.volume_estimate), qos=1, retain=True)
        self.mqtt_client.publish(f"{prefix}/status/mute", payload="true" if self.mute_state else "false", qos=1, retain=True)
        self.mqtt_client.publish(f"{prefix}/status/source", payload=self.current_source, qos=1, retain=True)
        self.mqtt_client.publish(f"{prefix}/status/zone", payload=self.current_zone, qos=1, retain=True)
        self.mqtt_client.publish(f"{prefix}/status/json", payload=json.dumps(state), qos=1, retain=True)

        logger.debug(f"Published status to MQTT: online={self.is_online}, power={self.power_state}, vol={self.volume_estimate}, src={self.current_source}")

    def loop(self):
        global RUNNING
        logger.info("Starting Harman Kardon AVR background daemon loop...")

        while RUNNING:
            now = time.time()
            self.reload_config()

            poll_cfg = self.config.get("polling", {})
            interval = poll_cfg.get("interval_active", 5) if self.is_online else poll_cfg.get("interval_standby", 30)

            # Polling receiver availability
            if now - self.last_poll_time >= interval:
                self.last_poll_time = now
                was_online = self.is_online
                self.is_online = self.check_receiver_online()

                if self.is_online != was_online:
                    logger.info(f"Receiver reachability changed: {'ONLINE' if self.is_online else 'OFFLINE'}")
                    if self.is_online:
                        self.power_state = "ON"
                    else:
                        self.power_state = "OFF"
                    self.persist_state()
                    self.publish_status(force=True)

            # Keep-Alive Heartbeat
            rcv_cfg = self.config.get("receiver", {})
            if rcv_cfg.get("keep_alive_enabled", True) and self.is_online:
                hb_interval = rcv_cfg.get("keep_alive_interval", 60)
                if now - self.last_heartbeat_time >= hb_interval:
                    self.last_heartbeat_time = now
                    try:
                        self.client.send_action("heart-alive")
                        logger.debug("Sent periodic keep-alive heartbeat to receiver")
                    except Exception as e:
                        logger.warning(f"Error sending keep-alive heartbeat: {e}")

            time.sleep(1.0)

        # Cleanup on shutdown
        logger.info("Stopping daemon and cleaning up...")
        if self.mqtt_client:
            try:
                prefix = self.get_topic_prefix()
                self.mqtt_client.publish(f"{prefix}/status/daemon", payload="offline", qos=1, retain=True)
                self.mqtt_client.loop_stop()
                self.mqtt_client.disconnect()
            except Exception:
                pass
        if self.client:
            self.client.close()
        logger.info("Harman Kardon AVR daemon terminated cleanly.")


def main():
    daemon = HarmanKardonDaemon()
    daemon.reload_config()
    daemon.setup_mqtt()
    daemon.loop()


if __name__ == "__main__":
    main()
