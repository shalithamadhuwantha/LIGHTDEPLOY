# LIGHTDEPLOY Configuration Guide

LightDeploy relies on JSON and PHP configuration files located in `/opt/lightdeploy/config/`.

---

## 1. Configuring Websites (`config/sites.json`)

File location: `/opt/lightdeploy/config/sites.json`

Example:

```json
{
    "sites": {
        "site-a": {
            "name": "Website A (Frontend Build)",
            "domain": "site-a.example.com",
            "script": "/opt/lightdeploy/scripts/site-a.sh",
            "rollback_script": "/opt/lightdeploy/scripts/site-a-rollback.sh",
            "health_check": "https://site-a.example.com/health",
            "health_check_enabled": true,
            "enabled": true
        },
        "site-b": {
            "name": "Website B (Backend API)",
            "domain": "site-b.example.com",
            "script": "/opt/lightdeploy/scripts/site-b.sh",
            "rollback_script": null,
            "health_check": "https://site-b.example.com/api/ping",
            "health_check_enabled": true,
            "enabled": true
        }
    }
}
```

### Options Breakdown

- `name` *(string)*: Display name on the dashboard.
- `domain` *(string)*: Domain name associated with the site.
- `script` *(string)*: Absolute path to the deployment `.sh` script. MUST reside inside `/opt/lightdeploy/scripts/` and be executable.
- `rollback_script` *(string|null)*: Path to optional rollback `.sh` script. Set `null` if disabled.
- `health_check` *(string|null)*: Target URL for post-deployment HTTP GET verification.
- `health_check_enabled` *(boolean)*: Enable or disable post-deployment health check.
- `enabled` *(boolean)*: Enable or disable site deployments.

---

## 2. Security Settings (`config/security.php`)

File location: `/opt/lightdeploy/config/security.php`

```php
<?php
return [
    'session_lifetime' => 86400,          // Session lifetime in seconds
    'max_concurrent_deployments' => 1,     // Maximum parallel site deployments
    'default_deployment_timeout' => 1800,  // Execution cutoff in seconds (30 min)
    
    // Post-Deployment Health Check Tuning
    'health_check_timeout' => 10,          // HTTP GET timeout (seconds)
    'health_check_retries' => 3,           // Retry attempts
    'health_check_delay' => 2,             // Delay between retries (seconds)
    
    // Rate Limiting
    'login_rate_limit_attempts' => 5,      // Failed logins per window
    'login_rate_limit_window' => 900,      // Window size (15 minutes)
    'deploy_rate_limit_attempts' => 10,    // Deployments per user per window
    'deploy_rate_limit_window' => 60,      // Window size (1 minute)
];
```

---

## 3. Users & Roles (`config/users.json`)

File location: `/opt/lightdeploy/config/users.json`

```json
{
    "users": {
        "admin": {
            "name": "System Administrator",
            "password_hash": "$2y$10$...",
            "role": "admin"
        },
        "deployer": {
            "name": "Release Engineer",
            "password_hash": "$2y$10$...",
            "role": "deployer"
        },
        "viewer": {
            "name": "Audit Viewer",
            "password_hash": "$2y$10$...",
            "role": "viewer"
        }
    }
}
```

### Supported Roles

1. `admin`: Full access (deploy, rollback, cancel, view logs, configure).
2. `deployer`: Deploy, cancel, view logs.
3. `viewer`: Read-only access to dashboard and logs.
