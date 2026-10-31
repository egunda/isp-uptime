#!/usr/bin/env python3
"""
ISP Monitor - Pure WebSocket Client Daemon
Maintains persistent WebSocket connection to the monitoring server.
Connection drop = DOWN, Connection established = UP.
Zero external ping dependencies required.
"""

import sys
import os
import time
import json
import socket
import logging
import signal

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(CURRENT_DIR, "config.json")

LOG_FORMAT = "%(asctime)s [%(levelname)s] %(message)s"
logging.basicConfig(level=logging.INFO, format=LOG_FORMAT)
logger = logging.getLogger("ISP-Client")

def load_config():
    if not os.path.exists(CONFIG_FILE):
        logger.error(f"Config file not found at {CONFIG_FILE}.")
        sys.exit(1)
    try:
        with open(CONFIG_FILE, "r") as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Error reading {CONFIG_FILE}: {e}")
        sys.exit(1)

class NativeWebSocketClient:
    """Minimal RFC 6455 pure-python WebSocket client fallback (Zero external dependencies)."""
    def __init__(self, url, timeout=5):
        self.url = url
        self.timeout = timeout
        self.sock = None
        self._parse_url()

    def _parse_url(self):
        clean = self.url.replace("ws://", "").replace("wss://", "")
        parts = clean.split("/", 1)
        host_port = parts[0].split(":")
        self.host = host_port[0]
        self.port = int(host_port[1]) if len(host_port) > 1 else 80
        self.path = "/" + (parts[1] if len(parts) > 1 else "")

    def connect(self):
        import base64
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect((self.host, self.port))

        sec_key = base64.b64encode(os.urandom(16)).decode('ascii')
        handshake = (
            f"GET {self.path} HTTP/1.1\r\n"
            f"Host: {self.host}:{self.port}\r\n"
            f"Upgrade: websocket\r\n"
            f"Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {sec_key}\r\n"
            f"Sec-WebSocket-Version: 13\r\n\r\n"
        )
        self.sock.sendall(handshake.encode('utf-8'))

        response = b""
        while b"\r\n\r\n" not in response:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError("Connection closed during WebSocket handshake")
            response += chunk

        if b"101 Switching Protocols" not in response:
            raise ConnectionError(f"Handshake failed: {response.decode('utf-8', errors='ignore')}")

    def send(self, message):
        """Encodes and sends masked text frame as per RFC 6455."""
        payload = message.encode('utf-8')
        length = len(payload)

        header = bytearray([0x81])
        if length <= 125:
            header.append(0x80 | length)
        elif length <= 65535:
            header.append(0x80 | 126)
            header.extend(length.to_bytes(2, 'big'))
        else:
            header.append(0x80 | 127)
            header.extend(length.to_bytes(8, 'big'))

        mask_key = os.urandom(4)
        header.extend(mask_key)
        masked_payload = bytearray(b ^ mask_key[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(header + masked_payload)

    def recv(self):
        """Reads frame from server."""
        header = self._recv_exact(2)
        b1, b2 = header[0], header[1]
        opcode = b1 & 0x0F
        masked = (b2 & 0x80) != 0
        length = b2 & 0x7F

        if length == 126:
            length = int.from_bytes(self._recv_exact(2), 'big')
        elif length == 127:
            length = int.from_bytes(self._recv_exact(8), 'big')

        mask_key = self._recv_exact(4) if masked else None
        payload = self._recv_exact(length)

        if masked:
            payload = bytearray(b ^ mask_key[i % 4] for i, b in enumerate(payload))

        if opcode == 0x8: # close
            raise ConnectionError("Server sent close frame")
        elif opcode == 0x9: # ping -> send pong
            pong = bytearray([0x8A, 0x80]) + os.urandom(4)
            self.sock.sendall(pong)
            return self.recv()

        return payload.decode('utf-8', errors='ignore')

    def _recv_exact(self, num_bytes):
        data = bytearray()
        while len(data) < num_bytes:
            packet = self.sock.recv(num_bytes - len(data))
            if not packet:
                raise ConnectionError("Socket closed prematurely")
            data.extend(packet)
        return data

    def close(self):
        if self.sock:
            try:
                self.sock.close()
            except Exception:
                pass
            self.sock = None

class IspClientAgent:
    def __init__(self, config):
        self.config = config
        self.server_url = config.get("server_url", "ws://127.0.0.1:8080")
        self.isp_id = config.get("isp_id", socket.gethostname())
        self.isp_name = config.get("isp_name", self.isp_id)
        self.token = config.get("token", "")
        self.heartbeat_interval = config.get("heartbeat_interval_seconds", 3)
        self.reconnect_delay = config.get("reconnect_delay_seconds", 2)
        self.running = True
        self.ws = None
        self.use_library = False
        self.last_rtt_ms = None

        try:
            import websocket
            self.use_library = True
            logger.info("Using 'websocket-client' library.")
        except ImportError:
            logger.info("Using native pure-Python WebSocket client (Zero dependencies).")

    def run(self):
        logger.info("======================================================")
        logger.info(f"ISP Pure-WebSocket Client Monitor Started")
        logger.info(f"ISP: '{self.isp_name}' ({self.isp_id})")
        logger.info(f"Target Server: {self.server_url}")
        logger.info(f"Keep-Alive Interval: {self.heartbeat_interval}s")
        logger.info("======================================================")

        local_outage_start = None

        while self.running:
            try:
                logger.info(f"Connecting to WebSocket server at {self.server_url}...")
                if self.use_library:
                    import websocket
                    self.ws = websocket.create_connection(self.server_url, timeout=5)
                else:
                    self.ws = NativeWebSocketClient(self.server_url, timeout=5)
                    self.ws.connect()

                # Connection established -> ISP is UP!
                now = time.time()
                if local_outage_start is not None:
                    downtime_sec = int(now - local_outage_start)
                    logger.info(f">>> [UP] WebSocket Connection Restored! Outage was ~{downtime_sec}s")
                    self.send_registration()
                    self.send_outage_report(int(local_outage_start), int(now))
                    local_outage_start = None
                else:
                    logger.info(f">>> [UP] WebSocket Connection Established. ISP is ONLINE.")
                    self.send_registration()

                # Heartbeat keep-alive loop
                while self.running:
                    time.sleep(self.heartbeat_interval)
                    self.send_heartbeat()

            except Exception as e:
                if local_outage_start is None:
                    local_outage_start = time.time()
                    logger.warning(f"<<< [DOWN] WebSocket Connection Lost ({e})! ISP is OFFLINE.")

                if self.ws:
                    try:
                        self.ws.close()
                    except Exception:
                        pass
                    self.ws = None

                time.sleep(self.reconnect_delay)

    def send_registration(self):
        payload = {
            "type": "register",
            "isp_id": self.isp_id,
            "isp_name": self.isp_name,
            "token": self.token,
            "client_timestamp": int(time.time())
        }
        self.ws.send(json.dumps(payload))
        # Wait for registration ack
        try:
            resp = self.ws.recv()
            logger.info(f"Server acknowledged registration for '{self.isp_name}'")
        except Exception:
            pass

    def send_heartbeat(self):
        now_ms = int(time.time() * 1000)
        payload = {
            "type": "heartbeat",
            "isp_id": self.isp_id,
            "isp_name": self.isp_name,
            "client_time_ms": now_ms,
            "ws_latency_ms": self.last_rtt_ms,
            "client_timestamp": int(time.time())
        }
        self.ws.send(json.dumps(payload))

        # Read ack to calculate true end-to-end WebSocket round-trip time
        try:
            resp = self.ws.recv()
            if resp:
                data = json.loads(resp)
                if data.get("type") == "heartbeat_ack" and "client_time_ms" in data:
                    rtt = (time.time() * 1000) - data["client_time_ms"]
                    self.last_rtt_ms = round(max(0.1, rtt), 1)
        except Exception:
            pass

    def send_outage_report(self, down_ts, up_ts):
        payload = {
            "type": "outage_report",
            "isp_id": self.isp_id,
            "isp_name": self.isp_name,
            "down_timestamp": down_ts,
            "up_timestamp": up_ts,
            "reason": "WebSocket connection dropped by client network"
        }
        try:
            self.ws.send(json.dumps(payload))
            logger.info(f"Sent outage reconciliation report: {up_ts - down_ts}s")
        except Exception:
            pass

    def stop(self):
        self.running = False
        if self.ws:
            try:
                self.ws.close()
            except Exception:
                pass

def main():
    config = load_config()

    log_file = config.get("log_file")
    if log_file:
        log_path = os.path.join(CURRENT_DIR, log_file)
        file_handler = logging.FileHandler(log_path)
        file_handler.setFormatter(logging.Formatter(LOG_FORMAT))
        logger.addHandler(file_handler)

    agent = IspClientAgent(config)

    def handle_sig(sig, frame):
        logger.info("Shutdown signal received. Exiting...")
        agent.stop()
        sys.exit(0)

    signal.signal(signal.SIGINT, handle_sig)
    signal.signal(signal.SIGTERM, handle_sig)

    agent.run()

if __name__ == "__main__":
    main()
