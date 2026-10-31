#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Client Stop Script
# Gracefully stops the client daemon
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/client.pid"

echo "==========================================================="
echo "   Stopping ISP Monitoring Client Daemon..."
echo "==========================================================="

if [ -f "${PID_FILE}" ]; then
    PID=$(cat "${PID_FILE}")
    if kill -0 "${PID}" 2>/dev/null; then
        echo "Stopping client daemon (PID: ${PID})..."
        kill -TERM "${PID}" 2>/dev/null || true
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
        echo "[OK] Client daemon stopped."
    else
        echo "[INFO] Client daemon (PID: ${PID}) was not running."
    fi
    rm -f "${PID_FILE}"
else
    echo "[INFO] No client PID file found (${PID_FILE})."
fi

echo "==========================================================="
