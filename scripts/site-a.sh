#!/bin/bash
# LightDeploy Deployment Script: Website A
# Node.js / Build Pipeline Example

set -e

echo "[START] $(date '+%H:%M:%S') Starting deployment for Website A..."
echo "[INFO] Deployment ID: ${DEPLOYMENT_ID:-DEP-LOCAL}"
echo "[INFO] Triggered by user: ${DEPLOYED_BY:-admin}"

sleep 1
echo "[GIT] Fetching latest code from origin/main..."
echo "From github.com:example/site-a"
echo " * branch            main       -> FETCH_HEAD"
echo "   a1b2c3d..e4f5g6h  main       -> origin/main"
echo "Updating a1b2c3d..e4f5g6h"
echo "Fast-forward"

sleep 1
echo "[DEPENDENCY] Installing npm dependencies..."
echo "added 142 packages, and audited 143 packages in 2s"

sleep 1
echo "[BUILD] Building production assets..."
echo "vite v5.0.0 building for production..."
echo "dist/assets/index-D7b.js   142.30 kB │ gzip: 45.10 kB"
echo "dist/index.html              0.45 kB │ gzip: 0.28 kB"
echo "[BUILD] Production bundle generated successfully."

sleep 1
echo "[SERVICE] Reloading web server configuration..."
echo "[SERVICE] Nginx configuration reloaded successfully."

echo "[DONE] $(date '+%H:%M:%S') Deployment for Website A completed successfully!"
exit 0
