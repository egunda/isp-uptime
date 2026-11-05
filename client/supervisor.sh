#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Standalone Process Supervisor Watchdog
# Automatically respawns client.py if it ever terminates unexpectedly
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/client.log"

CHILD_PID=""

# Forward shutdown signals (SIGTERM / SIGINT) to child process
cleanup() {
    if [ -n "${CHILD_PID}" ]; then
        kill -TERM "${CHILD_PID}" 2>/dev/null || true
    fi
    exit 0
}

# Ignore SIGHUP so SSH disconnects/terminal closures NEVER terminate supervisor
trap '' SIGHUP

# Handle clean shutdown requests
trap cleanup SIGTERM SIGINT

while true; do
    python3 "${SCRIPT_DIR}/client.py" >> "${LOG_FILE}" 2>&1 &
    CHILD_PID=$!
    wait ${CHILD_PID}
    EXIT_CODE=$?

    # Clean exit: 0 or signal termination (128+15=143, 128+2=130)
    if [ $EXIT_CODE -eq 0 ] || [ $EXIT_CODE -eq 143 ] || [ $EXIT_CODE -eq 130 ]; then
        break
    fi

    echo "$(date '+%Y-%m-%d %H:%M:%S') [SUPERVISOR] Client terminated (exit code ${EXIT_CODE}). Auto-respawning in 2s..." >> "${LOG_FILE}"
    sleep 2
done
