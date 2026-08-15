# LIGHTDEPLOY Security Model & Architecture

LightDeploy was architected from the ground up for high-security deployment management on production Linux servers.

---

## 1. Zero Command Injection Guarantee

### Threat
In traditional web panels, web inputs are passed directly to shell functions (`exec($_POST['command'])` or `system("deploy.sh " . $_GET['path'])`), creating extreme Remote Code Execution (RCE) vulnerabilities.

### Mitigation
LightDeploy strictly rejects arbitrary browser inputs:
- The browser ONLY sends a safe string key (e.g. `{"site_id": "site-a"}`).
- The backend validates `site_id` against regex `^[a-zA-Z0-9_-]{1,64}$`.
- The backend loads script configuration strictly from `/opt/lightdeploy/config/sites.json`.
- The browser NEVER supplies shell commands, arguments, paths, or executables.

---

## 2. Strict Path Validation & Allowlist

Every script executed is subjected to rigorous path security checks in `InputValidator`:
1. **Null Byte Check**: Rejects any path containing null bytes (`\0`).
2. **Extension Check**: Must end in `.sh`.
3. **Regular File & Executable Check**: Must be `is_file()` and `is_executable()`.
4. **Realpath Jail**: Resolves absolute path using `realpath()` and asserts that the canonical path resides inside `realpath(/opt/lightdeploy/scripts/)`. Traversal attacks (`../`) and external symlinks are automatically blocked.

---

## 3. Web Access Isolation

Only `/opt/lightdeploy/public/` is exposed to the web server.

Protected directories:
- `/opt/lightdeploy/app/`
- `/opt/lightdeploy/config/`
- `/opt/lightdeploy/scripts/`
- `/opt/lightdeploy/runtime/`
- `/opt/lightdeploy/logs/`

Direct access attempts to these folders via browser will return HTTP 404/403 due to Nginx rules and directory structure layout.

---

## 4. Session & Authentication Security

- Password Hashing: Bcrypt using `password_hash()` and `password_verify()`. Plaintext passwords are never stored or logged.
- Cookie Flags: `HttpOnly`, `SameSite=Strict`, `Secure` (when HTTPS enabled).
- Session Fixation Protection: Session ID regenerated using `session_regenerate_id(true)` upon successful login.
- CSRF Protection: `X-CSRF-Token` header verification on all state-changing endpoints (`POST /api/deploy`, `POST /api/cancel`, `POST /api/rollback`, `POST /api/logout`).
- Rate Limiting: File-based sliding window rate limiter protects login and deployment endpoints from brute-force attempts.

---

## 5. Security Audit Logging

Security events are written to `/opt/lightdeploy/logs/security/audit.log`.

Logged Events:
- `LOGIN_SUCCESS`, `LOGIN_FAILURE`, `LOGOUT`
- `DEPLOY_STARTED`, `DEPLOY_SUCCESS`, `DEPLOY_FAILED`, `DEPLOY_CANCELLED`
- `ROLLBACK_STARTED`, `ROLLBACK_SUCCESS`, `ROLLBACK_FAILED`
- `CSRF_FAILURE`, `RATE_LIMIT`, `UNAUTHORIZED_ACCESS`, `MALICIOUS_INPUT_ATTEMPT`

Passwords, tokens, API keys, and environment secrets are automatically redacted before logging.
