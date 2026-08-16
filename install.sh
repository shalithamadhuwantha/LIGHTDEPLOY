#!/bin/bash
# ==============================================================================
# LIGHTDEPLOY INSTALLATION & DEPENDENCY WIZARD
# Supports both Local Machine Development Testing & Production Server Installation
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo -e "${BLUE}====================================================${NC}"
echo -e "${BLUE}        LIGHTDEPLOY INSTALLATION WIZARD             ${NC}"
echo -e "${BLUE}====================================================${NC}"

# Check Mode Flag or Prompt User
MODE=""
if [ "$1" == "--local" ]; then
    MODE="1"
elif [ "$1" == "--production" ]; then
    MODE="2"
fi

if [ -z "$MODE" ]; then
    echo -e "\nPlease choose your installation environment:\n"
    echo -e "  ${GREEN}1) Local Development & Testing Mode${NC} (Run in current workspace, NO root required)"
    echo -e "  ${YELLOW}2) Production Server Mode${NC} (Install to /opt/lightdeploy for aaPanel / Nginx, requires root)\n"
    read -p "Select option [1 or 2] (default: 1): " CHOICE
    MODE="${CHOICE:-1}"
fi

# ------------------------------------------------------------------------------
# DEPENDENCY SCANNING & INTERACTIVE AUTO-INSTALLATION ENGINE
# ------------------------------------------------------------------------------

MISSING_CRITICAL=0
MISSING_OPTIONAL=0
MISSING_PKGS_DEBIAN=()
MISSING_PKGS_REDHAT=()
MISSING_PKGS_ALPINE=()
MISSING_DESCRIPTIONS=()

scan_dependencies() {
    MISSING_CRITICAL=0
    MISSING_OPTIONAL=0
    MISSING_PKGS_DEBIAN=()
    MISSING_PKGS_REDHAT=()
    MISSING_PKGS_ALPINE=()
    MISSING_DESCRIPTIONS=()

    echo -e "\n${BLUE}====================================================${NC}"
    echo -e "${BLUE}        DEPENDENCY & PREREQUISITE SCANNER          ${NC}"
    echo -e "${BLUE}====================================================${NC}\n"

    echo -e "${BOLD}1. System Binaries & CLI Tools:${NC}"

    # PHP CLI
    if command -v php &> /dev/null; then
        PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;' 2>/dev/null || echo "Unknown")
        PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
        if [ "$PHP_MAJOR" -ge 8 ]; then
            echo -e "  [ ${GREEN}HAVE${NC} ] PHP CLI (Version: ${PHP_VER} >= 8.0)"
        else
            echo -e "  [ ${RED}NOT HAVE${NC} ] PHP CLI Version >= 8.0 (Found ${PHP_VER})"
            MISSING_CRITICAL=$((MISSING_CRITICAL + 1))
            MISSING_DESCRIPTIONS+=("PHP CLI (Version 8.0+ required)")
            MISSING_PKGS_DEBIAN+=("php-cli")
            MISSING_PKGS_REDHAT+=("php-cli")
            MISSING_PKGS_ALPINE+=("php81-cli")
        fi
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] PHP CLI (Not installed)"
        MISSING_CRITICAL=$((MISSING_CRITICAL + 1))
        MISSING_DESCRIPTIONS+=("PHP CLI binary")
        MISSING_PKGS_DEBIAN+=("php-cli")
        MISSING_PKGS_REDHAT+=("php-cli")
        MISSING_PKGS_ALPINE+=("php81-cli")
    fi

    # Git
    if command -v git &> /dev/null; then
        echo -e "  [ ${GREEN}HAVE${NC} ] git"
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] git"
        MISSING_OPTIONAL=$((MISSING_OPTIONAL + 1))
        MISSING_DESCRIPTIONS+=("git command-line tool")
        MISSING_PKGS_DEBIAN+=("git")
        MISSING_PKGS_REDHAT+=("git")
        MISSING_PKGS_ALPINE+=("git")
    fi

    # Curl binary
    if command -v curl &> /dev/null; then
        echo -e "  [ ${GREEN}HAVE${NC} ] curl"
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] curl"
        MISSING_OPTIONAL=$((MISSING_OPTIONAL + 1))
        MISSING_DESCRIPTIONS+=("curl command-line tool")
        MISSING_PKGS_DEBIAN+=("curl")
        MISSING_PKGS_REDHAT+=("curl")
        MISSING_PKGS_ALPINE+=("curl")
    fi

    # Rsync
    if command -v rsync &> /dev/null; then
        echo -e "  [ ${GREEN}HAVE${NC} ] rsync"
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] rsync"
        MISSING_OPTIONAL=$((MISSING_OPTIONAL + 1))
        MISSING_DESCRIPTIONS+=("rsync file synchronizer")
        MISSING_PKGS_DEBIAN+=("rsync")
        MISSING_PKGS_REDHAT+=("rsync")
        MISSING_PKGS_ALPINE+=("rsync")
    fi

    # Tar
    if command -v tar &> /dev/null; then
        echo -e "  [ ${GREEN}HAVE${NC} ] tar"
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] tar"
        MISSING_OPTIONAL=$((MISSING_OPTIONAL + 1))
        MISSING_DESCRIPTIONS+=("tar archive utility")
        MISSING_PKGS_DEBIAN+=("tar")
        MISSING_PKGS_REDHAT+=("tar")
        MISSING_PKGS_ALPINE+=("tar")
    fi

    # Unzip
    if command -v unzip &> /dev/null; then
        echo -e "  [ ${GREEN}HAVE${NC} ] unzip"
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] unzip"
        MISSING_OPTIONAL=$((MISSING_OPTIONAL + 1))
        MISSING_DESCRIPTIONS+=("unzip archive utility")
        MISSING_PKGS_DEBIAN+=("unzip")
        MISSING_PKGS_REDHAT+=("unzip")
        MISSING_PKGS_ALPINE+=("unzip")
    fi

    echo -e "\n${BOLD}2. PHP Capabilities & Extensions:${NC}"

    if command -v php &> /dev/null; then
        # proc_open check
        if php -r 'exit(function_exists("proc_open") ? 0 : 1);' 2>/dev/null; then
            echo -e "  [ ${GREEN}HAVE${NC} ] PHP function: proc_open()"
        else
            echo -e "  [ ${RED}NOT HAVE${NC} ] PHP function: proc_open() (Disabled in php.ini)"
            MISSING_CRITICAL=$((MISSING_CRITICAL + 1))
            MISSING_DESCRIPTIONS+=("proc_open() function (Enabled in php.ini)")
        fi

        # Required & Recommended PHP Extensions
        REQUIRED_EXTS=("json" "session" "curl" "mbstring" "zip")
        for ext in "${REQUIRED_EXTS[@]}"; do
            if php -m 2>/dev/null | grep -qi "^${ext}$"; then
                echo -e "  [ ${GREEN}HAVE${NC} ] PHP extension: ${ext}"
            else
                echo -e "  [ ${RED}NOT HAVE${NC} ] PHP extension: ${ext}"
                if [[ "$ext" == "json" || "$ext" == "session" || "$ext" == "curl" ]]; then
                    MISSING_CRITICAL=$((MISSING_CRITICAL + 1))
                else
                    MISSING_OPTIONAL=$((MISSING_OPTIONAL + 1))
                fi
                MISSING_DESCRIPTIONS+=("PHP extension: ${ext}")
                MISSING_PKGS_DEBIAN+=("php-${ext}")
                MISSING_PKGS_REDHAT+=("php-${ext}")
                MISSING_PKGS_ALPINE+=("php81-${ext}")
            fi
        done
    else
        echo -e "  [ ${RED}NOT HAVE${NC} ] Unable to check PHP capabilities (PHP binary missing)"
    fi
}

