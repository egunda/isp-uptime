#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Client Stop Script
# Gracefully stops client supervisor, daemon, and/or systemd service
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="${SCRIPT_DIR}/client.pid"

echo "==========================================================="
echo "   Stopping ISP Monitoring Client..."
echo "==========================================================="

STOPPED=0

# 1. Stop systemd service if running
if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet isp-client.service 2>/dev/null; then
        echo "Stopping systemd service (isp-client.service)..."
        SUDO=""
        if [ "$(id -u)" -ne 0 ] && command -v sudo >/dev/null 2>&1; then
            SUDO="sudo"
        fi
        $SUDO systemctl stop isp-client.service 2>/dev/null || true
        echo "[OK] Systemd service stopped."
        STOPPED=1
    fi
fi

# 2. Stop standalone supervisor & python client
if [ -f "${PID_FILE}" ]; then
    PID=$(cat "${PID_FILE}")
    if kill -0 "${PID}" 2>/dev/null; then
        echo "Stopping supervisor/client process (PID: ${PID})..."
        
        # Find children of PID
        CHILD_PIDS=$(pgrep -P "${PID}" 2>/dev/null || true)
        
        # Send SIGTERM to supervisor and children
        kill -TERM "${PID}" 2>/dev/null || true
        for CPID in ${CHILD_PIDS}; do
            kill -TERM "${CPID}" 2>/dev/null || true
        done

        # Also find any stray client.py matching this directory
        pkill -f "python3 ${SCRIPT_DIR}/client.py" 2>/dev/null || true

        # Wait up to 5 seconds
        for i in {1..5}; do
            if ! kill -0 "${PID}" 2>/dev/null; then
                break
            fi
            sleep 1
        done

        if kill -0 "${PID}" 2>/dev/null; then
            echo "Force killing PID ${PID}..."
            kill -9 "${PID}" 2>/dev/null || true
            for CPID in ${CHILD_PIDS}; do
                kill -9 "${CPID}" 2>/dev/null || true
            done
        fi
        echo "[OK] Client daemon stopped."
        STOPPED=1
    else
        echo "[INFO] Stale PID file (${PID}) found."
    fi
    rm -f "${PID_FILE}"
fi

# Double check any lingering client.py for this directory
STRAY_PIDS=$(pgrep -f "python3 ${SCRIPT_DIR}/client.py" 2>/dev/null || true)
if [ -n "${STRAY_PIDS}" ]; then
    echo "Cleaning up lingering client instances (${STRAY_PIDS})..."
    kill -TERM ${STRAY_PIDS} 2>/dev/null || true
    sleep 1
    kill -9 ${STRAY_PIDS} 2>/dev/null || true
    STOPPED=1
fi

if [ ${STOPPED} -eq 0 ]; then
    echo "[INFO] No active client process or service was running."
fi

echo "==========================================================="
