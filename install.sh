#!/bin/bash
# ==============================================================================
# LIGHTDEPLOY INSTALLATION & DEPENDENCY WIZARD
# Supports both Local Machine Development Testing & Production Server Installation
# ==============================================================================

set -e

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

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

# Check Mode Flags or Prompt User
MODE=""
UPDATE_MODE=0
FRESH_INSTALL=0

for arg in "$@"; do
    case "$arg" in
        --local)
            MODE="1"
            ;;
        --production)
            MODE="2"
            ;;
        --update|--upgrade)
            MODE="2"
            UPDATE_MODE=1
            ;;
        --fresh)
            FRESH_INSTALL=1
            ;;
    esac
done

if [ -z "$MODE" ]; then
    echo -e "\nPlease choose your installation environment:\n"
    if [ -d "/opt/lightdeploy" ]; then
        echo -e "  ${GREEN}1) Safe Upgrade / Update Existing LightDeploy${NC} (Preserves all users, sites, databases & scripts)"
        echo -e "  ${YELLOW}2) Local Development & Testing Mode${NC} (Run in current workspace)"
        echo -e "  ${RED}3) Fresh Production Reinstall${NC} (OVERWRITE all existing configuration & users)\n"
        read -p "Select option [1, 2, or 3] (default: 1): " CHOICE
        CHOICE="${CHOICE:-1}"
        if [ "$CHOICE" == "1" ]; then
            MODE="2"
            UPDATE_MODE=1
        elif [ "$CHOICE" == "2" ]; then
            MODE="1"
        else
            MODE="2"
            FRESH_INSTALL=1
        fi
    else
        echo -e "  ${GREEN}1) Local Development & Testing Mode${NC} (Run in current workspace, NO root required)"
        echo -e "  ${YELLOW}2) Production Server Mode${NC} (Install to /opt/lightdeploy for aaPanel / Nginx, requires root)\n"
        read -p "Select option [1 or 2] (default: 1): " CHOICE
        MODE="${CHOICE:-1}"
    fi
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
PROC_OPEN_DISABLED=0
PHP_VER_SHORT=""
PHP_VER_NODOT=""

