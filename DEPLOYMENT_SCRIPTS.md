# Authoring Deployment Scripts for LightDeploy

LightDeploy uses standard Bash shell scripts stored in `/opt/lightdeploy/scripts/` to execute site deployment logic.

---

## Best Practices & Rules

1. **Shebang**: Always start with `#!/bin/bash`.
2. **Error Handling**: Use `set -e` at the beginning of the script so that any command returning a non-zero exit code stops execution immediately.
3. **Executable Bit**: Ensure the script is executable (`chmod +x /opt/lightdeploy/scripts/your-script.sh`).
4. **Environment Variables**: LightDeploy automatically injects the following environment variables into every script execution:
   - `DEPLOYMENT_ID`: Unique deployment identifier (e.g. `DEP-20260815-a1b2c3d4`).
   - `SITE_ID`: Site identifier (e.g. `site-a`).
   - `SITE_NAME`: Configured display name.
   - `SITE_DOMAIN`: Domain associated with site.
   - `DEPLOYED_BY`: Username of administrator who triggered deployment.
   - `IS_ROLLBACK`: `1` if rollback script, `0` otherwise.

---

## Example 1: Node.js / Vue / React Site Deployment

`scripts/site-a.sh`

```bash
#!/bin/bash
set -e

echo "[START] Deployment started for Website A"
cd /www/wwwroot/site-a.example.com

echo "[GIT] Fetching latest code..."
git pull origin main

echo "[DEPENDENCY] Installing Node dependencies..."
npm ci

echo "[BUILD] Building production assets..."
npm run build

echo "[SERVICE] Reloading web server..."
systemctl reload nginx

echo "[DONE] Deployment completed successfully!"
exit 0
```

---

## Example 2: PHP / Laravel Deployment

`scripts/site-b.sh`

```bash
#!/bin/bash
set -e

echo "[START] Starting deployment for Laravel Application"
cd /www/wwwroot/site-b.example.com

echo "[GIT] Pulling latest code..."
git pull origin main

echo "[COMPOSER] Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

echo "[MIGRATE] Running database migrations..."
php artisan migrate --force

echo "[CACHE] Clearing and rebuilding application cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[SERVICE] Reloading PHP-FPM service..."
systemctl reload php-fpm-81

echo "[DONE] Deployment completed successfully!"
exit 0
```

---

## Exit Codes

- `0`: Success (Triggers health check if enabled).
- `Non-Zero (1-255)`: Execution failure (Triggers FAILED status on panel).
