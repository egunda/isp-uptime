#!/usr/bin/env python3
"""
ISP Monitor - Resilient, Self-Recovering WebSocket Client Daemon
Specially hardened for Raspberry Pi & embedded Linux environments.

Key Resilience & Self-Recovery Features:
1. In-Process Self-Recovery Watchdog: Catches unexpected crashes, logs full diagnostics,
   and restarts the monitoring agent without terminating the process.
2. Hardened Native RFC 6455 WebSocket Engine: Zero external dependencies, frame-length
   safety limits (prevents OOM crashes), full Ping/Pong & Close handling, TLS (wss://) support.
3. Linux TCP Keep-Alive: Quick detection of dead connections across router resets and WiFi drops.
4. Active Internet Connectivity Check: Tests 1.1.1.1 / 8.8.8.8 on connection drops to verify
   if the local ISP is truly down vs. the monitoring server being restarted/unreachable.
5. Safe Rotating Logs: Caps log size (default 2MB x 3 backups) to prevent Raspberry Pi SD card exhaustion.
6. Signal Immunity: Ignores SIGPIPE and SIGHUP (survives SSH disconnects and broken socket writes).
7. Periodic Memory Compaction: Explicit gc.collect() to maintain low RAM usage (< 15MB).
"""

import sys
import os
import time
import json
import socket
import logging
from logging.handlers import RotatingFileHandler
import signal
import traceback
import gc

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(CURRENT_DIR, "config.json")
LOG_FORMAT = "%(asctime)s [%(levelname)s] %(message)s"
logger = logging.getLogger("ISP-Client")

# Global flag for clean daemon shutdown
SHUTDOWN_REQUESTED = False


def load_config():
    """Loads configuration with safe fallbacks and default values."""
    defaults = {
        "server_url": "ws://127.0.0.1:8080",
        "isp_id": socket.gethostname(),
        "isp_name": socket.gethostname(),
        "token": "",
        "heartbeat_interval_seconds": 3,
        "reconnect_delay_seconds": 2,
        "log_file": "client.log",
        "max_log_size_mb": 2,
        "log_backup_count": 3,
        "verify_internet_on_disconnect": True
    }

    if not os.path.exists(CONFIG_FILE):
        logger.warning(f"Config file not found at {CONFIG_FILE}. Using defaults.")
        return defaults

    try:
        with open(CONFIG_FILE, "r") as f:
            user_config = json.load(f)
            defaults.update(user_config)
            return defaults
    except Exception as e:
        logger.error(f"Error reading {CONFIG_FILE}: {e}. Continuing with defaults.")
        return defaults


def setup_logging(config):
    """Configures rotating file logging and interactive console output without duplicate entries."""
    logger.setLevel(logging.INFO)
    logger.handlers.clear()

    formatter = logging.Formatter(LOG_FORMAT)

    # 1. Rotating File Handler (prevents SD card exhaustion on Raspberry Pi)
    log_file = config.get("log_file", "client.log")
    if log_file:
        log_path = os.path.join(CURRENT_DIR, log_file)
        max_bytes = int(config.get("max_log_size_mb", 2)) * 1024 * 1024
        backup_count = int(config.get("log_backup_count", 3))
        try:
            file_handler = RotatingFileHandler(log_path, maxBytes=max_bytes, backupCount=backup_count)
            file_handler.setFormatter(formatter)
            logger.addHandler(file_handler)
        except Exception as e:
            sys.stderr.write(f"Could not initialize log file {log_path}: {e}\n")

    # 2. Interactive Console Handler (only if stdout is a TTY and not redirected)
    if sys.stdout.isatty():
        console_handler = logging.StreamHandler(sys.stdout)
        console_handler.setFormatter(formatter)
        logger.addHandler(console_handler)


def verify_internet_connectivity(timeout=1.5):
    """
    Rapid, non-root check to verify if the local ISP has active internet access
    by connecting to public DNS resolvers (Cloudflare 1.1.1.1:53, Google 8.8.8.8:53).
    Returns True if internet is reachable, False otherwise.
    """
    targets = [("1.1.1.1", 53), ("8.8.8.8", 53)]
    for host, port in targets:
        s = None
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.settimeout(timeout)
            s.connect((host, port))
            return True
        except Exception:
            pass
        finally:
            if s:
                try:
                    s.close()
                except Exception:
                    pass
    return False


