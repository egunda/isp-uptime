#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Uninstall Systemd Auto-Recovery Service
# ==============================================================================

SERVICE_NAME="isp-client"
TARGET_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

echo "==========================================================="
echo "   ISP Monitor - Systemd Service Uninstaller"
echo "==========================================================="

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    else
        echo "[ERROR] Root privileges or sudo required to uninstall systemd service."
        exit 1
    fi
fi

if [ -f "${TARGET_FILE}" ]; then
    echo "[INFO] Stopping and disabling ${SERVICE_NAME}.service..."
    $SUDO systemctl stop "${SERVICE_NAME}.service" 2>/dev/null || true
    $SUDO systemctl disable "${SERVICE_NAME}.service" 2>/dev/null || true
    $SUDO rm -f "${TARGET_FILE}"
    $SUDO systemctl daemon-reload
    echo "[OK] Service successfully removed."
else
    echo "[INFO] Service ${TARGET_FILE} is not installed."
fi

echo "==========================================================="
