#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Server Stop Script
# Gracefully stops the WebSocket daemon and fallback web server
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/ws_server.pid"
PHP_WEB_PID_FILE="${SCRIPT_DIR}/php_web.pid"

echo "==========================================================="
echo "   Stopping ISP Monitoring Server Services..."
echo "==========================================================="

STOPPED=0

# Stop WebSocket Server
if [ -f "${PID_FILE}" ]; then
    PID=$(cat "${PID_FILE}")
    if kill -0 "${PID}" 2>/dev/null; then
        echo "Stopping WebSocket server daemon (PID: ${PID})..."
        kill -TERM "${PID}" 2>/dev/null || true
        # Wait up to 5 seconds for termination
        for i in {1..5}; do
            if ! kill -0 "${PID}" 2>/dev/null; then
                break
            fi
            sleep 1
        done
        if kill -0 "${PID}" 2>/dev/null; then
            echo "Force killing PID ${PID}..."
            kill -9 "${PID}" 2>/dev/null || true
        fi
        echo "[OK] WebSocket server stopped."
        STOPPED=1
    else
        echo "[INFO] WebSocket server (PID ${PID}) was not running."
    fi
    rm -f "${PID_FILE}"
else
    echo "[INFO] No WebSocket PID file found."
fi

# Stop fallback PHP web server if running
if [ -f "${PHP_WEB_PID_FILE}" ]; then
    WEB_PID=$(cat "${PHP_WEB_PID_FILE}")
    if kill -0 "${WEB_PID}" 2>/dev/null; then
        echo "Stopping fallback PHP web server (PID: ${WEB_PID})..."
        kill -TERM "${WEB_PID}" 2>/dev/null || true
        echo "[OK] Fallback PHP web server stopped."
        STOPPED=1
    fi
    rm -f "${PHP_WEB_PID_FILE}"
fi

echo "==========================================================="
echo "   Server services stopped successfully."
echo "==========================================================="
