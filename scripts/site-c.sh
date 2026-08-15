#!/bin/bash
# LightDeploy Deployment Script: Website C
# Static Website Sync Example

set -e

echo "[START] $(date '+%H:%M:%S') Starting static site sync for Website C..."

sleep 1
echo "[SYNC] Synchronizing static HTML/CSS/JS files..."
echo "sending incremental file list"
echo "index.html"
echo "styles.css"
echo "sent 2,410 bytes  received 42 bytes  4,904.00 bytes/sec"

echo "[DONE] $(date '+%H:%M:%S') Website C static sync completed!"
exit 0
