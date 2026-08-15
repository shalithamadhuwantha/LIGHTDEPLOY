#!/bin/bash
# ==============================================================================
# LIGHTDEPLOY LOCAL DEVELOPMENT SERVER LAUNCHER
# Easily run and test LightDeploy on your local machine without root or Nginx!
# ==============================================================================

set -e

PORT="${1:-8000}"
HOST="127.0.0.1"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[0;33m'
NC='\033[0m'

echo -e "${BLUE}====================================================${NC}"
echo -e "${BLUE}    LIGHTDEPLOY LOCAL DEVELOPMENT RUNNER            ${NC}"
echo -e "${BLUE}====================================================${NC}"

# Check PHP binary
if ! command -v php &> /dev/null; then
    echo -e "\033[0;31m[ERROR] PHP CLI is not installed or not in system PATH.\033[0m"
    exit 1
fi

# Prepare local workspace directories
mkdir -p "$SRC_DIR"/{runtime/{locks,jobs,pids,streams},logs/{deployments,security,application},releases}
chmod -R 777 "$SRC_DIR"/runtime "$SRC_DIR"/logs 2>/dev/null || true
chmod +x "$SRC_DIR"/scripts/*.sh 2>/dev/null || true

echo -e "${GREEN}[OK] Local workspace initialized.${NC}"
echo -e "${YELLOW}Server URL:${NC} http://${HOST}:${PORT}"
echo -e "${YELLOW}Dashboard Login Credentials:${NC}"
echo -e "   Username: ${GREEN}admin${NC}"
echo -e "   Password: ${GREEN}admin123${NC} (or deployer / viewer)"
echo -e "${BLUE}----------------------------------------------------${NC}"
echo -e "Press Ctrl+C to stop the local server.\n"

cd "$SRC_DIR"
php -S "${HOST}:${PORT}" -t public public/router.php
