#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Resilient Client Start Script
# Launches client daemon with built-in auto-recovery supervisor
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/client.pid"
LOG_FILE="${SCRIPT_DIR}/client.log"

echo "==========================================================="
echo "   Starting ISP Monitoring Client (Self-Recovering)..."
echo "==========================================================="

# Check if systemd service is running
if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet isp-client.service 2>/dev/null; then
        echo "[OK] Client is already running as a managed systemd service (isp-client.service)!"
        echo "Status: sudo systemctl status isp-client"
        echo "Logs:   tail -f ${LOG_FILE}"
        exit 0
    fi
fi

# Check existing standalone PID
if [ -f "${PID_FILE}" ]; then
    PID=$(cat "${PID_FILE}")
    if kill -0 "${PID}" 2>/dev/null; then
        echo "[OK] Client daemon / supervisor is already running (PID: ${PID})."
        echo "Log output: ${LOG_FILE}"
        exit 0
    else
        echo "[WARN] Stale PID file found (${PID}). Cleaning up..."
        rm -f "${PID_FILE}"
    fi
fi

# Verify config.json exists
if [ ! -f "${SCRIPT_DIR}/config.json" ]; then
    echo "[ERROR] ${SCRIPT_DIR}/config.json not found! Please run ./install.sh first."
    exit 1
fi

echo "Launching self-recovering supervisor and client daemon..."

nohup bash "${SCRIPT_DIR}/supervisor.sh" >> "${LOG_FILE}" 2>&1 &
SUPERVISOR_PID=$!
disown "${SUPERVISOR_PID}" 2>/dev/null || true

echo "${SUPERVISOR_PID}" > "${PID_FILE}"
sleep 1

if kill -0 "${SUPERVISOR_PID}" 2>/dev/null; then
    echo "[OK] Client supervisor successfully started (PID: ${SUPERVISOR_PID})."
    echo "Logs are being written to: ${LOG_FILE}"
    echo ""
    echo "Self-Recovery Status: ACTIVE"
    echo "  - In-process crash guard:   ACTIVE"
    echo "  - Process supervisor loop:  ACTIVE"
    echo ""
    echo "To view live logs:"
    echo "   tail -f ${LOG_FILE}"
    echo ""
    echo "To stop the client:"
    echo "   ./stop.sh"
    echo ""
    echo "💡 For 24/7 Raspberry Pi auto-start on boot, run:"
    echo "   ./install-service.sh"
else
    echo "[ERROR] Failed to start client daemon. Check ${LOG_FILE}:"
    tail -n 10 "${LOG_FILE}"
    exit 1
fi

echo "==========================================================="
