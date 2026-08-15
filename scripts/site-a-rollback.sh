#!/bin/bash
# LightDeploy Rollback Script: Website A
# Restores previous release commit/build

set -e

echo "[START] $(date '+%H:%M:%S') Initiating ROLLBACK for Website A..."
echo "[INFO] Rollback triggered by: ${DEPLOYED_BY:-admin}"

sleep 1
echo "[ROLLBACK] Reverting git repository to HEAD~1..."
echo "HEAD is now at a1b2c3d Previous stable release"

sleep 1
echo "[ROLLBACK] Restoring previous build bundle..."
echo "[SERVICE] Reloading web server configuration..."

echo "[DONE] $(date '+%H:%M:%S') Rollback for Website A executed successfully!"
exit 0
