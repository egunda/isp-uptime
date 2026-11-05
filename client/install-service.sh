#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Install Systemd Auto-Recovery Service
# Sets up 24/7 self-recovering daemon that auto-starts on boot and auto-restarts on crash
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE_NAME="isp-client"
TEMPLATE_FILE="${SCRIPT_DIR}/isp-client.service.template"
TARGET_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

echo "==========================================================="
echo "   ISP Monitor - Systemd Auto-Recovery Service Installer"
echo "==========================================================="

# Check sudo / root
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    else
        echo "[ERROR] Root privileges or sudo required to install systemd service."
        exit 1
    fi
fi

# Detect Python 3
PYTHON_BIN="$(command -v python3 || true)"
if [ -z "${PYTHON_BIN}" ]; then
    echo "[ERROR] Python 3 not found! Please install python3 first."
    exit 1
fi

CURRENT_USER="$(id -un)"
echo "[INFO] Configuring service with:"
echo "   User:             ${CURRENT_USER}"
echo "   Working Directory: ${SCRIPT_DIR}"
echo "   Python Executable: ${PYTHON_BIN}"
echo "   Service File:     ${TARGET_FILE}"

# Generate service unit file
TEMP_UNIT="$(mktemp)"
sed -e "s|__USER__|${CURRENT_USER}|g" \
    -e "s|__DIR__|${SCRIPT_DIR}|g" \
    -e "s|__PYTHON__|${PYTHON_BIN}|g" \
    "${TEMPLATE_FILE}" > "${TEMP_UNIT}"

# Install service
echo "[INFO] Installing ${TARGET_FILE}..."
$SUDO cp "${TEMP_UNIT}" "${TARGET_FILE}"
$SUDO chmod 644 "${TARGET_FILE}"
rm -f "${TEMP_UNIT}"

# Stop any running manual instance
if [ -f "${SCRIPT_DIR}/client.pid" ]; then
    echo "[INFO] Stopping manually running client daemon..."
    "${SCRIPT_DIR}/stop.sh" 2>/dev/null || true
fi

# Reload systemd and enable service
echo "[INFO] Enabling and starting ${SERVICE_NAME}.service..."
$SUDO systemctl daemon-reload
$SUDO systemctl enable "${SERVICE_NAME}.service"
$SUDO systemctl restart "${SERVICE_NAME}.service"

sleep 1

if $SUDO systemctl is-active --quiet "${SERVICE_NAME}.service"; then
    echo ""
    echo "==========================================================="
    echo "   🎉 ${SERVICE_NAME}.service is ACTIVE and RUNNING!"
    echo "==========================================================="
    echo "Self-Recovery Features Enabled:"
    echo " ✔ Automatically starts when Raspberry Pi powers on / reboots"
    echo " ✔ Automatically recovers in 3s if crashed or killed"
    echo " ✔ Immune to SSH session logout / terminal close"
    echo " ✔ Protected against Raspberry Pi OOM memory killer"
    echo ""
    echo "Useful Commands:"
    echo " - Check status:    sudo systemctl status ${SERVICE_NAME}"
    echo " - Restart service: sudo systemctl restart ${SERVICE_NAME}"
    echo " - Stop service:    sudo systemctl stop ${SERVICE_NAME}"
    echo " - View live logs:  tail -f ${SCRIPT_DIR}/client.log"
    echo "   or:              sudo journalctl -u ${SERVICE_NAME} -f"
    echo "==========================================================="
else
    echo "[ERROR] Service failed to start. Status:"
    $SUDO systemctl status "${SERVICE_NAME}.service" --no-pager
    exit 1
fi