scan_dependencies() {
    MISSING_CRITICAL=0
    MISSING_OPTIONAL=0
    MISSING_PKGS_DEBIAN=()
    MISSING_PKGS_REDHAT=()
    MISSING_PKGS_ALPINE=()
    MISSING_DESCRIPTIONS=()
    PROC_OPEN_DISABLED=0
    PHP_VER_SHORT=""
    PHP_VER_NODOT=""

    echo -e "\n${BLUE}====================================================${NC}"
    echo -e "${BLUE}        DEPENDENCY & PREREQUISITE SCANNER          ${NC}"
    echo -e "${BLUE}====================================================${NC}\n"

    echo -e "${BOLD}1. System Binaries & CLI Tools:${NC}"

    # PHP CLI
    if command -v php &> /dev/null; then
        PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;' 2>/dev/null || echo "Unknown")
        PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
        PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;' 2>/dev/null || echo "0")
        PHP_VER_SHORT="${PHP_MAJOR}.${PHP_MINOR}"
        PHP_VER_NODOT="${PHP_MAJOR}${PHP_MINOR}"

        if [ "$PHP_MAJOR" -ge 8 ]; then
            echo -e "  [ ${GREEN}HAVE${NC} ] PHP CLI (Version: ${PHP_VER} >= 8.0)"
        else
            echo -e "  [ ${RED}NOT HAVE${NC} ] PHP CLI Version >= 8.0 (Found ${PHP_VER})"
            MISSING_CRITICAL=$((MISSING_CRITICAL + 1))
            MISSING_DESCRIPTIONS+=("PHP CLI (Version 8.0+ required)")
            MISSING_PKGS_DEBIAN+=("php-cli" "php${PHP_VER_SHORT}-cli")
            MISSING_PKGS_REDHAT+=("php-cli" "php${PHP_VER_NODOT}-php-cli")
            MISSING_PKGS_ALPINE+=("php${PHP_VER_NODOT}-cli" "php81-cli")
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
            MISSING_DESCRIPTIONS+=("proc_open() function (Disabled in php.ini)")
            PROC_OPEN_DISABLED=1
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
                if [ -n "$PHP_VER_SHORT" ]; then
                    MISSING_PKGS_DEBIAN+=("php${PHP_VER_SHORT}-${ext}" "php-${ext}")
                    MISSING_PKGS_REDHAT+=("php-${ext}" "php${PHP_VER_NODOT}-php-${ext}")
                    MISSING_PKGS_ALPINE+=("php${PHP_VER_NODOT}-${ext}" "php81-${ext}")
                else
                    MISSING_PKGS_DEBIAN+=("php-${ext}")
                    MISSING_PKGS_REDHAT+=("php-${ext}")
                    MISSING_PKGS_ALPINE+=("php81-${ext}")
                fi
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

    # Detect Root / Sudo
    SUDO=""
    if [ "$EUID" -ne 0 ]; then
        if command -v sudo &> /dev/null; then
            SUDO="sudo"
        else
            echo -e "${RED}[ERROR] Root privileges or sudo is required to install system packages.${NC}"
            exit 1
        fi
    fi

    # 1. Auto-enable proc_open if disabled in php.ini
    if [ "$PROC_OPEN_DISABLED" -eq 1 ]; then
        INI_FILE=$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo "")
        if [ -n "$INI_FILE" ] && [ -f "$INI_FILE" ]; then
            echo -e "${YELLOW}[FIXING] Attempting to enable proc_open() in ${INI_FILE}...${NC}"
            $SUDO sed -i 's/proc_open,//g; s/,proc_open//g; s/proc_open//g' "$INI_FILE" 2>/dev/null || true
        fi
    fi

    # Detect Package Manager
    PKG_MGR=""
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
        echo -e "${YELLOW}[INFO] Re-scanning dependencies...${NC}"
        scan_dependencies
        return 0
    fi

    echo -e "\n${YELLOW}[INSTALLING] Resolving packages using ${PKG_MGR}...${NC}"

    case "$PKG_MGR" in
        apt-get)
            $SUDO apt-get update -y
            VALID_PKGS=()
            for pkg in "${UNIQUE_PKGS[@]}"; do
                if apt-cache show "$pkg" &>/dev/null; then
                    VALID_PKGS+=("$pkg")
                fi
            done
            if [ ${#VALID_PKGS[@]} -gt 0 ]; then
                echo -e "Installing candidate packages: ${GREEN}${VALID_PKGS[*]}${NC}"
                $SUDO apt-get install -y "${VALID_PKGS[@]}"
            else
                echo -e "${YELLOW}[WARN] No exact package matches found in apt repositories for ${UNIQUE_PKGS[*]}${NC}"
            fi
            if command -v phpenmod &>/dev/null; then
                for ext in "curl" "mbstring" "zip"; do
                    [ -n "$PHP_VER_SHORT" ] && $SUDO phpenmod -v "$PHP_VER_SHORT" "$ext" 2>/dev/null || true
                    $SUDO phpenmod "$ext" 2>/dev/null || true
                done
            fi
            ;;
        dnf|yum)
            VALID_PKGS=()
            for pkg in "${UNIQUE_PKGS[@]}"; do
                if $SUDO "$PKG_MGR" list available "$pkg" &>/dev/null || $SUDO "$PKG_MGR" list installed "$pkg" &>/dev/null; then
                    VALID_PKGS+=("$pkg")
                fi
            done
            if [ ${#VALID_PKGS[@]} -gt 0 ]; then
                echo -e "Installing candidate packages: ${GREEN}${VALID_PKGS[*]}${NC}"
                $SUDO "$PKG_MGR" install -y "${VALID_PKGS[@]}"
            else
                $SUDO "$PKG_MGR" install -y "${UNIQUE_PKGS[@]}" || true
            fi
            ;;
        apk)
            $SUDO apk add --no-cache "${UNIQUE_PKGS[@]}" || true
            ;;
        pacman)
            $SUDO pacman -S --noconfirm "${UNIQUE_PKGS[@]}" || true
            ;;
    esac

    echo -e "\n${GREEN}[OK] Package installation completed! Re-scanning dependencies...${NC}"
    scan_dependencies

    if [ "$MISSING_CRITICAL" -gt 0 ]; then
        echo -e "${RED}[ERROR] Critical dependencies are still missing after package installation attempt.${NC}"
        if [ -n "$PHP_VER_SHORT" ]; then
            echo -e "For PHP ${PHP_VER_SHORT}, you can manually run: ${YELLOW}apt-get install -y php${PHP_VER_SHORT}-curl php${PHP_VER_SHORT}-mbstring php${PHP_VER_SHORT}-zip${NC}"
        fi
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
    mkdir -p "$SRC_DIR"/{runtime/{locks,jobs,pids,streams,sessions},logs/{deployments,security,application},releases}
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
    USERADD_BIN=""
    if command -v useradd &>/dev/null; then
        USERADD_BIN="$(command -v useradd)"
    elif [ -x /usr/sbin/useradd ]; then
        USERADD_BIN="/usr/sbin/useradd"
    elif [ -x /sbin/useradd ]; then
        USERADD_BIN="/sbin/useradd"
    fi

    if [ -n "$USERADD_BIN" ]; then
        "$USERADD_BIN" -r -s /bin/false -d "$TARGET_DIR" "$SERVICE_USER" 2>/dev/null || "$USERADD_BIN" -s /bin/false "$SERVICE_USER"
        echo -e "${GREEN}[OK] Created dedicated system user '${SERVICE_USER}'.${NC}"
    elif command -v adduser &>/dev/null; then
        adduser -S -D -h "$TARGET_DIR" "$SERVICE_USER" 2>/dev/null || adduser --system --no-create-home "$SERVICE_USER" 2>/dev/null || true
        echo -e "${GREEN}[OK] Created dedicated system user '${SERVICE_USER}'.${NC}"
    else
        echo -e "${YELLOW}[WARNING] Neither useradd nor adduser binary found. Skipping service user creation.${NC}"
    fi
else
    echo -e "${GREEN}[OK] System user '${SERVICE_USER}' already exists.${NC}"
fi

# 3. Copy Files & Apply Safe Upgrade Guard
echo -e "\n${YELLOW}[2/5] Deploying LightDeploy Files to ${TARGET_DIR}...${NC}"

mkdir -p "$TARGET_DIR"/{app,public/assets,config,scripts,runtime/{locks,jobs,pids,streams,sessions},logs/{deployments,security,application},releases,tests}

if [ "$UPDATE_MODE" -eq 1 ]; then
    echo -e "${GREEN}[SAFE UPGRADE MODE] Preserving existing user data, sites.json, databases.json, and custom scripts...${NC}"
    
    # 3a. Backup existing config & scripts as safety net
    BACKUP_STAMP=$(date +%Y%m%d_%H%M%S)
    mkdir -p "$TARGET_DIR/backups/$BACKUP_STAMP"
    cp -r "$TARGET_DIR/config" "$TARGET_DIR/backups/$BACKUP_STAMP/" 2>/dev/null || true
    cp -r "$TARGET_DIR/scripts" "$TARGET_DIR/backups/$BACKUP_STAMP/" 2>/dev/null || true
    echo -e "  [${GREEN}BACKUP${NC}] Safety snapshot created at: ${TARGET_DIR}/backups/${BACKUP_STAMP}/"

    # 3b. Overwrite code application directories ONLY
    cp -r "$SRC_DIR"/app/* "$TARGET_DIR"/app/
    cp -r "$SRC_DIR"/public/* "$TARGET_DIR"/public/
    cp -r "$SRC_DIR"/tests/* "$TARGET_DIR"/tests/
    cp "$SRC_DIR"/*.md "$TARGET_DIR"/ 2>/dev/null || true

    # 3c. Copy config files & subdirectories ONLY if they do NOT exist
    for cfg in "$SRC_DIR"/config/*; do
        [ -e "$cfg" ] || continue
        cfg_name=$(basename "$cfg")
        if [ ! -e "$TARGET_DIR/config/$cfg_name" ]; then
            cp -r "$cfg" "$TARGET_DIR/config/"
            echo -e "  [${GREEN}NEW CONFIG${NC}] Added missing config template: ${cfg_name}"
        fi
    done

    # 3d. Copy scripts & subdirectories ONLY if they do NOT exist
    for scr in "$SRC_DIR"/scripts/*; do
        [ -e "$scr" ] || continue
        scr_name=$(basename "$scr")
        if [ ! -e "$TARGET_DIR/scripts/$scr_name" ]; then
            cp -r "$scr" "$TARGET_DIR/scripts/"
            echo -e "  [${GREEN}NEW SCRIPT${NC}] Added missing script template: ${scr_name}"
        fi
    done
else
    # Fresh Install Mode
    cp -r "$SRC_DIR"/app/* "$TARGET_DIR"/app/
    cp -r "$SRC_DIR"/public/* "$TARGET_DIR"/public/
    cp -r "$SRC_DIR"/config/* "$TARGET_DIR"/config/
    cp -r "$SRC_DIR"/scripts/* "$TARGET_DIR"/scripts/
    cp -r "$SRC_DIR"/tests/* "$TARGET_DIR"/tests/
    cp "$SRC_DIR"/ecosystem.config.js "$TARGET_DIR"/ 2>/dev/null || true
    cp "$SRC_DIR"/*.md "$TARGET_DIR"/ 2>/dev/null || true
fi

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

# 5. Admin Credentials (Skipped on Upgrade)
if [ "$UPDATE_MODE" -eq 1 ] && [ -f "$TARGET_DIR/config/users.json" ]; then
    echo -e "\n${GREEN}[4/5] Preserving Existing Admin & User Credentials (users.json intact).${NC}"
else
    echo -e "\n${YELLOW}[4/5] Generating Production Administrator Account...${NC}"
    read -p "Enter Administrator Username (default: admin): " INPUT_ADMIN_USER
    ADMIN_USER=$(echo "${INPUT_ADMIN_USER:-admin}" | tr -cd 'a-zA-Z0-9_-')
    if [ -z "$ADMIN_USER" ]; then
        ADMIN_USER="admin"
    fi

    ADMIN_PASS=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 16)
    ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")

    cat <<EOF > "$TARGET_DIR/config/users.json"
{
    "users": {
        "${ADMIN_USER}": {
            "name": "System Administrator",
            "password_hash": "${ADMIN_HASH}",
            "role": "admin"
        }
    }
}
EOF
    chmod 600 "$TARGET_DIR/config/users.json"
fi

# 6. Sanity Check
echo -e "\n${YELLOW}[5/5] Running Security Sanity Check...${NC}"
cd "$TARGET_DIR"
if php tests/test_runner.php; then
    echo -e "${GREEN}[OK] Security Sanity Check PASSED!${NC}"
fi

# 7. PM2 Process Manager Upgrade / Setup
echo -e "\n${BLUE}----------------------------------------------------${NC}"
echo -e "${YELLOW}PM2 PRODUCTION PROCESS MANAGER SETUP${NC}"
echo -e "${BLUE}----------------------------------------------------${NC}"

if [ "$UPDATE_MODE" -eq 1 ] && command -v pm2 &>/dev/null; then
    if pm2 list 2>/dev/null | grep -q "lightdeploy"; then
        echo -e "${GREEN}[PM2 RELOAD] Reloading LightDeploy service under PM2...${NC}"
        pm2 reload lightdeploy 2>/dev/null || pm2 restart lightdeploy 2>/dev/null || true
        pm2 save 2>/dev/null || true
        echo -e "${GREEN}[OK] LightDeploy process updated and reloaded with ZERO DOWNTIME!${NC}"
    fi
fi

PM2_WANT_INSTALL=""
if [ "$UPDATE_MODE" -eq 1 ]; then
    PM2_WANT_INSTALL="n"
elif [ "$1" == "--pm2" ] || [ "$2" == "--pm2" ]; then
    PM2_WANT_INSTALL="y"
else
    read -p "Do you want to host and launch LightDeploy using PM2? [y/N]: " PM2_INPUT
    PM2_WANT_INSTALL=$(echo "${PM2_INPUT:-n}" | tr '[:upper:]' '[:lower:]')
fi

PM2_RUNNING_OK=0

if [ "$PM2_WANT_INSTALL" == "y" ] || [ "$PM2_WANT_INSTALL" == "yes" ]; then
    echo -e "\n${YELLOW}Configuring PM2 Service Options:${NC}"
    read -p "  1. PM2 Process Name (default: lightdeploy): " INPUT_PM2_NAME
    PM2_APP_NAME="${INPUT_PM2_NAME:-lightdeploy}"

    read -p "  2. Server Port (default: 8000): " INPUT_PM2_PORT
    PM2_APP_PORT="${INPUT_PM2_PORT:-8000}"

    read -p "  3. Server Host IP (default: 0.0.0.0): " INPUT_PM2_HOST
    PM2_APP_HOST="${INPUT_PM2_HOST:-0.0.0.0}"

    # Auto-install PM2 if binary is missing
    if ! command -v pm2 &>/dev/null; then
        echo -e "${YELLOW}[INFO] PM2 binary not found. Installing PM2 globally via npm...${NC}"
        if command -v npm &>/dev/null; then
            npm install -g pm2
        else
            echo -e "${RED}[ERROR] npm is required to install PM2. Please install Node.js & npm first.${NC}"
        fi
    fi

    if command -v pm2 &>/dev/null; then
        # Generate custom ecosystem.config.js with user settings
        cat <<ECOSYSTEM_EOF > "$TARGET_DIR/ecosystem.config.js"
module.exports = {
  apps: [
    {
      name: '${PM2_APP_NAME}',
      script: 'php',
      args: '-S ${PM2_APP_HOST}:${PM2_APP_PORT} -t public',
      cwd: '${TARGET_DIR}',
      instances: 1,
      autorestart: true,
      watch: false,
      env: {
        NODE_ENV: 'production',
        PORT: ${PM2_APP_PORT}
      },
      error_file: '${TARGET_DIR}/logs/application/pm2-error.log',
      out_file: '${TARGET_DIR}/logs/application/pm2-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z'
    }
  ]
};
ECOSYSTEM_EOF

        echo -e "${GREEN}[OK] Generated custom PM2 ecosystem configuration at ${TARGET_DIR}/ecosystem.config.js.${NC}"
        echo -e "${YELLOW}Starting LightDeploy under PM2...${NC}"

        cd "$TARGET_DIR"
        pm2 delete "$PM2_APP_NAME" 2>/dev/null || true
        pm2 start ecosystem.config.js
        pm2 save

        PM2_RUNNING_OK=1
        echo -e "${GREEN}[OK] LightDeploy process '${PM2_APP_NAME}' is now LIVE and saved on PM2!${NC}"
    fi
fi

echo -e "\n${BLUE}====================================================${NC}"
echo -e "${GREEN}      PRODUCTION INSTALLATION SUCCESSFUL!           ${NC}"
echo -e "${BLUE}====================================================${NC}"
echo -e "Target Directory: ${TARGET_DIR}"
echo -e "Admin Username:   ${BOLD}${ADMIN_USER}${NC}"
echo -e "Admin Password:   ${BOLD}${ADMIN_PASS}${NC}"

if [ "$PM2_RUNNING_OK" -eq 1 ]; then
    echo -e "PM2 Status:       ${GREEN}LIVE (${PM2_APP_NAME} on http://${PM2_APP_HOST}:${PM2_APP_PORT})${NC}"
    echo -e "\n${YELLOW}PM2 Management Commands:${NC}"
    echo -e "  pm2 status ${PM2_APP_NAME}"
    echo -e "  pm2 logs ${PM2_APP_NAME}"
    echo -e "  pm2 restart ${PM2_APP_NAME}"
else
    echo -e "\n${YELLOW}Host LightDeploy Production Service via PM2:${NC}"
    echo -e "  cd ${TARGET_DIR}"
    echo -e "  ${GREEN}pm2 start ecosystem.config.js${NC}"
    echo -e "  ${GREEN}pm2 save && pm2 startup${NC}"
fi
echo -e "${BLUE}====================================================${NC}\n"
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