class NativeWebSocketClient:
    """
    Hardened RFC 6455 pure-Python WebSocket client (Zero external dependencies).
    Specially protected against OOM crashes, frame desynchronization, and silent socket deadlocks.
    """
    MAX_FRAME_SIZE = 1048576  # 1MB safety limit to prevent corrupted lengths from exhausting RAM

    def __init__(self, url, timeout=5):
        self.url = url
        self.timeout = timeout
        self.sock = None
        self.is_ssl = False
        self.host = ""
        self.port = 80
        self.path = "/"
        self._parse_url()

    def _parse_url(self):
        clean = self.url.strip()
        if clean.lower().startswith("wss://"):
            self.is_ssl = True
            clean = clean[6:]
            default_port = 443
        elif clean.lower().startswith("ws://"):
            self.is_ssl = False
            clean = clean[5:]
            default_port = 80
        else:
            default_port = 80

        parts = clean.split("/", 1)
        host_port = parts[0].split(":")
        self.host = host_port[0]
        self.port = int(host_port[1]) if len(host_port) > 1 else default_port
        self.path = "/" + (parts[1] if len(parts) > 1 else "")

    def connect(self):
        import base64
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)

        # Linux TCP Keep-Alive: Detects router restarts & dead links quickly
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        if hasattr(socket, 'TCP_KEEPIDLE'):
            try:
                self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_KEEPIDLE, 5)
            except OSError:
                pass
        if hasattr(socket, 'TCP_KEEPINTVL'):
            try:
                self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_KEEPINTVL, 3)
            except OSError:
                pass
        if hasattr(socket, 'TCP_KEEPCNT'):
            try:
                self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_KEEPCNT, 3)
            except OSError:
                pass

        self.sock.settimeout(self.timeout)
        self.sock.connect((self.host, self.port))

        # TLS wrap if wss://
        if self.is_ssl:
            import ssl
            context = ssl.create_default_context()
            self.sock = context.wrap_socket(self.sock, server_hostname=self.host)

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
        if not self.sock:
            raise ConnectionError("Socket is not connected")
        payload = message.encode('utf-8')
        self._send_frame(0x1, payload)

    def _send_frame(self, opcode, payload):
        length = len(payload)
        header = bytearray([0x80 | (opcode & 0x0F)])  # FIN + Opcode

        if length <= 125:
            header.append(0x80 | length)  # Mask bit set
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

    def recv(self, timeout=None):
        """
        Reads and parses incoming frame from server.
        Handles Ping/Pong, Close, and length bounds checking safely.
        """
        if not self.sock:
            raise ConnectionError("Socket is not connected")

        orig_timeout = self.sock.gettimeout()
        if timeout is not None:
            self.sock.settimeout(timeout)

        try:
            header = self._recv_exact(2)
            b1, b2 = header[0], header[1]
            opcode = b1 & 0x0F
            masked = (b2 & 0x80) != 0
            length = b2 & 0x7F

            if length == 126:
                length_bytes = self._recv_exact(2)
                length = int.from_bytes(length_bytes, 'big')
            elif length == 127:
                length_bytes = self._recv_exact(8)
                length = int.from_bytes(length_bytes, 'big')

            # Protection against corrupted/malicious frame lengths exhausting Raspberry Pi memory
            if length > self.MAX_FRAME_SIZE:
                raise ConnectionError(f"Frame length ({length} bytes) exceeds safety limit ({self.MAX_FRAME_SIZE} bytes)")

            mask_key = self._recv_exact(4) if masked else None
            payload = self._recv_exact(length)

            if masked:
                payload = bytearray(b ^ mask_key[i % 4] for i, b in enumerate(payload))

            # Opcode Handling
            if opcode == 0x8:  # Close frame
                try:
                    # Echo close frame back
                    self._send_frame(0x8, payload)
                except Exception:
                    pass
                raise ConnectionError("Server sent close frame")

            elif opcode == 0x9:  # Ping frame -> echo payload in Pong frame (RFC 6455)
                self._send_frame(0xA, payload)
                return self.recv(timeout=timeout)

            elif opcode == 0xA:  # Pong frame -> ignore and read next
                return self.recv(timeout=timeout)

            return payload.decode('utf-8', errors='ignore')

        finally:
            if timeout is not None and self.sock:
                try:
                    self.sock.settimeout(orig_timeout)
                except Exception:
                    pass

    def _recv_exact(self, num_bytes):
        data = bytearray()
        while len(data) < num_bytes:
            try:
                packet = self.sock.recv(num_bytes - len(data))
            except socket.timeout:
                raise TimeoutError("Socket timed out while waiting for data")
            if not packet:
                raise ConnectionError("Socket closed prematurely by peer")
            data.extend(packet)
        return data

    def close(self):
        if self.sock:
            try:
                self.sock.shutdown(socket.SHUT_RDWR)
            except Exception:
                pass
            try:
                self.sock.close()
            except Exception:
                pass
            self.sock = None


