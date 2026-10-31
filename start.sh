#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Master Start Script
# Starts Server, Client, or Both
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-all}"

case "$TARGET" in
    server)
        bash "${SCRIPT_DIR}/server/start.sh"
        ;;
    client)
        bash "${SCRIPT_DIR}/client/start.sh"
        ;;
    all|both)
        if [ -f "${SCRIPT_DIR}/server/ws_server.php" ]; then
            bash "${SCRIPT_DIR}/server/start.sh"
            echo ""
        fi
        if [ -f "${SCRIPT_DIR}/client/client.py" ]; then
            bash "${SCRIPT_DIR}/client/start.sh"
        fi
        ;;
    *)
        echo "Usage: ./start.sh [server|client|all]"
        exit 1
        ;;
esac
