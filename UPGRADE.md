# LIGHTDEPLOY Zero-Data-Loss Upgrade Guide

This document outlines instructions for upgrading LightDeploy to newer releases without losing any user accounts, configured websites, database settings, or custom deployment scripts.

---

## Method 1: Automatic 1-Command Upgrade (Recommended)

To upgrade LightDeploy to the latest source code version without losing any data:

```bash
# 1. Pull the latest code repository
git pull

# 2. Run the Safe Upgrade Engine (requires root / sudo)
sudo ./install.sh --update
```

### What `--update` Automatically Handles:
1. **Safety Backup**: Creates an automatic snapshot in `/opt/lightdeploy/backups/pre_upgrade_<timestamp>/`.
2. **Core Engine Update**: Updates `/opt/lightdeploy/app/`, `/opt/lightdeploy/public/`, and test suites.
3. **Data Preservation**: Preserves all existing configurations:
   - `config/users.json` (User accounts and passwords intact)
   - `config/sites.json` (Configured websites and PM2 settings intact)
   - `config/databases.json` (MySQL backup credentials intact)
   - `config/vps_ports.json` (Saved port notes intact)
   - `config/ecosystem.*.config.js` (Custom PM2 ecosystem scripts intact)
   - `scripts/*` (All custom deployment bash scripts intact)
4. **Zero-Downtime Reload**: Automatically executes `pm2 reload lightdeploy` if PM2 is running.

---

## Method 2: Interactive Installer

If you run the standard installer on a server where `/opt/lightdeploy` already exists:

```bash
sudo ./install.sh
```

Select **Option 1**:
```
  1) Safe Upgrade / Update Existing LightDeploy (Preserves all users, sites, databases & scripts)
```

---

## Method 3: Manual Step-by-Step Upgrade

If you prefer to perform a manual upgrade:

1. Copy only application code directories over `/opt/lightdeploy/`:
   ```bash
   sudo cp -r app/* /opt/lightdeploy/app/
   sudo cp -r public/* /opt/lightdeploy/public/
   sudo cp -r tests/* /opt/lightdeploy/tests/
   ```

2. Re-apply file permissions:
   ```bash
   sudo chown -R www-data:www-data /opt/lightdeploy  # Or www:www for aaPanel
   sudo chmod -R 700 /opt/lightdeploy/app /opt/lightdeploy/config
   sudo chmod -R 755 /opt/lightdeploy/public
   ```

3. Reload PM2:
   ```bash
   pm2 reload lightdeploy
   ```