class IspClientAgent:
    """
    Main ISP Monitoring Client Agent.
    Maintains persistent WebSocket connection and handles keep-alive heartbeats.
    """
    def __init__(self, config):
        self.config = config
        self.server_url = config.get("server_url", "ws://127.0.0.1:8080")
        self.isp_id = config.get("isp_id", socket.gethostname())
        self.isp_name = config.get("isp_name", self.isp_id)
        self.token = config.get("token", "")
        self.heartbeat_interval = int(config.get("heartbeat_interval_seconds", 3))
        self.reconnect_delay = int(config.get("reconnect_delay_seconds", 2))
        self.verify_internet = bool(config.get("verify_internet_on_disconnect", True))
        self.running = True
        self.ws = None
        self.use_library = False
        self.last_rtt_ms = None
        self.heartbeat_count = 0

        try:
            import websocket
            self.use_library = True
            logger.info("Using 'websocket-client' library.")
        except ImportError:
            logger.info("Using native pure-Python WebSocket client (Zero dependencies).")

    def run(self):
        logger.info("======================================================")
        logger.info(f"ISP Self-Recovering Pure-WebSocket Client Started")
        logger.info(f"ISP: '{self.isp_name}' ({self.isp_id})")
        logger.info(f"Target Server: {self.server_url}")
        logger.info(f"Keep-Alive Interval: {self.heartbeat_interval}s")
        logger.info("======================================================")

        local_outage_start = None
        is_true_isp_outage = False

        while self.running and not SHUTDOWN_REQUESTED:
            try:
                logger.info(f"Connecting to WebSocket server at {self.server_url}...")
                if self.use_library:
                    import websocket
                    self.ws = websocket.create_connection(self.server_url, timeout=5)
                else:
                    self.ws = NativeWebSocketClient(self.server_url, timeout=5)
                    self.ws.connect()

                now = time.time()
                if local_outage_start is not None:
                    downtime_sec = int(now - local_outage_start)
                    logger.info(f">>> [UP] WebSocket Connection Restored! Outage was ~{downtime_sec}s")
                    self.send_registration()
                    if is_true_isp_outage or not self.verify_internet:
                        self.send_outage_report(int(local_outage_start), int(now))
                    else:
                        logger.info("Reconnected after server unreachable / client restart (Local ISP remained alive).")
                    local_outage_start = None
                    is_true_isp_outage = False
                else:
                    logger.info(f">>> [UP] WebSocket Connection Established. ISP is ONLINE.")
                    self.send_registration()

                # Heartbeat keep-alive loop
                while self.running and not SHUTDOWN_REQUESTED:
                    time.sleep(self.heartbeat_interval)
                    self.send_heartbeat()

                    # Periodic memory cleanup every 100 heartbeats (~5 mins) for Raspberry Pi
                    self.heartbeat_count += 1
                    if self.heartbeat_count % 100 == 0:
                        gc.collect()

            except (KeyboardInterrupt, SystemExit):
                logger.info("Termination requested during run loop.")
                break

            except Exception as e:
                now = time.time()
                if local_outage_start is None:
                    local_outage_start = now
                    # Verify whether the local internet is actually down, or just the WS server
                    if self.verify_internet:
                        internet_ok = verify_internet_connectivity(timeout=1.5)
                        if internet_ok:
                            is_true_isp_outage = False
                            logger.warning(f"<<< [NOTICE] WebSocket server unreachable ({e}), but local ISP is UP (1.1.1.1 OK). Server may be restarting.")
                        else:
                            is_true_isp_outage = True
                            logger.warning(f"<<< [DOWN] WebSocket Connection Lost ({e}) & Local Internet is OFFLINE! True ISP Outage.")
                    else:
                        logger.warning(f"<<< [DOWN] WebSocket Connection Lost ({e})! ISP is OFFLINE.")

                self.cleanup()
                time.sleep(self.reconnect_delay)

    def send_registration(self):
        if not self.ws:
            return
        payload = {
            "type": "register",
            "isp_id": self.isp_id,
            "isp_name": self.isp_name,
            "token": self.token,
            "client_timestamp": int(time.time())
        }
        try:
            self.ws.send(json.dumps(payload))
            # Wait up to 3s for registration ack
            resp = self._recv_with_timeout(3.0)
            if resp:
                logger.info(f"Server acknowledged registration for '{self.isp_name}'")
        except Exception as e:
            logger.debug(f"Registration send/ack error: {e}")

    def send_heartbeat(self):
        if not self.ws:
            raise ConnectionError("No active WebSocket connection")

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

        # Read heartbeat ACK with a tight 2s timeout for WebSocket RTT calculation
        try:
            resp = self._recv_with_timeout(2.0)
            if resp:
                data = json.loads(resp)
                if data.get("type") == "heartbeat_ack" and "client_time_ms" in data:
                    rtt = (time.time() * 1000) - data["client_time_ms"]
                    self.last_rtt_ms = round(max(0.1, rtt), 1)
        except (TimeoutError, socket.timeout):
            # Packet dropped or delayed; keep connection alive
            pass
        except json.JSONDecodeError:
            pass

    def _recv_with_timeout(self, timeout_sec):
        if not self.ws:
            return None
        if hasattr(self.ws, 'recv'):
            if self.use_library:
                orig_timeout = self.ws.gettimeout() if hasattr(self.ws, 'gettimeout') else None
                try:
                    if hasattr(self.ws, 'settimeout'):
                        self.ws.settimeout(timeout_sec)
                    return self.ws.recv()
                finally:
                    if orig_timeout and hasattr(self.ws, 'settimeout'):
                        try:
                            self.ws.settimeout(orig_timeout)
                        except Exception:
                            pass
            else:
                return self.ws.recv(timeout=timeout_sec)
        return None

    def send_outage_report(self, down_ts, up_ts):
        if not self.ws:
            return
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

    def cleanup(self):
        if self.ws:
            try:
                self.ws.close()
            except Exception:
                pass
            self.ws = None

    def stop(self):
        self.running = False
        self.cleanup()


