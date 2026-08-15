# LIGHTDEPLOY

> **Lightweight Secure Web Deployment Panel**
> High-performance, zero-database, zero-daemon deployment panel designed specifically for resource-constrained Linux servers running aaPanel, Nginx, or Apache with PHP 8.x.

---

## Key Features

- ⚡ **Ultra-Low Resource Footprint**: 0 MB idle RAM usage. No continuously running daemons, Redis, Docker, or background workers.
- 🔒 **Zero Command Injection Vector**: Arbitrary shell commands from HTTP requests are strictly impossible. Executions are driven exclusively by server-side allowlists (`config/sites.json`).
- 📡 **Real-Time Live Logs**: Stream stdout & stderr live to the browser using Server-Sent Events (SSE).
- 🔁 **Automatic Browser Reconnect**: Refreshing or closing the browser during a deployment automatically reconnects to the active stream without duplicate execution.
- 🔒 **Atomic Concurrency Lock**: Prevents accidental parallel deployments for the same site using file locks (`runtime/locks/`).
- 🛑 **Graceful Process Cancellation**: Terminate hanging processes safely with PID tracking and cleanup.
- 🏥 **Post-Deployment Health Checks**: Automated HTTP GET health verification with configurable retries and delays.
- ⏪ **One-Click Rollbacks**: Support for administrator-configured rollback shell scripts.
- 📜 **Audit History & Logs**: Complete file-based execution and security audit logs (`logs/deployments/` and `logs/security/`).
- 👥 **Role-Based Access Control**: Built-in support for `admin`, `deployer`, and `viewer` roles.

---

## Directory Structure

```
/opt/lightdeploy/
├── app/                      # Application core (Autoloader, Auth, Deployment, Security)
├── public/                   # Web Document Root (ONLY THIS DIRECTORY IS ACCESSIBLE)
│   ├── index.php             # SPA Dashboard
│   ├── login.php             # Login Interface
│   └── assets/               # CSS Design & Vanilla JS Engine
├── config/                   # Administrator Configurations
│   ├── sites.json            # Site allowlist and script mappings
│   ├── users.json            # Credentials & role definitions
│   └── security.php          # Security & timeout settings
├── scripts/                  # Administrator-controlled deployment shell scripts
├── runtime/                  # Runtime state (Locks, Jobs, PIDs, Streams)
├── logs/                     # Application, Deployment, and Audit logs
├── tests/                    # Automated Test Suite
├── install.sh                # Automated Installer
└── uninstall.sh              # Safe Uninstaller
```

---

## Quick Start Options

LightDeploy supports both **Local Machine Testing** (zero root / zero Nginx setup) and **Production Server Installation**.

### Option A: Local Development & Testing (Local Machine)

To test LightDeploy directly on your local machine without installing to `/opt` or configuring Nginx:

```bash
# 1. Run local workspace setup
./install.sh --local

# 2. Launch local development server (Default: http://127.0.0.1:8000)
./serve.sh
```

- Open `http://127.0.0.1:8000` in your browser.
- Login with: **Username**: `admin` | **Password**: `admin123`.

---

### Option B: Production Server Installation (aaPanel / Linux)

To install LightDeploy on a production server:

```bash
git clone https://github.com/your-repo/lightdeploy.git /opt/lightdeploy
cd /opt/lightdeploy
sudo ./install.sh --production
```

For full production server setup and aaPanel integration, see [INSTALL.md](file:///opt/lightdeploy/INSTALL.md).

---

## Security Model & Principles

LightDeploy follows strict security principles:
1. **Never accept shell commands over HTTP.** The browser only passes safe site IDs (`site-a`).
2. **Path traversal prevention.** All scripts are validated using `realpath()` and verified to reside within `/opt/lightdeploy/scripts/`.
3. **Web root isolation.** Only `/opt/lightdeploy/public/` is exposed to Nginx/Apache. Application code, configurations, logs, and scripts cannot be downloaded via HTTP.
4. **Session hardening.** HttpOnly, SameSite=Strict cookies, and session regeneration on authentication.

For detailed security documentation, see [SECURITY.md](file:///opt/lightdeploy/SECURITY.md).

---

## Documentation Sitemap

- 📖 [INSTALL.md](file:///opt/lightdeploy/INSTALL.md) — Installation & aaPanel setup guide.
- 🔒 [SECURITY.md](file:///opt/lightdeploy/SECURITY.md) — Security model, threat analysis, hardening.
- ⚙️ [CONFIGURATION.md](file:///opt/lightdeploy/CONFIGURATION.md) — Configuring websites and security options.
- 📜 [DEPLOYMENT_SCRIPTS.md](file:///opt/lightdeploy/DEPLOYMENT_SCRIPTS.md) — Authoring `.sh` deployment scripts.
- 🔧 [TROUBLESHOOTING.md](file:///opt/lightdeploy/TROUBLESHOOTING.md) — Common operations & fix guides.
- 📡 [API.md](file:///opt/lightdeploy/API.md) — REST & SSE API specification.
- 🚀 [UPGRADE.md](file:///opt/lightdeploy/UPGRADE.md) — Upgrading guidelines.

---

## License

LightDeploy is open-source software released under the MIT License.