install_missing_dependencies() {
    TOTAL_MISSING=$((MISSING_CRITICAL + MISSING_OPTIONAL))
    if [ "$TOTAL_MISSING" -eq 0 ]; then
        echo -e "\n${GREEN}[OK] All dependencies and prerequisites are satisfied!${NC}"
        return 0
    fi

    echo -e "\n${YELLOW}----------------------------------------------------${NC}"
    echo -e "${YELLOW}[!] MISSING DEPENDENCIES SUMMARY [NOT HAVE]:${NC}"
    for desc in "${MISSING_DESCRIPTIONS[@]}"; do
        echo -e "  - ${desc}"
    done
    echo -e "${YELLOW}----------------------------------------------------${NC}"

    read -p "May I install the missing dependencies automatically? (y/N): " MAY_INSTALL
    if [[ "$MAY_INSTALL" != "y" && "$MAY_INSTALL" != "Y" ]]; then
        echo -e "${YELLOW}[INFO] Automatic installation skipped by user.${NC}"
        if [ "$MISSING_CRITICAL" -gt 0 ]; then
            echo -e "${RED}[ERROR] Critical dependencies are missing. Cannot proceed without installation.${NC}"
            exit 1
        fi
        return 0
    fi

    # Detect Package Manager
    PKG_MGR=""
    SUDO=""
    if [ "$EUID" -ne 0 ]; then
        if command -v sudo &> /dev/null; then
            SUDO="sudo"
        else
            echo -e "${RED}[ERROR] Root privileges or sudo is required to install system packages.${NC}"
            exit 1
        fi
    fi

    if command -v apt-get &> /dev/null; then
        PKG_MGR="apt-get"
        INSTALL_PKGS=("${MISSING_PKGS_DEBIAN[@]}")
    elif command -v dnf &> /dev/null; then
        PKG_MGR="dnf"
        INSTALL_PKGS=("${MISSING_PKGS_REDHAT[@]}")
    elif command -v yum &> /dev/null; then
        PKG_MGR="yum"
        INSTALL_PKGS=("${MISSING_PKGS_REDHAT[@]}")
    elif command -v apk &> /dev/null; then
        PKG_MGR="apk"
        INSTALL_PKGS=("${MISSING_PKGS_ALPINE[@]}")
    elif command -v pacman &> /dev/null; then
        PKG_MGR="pacman"
        INSTALL_PKGS=("${MISSING_PKGS_DEBIAN[@]}")
    fi

    if [ -z "$PKG_MGR" ]; then
        echo -e "${RED}[ERROR] Operating system package manager could not be auto-detected.${NC}"
        echo -e "Please install the missing dependencies manually and re-run install.sh."
        if [ "$MISSING_CRITICAL" -gt 0 ]; then
            exit 1
        fi
        return 0
    fi

    # Deduplicate package list
    UNIQUE_PKGS=($(echo "${INSTALL_PKGS[@]}" | tr ' ' '\n' | sort -u | tr '\n' ' '))

    if [ ${#UNIQUE_PKGS[@]} -eq 0 ]; then
        echo -e "${YELLOW}[INFO] No installable system packages mapped. Please resolve missing functions/ini settings manually.${NC}"
        return 0
    fi

    echo -e "\n${YELLOW}[INSTALLING] Triggering package installation using ${PKG_MGR}...${NC}"
    echo -e "Packages to install: ${GREEN}${UNIQUE_PKGS[*]}${NC}"

    case "$PKG_MGR" in
        apt-get)
            $SUDO apt-get update -y
            $SUDO apt-get install -y "${UNIQUE_PKGS[@]}"
            ;;
        dnf|yum)
            $SUDO "$PKG_MGR" install -y "${UNIQUE_PKGS[@]}"
            ;;
        apk)
            $SUDO apk add --no-cache "${UNIQUE_PKGS[@]}"
            ;;
        pacman)
            $SUDO pacman -S --noconfirm "${UNIQUE_PKGS[@]}"
            ;;
    esac

    echo -e "\n${GREEN}[OK] Package installation completed! Re-scanning dependencies...${NC}"
    scan_dependencies

    if [ "$MISSING_CRITICAL" -gt 0 ]; then
        echo -e "${RED}[ERROR] Critical dependencies are still missing after package installation attempt.${NC}"
        echo -e "If proc_open() is listed as disabled, please remove 'proc_open' from 'disable_functions' in your php.ini."
        exit 1
    fi
}

