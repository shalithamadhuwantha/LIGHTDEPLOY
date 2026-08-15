#!/bin/bash
# LightDeploy Deployment Script: Website B
# PHP Laravel Example

set -e

echo "[START] $(date '+%H:%M:%S') Starting deployment for Website B (Laravel PHP)..."

sleep 1
echo "[GIT] Pulling latest updates from git repository..."
echo "Already up to date."

sleep 1
echo "[COMPOSER] Running composer install --no-dev --optimize-autoloader..."
echo "Installing dependencies from lock file (including require-dev)"
echo "Generating optimized autoload files"

sleep 1
echo "[CACHE] Optimizing Laravel application cache..."
echo "Configuration cache cleared!"
echo "Configuration cached successfully!"
echo "Route cache cleared!"
echo "Routes cached successfully!"

echo "[DONE] $(date '+%H:%M:%S') Deployment for Website B completed successfully!"
exit 0
