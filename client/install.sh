#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Client Installation Script
# Handles fresh Linux environments: checks/installs Python 3 and dependencies.
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==========================================================="
echo "   ISP Monitoring Client - Installation & Dependency Check"
echo "==========================================================="

# Check root / sudo
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    fi
fi

# 1. Check Python 3
echo "[1/3] Checking Python 3..."
if ! command -v python3 >/dev/null 2>&1; then
    echo "Python 3 not found. Installing..."
    if command -v apt-get >/dev/null 2>&1; then
        $SUDO apt-get update -y
        $SUDO apt-get install -y python3 python3-pip
    elif command -v dnf >/dev/null 2>&1; then
        $SUDO dnf install -y python3 python3-pip
    elif command -v yum >/dev/null 2>&1; then
        $SUDO yum install -y python3 python3-pip
    elif command -v pacman >/dev/null 2>&1; then
        $SUDO pacman -Sy --noconfirm python python-pip
    elif command -v apk >/dev/null 2>&1; then
        $SUDO apk add --no-cache python3 py3-pip
    fi
fi

if command -v python3 >/dev/null 2>&1; then
    echo "[OK] Python 3 is available: $(python3 --version)"
else
    echo "[ERROR] Python 3 could not be installed. Please install Python 3 manually."
    exit 1
fi

# 2. Try to install websocket-client (Optional - client.py has built-in zero-dependency fallback)
echo "[2/3] Checking WebSocket client dependencies..."
INSTALL_SUCCESS=0

if python3 -c "import websocket" 2>/dev/null; then
    echo "[OK] 'websocket-client' is already installed."
    INSTALL_SUCCESS=1
else
    echo "Attempting to install 'websocket-client' via pip..."
    if command -v pip3 >/dev/null 2>&1 || command -v pip >/dev/null 2>&1; then
        pip3 install -r "${SCRIPT_DIR}/requirements.txt" --break-system-packages 2>/dev/null || \
        pip3 install -r "${SCRIPT_DIR}/requirements.txt" 2>/dev/null || \
        pip install -r "${SCRIPT_DIR}/requirements.txt" 2>/dev/null || true
    fi

    if python3 -c "import websocket" 2>/dev/null; then
        echo "[OK] Successfully installed 'websocket-client'."
        INSTALL_SUCCESS=1
    else
        echo "[INFO] pip installation skipped or restricted. Client will use the built-in native pure-Python WebSocket fallback (Zero external dependencies needed!)."
    fi
fi

# 3. Setup Permissions & Config
echo "[3/3] Setting permissions and verifying configuration..."
chmod +x "${SCRIPT_DIR}/client.py"
chmod +x "${SCRIPT_DIR}/start.sh"
chmod +x "${SCRIPT_DIR}/stop.sh"

if [ ! -f "${SCRIPT_DIR}/config.json" ]; then
    echo "Creating default config.json..."
    cat << 'EOF' > "${SCRIPT_DIR}/config.json"
{
  "server_url": "ws://127.0.0.1:8080",
  "isp_id": "isp_1",
  "isp_name": "Primary Fiber ISP",
  "token": "",
  "heartbeat_interval_seconds": 3,
  "reconnect_delay_seconds": 2,
  "log_file": "client.log"
}
EOF
fi

echo ""
echo "==========================================================="
echo "   Client Installation Completed Successfully! 🎉"
echo "==========================================================="
echo "Next Steps:"
echo "1. Edit configuration file: ${SCRIPT_DIR}/config.json"
echo "   Set 'server_url' to your monitoring server IP (e.g. ws://YOUR_SERVER_IP:8080)"
echo "   Set 'isp_name' to your ISP identifier (e.g. Airtel Fiber, Jio, etc.)"
echo ""
echo "2. Start the client daemon:"
echo "   cd ${SCRIPT_DIR} && ./start.sh"
echo "==========================================================="