# Run Pre-flight Dependency Scan & Auto-Installer
scan_dependencies
install_missing_dependencies

# ------------------------------------------------------------------------------
# MODE 1: LOCAL DEVELOPMENT & TESTING MODE
# ------------------------------------------------------------------------------
if [ "$MODE" == "1" ]; then
    echo -e "\n${YELLOW}[LOCAL SETUP] Initializing LightDeploy Local Development Workspace...${NC}"

    # Create local directories
    mkdir -p "$SRC_DIR"/{runtime/{locks,jobs,pids,streams},logs/{deployments,security,application},releases}
    chmod -R 777 "$SRC_DIR"/runtime "$SRC_DIR"/logs 2>/dev/null || true
    chmod +x "$SRC_DIR"/scripts/*.sh 2>/dev/null || true
    chmod +x "$SRC_DIR"/serve.sh 2>/dev/null || true

    echo -e "${GREEN}[OK] Local runtime & log directories initialized.${NC}"
    echo -e "${GREEN}[OK] Test deployment scripts made executable.${NC}"

    echo -e "\n${BLUE}====================================================${NC}"
    echo -e "${GREEN}    LOCAL DEVELOPMENT ENVIRONMENT READY!            ${NC}"
    echo -e "${BLUE}====================================================${NC}"
    echo -e "To launch the local web server, run:"
    echo -e "   ${YELLOW}./serve.sh${NC} (or ./serve.sh 8080)\n"
    echo -e "Default Dashboard Credentials:"
    echo -e "   Username: ${GREEN}admin${NC}"
    echo -e "   Password: ${GREEN}admin123${NC}\n"

    read -p "Would you like to start the local server now? (y/N): " START_NOW
    if [[ "$START_NOW" == "y" || "$START_NOW" == "Y" ]]; then
        exec "$SRC_DIR/serve.sh"
    fi
    exit 0
fi

# ------------------------------------------------------------------------------
# MODE 2: PRODUCTION SERVER MODE (/opt/lightdeploy)
# ------------------------------------------------------------------------------
TARGET_DIR="/opt/lightdeploy"
SERVICE_USER="lightdeploy"

echo -e "\n${YELLOW}[PRODUCTION SETUP] Installing LightDeploy to ${TARGET_DIR}...${NC}"

# 1. Root Privileges Check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Production server installation requires root privileges (sudo ./install.sh --production).${NC}"
    exit 1
fi

# 2. Configure Service User
echo -e "\n${YELLOW}[1/5] Configuring Service User (${SERVICE_USER})...${NC}"
if ! id "$SERVICE_USER" &>/dev/null; then
    useradd -r -s /bin/false -d "$TARGET_DIR" "$SERVICE_USER"
    echo -e "${GREEN}[OK] Created dedicated system user '${SERVICE_USER}'.${NC}"
else
    echo -e "${GREEN}[OK] System user '${SERVICE_USER}' already exists.${NC}"
fi

# 3. Copy Files
echo -e "\n${YELLOW}[2/5] Deploying LightDeploy Files to ${TARGET_DIR}...${NC}"

mkdir -p "$TARGET_DIR"/{app,public/assets,config,scripts,runtime/{locks,jobs,pids,streams},logs/{deployments,security,application},releases,tests}

cp -r "$SRC_DIR"/app/* "$TARGET_DIR"/app/
cp -r "$SRC_DIR"/public/* "$TARGET_DIR"/public/
cp -r "$SRC_DIR"/config/* "$TARGET_DIR"/config/
cp -r "$SRC_DIR"/scripts/* "$TARGET_DIR"/scripts/
cp -r "$SRC_DIR"/tests/* "$TARGET_DIR"/tests/
cp "$SRC_DIR"/*.md "$TARGET_DIR"/ 2>/dev/null || true

# 4. Security & Permissions
echo -e "\n${YELLOW}[3/5] Setting Secure File Permissions...${NC}"

WEB_USER="www"
if id "www-data" &>/dev/null; then
    WEB_USER="www-data"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
fi

chown -R "$WEB_USER:$WEB_USER" "$TARGET_DIR"
chmod 755 "$TARGET_DIR"
chmod -R 755 "$TARGET_DIR"/public
chmod -R 700 "$TARGET_DIR"/app
chmod -R 700 "$TARGET_DIR"/config
chmod -R 700 "$TARGET_DIR"/runtime
chmod -R 700 "$TARGET_DIR"/logs
chmod -R 755 "$TARGET_DIR"/scripts
chmod +x "$TARGET_DIR"/scripts/*.sh 2>/dev/null || true

echo -e "${GREEN}[OK] Permissions hardened: Only public/ is web-accessible.${NC}"

# 5. Admin Credentials
echo -e "\n${YELLOW}[4/5] Generating Production Administrator Account...${NC}"
ADMIN_PASS=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 16)
ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")

cat <<EOF > "$TARGET_DIR/config/users.json"
{
    "users": {
        "admin": {
            "name": "System Administrator",
            "password_hash": "${ADMIN_HASH}",
            "role": "admin"
        }
    }
}
EOF
chmod 600 "$TARGET_DIR/config/users.json"

# 6. Sanity Check
echo -e "\n${YELLOW}[5/5] Running Security Sanity Check...${NC}"
cd "$TARGET_DIR"
if php tests/test_runner.php; then
    echo -e "${GREEN}[OK] Security Sanity Check PASSED!${NC}"
fi

echo -e "\n${BLUE}====================================================${NC}"
echo -e "${GREEN}      PRODUCTION INSTALLATION SUCCESSFUL!           ${NC}"
echo -e "${BLUE}====================================================${NC}"
echo -e "Target Directory: ${TARGET_DIR}"
echo -e "Web Document Root: ${TARGET_DIR}/public"
echo -e "Initial Admin Username: ${YELLOW}admin${NC}"
echo -e "Initial Admin Password: ${YELLOW}${ADMIN_PASS}${NC}"
echo -e "\n${YELLOW}IMPORTANT AAPANEL / NGINX CONFIGURATION:${NC}"
cat <<'NGINX_CONF'
Set the website Document Root in aaPanel / Nginx to:
  /opt/lightdeploy/public

Nginx Block Example:
  server {
      listen 80;
      server_name lightdeploy.yourdomain.com;
      root /opt/lightdeploy/public;
      index index.php;

      location ~ ^/(app|config|logs|runtime|scripts|releases|tests)/ {
          deny all;
          return 404;
      }

      location / {
          try_files $uri $uri/ /index.php?$query_string;
      }

      location ~ \.php$ {
          include enable-php-81.conf;
          fastcgi_pass unix:/tmp/php-cgi-81.sock;
          fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
          include fastcgi_params;
          fastcgi_buffering off;
          fastcgi_read_timeout 3600;
      }
  }
NGINX_CONF

echo -e "\nInstallation finished!"

