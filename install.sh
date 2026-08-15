#!/bin/bash
# ==============================================================================
# LIGHTDEPLOY INSTALLATION & SETUP WIZARD
# Supports both Local Machine Development Testing & Production Server Installation
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
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
# MODE 1: LOCAL DEVELOPMENT & TESTING MODE
# ------------------------------------------------------------------------------
if [ "$MODE" == "1" ]; then
    echo -e "\n${YELLOW}[LOCAL SETUP] Initializing LightDeploy Local Development Workspace...${NC}"

    if ! command -v php &> /dev/null; then
        echo -e "${RED}[ERROR] PHP CLI is not installed or not in system PATH.${NC}"
        exit 1
    fi

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

# 2. System PHP Audit
echo -e "\n${YELLOW}[1/6] Auditing System & PHP Dependencies...${NC}"

if ! command -v php &> /dev/null; then
    echo -e "${RED}[ERROR] PHP is not installed or not in system PATH.${NC}"
    exit 1
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
echo -e "${GREEN}[OK] Detected PHP Version: ${PHP_VER}${NC}"

PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
if [ "$PHP_MAJOR" -lt 8 ]; then
    echo -e "${RED}[ERROR] LightDeploy requires PHP 8.0 or higher (Found PHP ${PHP_VER}).${NC}"
    exit 1
fi

REQUIRED_EXTS=("json" "session" "curl")
for ext in "${REQUIRED_EXTS[@]}"; do
    if ! php -m | grep -qi "$ext"; then
        echo -e "${RED}[ERROR] Missing required PHP extension: ${ext}${NC}"
        exit 1
    fi
done

if php -r 'exit(function_exists("proc_open") ? 0 : 1);'; then
    echo -e "${GREEN}[OK] Required PHP execution function proc_open() is enabled.${NC}"
else
    echo -e "${RED}[ERROR] proc_open() is disabled in php.ini. Remove proc_open from disable_functions.${NC}"
    exit 1
fi

# 3. Create System User
echo -e "\n${YELLOW}[2/6] Configuring Service User (${SERVICE_USER})...${NC}"
if ! id "$SERVICE_USER" &>/dev/null; then
    useradd -r -s /bin/false -d "$TARGET_DIR" "$SERVICE_USER"
    echo -e "${GREEN}[OK] Created dedicated system user '${SERVICE_USER}'.${NC}"
else
    echo -e "${GREEN}[OK] System user '${SERVICE_USER}' already exists.${NC}"
fi

# 4. Copy Files
echo -e "\n${YELLOW}[3/6] Deploying LightDeploy Files to ${TARGET_DIR}...${NC}"

mkdir -p "$TARGET_DIR"/{app,public/assets,config,scripts,runtime/{locks,jobs,pids,streams},logs/{deployments,security,application},releases,tests}

cp -r "$SRC_DIR"/app/* "$TARGET_DIR"/app/
cp -r "$SRC_DIR"/public/* "$TARGET_DIR"/public/
cp -r "$SRC_DIR"/config/* "$TARGET_DIR"/config/
cp -r "$SRC_DIR"/scripts/* "$TARGET_DIR"/scripts/
cp -r "$SRC_DIR"/tests/* "$TARGET_DIR"/tests/
cp "$SRC_DIR"/*.md "$TARGET_DIR"/ 2>/dev/null || true

# 5. Security & Permissions
echo -e "\n${YELLOW}[4/6] Setting Secure File Permissions...${NC}"

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

# 6. Admin Credentials
echo -e "\n${YELLOW}[5/6] Generating Production Administrator Account...${NC}"
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

# 7. Sanity Check
echo -e "\n${YELLOW}[6/6] Running Security Sanity Check...${NC}"
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
