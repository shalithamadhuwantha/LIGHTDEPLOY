<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY SPA Dashboard
 */

$config = require_once dirname(__DIR__) . '/app/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$authService = new AuthService($config['config_dir'] . '/users.json');
$user = $authService->requireAuth();
$csrfToken = Csrf::getToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - LightDeploy</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="dashboard-body" data-user-role="<?= htmlspecialchars($user['role']) ?>" data-csrf-token="<?= htmlspecialchars($csrfToken) ?>">
    <!-- Top Navigation Bar -->
    <header class="app-header">
        <div class="header-left">
            <div class="brand">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
                <span class="brand-title">LIGHTDEPLOY</span>
                <span class="badge badge-version">v1.0</span>
            </div>
        </div>

        <div class="header-center" id="serverMetricsWidget">
            <div class="metric-pill" id="metricCpu">
                <span class="metric-label">CPU</span>
                <span class="metric-value">--%</span>
            </div>
            <div class="metric-pill" id="metricRam">
                <span class="metric-label">RAM</span>
                <span class="metric-value">--%</span>
            </div>
            <div class="metric-pill" id="metricDisk">
                <span class="metric-label">DISK</span>
                <span class="metric-value">--%</span>
            </div>
            <div class="metric-pill" id="metricUptime">
                <span class="metric-label">UPTIME</span>
                <span class="metric-value">--</span>
            </div>
        </div>

        <div class="header-right">
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                <span class="badge badge-role badge-role-<?= htmlspecialchars($user['role']) ?>"><?= strtoupper(htmlspecialchars($user['role'])) ?></span>
            </div>
            <button id="logoutBtn" class="btn btn-outline-danger btn-sm">Logout</button>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="app-content">
        <div class="section-header">
            <div>
                <h2>Configured Websites</h2>
                <p class="section-desc">Select a site to initiate controlled script deployment</p>
            </div>
            <div class="section-actions">
                <button id="refreshSitesBtn" class="btn btn-secondary btn-sm">Refresh List</button>
                <button id="viewHistoryBtn" class="btn btn-secondary btn-sm">Deployment History</button>
            </div>
        </div>

        <!-- Global Toast Alert Container -->
        <div id="toastContainer" class="toast-container"></div>

        <!-- Sites Grid -->
        <div id="sitesGrid" class="sites-grid">
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
        </div>
    </main>

    <!-- Active Deployment / Terminal Modal -->
    <div id="deploymentModal" class="modal-overlay hidden">
        <div class="modal-card modal-lg">
            <div class="modal-header">
                <div>
                    <div class="modal-title-row">
                        <h3 id="modalSiteTitle">Deployment</h3>
                        <span id="modalStatusBadge" class="badge badge-status badge-status-running">RUNNING</span>
                    </div>
                    <div class="modal-sub-info">
                        <span>ID: <code id="modalDepId">DEP-00000000-0000</code></span>
                        &bull;
                        <span>Started: <span id="modalStartTime">--</span></span>
                    </div>
                </div>
                <button id="closeModalBtn" class="modal-close-btn">&times;</button>
            </div>

            <div class="modal-body">
                <!-- Terminal Output Window -->
                <div class="terminal-container">
                    <div class="terminal-header">
                        <span class="terminal-dot red"></span>
                        <span class="terminal-dot yellow"></span>
                        <span class="terminal-dot green"></span>
                        <span class="terminal-title">live_execution.log</span>
                        <label class="terminal-autoscroll">
                            <input type="checkbox" id="autoscrollCheck" checked> Auto-scroll
                        </label>
                    </div>
                    <pre id="terminalOutput" class="terminal-body">Connecting to live SSE stream...</pre>
                </div>
            </div>

            <div class="modal-footer">
                <button id="modalCancelBtn" class="btn btn-danger">Cancel Deployment</button>
                <button id="modalRollbackBtn" class="btn btn-warning hidden">Rollback</button>
                <button id="modalDeployAgainBtn" class="btn btn-primary hidden">Deploy Again</button>
                <button id="modalCloseFooterBtn" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- History Drawer / Modal -->
    <div id="historyModal" class="modal-overlay hidden">
        <div class="modal-card modal-xl">
            <div class="modal-header">
                <h3>Deployment Audit History</h3>
                <button id="closeHistoryBtn" class="modal-close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Deployment ID</th>
                                <th>Website</th>
                                <th>Triggered By</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr><td colspan="7" class="text-center">Loading audit history...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button id="closeHistoryFooterBtn" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>
