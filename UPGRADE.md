# LIGHTDEPLOY Upgrade Guide

This document outlines instructions for upgrading LightDeploy to newer releases.

---

## Zero-Downtime Upgrade Steps

1. Backup your existing configuration and user database:
   ```bash
   cp /opt/lightdeploy/config/sites.json /tmp/sites_backup.json
   cp /opt/lightdeploy/config/users.json /tmp/users_backup.json
   ```

2. Pull the latest release repository:
   ```bash
   cd /tmp
   git clone https://github.com/your-repo/lightdeploy.git lightdeploy_new
   ```

3. Copy new application files over `/opt/lightdeploy/`:
   ```bash
   cp -r /tmp/lightdeploy_new/app/* /opt/lightdeploy/app/
   cp -r /tmp/lightdeploy_new/public/* /opt/lightdeploy/public/
   ```

4. Restore user configuration files:
   ```bash
   cp /tmp/sites_backup.json /opt/lightdeploy/config/sites.json
   cp /tmp/users_backup.json /opt/lightdeploy/config/users.json
   ```

5. Reset ownership permissions:
   ```bash
   chown -R www:www /opt/lightdeploy
   ```

6. Run the automated test suite to verify upgrade:
   ```bash
   cd /opt/lightdeploy
   php tests/test_runner.php
   ```