def main():
    global SHUTDOWN_REQUESTED

    # Protect against Linux kernel terminating process on broken socket or SSH logout
    if hasattr(signal, 'SIGPIPE'):
        signal.signal(signal.SIGPIPE, signal.SIG_IGN)
    if hasattr(signal, 'SIGHUP'):
        signal.signal(signal.SIGHUP, signal.SIG_IGN)

    current_agent = None

    def handle_sig(sig, frame):
        global SHUTDOWN_REQUESTED
        sig_name = signal.Signals(sig).name if hasattr(signal, 'Signals') else str(sig)
        logger.info(f"Shutdown signal ({sig_name}) received. Exiting gracefully...")
        SHUTDOWN_REQUESTED = True
        if current_agent:
            current_agent.stop()
        sys.exit(0)

    signal.signal(signal.SIGINT, handle_sig)
    signal.signal(signal.SIGTERM, handle_sig)

    # Master In-Process Self-Recovery Loop
    # Guarantees that any unexpected Python crash or exception is caught, logged,
    # and automatically restarted without permanently dropping the client daemon.
    consecutive_crashes = 0
    max_backoff = 30

    while not SHUTDOWN_REQUESTED:
        try:
            config = load_config()
            setup_logging(config)

            current_agent = IspClientAgent(config)
            current_agent.run()
            # Clean exit
            break

        except (KeyboardInterrupt, SystemExit):
            logger.info("Client daemon stopped cleanly.")
            break

        except BaseException as e:
            consecutive_crashes += 1
            backoff = min(max_backoff, max(2, consecutive_crashes * 2))
            logger.error(
                f"[SELF-RECOVERY] Uncaught exception in client daemon ({type(e).__name__}: {e}).\n"
                f"{traceback.format_exc()}\n"
                f"Auto-restarting agent in {backoff}s (Crash attempt #{consecutive_crashes})..."
            )
            try:
                if current_agent:
                    current_agent.cleanup()
            except Exception:
                pass
            time.sleep(backoff)


if __name__ == "__main__":
    main()
