#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Master Installation Script
# Allows installing Server, Client, or Both
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==========================================================="
echo "   ⚡ ISP Downtime & Reliability Monitor - Setup Wizard"
echo "==========================================================="

CHOICE="$1"

if [ -z "$CHOICE" ]; then
    echo "What would you like to install on this machine?"
    echo "  1) Server  (Apache + PHP Web Dashboard + WebSocket Daemon)"
    echo "  2) Client  (ISP connection monitoring agent)"
    echo "  3) Both    (Full stack on this machine)"
    read -rp "Enter choice [1-3] (Default: 3): " INPUT_CHOICE
    case "$INPUT_CHOICE" in
        1) CHOICE="server" ;;
        2) CHOICE="client" ;;
        *) CHOICE="all" ;;
    esac
fi

case "$CHOICE" in
    server)
        echo "--> Running Server Installation..."
        bash "${SCRIPT_DIR}/server/install.sh"
        ;;
    client)
        echo "--> Running Client Installation..."
        bash "${SCRIPT_DIR}/client/install.sh"
        ;;
    all|both)
        echo "--> Running Server Installation..."
        bash "${SCRIPT_DIR}/server/install.sh"
        echo ""
        echo "--> Running Client Installation..."
        bash "${SCRIPT_DIR}/client/install.sh"
        ;;
    *)
        echo "Invalid choice. Usage: ./install.sh [server|client|all]"
        exit 1
        ;;
esac

echo ""
echo "Setup complete! You can start services using ./start.sh"
