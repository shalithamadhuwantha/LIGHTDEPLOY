# LIGHTDEPLOY API Specification

All state-changing endpoints (`POST`) require:
1. Valid Session Cookie (`LIGHTDEPLOY_SESS`).
2. CSRF Token header `X-CSRF-Token` or form field `csrf_token`.

All API responses follow the standard JSON format:

```json
{
    "success": true,
    "data": { ... }
}
```

Error format:

```json
{
    "success": false,
    "error": {
        "code": "DEPLOYMENT_ALREADY_RUNNING",
        "message": "A deployment for this site is already running."
    }
}
```

---

## Endpoint Reference

### 1. `POST /api/login.php`
- **Auth**: Public
- **Rate Limit**: 5 attempts per 15 minutes
- **Body**: `{"username": "admin", "password": "..."}`
- **Response**: `200 OK` + session cookie + CSRF token.

---

### 2. `POST /api/logout.php`
- **Auth**: Required
- **CSRF**: Required
- **Response**: `200 OK`

---

### 3. `GET /api/sites.php`
- **Auth**: Required (`admin`, `deployer`, `viewer`)
- **Query Params**: `id` (optional single site filter)
- **Response**: List of configured sites, locks, and last deployment metadata.

---

### 4. `POST /api/deploy.php`
- **Auth**: Required (`admin`, `deployer`)
- **CSRF**: Required
- **Body**: `{"site_id": "site-a"}`
- **Response**: `201 Created`
  ```json
  {
      "success": true,
      "deployment_id": "DEP-20260815-a1b2c3d4",
      "site_id": "site-a",
      "status": "running",
      "stream_url": "/api/stream.php?deployment_id=DEP-20260815-a1b2c3d4"
  }
  ```

---

### 5. `GET /api/stream.php?deployment_id=...`
- **Auth**: Required
- **Header**: `Accept: text/event-stream`
- **Event Types**:
  - `event: log` -> `{ "line": "[GIT] Pulling code..." }`
  - `event: status` -> `{ "status": "running", ... }`
  - `event: end` -> `{ "status": "success", "deployment_id": "..." }`

---

### 6. `POST /api/cancel.php`
- **Auth**: Required (`admin`, `deployer`)
- **CSRF**: Required
- **Body**: `{"deployment_id": "DEP-20260815-a1b2c3d4"}`
- **Response**: `200 OK`

---

### 7. `POST /api/rollback.php`
- **Auth**: Required (`admin`)
- **CSRF**: Required
- **Body**: `{"site_id": "site-a"}`
- **Response**: `201 Created`

---

### 8. `GET /api/history.php`
- **Auth**: Required
- **Query Params**: `limit` (default 50)
- **Response**: Array of historical deployment objects.

---

### 9. `GET /api/server_status.php`
- **Auth**: Required
- **Response**: Server CPU %, RAM %, Disk %, Uptime metrics.
