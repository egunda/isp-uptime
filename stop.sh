#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Master Stop Script
# Stops Server, Client, or Both
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-all}"

case "$TARGET" in
    server)
        bash "${SCRIPT_DIR}/server/stop.sh"
        ;;
    client)
        bash "${SCRIPT_DIR}/client/stop.sh"
        ;;
    all|both)
        if [ -f "${SCRIPT_DIR}/client/stop.sh" ]; then
            bash "${SCRIPT_DIR}/client/stop.sh"
            echo ""
        fi
        if [ -f "${SCRIPT_DIR}/server/stop.sh" ]; then
            bash "${SCRIPT_DIR}/server/stop.sh"
        fi
        ;;
    *)
        echo "Usage: ./stop.sh [server|client|all]"
        exit 1
        ;;
esac
