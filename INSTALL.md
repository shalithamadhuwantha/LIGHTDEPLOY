# LIGHTDEPLOY Installation & aaPanel Integration Guide

This guide covers installing LightDeploy on a Linux server running aaPanel, Nginx, or Apache.

---

## Requirements & Interactive Auto-Installer

LightDeploy includes an automated **Dependency & Prerequisite Scanner**. When running `./install.sh`, it automatically checks system binaries, PHP version, and PHP extensions, presenting a clear `[HAVE]` vs `[NOT HAVE]` report.

If missing dependencies are detected, the installer will prompt:
`May I install the missing dependencies automatically? (y/N):`

If accepted, it detects your package manager (`apt-get`, `dnf`, `yum`, `apk`, `pacman`) and installs the required packages.

### System Prerequisites
- **Operating System**: Linux (Ubuntu 20.04+, Debian 10+, CentOS 7+/Rocky Linux, AlmaLinux, Alpine)
- **Web Server**: Nginx or Apache (managed by aaPanel or standalone)
- **PHP**: PHP 8.0, 8.1, 8.2, 8.3 with PHP-FPM
- **PHP Extensions**: `json`, `session`, `curl` (Required), `mbstring`, `zip` (Recommended)
- **PHP Functions**: `proc_open` must be enabled (not listed in `disable_functions` in `php.ini`)
- **System Tools**: `git`, `curl`, `rsync`, `tar`, `unzip`

---

## Installation Options

LightDeploy can be run in two modes:

1. **Local Machine Testing Mode**: For testing directly in your workspace directory without root or Nginx.
2. **Production Server Mode**: For installing on a Linux server running aaPanel / Nginx / Apache under `/opt/lightdeploy`.

---

## Mode 1: Local Machine Testing Setup (No Root Required)

```bash
# Navigate to project directory
cd /path/to/lightdeploy

# Run local setup wizard
./install.sh --local

# Start built-in local development web server
./serve.sh
```

Navigate to `http://127.0.0.1:8000` in your browser. Log in with:
- **Username**: `admin`
- **Password**: `admin123`

---

## Mode 2: Production Server Setup (/opt/lightdeploy)

Run the following commands as `root`:

```bash
cd /opt
git clone https://github.com/your-repo/lightdeploy.git
cd lightdeploy
chmod +x install.sh
sudo ./install.sh --production
```

The installer will:
1. Scan all system tools, PHP version, functions, and extensions (`[HAVE]` / `[NOT HAVE]`).
2. Prompt to auto-install any missing dependencies using your OS package manager.
3. Verify that `proc_open()` is enabled in `php.ini`.
4. Deploy files to `/opt/lightdeploy`.
4. Set directory permissions (`755` for public, `700` for configs/logs).
5. Generate a random password for the default `admin` account.

---

## Step 2: Configure aaPanel Website

1. Log into your **aaPanel Control Panel**.
2. Click **Website** -> **Add Site**.
3. Set domain (e.g. `deploy.yourdomain.com`).
4. Set **PHP Version** to `PHP-8.x`.
5. After creating the website, go to **Site Settings** -> **Site Directory**.
6. Set **Website Directory** / **Document Root** to:
   `/opt/lightdeploy/public`
7. Uncheck "Anti-XSS attack (open_basedir)" or add `/opt/lightdeploy` to the allowed path.

---

## Step 3: Nginx Configuration Rules

In aaPanel **Site Settings** -> **Config**, ensure your Nginx configuration includes:

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name deploy.yourdomain.com;

    root /opt/lightdeploy/public;
    index index.php;

    # Protect internal application directories
    location ~ ^/(app|config|logs|runtime|scripts|releases|tests)/ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include enable-php-81.conf; # Match your PHP version
        fastcgi_pass unix:/tmp/php-cgi-81.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Disable FastCGI buffering for SSE real-time log streaming
        fastcgi_buffering off;
        fastcgi_read_timeout 3600;
    }
}
```

---

## Step 4: Verify Installation & Sanity Test

Run the automated test suite to confirm everything is working properly:

```bash
cd /opt/lightdeploy
php tests/test_runner.php
```

You should see all security, path traversal, command injection, and deployment tests passing with `PASS`.
