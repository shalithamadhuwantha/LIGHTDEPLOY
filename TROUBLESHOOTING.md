# LIGHTDEPLOY Operations & Troubleshooting Guide

---

## 1. Common Issues & Solutions

### Issue: "A deployment for this site is already running" (HTTP 409)

**Cause**: A deployment process is currently executing or a stale lock file remains after an ungraceful server reboot.

**Fix**:
1. Check if process PID is actually running:
   ```bash
   ls -l /opt/lightdeploy/runtime/locks/
   cat /opt/lightdeploy/runtime/locks/site-a.lock
   ```
2. If process is dead, remove the lock manually:
   ```bash
   rm /opt/lightdeploy/runtime/locks/site-a.lock
   ```

---

### Issue: SSE Live Output not streaming (Logs appear all at once when finished)

**Cause**: Nginx or Apache FastCGI output buffering is enabled.

**Fix**:
Add `fastcgi_buffering off;` and `proxy_buffering off;` to your Nginx site configuration block:

```nginx
location ~ \.php$ {
    fastcgi_buffering off;
    fastcgi_read_timeout 3600;
    ...
}
```

---

### Issue: "proc_open() has been disabled for security reasons"

**Cause**: `proc_open` is listed in `disable_functions` in `php.ini`.

**Fix**:
1. Open your aaPanel PHP settings (`PHP-8.x` -> **Disabled Functions**).
2. Remove `proc_open` from the list.
3. Reload PHP-FPM service.

---

### Issue: Permission Denied when running script

**Cause**: The deployment `.sh` script is not executable by the PHP-FPM service user (`www` / `www-data`).

**Fix**:
```bash
chmod +x /opt/lightdeploy/scripts/*.sh
chown -R www:www /opt/lightdeploy
```

---

## 2. Checking Application Logs

LightDeploy maintains 3 log channels:
1. **Deployment Execution Logs**: `/opt/lightdeploy/logs/deployments/DEP-XXXXXXXX.log`
2. **Security Audit Logs**: `/opt/lightdeploy/logs/security/audit.log`
3. **Application PHP Error Logs**: `/opt/lightdeploy/logs/application/error.log`
