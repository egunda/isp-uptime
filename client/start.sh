#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Client Start Script
# Launches Python client monitoring daemon in the background
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/client.pid"
LOG_FILE="${SCRIPT_DIR}/client.log"

echo "==========================================================="
echo "   Starting ISP Monitoring Client Daemon..."
echo "==========================================================="

if [ -f "${PID_FILE}" ]; then
    PID=$(cat "${PID_FILE}")
    if kill -0 "${PID}" 2>/dev/null; then
        echo "[OK] Client daemon is already running (PID: ${PID})."
        echo "Log output: ${LOG_FILE}"
        exit 0
    else
        echo "[WARN] Stale PID file found. Cleaning up..."
        rm -f "${PID_FILE}"
    fi
fi

# Verify config.json exists
if [ ! -f "${SCRIPT_DIR}/config.json" ]; then
    echo "[ERROR] ${SCRIPT_DIR}/config.json not found! Please run ./install.sh first."
    exit 1
fi

echo "Launching client daemon in background..."
nohup python3 "${SCRIPT_DIR}/client.py" >> "${LOG_FILE}" 2>&1 &
PID=$!
echo "${PID}" > "${PID_FILE}"
sleep 1

if kill -0 "${PID}" 2>/dev/null; then
    echo "[OK] Client daemon successfully started (PID: ${PID})."
    echo "Logs are being written to: ${LOG_FILE}"
    echo ""
    echo "To view live logs:"
    echo "   tail -f ${LOG_FILE}"
    echo ""
    echo "To stop the client:"
    echo "   ./stop.sh"
else
    echo "[ERROR] Failed to start client daemon. Check ${LOG_FILE}:"
    tail -n 10 "${LOG_FILE}"
    exit 1
fi

echo "==========================================================="
