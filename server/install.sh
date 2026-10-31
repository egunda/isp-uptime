#!/usr/bin/env bash
# ==============================================================================
# ISP Monitor - Server Installation Script
# Handles fresh Linux environments: installs Apache, PHP, and sets permissions.
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA_DIR="${SCRIPT_DIR}/data"
WEB_DIR="${SCRIPT_DIR}/web"

echo "==========================================================="
echo "   ISP Monitoring Server - Installation & Dependency Check"
echo "==========================================================="

# Check root / sudo
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    else
        echo "[WARNING] Running without root or sudo. Package installation may fail if dependencies are missing."
    fi
fi

# Detect Package Manager & Install Dependencies
install_dependencies() {
    echo "[1/4] Checking and installing system packages (Apache, PHP)..."

    if command -v apt-get >/dev/null 2>&1; then
        echo "Detected Debian/Ubuntu-based distribution."
        $SUDO apt-get update -y
        $SUDO apt-get install -y apache2 php php-cli php-curl php-json libapache2-mod-php
    elif command -v dnf >/dev/null 2>&1; then
        echo "Detected RHEL/Fedora/Rocky/AlmaLinux distribution."
        $SUDO dnf install -y httpd php php-cli php-json php-curl
    elif command -v yum >/dev/null 2>&1; then
        echo "Detected CentOS/RHEL distribution."
        $SUDO yum install -y httpd php php-cli php-json php-curl
    elif command -v pacman >/dev/null 2>&1; then
        echo "Detected Arch Linux."
        $SUDO pacman -Sy --noconfirm apache php
    elif command -v apk >/dev/null 2>&1; then
        echo "Detected Alpine Linux."
        $SUDO apk add --no-cache apache2 php php-cli php-curl php-json apache2-utils
    else
        echo "[WARNING] Unknown package manager. Please ensure Apache and PHP CLI are installed manually."
    fi
}

# Verify PHP installation
check_php() {
    if ! command -v php >/dev/null 2>&1; then
        install_dependencies
    fi

    if command -v php >/dev/null 2>&1; then
        PHP_VER=$(php -v | head -n 1)
        echo "[OK] PHP is installed: ${PHP_VER}"
    else
        echo "[ERROR] PHP could not be found or installed. Please install PHP 7.4+ or 8.x."
        exit 1
    fi
}

# Verify Apache installation
check_apache() {
    APACHE_BIN=""
    if command -v apache2 >/dev/null 2>&1; then
        APACHE_BIN="apache2"
    elif command -v httpd >/dev/null 2>&1; then
        APACHE_BIN="httpd"
    else
        install_dependencies
    fi

    if command -v apache2 >/dev/null 2>&1 || command -v httpd >/dev/null 2>&1; then
        echo "[OK] Apache web server is installed."
    else
        echo "[ERROR] Apache web server could not be found or installed."
        exit 1
    fi
}

# Configure Apache DocumentRoot / Symlink
configure_apache() {
    echo "[2/4] Configuring Apache Web Directory..."

    # Enable rewrite module if on debian/ubuntu
    if command -v a2enmod >/dev/null 2>&1; then
        $SUDO a2enmod rewrite >/dev/null 2>&1 || true
    fi

    TARGET_LINK="/var/www/html/isp-monitor"
    if [ -d "/var/www/html" ]; then
        if [ ! -e "${TARGET_LINK}" ]; then
            echo "Creating symlink from ${WEB_DIR} to ${TARGET_LINK}..."
            $SUDO ln -s "${WEB_DIR}" "${TARGET_LINK}" 2>/dev/null || true
        fi
    fi
}

# Initialize Plain Text Storage
setup_storage() {
    echo "[3/4] Initializing plain text storage files (NO DATABASE)..."
    mkdir -p "${DATA_DIR}"
    chmod 777 "${DATA_DIR}" || true

    # Initialize events.csv
    if [ ! -f "${DATA_DIR}/events.csv" ]; then
        echo "timestamp,datetime,isp_id,isp_name,event_type,duration_seconds,details" > "${DATA_DIR}/events.csv"
    fi
    chmod 666 "${DATA_DIR}/events.csv" || true

    # Initialize outages.csv
    if [ ! -f "${DATA_DIR}/outages.csv" ]; then
        echo "id,isp_id,isp_name,down_timestamp,down_datetime,up_timestamp,up_datetime,duration_seconds,status,reason" > "${DATA_DIR}/outages.csv"
    fi
    chmod 666 "${DATA_DIR}/outages.csv" || true

    # Initialize state.json
    if [ ! -f "${DATA_DIR}/state.json" ]; then
        echo "{}" > "${DATA_DIR}/state.json"
    fi
    chmod 666 "${DATA_DIR}/state.json" || true

    # Fix ownership for web server user (www-data or apache)
    for u in www-data apache nobody; do
        if id "$u" >/dev/null 2>&1; then
            $SUDO chown -R "$u:$u" "${DATA_DIR}" 2>/dev/null || true
            break
        fi
    done
}

# Set script permissions
setup_permissions() {
    echo "[4/4] Setting execution permissions..."
    chmod +x "${SCRIPT_DIR}/ws_server.php"
    chmod +x "${SCRIPT_DIR}/start.sh"
    chmod +x "${SCRIPT_DIR}/stop.sh"
}

# Run install steps
check_php
check_apache
configure_apache
setup_storage
setup_permissions

echo ""
echo "==========================================================="
echo "   Server Installation Completed Successfully! 🎉"
echo "==========================================================="
echo "To start the monitoring server:"
echo "   cd ${SCRIPT_DIR} && ./start.sh"
echo ""
echo "Web Interface will be accessible at:"
echo "   http://<YOUR_SERVER_IP>/isp-monitor/   (or via custom VirtualHost)"
echo "WebSocket Daemon port:"
echo "   ws://<YOUR_SERVER_IP>:8080"
echo "==========================================================="
