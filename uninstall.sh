#!/bin/bash
# ==============================================================================
# LIGHTDEPLOY UNINSTALLATION SCRIPT
# Safely removes LightDeploy panel without touching aaPanel or website files.
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

TARGET_DIR="/opt/lightdeploy"

echo -e "${RED}====================================================${NC}"
echo -e "${RED}        LIGHTDEPLOY UNINSTALLATION WIZARD           ${NC}"
echo -e "${RED}====================================================${NC}"

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERROR] Please run uninstall.sh as root (sudo ./uninstall.sh).${NC}"
    exit 1
fi

echo -e "${YELLOW}WARNING: This script will terminate active LightDeploy processes and remove ${TARGET_DIR}.${NC}"
echo -e "${GREEN}NOTE: Your managed websites, source code, aaPanel configurations, and databases WILL NOT BE TOUCHED.${NC}"
read -p "Are you sure you want to proceed with uninstallation? (y/N): " CONFIRM

if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
    echo "Uninstallation cancelled."
    exit 0
fi

echo -e "\n[1/3] Terminating any running deployment processes..."
if [ -d "$TARGET_DIR/runtime/pids" ]; then
    for pidfile in "$TARGET_DIR/runtime/pids"/*.pid; do
        if [ -f "$pidfile" ]; then
            PID=$(cat "$pidfile")
            if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
                echo "Terminating deployment PID $PID..."
                kill -15 "$PID" 2>/dev/null || true
            fi
        fi
    done
fi

echo -e "[2/3] Removing LightDeploy application files in ${TARGET_DIR}..."
if [ -d "$TARGET_DIR" ]; then
    rm -rf "$TARGET_DIR"
    echo -e "${GREEN}[OK] Removed ${TARGET_DIR}${NC}"
fi

echo -e "[3/3] Cleaning up system temporary locks..."
rm -f /tmp/lightdeploy_*.tmp 2>/dev/null || true

echo -e "\n${GREEN}LightDeploy uninstallation completed successfully.${NC}"
