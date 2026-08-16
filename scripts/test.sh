#!/bin/bash
# LightDeploy Script: pm2-test-node
set -e

echo "[START] $(date '+%H:%M:%S') Starting deployment for pm2-test-node..."
echo "[INFO] Deployment ID: ${DEPLOYMENT_ID:-DEP-LOCAL}"
echo "[INFO] Triggered by user: ${DEPLOYED_BY:-admin}"

sleep 1
echo "[SYNC] Synchronizing code and assets..."
echo "[PM2] Reloading PM2 process testapp..."
if command -v pm2 >/dev/null 2>&1; then pm2 reload testapp || pm2 start /home/shalith/pm2-test/app.js --name testapp; fi
sleep 1
echo "[DONE] $(date '+%H:%M:%S') Deployment for pm2-test-node completed successfully!"
exit 0
