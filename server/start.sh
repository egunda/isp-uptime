#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Server Start Script
# Starts Apache (if stopped) and launches PHP WebSocket daemon in background
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/ws_server.pid"
LOG_FILE="${SCRIPT_DIR}/ws_server.log"

echo "==========================================================="
echo "   Starting ISP Monitoring Server Services..."
echo "==========================================================="

# 1. Start Apache if available and not running
APACHE_STARTED=0
if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet apache2 2>/dev/null; then
        echo "[OK] Apache service (apache2) is already running."
        APACHE_STARTED=1
    elif systemctl is-active --quiet httpd 2>/dev/null; then
        echo "[OK] Apache service (httpd) is already running."
        APACHE_STARTED=1
    else
        echo "Attempting to start Apache service..."
        sudo systemctl start apache2 2>/dev/null || sudo systemctl start httpd 2>/dev/null || true
        if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then
            echo "[OK] Apache service started."
            APACHE_STARTED=1
        fi
    fi
elif command -v service >/dev/null 2>&1; then
    sudo service apache2 start 2>/dev/null || sudo service httpd start 2>/dev/null || true
fi

# Fallback: If Apache is not running / cannot be started by systemd, check if PHP built-in server can be launched
if [ "$APACHE_STARTED" -eq 0 ]; then
    echo "[INFO] Apache is not running as a system service."
    # Check if port 80 or 8000 is open
    PHP_WEB_PID_FILE="${SCRIPT_DIR}/php_web.pid"
    if [ -f "${PHP_WEB_PID_FILE}" ] && kill -0 "$(cat "${PHP_WEB_PID_FILE}")" 2>/dev/null; then
        echo "[OK] Fallback PHP web server already running on http://0.0.0.0:8000"
    else
        echo "Starting fallback standalone PHP web server on http://0.0.0.0:8000..."
        nohup php -S 0.0.0.0:8000 -t "${SCRIPT_DIR}/web" > "${SCRIPT_DIR}/php_web.log" 2>&1 &
        echo $! > "${PHP_WEB_PID_FILE}"
        echo "[OK] Standalone PHP web server started (PID: $(cat "${PHP_WEB_PID_FILE}"))"
    fi
fi

# 2. Start WebSocket Server Daemon
if [ -f "${PID_FILE}" ]; then
    PID=$(cat "${PID_FILE}")
    if kill -0 "${PID}" 2>/dev/null; then
        echo "[OK] WebSocket Server daemon is already running (PID: ${PID})."
    else
        echo "[WARN] Stale PID file found. Cleaning up..."
        rm -f "${PID_FILE}"
    fi
fi

if [ ! -f "${PID_FILE}" ]; then
    echo "Starting PHP WebSocket server daemon..."
    nohup php "${SCRIPT_DIR}/ws_server.php" > "${LOG_FILE}" 2>&1 &
    PID=$!
    echo "${PID}" > "${PID_FILE}"
    sleep 1

    if kill -0 "${PID}" 2>/dev/null; then
        echo "[OK] WebSocket Server daemon successfully started (PID: ${PID})."
    else
        echo "[ERROR] Failed to start WebSocket server. Check ${LOG_FILE} for details:"
        tail -n 10 "${LOG_FILE}"
        exit 1
    fi
fi

echo "==========================================================="
echo "   ISP Monitoring Server is ACTIVE! 🚀"
echo "==========================================================="
echo "WebSocket Daemon: ws://0.0.0.0:8080"
echo "Log File:        ${LOG_FILE}"
if [ "$APACHE_STARTED" -eq 1 ]; then
    echo "Web Dashboard:   http://localhost/isp-monitor/"
else
    echo "Web Dashboard:   http://localhost:8000/"
fi
echo "To stop the server: ./stop.sh"
echo "==========================================================="
