#!/bin/bash
# LightDeploy Script: test011
set -e

echo "[START] $(date '+%H:%M:%S') Starting deployment for test011..."
echo "[INFO] Deployment ID: ${DEPLOYMENT_ID:-DEP-LOCAL}"
echo "[INFO] Triggered by user: ${DEPLOYED_BY:-admin}"

sleep 1
echo "[SYNC] Synchronizing code and assets..."
echo "[PM2] Reloading PM2 ecosystem config /home/shalith/Documents/CYBERnetic/test/ecosystem.config.js..."
if command -v pm2 >/dev/null 2>&1; then pm2 reload /home/shalith/Documents/CYBERnetic/test/ecosystem.config.js || pm2 start /home/shalith/Documents/CYBERnetic/test/ecosystem.config.js; fi
sleep 1
echo "[DONE] $(date '+%H:%M:%S') Deployment for test011 completed successfully!"
exit 0
