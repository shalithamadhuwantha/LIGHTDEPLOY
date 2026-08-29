<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY SPA Dashboard
 */

$config = require_once dirname(__DIR__) . '/app/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$authService = new AuthService($config['config_dir'] . '/users.json');
if (!$authService->isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$user = $authService->getCurrentUser();
$csrfToken = Csrf::getToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - LightDeploy by Blue Octopus</title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= time() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script>
        function openUserMgmtModal() {
            var modal = document.getElementById('userMgmtModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
            if (window.loadUsersList) {
                window.loadUsersList();
            }
        }
        function closeUserMgmtModal() {
            var modal = document.getElementById('userMgmtModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openUserEditModal(usernameToEdit) {
            var modal = document.getElementById('userEditModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '100000', 'important');
            }
            if (window.prepareUserEditModal) {
                window.prepareUserEditModal(usernameToEdit);
            }
        }
        function closeUserEditModal() {
            var modal = document.getElementById('userEditModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openVpsPortsModal() {
            var modal = document.getElementById('portsModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
            if (window.loadVpsPorts) {
                window.loadVpsPorts();
            }
        }
        function closeVpsPortsModal() {
            var modal = document.getElementById('portsModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openDbBackupsModal() {
            window.location.href = '/databases.php';
        }
        function closeDbBackupsModal() {
            var modal = document.getElementById('dbBackupsModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openAddDbModal() {
            var modal = document.getElementById('addDbModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '100000', 'important');
            }
        }
        function closeAddDbModal() {
            var modal = document.getElementById('addDbModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openUpdateSystemModal() {
            var modal = document.getElementById('updateSystemModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
            if (window.checkSystemUpdates) {
                window.checkSystemUpdates();
            }
        }
        function closeUpdateSystemModal() {
            var modal = document.getElementById('updateSystemModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openScriptGenModal(mode) {
            var modal = document.getElementById('scriptGenModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
            if (window.populateSgSiteDropdown) {
                window.populateSgSiteDropdown();
            }
            if (window.setScriptGenMode) {
                window.setScriptGenMode(mode || 'bash');
            } else if (window.updateScriptPreview) {
                window.updateScriptPreview();
            }
        }
        function closeScriptGenModal() {
            var modal = document.getElementById('scriptGenModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
    </script>
</head>
<body class="dashboard-body" data-user-role="<?= htmlspecialchars($user['role']) ?>" data-username="<?= htmlspecialchars($user['username']) ?>" data-csrf-token="<?= htmlspecialchars($csrfToken) ?>" data-allowed-functions='<?= json_encode($user['allowed_functions'] ?? ['*']) ?>' data-allowed-systems='<?= json_encode($user['allowed_systems'] ?? ['*']) ?>'>
    <!-- Top Navigation Bar -->
    <header class="app-header">
        <div class="header-left">
            <div class="brand">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
                <span class="brand-title">LIGHTDEPLOY <span style="font-size: 0.62rem; color: #a855f7; font-weight: 600; letter-spacing: 0.5px; background: rgba(168, 85, 247, 0.15); padding: 2px 7px; border-radius: 4px; margin-left: 4px; border: 1px solid rgba(168, 85, 247, 0.3); text-transform: uppercase;">BY BLUE OCTOPUS</span></span>
                <span class="badge badge-version">v1.2.5</span>
            </div>
        </div>

        <div class="header-center">
            <div class="header-status-pill" title="Live Server Status">
                <span class="status-pulse"></span>
                <span style="font-size: 0.75rem; font-weight: 700; color: #6ee7b7; letter-spacing: 0.5px;">SYSTEM ONLINE</span>
            </div>
        </div>

        <div class="header-right">
            <?php if ($authService->hasPermission('db_backups')): ?>
                <a id="headerDbBackupsBtn" href="/databases.php" class="btn btn-secondary btn-sm btn-db-backups" style="margin-right: 6px; text-decoration: none;">🗄️ Database Backups</a>
            <?php endif; ?>
            <?php if ($authService->hasPermission('vps_ports')): ?>
                <button id="headerViewPortsBtn" class="btn btn-secondary btn-sm btn-view-ports" style="margin-right: 6px;" onclick="openVpsPortsModal()">🌐 VPS Ports</button>
            <?php endif; ?>
            <?php if ($authService->hasPermission('script_gen')): ?>
                <button id="headerScriptGenBtn" class="btn btn-primary btn-sm" style="margin-right: 6px; background: linear-gradient(135deg, #7c3aed, #a855f7);" onclick="openScriptGenModal()">📜 Script Generator</button>
            <?php endif; ?>
            <?php if ($authService->hasPermission('update_system')): ?>
                <button id="headerUpdateSystemBtn" class="btn btn-primary btn-sm" style="margin-right: 6px; background: linear-gradient(135deg, #059669, #10b981);" onclick="openUpdateSystemModal()">🔄 Update System</button>
            <?php endif; ?>
            <?php if ($authService->hasPermission('user_mgmt')): ?>
                <button id="headerUserMgmtBtn" class="btn btn-secondary btn-sm" style="margin-right: 6px; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; border: none;" onclick="openUserMgmtModal()">👥 Manage Users</button>
            <?php endif; ?>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                <span class="badge badge-role badge-role-<?= htmlspecialchars($user['role']) ?>"><?= strtoupper(htmlspecialchars($user['role'])) ?></span>
            </div>
            <button id="logoutBtn" class="btn btn-outline-danger btn-sm">Logout</button>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="app-content">
        <!-- Server Performance & Resource Meters Card (Horizontal Meter System) -->
        <section class="server-status-banner">
            <div class="status-banner-header">
                <div class="status-banner-title">
                    <span class="status-pulse"></span>
                    <h3>Server Performance &amp; Resource Status</h3>
                </div>
                <div class="status-banner-meta">
                    <span class="badge badge-version">VPS NODE</span>
                    <span class="uptime-label" style="font-size: 0.8rem; color: var(--text-muted);">UPTIME: <strong id="bodyUptimeVal" style="color: #6ee7b7; font-family: var(--font-mono);">--</strong></span>
                </div>
            </div>
            <div class="server-meters-grid" id="serverMetricsWidget">
                <!-- 1. OVERALL LOAD -->
                <div class="body-metric-card meter-overall" id="metricOverall" title="Unified System Load Index (CPU + RAM + DISK)">
                    <div class="body-metric-header">
                        <div class="body-metric-title">
                            <span class="body-metric-icon">⚡</span>
                            <span class="body-metric-name" style="color: #c4b5fd;">OVERALL LOAD</span>
                        </div>
                        <span class="body-metric-val metric-value" style="color: #a855f7;">--%</span>
                    </div>
                    <div class="meter-track">
                        <div class="meter-fill meter-overall" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- 2. CPU USAGE -->
                <div class="body-metric-card meter-cpu" id="metricCpu" title="Server CPU Load Average">
                    <div class="body-metric-header">
                        <div class="body-metric-title">
                            <span class="body-metric-icon">💻</span>
                            <span class="body-metric-name">CPU USAGE</span>
                        </div>
                        <span class="body-metric-val metric-value">--%</span>
                    </div>
                    <div class="meter-track">
                        <div class="meter-fill meter-cpu" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- 3. RAM USAGE -->
                <div class="body-metric-card meter-ram" id="metricRam" title="System Memory Usage">
                    <div class="body-metric-header">
                        <div class="body-metric-title">
                            <span class="body-metric-icon">🧠</span>
                            <span class="body-metric-name">RAM USAGE</span>
                        </div>
                        <span class="body-metric-val metric-value">--%</span>
                    </div>
                    <div class="meter-track">
                        <div class="meter-fill meter-ram" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- 4. DISK SPACE -->
                <div class="body-metric-card meter-disk" id="metricDisk" title="Disk Space Usage">
                    <div class="body-metric-header">
                        <div class="body-metric-title">
                            <span class="body-metric-icon">💾</span>
                            <span class="body-metric-name">DISK SPACE</span>
                        </div>
                        <span class="body-metric-val metric-value">--%</span>
                    </div>
                    <div class="meter-track">
                        <div class="meter-fill meter-disk" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- 5. APP RAM FOOTPRINT -->
                <div class="body-metric-card meter-app" id="metricAppRam" title="LightDeploy App Memory Footprint">
                    <div class="body-metric-header">
                        <div class="body-metric-title">
                            <span class="body-metric-icon">🚀</span>
                            <span class="body-metric-name" style="color: #38bdf8;">APP RAM</span>
                        </div>
                        <span class="body-metric-val metric-value" id="metricAppRamVal" style="color: #38bdf8;">-- MB</span>
                    </div>
                    <div class="meter-track">
                        <div class="meter-fill meter-app" style="width: 20%;"></div>
                    </div>
                </div>
            </div>
        </section>

        <div class="section-header">
            <div>
                <h2>Configured Websites</h2>
                <p class="section-desc">Select a site to initiate controlled script deployment &bull; <span id="sitesCountLabel">0 sites</span></p>
            </div>
            <div class="section-actions">
                <?php if ($authService->hasPermission('add_edit_sites')): ?>
                    <button id="addSiteBtn" class="btn btn-primary btn-sm">+ Add New Site</button>
                <?php endif; ?>
                <button id="refreshSitesBtn" class="btn btn-secondary btn-sm">Refresh List</button>
                <?php if ($authService->hasPermission('vps_ports')): ?>
                    <button id="viewPortsBtn" class="btn btn-secondary btn-sm btn-view-ports" onclick="openVpsPortsModal()">🌐 VPS Open Ports</button>
                <?php endif; ?>
                <?php if ($authService->hasPermission('deploy_history')): ?>
                    <button id="viewHistoryBtn" class="btn btn-secondary btn-sm">Deployment History</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sites Toolbar: Search + View Toggle + Bulk Actions -->
        <div class="sites-toolbar">
            <div class="sites-toolbar-left">
                <div class="sites-search-box">
                    <svg class="sites-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="sitesSearchInput" class="sites-search-input" placeholder="Search sites by name, domain, or ID...">
                    <span id="sitesSearchCount" class="sites-search-count hidden">0 results</span>
                </div>
                <?php if (in_array(($user['role'] ?? ''), ['admin', 'deployer'], true)): ?>
                <div id="bulkActionsBar" class="bulk-actions-bar hidden">
                    <label class="form-checkbox-label bulk-select-all-label">
                        <input type="checkbox" id="bulkSelectAll">
                        <span id="bulkSelectedCount">0 selected</span>
                    </label>
                    <button id="bulkDeployBtn" class="btn btn-primary btn-sm" disabled>🚀 Deploy Selected</button>
                    <button id="bulkDeselectBtn" class="btn btn-secondary btn-sm">✕ Deselect</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="sites-toolbar-right">
                <div class="view-toggle-group" id="viewToggleGroup">
                    <button id="viewCardBtn" class="view-toggle-btn active" title="Card View">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg>
                    </button>
                    <button id="viewListBtn" class="view-toggle-btn" title="List View">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Global Toast Alert Container -->
        <div id="toastContainer" class="toast-container"></div>

        <!-- Sites Grid / List Container -->
        <div id="sitesGrid" class="sites-grid">
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
            <div class="skeleton-card"></div>
        </div>

        <!-- PM2 Process Manager Section -->
        <div class="section-header" style="margin-top: 40px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h2>PM2 Process Manager</h2>
                    <span id="pm2StatusBadge" class="badge badge-version">Checking PM2...</span>
                </div>
                <p class="section-desc">Monitor and control Node.js, Python, and background daemon processes live</p>
            </div>
            <div class="section-actions">
                <?php if ($authService->hasPermission('pm2')): ?>
                    <button id="startPm2AppBtn" class="btn btn-primary btn-sm">+ Launch App in PM2</button>
                <?php endif; ?>
                <?php if ($authService->hasPermission('script_gen')): ?>
                    <button id="createPm2ScriptBtn" class="btn btn-secondary btn-sm" onclick="openScriptGenModal('pm2_ecosystem')" style="border-color: rgba(168, 85, 247, 0.4); color: #c4b5fd;">📜 + Create PM2 Script</button>
                <?php endif; ?>
                <button id="refreshPm2Btn" class="btn btn-secondary btn-sm">Refresh PM2</button>
            </div>
        </div>

        <div id="pm2Card" class="pm2-card">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>PID</th>
                            <th>Status</th>
                            <th>CPU</th>
                            <th>Memory</th>
                            <th>Uptime</th>
                            <th>Restarts</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pm2TableBody">
                        <tr><td colspan="9" class="text-center">Loading PM2 process status...</td></tr>
                    </tbody>
                </table>
            </div>
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

    <!-- Add / Configure Site Modal -->
    <div id="addSiteModal" class="modal-overlay hidden">
        <div class="modal-card modal-lg" style="max-width: 780px;">
            <div class="modal-header">
                <div>
                    <h3>Add New Website</h3>
                    <div class="modal-sub-info">Configure deployment scripts, post-deploy health checks, and PM2 ecosystem runner</div>
                </div>
                <button id="closeAddSiteBtn" class="modal-close-btn">&times;</button>
            </div>
            <form id="addSiteForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;">
                <div class="modal-body" style="max-height: 72vh; overflow-y: auto; padding: 20px 24px;">
                    <!-- Section 1: Basic Site Configuration -->
                    <h4 style="margin: 0 0 14px; color: var(--accent-blue); font-size: 0.95rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        🌐 1. Basic Site Configuration
                    </h4>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px;">
                        <div class="form-group">
                            <label for="siteIdInput" class="form-label">Site Identifier (ID)</label>
                            <input type="text" id="siteIdInput" name="site_id" class="form-input" placeholder="e.g. site-d or my-app" required pattern="[a-zA-Z0-9_-]{3,32}">
                            <small class="form-help">Unique ID (3-32 chars: letters, numbers, hyphens)</small>
                        </div>

                        <div class="form-group">
                            <label for="siteNameInput" class="form-label">Display Name</label>
                            <input type="text" id="siteNameInput" name="name" class="form-input" placeholder="e.g. E-Commerce Store" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 10px;">
                        <label for="siteDomainInput" class="form-label">Domain Name</label>
                        <input type="text" id="siteDomainInput" name="domain" class="form-input" placeholder="e.g. shop.example.com">
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-top: 10px;">
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <label for="siteScriptInput" class="form-label" style="margin-bottom: 0;">Deployment Script Path</label>
                                <button type="button" id="editDeployScriptBtn" class="btn btn-secondary btn-sm" style="font-size: 0.72rem; padding: 2px 8px; border-color: rgba(124, 58, 237, 0.4); color: #c4b5fd;">✏️ Edit in Generator</button>
                            </div>
                            <input type="text" id="siteScriptInput" name="script" class="form-input" placeholder="e.g. scripts/site-d.sh (auto-created if empty)">
                            <small class="form-help">Auto-generated in <code>scripts/</code> if left blank.</small>
                        </div>

                        <div class="form-group">
                            <label for="siteRollbackInput" class="form-label">Rollback Script Path (Optional)</label>
                            <input type="text" id="siteRollbackInput" name="rollback_script" class="form-input" placeholder="e.g. scripts/site-d-rollback.sh">
                        </div>
                    </div>

                    <!-- Section 2: Health Check -->
                    <h4 style="margin: 20px 0 14px; color: var(--accent-blue); font-size: 0.95rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        🩺 2. Post-Deployment Health Check
                    </h4>

                    <div class="form-group">
                        <label class="form-checkbox-label">
                            <input type="checkbox" id="healthCheckEnableInput" name="health_check_enabled">
                            Enable Post-Deployment HTTP Health Check
                        </label>
                    </div>

                    <div class="form-group hidden" id="healthCheckUrlGroup" style="margin-top: 10px;">
                        <label for="siteHealthCheckInput" class="form-label">Health Check Target URL</label>
                        <input type="url" id="siteHealthCheckInput" name="health_check" class="form-input" placeholder="https://shop.example.com/healthz">
                    </div>

                    <!-- Section 3: PM2 Process Manager Ecosystem -->
                    <h4 style="margin: 20px 0 14px; color: var(--accent-blue); font-size: 0.95rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        ⚡ 3. PM2 Process Manager Ecosystem Config
                    </h4>

                    <div class="form-group">
                        <label class="form-checkbox-label">
                            <input type="checkbox" id="pm2EnableInput" name="pm2_enabled">
                            Register & Control with PM2 Process Manager
                        </label>
                    </div>

                    <div class="form-group hidden" id="pm2OptionsGroup" style="margin-top: 12px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; gap: 8px; flex-wrap: wrap;">
                            <label for="pm2EcosystemInput" class="form-label" style="margin-bottom: 0;">📋 PM2 Ecosystem Config Script (<code>ecosystem.config.js</code>)</label>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" id="openInPm2GenBtn" class="btn btn-primary btn-sm" style="font-size: 0.75rem; padding: 3px 10px; background: linear-gradient(135deg, #7c3aed, #a855f7);">✏️ Edit in Generator</button>
                                <button type="button" id="loadPm2TemplateBtn" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 3px 10px; border-color: rgba(168, 85, 247, 0.4); color: #c4b5fd;">🪄 Load Base PM2 Template</button>
                            </div>
                        </div>
                        <textarea id="pm2EcosystemInput" name="pm2_ecosystem" class="form-input" rows="16" style="font-family: var(--font-mono); font-size: 0.83rem; line-height: 1.5; resize: vertical; background: rgba(15, 23, 42, 0.7); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); white-space: pre; tab-size: 2; overflow-x: auto; border-radius: var(--radius-md);" placeholder="module.exports = {
  apps: [{
    name: 'solar-backend',
    script: 'src/index.ts',
    interpreter: 'node',
    interpreter_args: '--require esbuild-register',
    cwd: '/www/wwwroot/apisolar.blueoctopus.site',
    
    instances: 1,
    exec_mode: 'fork',
    watch: false,
    max_memory_restart: '1G',
    
    env: {
      NODE_ENV: 'production',
      PORT: 3000,
    },
    
    error_file: '/var/log/solar-backend-error.log',
    out_file: '/var/log/solar-backend-out.log',
    log_file: '/var/log/solar-backend-combined.log',
    time: true,
    
    autorestart: true,
    max_restarts: 10,
    min_uptime: '10s',
    
    kill_timeout: 5000,
    listen_timeout: 3000,
    
    merge_logs: true,
  }]
};"></textarea>
                        <small class="form-help">Paste your full <code>module.exports = { apps: [...] }</code> PM2 ecosystem config. LightDeploy will save this script and execute <code>pm2 start ecosystem.config.js</code> automatically during deployment.</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="deleteSiteModalBtn" class="btn btn-danger hidden" style="margin-right: auto;">Delete Site</button>
                    <button type="button" id="closeAddSiteFooterBtn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" id="saveSiteSubmitBtn" class="btn btn-primary">Save Configuration</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Launch App in PM2 Modal -->
    <div id="pm2StartModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Launch App in PM2</h3>
                <button id="closePm2StartBtn" class="modal-close-btn">&times;</button>
            </div>
            <form id="pm2StartForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="pm2ScriptInput" class="form-label">Script / Application Path</label>
                        <input type="text" id="pm2ScriptInput" name="script" class="form-input" placeholder="e.g. app.js, index.js, or server.py" required>
                        <small class="form-help">Path to main executable file or ecosystem config</small>
                    </div>

                    <div class="form-group">
                        <label for="pm2AppNameInput" class="form-label">Application Name (Optional)</label>
                        <input type="text" id="pm2AppNameInput" name="name" class="form-input" placeholder="e.g. my-node-api">
                    </div>

                    <div class="form-group">
                        <label for="pm2CwdInput" class="form-label">Working Directory (Optional)</label>
                        <input type="text" id="pm2CwdInput" name="cwd" class="form-input" placeholder="e.g. /home/user/apps/api">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="closePm2StartFooterBtn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" id="pm2StartSubmitBtn" class="btn btn-primary">Launch Process</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PM2 Process Logs Modal -->
    <div id="pm2LogsModal" class="modal-overlay hidden">
        <div class="modal-card modal-lg">
            <div class="modal-header">
                <div>
                    <h3 id="pm2LogsTitle">PM2 Output Logs</h3>
                    <div class="modal-sub-info">Showing recent stderr and stdout lines</div>
                </div>
                <button id="closePm2LogsBtn" class="modal-close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="terminal-container">
                    <div class="terminal-header">
                        <span class="terminal-dot red"></span>
                        <span class="terminal-dot yellow"></span>
                        <span class="terminal-dot green"></span>
                        <span class="terminal-title" id="pm2LogsTerminalTitle">pm2_output.log</span>
                        <button id="refreshPm2LogsBtn" class="btn btn-secondary btn-sm" style="padding: 2px 8px; font-size: 0.75rem;">Refresh Logs</button>
                    </div>
                    <pre id="pm2LogsOutput" class="terminal-body">Loading PM2 logs...</pre>
                </div>
            </div>
            <div class="modal-footer">
                <button id="closePm2LogsFooterBtn" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- PM2 Edit Process Config Modal (Full Ecosystem Suite) -->
    <div id="pm2EditModal" class="modal-overlay hidden">
        <div class="modal-card modal-lg">
            <div class="modal-header">
                <div>
                    <h3>⚙️ PM2 Process Ecosystem Settings</h3>
                    <div class="modal-sub-info">Configure complete process lifecycle, memory, cron, logs & environment variables</div>
                </div>
                <button id="closePm2EditBtn" class="modal-close-btn">&times;</button>
            </div>
            <form id="pm2EditForm">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Section 1: Core Execution Settings -->
                    <h4 style="margin: 0 0 12px; color: var(--accent-primary); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 6px;">1. Core Execution & Binary Settings</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <div class="form-group">
                            <label for="pm2EditNameInput" class="form-label">Process Name</label>
                            <input type="text" id="pm2EditNameInput" name="name" class="form-input" readonly required style="background: rgba(255,255,255,0.05);">
                        </div>
                        <div class="form-group">
                            <label for="pm2EditScriptInput" class="form-label">Script / Entry File Path</label>
                            <input type="text" id="pm2EditScriptInput" name="script" class="form-input" placeholder="e.g. app.js or /path/to/server.py" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 8px;">
                        <div class="form-group">
                            <label for="pm2EditCwdInput" class="form-label">Working Directory (`cwd`)</label>
                            <input type="text" id="pm2EditCwdInput" name="cwd" class="form-input" placeholder="e.g. /home/user/pm2-test">
                        </div>
                        <div class="form-group">
                            <label for="pm2EditArgsInput" class="form-label">Script Arguments (`args`)</label>
                            <input type="text" id="pm2EditArgsInput" name="args" class="form-input" placeholder="e.g. --port 3000 --env prod">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 8px;">
                        <div class="form-group">
                            <label for="pm2EditInterpreterInput" class="form-label">Interpreter Binary</label>
                            <input type="text" id="pm2EditInterpreterInput" name="interpreter" class="form-input" placeholder="e.g. node, python3, bash, ts-node">
                        </div>
                        <div class="form-group">
                            <label for="pm2EditInstancesInput" class="form-label">Cluster Instances (`-i`)</label>
                            <input type="text" id="pm2EditInstancesInput" name="instances" class="form-input" placeholder="1 or max">
                        </div>
                    </div>

                    <!-- Section 2: Memory, Auto-Restart & Cron -->
                    <h4 style="margin: 18px 0 12px; color: var(--accent-primary); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 6px;">2. Memory, Auto-Restart & Cron</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <div class="form-group">
                            <label for="pm2EditMemInput" class="form-label">Max Memory Restart</label>
                            <input type="text" id="pm2EditMemInput" name="max_memory_restart" class="form-input" placeholder="e.g. 150M, 500M, 1G">
                        </div>
                        <div class="form-group">
                            <label for="pm2EditCronInput" class="form-label">Cron Restart Pattern</label>
                            <input type="text" id="pm2EditCronInput" name="cron_restart" class="form-input" placeholder="e.g. 0 0 * * *">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 8px;">
                        <div class="form-group">
                            <label for="pm2EditRestartDelayInput" class="form-label">Restart Delay (ms)</label>
                            <input type="number" id="pm2EditRestartDelayInput" name="restart_delay" class="form-input" placeholder="e.g. 3000">
                        </div>
                        <div class="form-group" style="display: flex; align-items: center; margin-top: 24px;">
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="pm2EditAutoRestartInput" name="autorestart" checked>
                                <span>Enable Auto-Restart on Crash</span>
                            </label>
                        </div>
                    </div>

                    <!-- Section 3: Logging & Environment Variables -->
                    <h4 style="margin: 18px 0 12px; color: var(--accent-primary); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 6px;">3. Logs & Custom Environment Variables</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <div class="form-group">
                            <label for="pm2EditOutLogInput" class="form-label">Stdout Log Path (`-o`)</label>
                            <input type="text" id="pm2EditOutLogInput" name="output_log" class="form-input" placeholder="e.g. /var/log/app-out.log">
                        </div>
                        <div class="form-group">
                            <label for="pm2EditErrLogInput" class="form-label">Stderr Log Path (`-e`)</label>
                            <input type="text" id="pm2EditErrLogInput" name="error_log" class="form-input" placeholder="e.g. /var/log/app-err.log">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 8px;">
                        <label for="pm2EditEnvInput" class="form-label">Environment Variables (KEY=VALUE, comma or newline separated)</label>
                        <textarea id="pm2EditEnvInput" name="env_str" class="form-input" rows="3" placeholder="PORT=3000&#10;NODE_ENV=production&#10;DATABASE_URL=postgres://..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="closePm2EditFooterBtn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" id="pm2EditSubmitBtn" class="btn btn-primary">Save & Apply Ecosystem Settings</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Trigger Deployment / Rollback Confirmation & User Selection Modal -->
    <div id="triggerDeployModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <div>
                    <h3 id="triggerDeployTitle">🚀 Confirm Deployment</h3>
                    <div class="modal-sub-info" id="triggerDeploySubInfo">Site: --</div>
                </div>
                <button id="closeTriggerDeployBtn" class="modal-close-btn">&times;</button>
            </div>
            <form id="triggerDeployForm">
                <div class="modal-body">
                    <p style="margin-top: 0; color: var(--text-muted); font-size: 0.9rem;">
                        You are about to execute a deployment for website <strong id="triggerDeploySiteName">--</strong>.
                    </p>

                    <div class="form-group" style="margin-top: 14px;">
                        <label for="triggerDeployedByInput" class="form-label">Deployed By (Operator Username)</label>
                        <?php if (($user['role'] ?? '') === 'admin'): ?>
                            <input type="text" id="triggerDeployedByInput" name="deployed_by" class="form-input" value="<?= htmlspecialchars($user['username'] ?? 'admin') ?>" placeholder="e.g. admin, deployer, system-bot" required>
                            <small class="form-help">Admin Privilege: Choose or enter any admin/user name for deployment tracking.</small>
                        <?php else: ?>
                            <input type="text" id="triggerDeployedByInput" name="deployed_by" class="form-input" value="<?= htmlspecialchars($user['username'] ?? '') ?>" readonly style="background: rgba(255,255,255,0.05);">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="closeTriggerDeployFooterBtn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" id="triggerDeploySubmitBtn" class="btn btn-primary">🚀 Execute Now</button>
                </div>
            </form>
        </div>
    </div>

    <!-- VPS Open Ports & Process Discovery Modal -->
    <div id="portsModal" class="modal-overlay hidden">
        <div class="modal-card modal-xl">
            <div class="modal-header">
                <div>
                    <h3>🌐 VPS Open Ports & Process Manager</h3>
                    <div class="modal-sub-info">Scanned active listening ports and process assignments on this server</div>
                </div>
                <button id="closePortsBtn" class="modal-close-btn" onclick="closeVpsPortsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Suggested Free Ports Callout -->
                <div class="alert-box alert-success" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
                    <div>
                        <strong>💡 Available Free Ports for New Applications:</strong>
                        <div id="freePortsList" style="margin-top: 6px; font-family: var(--font-mono); font-size: 0.9rem; gap: 6px; display: flex; flex-wrap: wrap;">
                            <span class="badge badge-version">Scanning...</span>
                        </div>
                    </div>
                    <small style="color: var(--text-muted);">Use these unassigned ports when setting up new app configurations.</small>
                </div>

                <!-- Filter & Search Bar -->
                <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                    <input type="text" id="portSearchInput" class="form-input" placeholder="🔍 Search port number, process name (e.g. node, php, 3000, 8085)..." style="flex: 1;">
                    <button id="refreshPortsModalBtn" class="btn btn-secondary btn-sm">🔄 Refresh Ports</button>
                </div>

                <!-- Open Ports Table -->
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Port</th>
                                <th>Protocol</th>
                                <th>Scope / Bind Address</th>
                                <th>System Process (PID)</th>
                                <th>LightDeploy Application</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="portsTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Scanning VPS ports...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button id="closePortsFooterBtn" class="btn btn-secondary" onclick="closeVpsPortsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- MySQL Database Backups Suite Modal -->
    <div id="dbBackupsModal" class="modal-overlay hidden">
        <div class="modal-card modal-xl">
            <div class="modal-header">
                <div>
                    <h3>🗄️ MySQL Database Backup & Automated Retention Manager</h3>
                    <div class="modal-sub-info">Manage database credentials, 1-Click full dumps, automated schedules & 7-day backup rotation</div>
                </div>
                <button id="closeDbBackupsBtn" class="modal-close-btn" onclick="closeDbBackupsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Retention Callout & Section Actions -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                    <div class="alert-box alert-success" style="margin: 0; flex: 1; min-width: 280px; padding: 10px 14px;">
                        <strong>💡 Automated Retention Policy:</strong> Each database backup is automatically compressed (`.sql.gz`) and kept for <strong>7 days only</strong>. Backups older than 7 days are automatically pruned to preserve disk space.
                    </div>
                    <?php if (in_array(($user['role'] ?? ''), ['admin', 'deployer'], true)): ?>
                        <button id="addDbConfigBtn" class="btn btn-primary btn-sm" onclick="openAddDbModal()">+ Add New Database</button>
                    <?php endif; ?>
                </div>

                <!-- Database Cards & Backup Archives Container -->
                <div id="dbContainer" style="display: flex; flex-direction: column; gap: 20px;">
                    <div style="text-align: center; color: var(--text-muted); padding: 30px;">Loading database backup configurations...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="closeDbBackupsFooterBtn" class="btn btn-secondary" onclick="closeDbBackupsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Add / Edit Database Credentials Modal -->
    <div id="addDbModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 540px;">
            <div class="modal-header">
                <div>
                    <h3 id="addDbModalTitle">🗄️ Add MySQL Database Configuration</h3>
                    <div class="modal-sub-info">Configure unique host, port, username, password, and backup schedule</div>
                </div>
                <button id="closeAddDbBtn" class="modal-close-btn" onclick="closeAddDbModal()">&times;</button>
            </div>
            <form id="addDbForm">
                <input type="hidden" id="dbIdInput" name="id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="dbLabelInput" class="form-label">Database Display Label / System Name</label>
                        <input type="text" id="dbLabelInput" name="label" class="form-input" placeholder="e.g. Production E-Commerce DB" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-top: 10px;">
                        <div class="form-group">
                            <label for="dbHostInput" class="form-label">MySQL Host / IP</label>
                            <input type="text" id="dbHostInput" name="db_host" class="form-input" value="127.0.0.1" required>
                        </div>
                        <div class="form-group">
                            <label for="dbPortInput" class="form-label">Port</label>
                            <input type="number" id="dbPortInput" name="db_port" class="form-input" value="3306" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 10px;">
                        <label for="dbNameInput" class="form-label">Target Database Name (`db_name`)</label>
                        <input type="text" id="dbNameInput" name="db_name" class="form-input" placeholder="e.g. myapp_production" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                        <div class="form-group">
                            <label for="dbUserInput" class="form-label">Database Username (`db_user`)</label>
                            <input type="text" id="dbUserInput" name="db_user" class="form-input" placeholder="e.g. myapp_user" required>
                        </div>
                        <div class="form-group">
                            <label for="dbPassInput" class="form-label">Database Password (`db_pass`)</label>
                            <input type="password" id="dbPassInput" name="db_pass" class="form-input" placeholder="Enter password">
                            <small class="form-help">Leave blank to keep existing password when editing.</small>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                        <div class="form-group">
                            <label for="dbScheduleInput" class="form-label">Automated Backup Schedule</label>
                            <select id="dbScheduleInput" name="schedule" class="form-input">
                                <option value="daily" selected>Daily (Every 24 Hours)</option>
                                <option value="12h">Every 12 Hours</option>
                                <option value="6h">Every 6 Hours</option>
                                <option value="weekly">Weekly (Every 7 Days)</option>
                                <option value="disabled">Disabled (Manual Only)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="dbRetentionInput" class="form-label">Retention Policy (Days)</label>
                            <input type="number" id="dbRetentionInput" name="retention_days" class="form-input" value="7" readonly style="background: rgba(255,255,255,0.05);" title="Auto-prunes backups older than 7 days">
                            <small class="form-help">Strict 7-day rotation policy enabled.</small>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="closeAddDbFooterBtn" class="btn btn-secondary" onclick="closeAddDbModal()">Cancel</button>
                    <button type="submit" id="addDbSubmitBtn" class="btn btn-primary">Save Database Config</button>
                </div>
            </form>
        </div>
    </div>
    <!-- System Update from GitHub Modal -->
    <div id="updateSystemModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 580px;">
            <div class="modal-header">
                <div>
                    <h3>🔄 GitHub Software Update Center</h3>
                    <div class="modal-sub-info">Repository: <a href="https://github.com/shalithamadhuwantha/LIGHTDEPLOY" target="_blank" style="color: var(--accent-blue); text-decoration: underline;">shalithamadhuwantha/LIGHTDEPLOY</a></div>
                </div>
                <button class="modal-close-btn" onclick="closeUpdateSystemModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert-box alert-success" style="margin-bottom: 16px;">
                    <strong>💡 Zero-Data-Loss Protection:</strong> Upgrading will pull the latest source code from GitHub while preserving all your configured websites, user logins, database settings, and custom scripts intact!
                </div>

                <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--bg-card-border); padding: 16px; border-radius: var(--radius-md); margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="color: var(--text-muted); font-weight: 600; font-size: 0.85rem;">Installed Version</span>
                        <span class="badge badge-version" style="font-size: 0.9rem;">v1.2.5</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="color: var(--text-muted); font-weight: 600; font-size: 0.85rem;">Latest GitHub Commit (`main`)</span>
                        <span id="updateRepoCommitVal" class="badge badge-version" style="font-size: 0.85rem; background: rgba(56, 189, 248, 0.15); color: #38bdf8;">Checking GitHub...</span>
                    </div>
                    <div id="updateCommitMsgBox" style="font-family: var(--font-mono); font-size: 0.78rem; color: var(--text-secondary); background: rgba(0,0,0,0.3); padding: 8px 10px; border-radius: 6px; margin-top: 8px;" class="hidden">
                        --
                    </div>
                </div>

                <!-- Terminal Output Window for Update Progress -->
                <div id="updateTerminalContainer" class="terminal-container hidden" style="margin-top: 12px;">
                    <div class="terminal-header">
                        <span class="terminal-dot red"></span>
                        <span class="terminal-dot yellow"></span>
                        <span class="terminal-dot green"></span>
                        <span class="terminal-title">system_upgrade_process.log</span>
                    </div>
                    <pre id="updateTerminalOutput" class="terminal-body" style="max-height: 200px; font-size: 0.75rem;">Initializing update procedure...</pre>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUpdateSystemModal()">Close</button>
                <button type="button" id="executeSystemUpdateBtn" class="btn btn-primary" onclick="window.triggerSystemUpdate()" style="background: linear-gradient(135deg, #059669, #10b981);">🚀 Update Now from GitHub</button>
            </div>
        </div>
    </div>

    <!-- Deployment & PM2 Script Generator Modal -->
    <div id="scriptGenModal" class="modal-overlay hidden">
        <div class="modal-card modal-xl" style="max-width: 1200px;">
            <div class="modal-header">
                <div>
                    <h3 id="scriptGenTitle">📜 Script Generator</h3>
                    <div class="modal-sub-info" id="scriptGenSubInfo">Generate production-grade deployment scripts or PM2 ecosystem configurations</div>
                </div>
                <button class="modal-close-btn" onclick="closeScriptGenModal()">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 78vh; overflow-y: auto; padding: 20px 24px;">
                <!-- Script Type Switcher -->
                <div style="display: flex; gap: 10px; margin-bottom: 14px; background: rgba(15, 23, 42, 0.7); padding: 6px; border-radius: var(--radius-md); border: 1px solid var(--bg-card-border);">
                    <button id="sgTypeBashBtn" type="button" class="btn btn-primary btn-sm" style="flex: 1; border-radius: 6px;">📜 Bash Deployment Script (.sh)</button>
                    <button id="sgTypePm2Btn" type="button" class="btn btn-secondary btn-sm" style="flex: 1; border-radius: 6px;">⚡ PM2 Ecosystem Script (ecosystem.config.js)</button>
                </div>

                <!-- Site Quick Select for Editing Existing Configurations -->
                <div style="margin-bottom: 16px; display: flex; align-items: center; gap: 10px; background: rgba(30, 41, 59, 0.5); padding: 10px 14px; border-radius: var(--radius-md); border: 1px dashed rgba(124, 58, 237, 0.4);">
                    <label for="sgSiteQuickSelect" style="font-weight: 600; font-size: 0.85rem; color: #c4b5fd; white-space: nowrap; margin-bottom: 0;">✏️ Load Existing Site Config to Edit:</label>
                    <select id="sgSiteQuickSelect" class="form-input" style="font-size: 0.83rem; padding: 4px 10px; background: rgba(15, 23, 42, 0.8);">
                        <option value="">-- Choose an Existing Site to Load &amp; Edit --</option>
                    </select>
                </div>

                <div class="scriptgen-layout">
                    <!-- LEFT COLUMN: Configuration Form -->
                    <div class="scriptgen-form-col">
                        <!-- BASH SCRIPT FORM CONTAINER -->
                        <div id="sgBashFormContainer">
                            <!-- Section 1: Application -->
                            <h4 class="scriptgen-section-title">📁 1. Application &amp; Repository</h4>
                            <div class="form-group">
                                <label for="sgAppDir" class="form-label">Application Directory *</label>
                                <input type="text" id="sgAppDir" class="form-input" placeholder="e.g. /www/wwwroot/example.com" required>
                                <small class="form-help">Target directory where the app will be deployed</small>
                            </div>
                            <div class="form-group">
                                <label for="sgRepoUrl" class="form-label">GitHub Repository URL *</label>
                                <input type="text" id="sgRepoUrl" class="form-input" placeholder="e.g. https://github.com/user/repo.git" required>
                            </div>
                            <div class="form-group">
                                <label for="sgBranch" class="form-label">Git Branch</label>
                                <input type="text" id="sgBranch" class="form-input" placeholder="main" value="main">
                            </div>

                            <!-- Section 2: Environment -->
                            <h4 class="scriptgen-section-title">🔐 2. Environment Configuration</h4>
                            <div class="form-group">
                                <label for="sgEnvSource" class="form-label">Environment File Path (optional)</label>
                                <input type="text" id="sgEnvSource" class="form-input" placeholder="e.g. /root/envfiles/.env.production">
                                <small class="form-help">Will be copied to <code>$APP_DIR/.env</code> during deployment</small>
                            </div>

                            <!-- Section 3: Build Pipeline -->
                            <h4 class="scriptgen-section-title">🔨 3. Build Pipeline</h4>
                            <div style="display: flex; gap: 24px; flex-wrap: wrap;">
                                <label class="form-checkbox-label">
                                    <input type="checkbox" id="sgHasNpm">
                                    Run <code>npm install</code>
                                </label>
                                <label class="form-checkbox-label">
                                    <input type="checkbox" id="sgHasBuild">
                                    Run <code>npm run build</code>
                                </label>
                            </div>

                            <!-- Section 4: PM2 -->
                            <h4 class="scriptgen-section-title">⚡ 4. PM2 Process Manager</h4>
                            <label class="form-checkbox-label">
                                <input type="checkbox" id="sgHasPm2">
                                Enable PM2 restart / start after deployment
                            </label>
                            <div id="sgPm2Group" class="hidden" style="margin-top: 10px;">
                                <div class="form-group">
                                    <label for="sgAppName" class="form-label">PM2 Application Name *</label>
                                    <input type="text" id="sgAppName" class="form-input" placeholder="e.g. my-node-api">
                                </div>
                            </div>

                            <!-- Section 5: Server Ownership -->
                            <h4 class="scriptgen-section-title">👤 5. Server Ownership</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div class="form-group">
                                    <label for="sgSiteUser" class="form-label">Site User</label>
                                    <input type="text" id="sgSiteUser" class="form-input" value="www" placeholder="www">
                                </div>
                                <div class="form-group">
                                    <label for="sgSiteGroup" class="form-label">Site Group</label>
                                    <input type="text" id="sgSiteGroup" class="form-input" value="www" placeholder="www">
                                </div>
                            </div>

                            <!-- Section 6: Output & Edit Existing -->
                            <h4 class="scriptgen-section-title">💾 6. Save or Edit Existing Script on Server</h4>
                            <div class="form-group">
                                <label for="sgOutputPath" class="form-label">Server File Path</label>
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" id="sgOutputPath" class="form-input" placeholder="e.g. /root/scripts/deploy-myapp.sh" style="flex: 1;">
                                    <button type="button" id="sgLoadBashBtn" class="btn btn-secondary btn-sm" style="white-space: nowrap; border-color: rgba(124, 58, 237, 0.4); color: #c4b5fd;">📖 Read &amp; Edit File</button>
                                </div>
                                <small class="form-help">Must end with <code>.sh</code>. Click "Read &amp; Edit File" to load an existing script from disk.</small>
                            </div>
                        </div>

                        <!-- PM2 ECOSYSTEM FORM CONTAINER -->
                        <div id="sgPm2FormContainer" class="hidden">
                            <!-- Section 1: PM2 Application Config -->
                            <h4 class="scriptgen-section-title">⚡ 1. PM2 Application &amp; Entry Point</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="sgPm2AppNameInput" class="form-label">Application Name *</label>
                                    <input type="text" id="sgPm2AppNameInput" class="form-input" value="solar-backend" placeholder="e.g. solar-backend" required>
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2ScriptInput" class="form-label">Entry Script *</label>
                                    <input type="text" id="sgPm2ScriptInput" class="form-input" value="src/index.ts" placeholder="e.g. src/index.ts or dist/index.js" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="sgPm2CwdInput" class="form-label">Working Directory (cwd) *</label>
                                <input type="text" id="sgPm2CwdInput" class="form-input" value="/www/wwwroot/apisolar.blueoctopus.site" placeholder="e.g. /www/wwwroot/apisolar.blueoctopus.site" required>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="sgPm2InterpreterInput" class="form-label">Interpreter</label>
                                    <input type="text" id="sgPm2InterpreterInput" class="form-input" value="node" placeholder="node, python, php, etc.">
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2InterpreterArgsInput" class="form-label">Interpreter Args</label>
                                    <input type="text" id="sgPm2InterpreterArgsInput" class="form-input" value="--require esbuild-register" placeholder="e.g. --require esbuild-register">
                                </div>
                            </div>

                            <!-- Section 2: Execution & Scaling -->
                            <h4 class="scriptgen-section-title">⚙️ 2. Execution &amp; Scaling Settings</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="sgPm2InstancesInput" class="form-label">Instances</label>
                                    <input type="number" id="sgPm2InstancesInput" class="form-input" value="1" min="1" max="64">
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2ExecModeInput" class="form-label">Exec Mode</label>
                                    <select id="sgPm2ExecModeInput" class="form-input">
                                        <option value="fork" selected>fork</option>
                                        <option value="cluster">cluster</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2MaxMemInput" class="form-label">Max Memory Restart</label>
                                    <input type="text" id="sgPm2MaxMemInput" class="form-input" value="1G" placeholder="1G, 512M">
                                </div>
                            </div>

                            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 12px;">
                                <label class="form-checkbox-label">
                                    <input type="checkbox" id="sgPm2WatchCheck">
                                    Watch File Changes (<code>watch</code>)
                                </label>
                                <label class="form-checkbox-label">
                                    <input type="checkbox" id="sgPm2TimeCheck" checked>
                                    Timestamp Logs (<code>time</code>)
                                </label>
                                <label class="form-checkbox-label">
                                    <input type="checkbox" id="sgPm2MergeLogsCheck" checked>
                                    Merge Logs (<code>merge_logs</code>)
                                </label>
                            </div>

                            <!-- Section 3: Environment Variables -->
                            <h4 class="scriptgen-section-title">🌐 3. Environment Variables</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="sgPm2NodeEnvInput" class="form-label">NODE_ENV</label>
                                    <input type="text" id="sgPm2NodeEnvInput" class="form-input" value="production" placeholder="production">
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2PortInput" class="form-label">PORT</label>
                                    <input type="number" id="sgPm2PortInput" class="form-input" value="3000" placeholder="3000">
                                </div>
                            </div>

                            <!-- Section 4: Log Files Configuration -->
                            <h4 class="scriptgen-section-title">📊 4. Log Files Configuration</h4>
                            <div class="form-group">
                                <label for="sgPm2ErrorFileInput" class="form-label">Error Log Path (<code>error_file</code>)</label>
                                <input type="text" id="sgPm2ErrorFileInput" class="form-input" value="/var/log/solar-backend-error.log" placeholder="/var/log/app-error.log">
                            </div>
                            <div class="form-group">
                                <label for="sgPm2OutFileInput" class="form-label">Output Log Path (<code>out_file</code>)</label>
                                <input type="text" id="sgPm2OutFileInput" class="form-input" value="/var/log/solar-backend-out.log" placeholder="/var/log/app-out.log">
                            </div>
                            <div class="form-group">
                                <label for="sgPm2LogFileInput" class="form-label">Combined Log Path (<code>log_file</code>)</label>
                                <input type="text" id="sgPm2LogFileInput" class="form-input" value="/var/log/solar-backend-combined.log" placeholder="/var/log/app-combined.log">
                            </div>

                            <!-- Section 5: Lifecycle & Resiliency -->
                            <h4 class="scriptgen-section-title">🛡️ 5. Resiliency &amp; Timeouts</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="sgPm2MaxRestartsInput" class="form-label">Max Restarts</label>
                                    <input type="number" id="sgPm2MaxRestartsInput" class="form-input" value="10" min="1">
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2MinUptimeInput" class="form-label">Min Uptime</label>
                                    <input type="text" id="sgPm2MinUptimeInput" class="form-input" value="10s" placeholder="10s">
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="sgPm2KillTimeoutInput" class="form-label">Kill Timeout (ms)</label>
                                    <input type="number" id="sgPm2KillTimeoutInput" class="form-input" value="5000" placeholder="5000">
                                </div>
                                <div class="form-group">
                                    <label for="sgPm2ListenTimeoutInput" class="form-label">Listen Timeout (ms)</label>
                                    <input type="number" id="sgPm2ListenTimeoutInput" class="form-input" value="3000" placeholder="3000">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-checkbox-label">
                                    <input type="checkbox" id="sgPm2AutorestartCheck" checked>
                                    Enable Auto Restart (<code>autorestart</code>)
                                </label>
                            </div>

                            <!-- Section 6: Save PM2 Ecosystem to Server -->
                            <h4 class="scriptgen-section-title">💾 6. Save or Edit PM2 Script on Server</h4>
                            <div class="form-group">
                                <label for="sgPm2OutputPathInput" class="form-label">Server File Path</label>
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" id="sgPm2OutputPathInput" class="form-input" placeholder="e.g. /www/wwwroot/apisolar.blueoctopus.site/ecosystem.config.js" style="flex: 1;">
                                    <button type="button" id="sgLoadPm2Btn" class="btn btn-secondary btn-sm" style="white-space: nowrap; border-color: rgba(168, 85, 247, 0.4); color: #c4b5fd;">📖 Read &amp; Edit File</button>
                                </div>
                                <small class="form-help">Must end with <code>.config.js</code> or <code>.js</code>. Click "Read &amp; Edit File" to load an existing ecosystem script from disk.</small>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: Live Preview -->
                    <div class="scriptgen-preview-col">
                        <div class="scriptgen-preview-header">
                            <span id="sgPreviewTitle">📄 Live Script Preview</span>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" id="sgCopyBtn" class="btn btn-secondary btn-sm" style="padding: 4px 10px; font-size: 0.75rem;">📋 Copy</button>
                            </div>
                        </div>
                        <pre id="sgPreviewOutput" class="scriptgen-preview-body">Fill in the configuration fields to generate a script preview...</pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeScriptGenModal()">Close</button>
                <button type="button" id="sgDownloadBtn" class="btn btn-primary" style="background: linear-gradient(135deg, #7c3aed, #a855f7);">📥 Download Script</button>
                <button type="button" id="sgSaveBtn" class="btn btn-primary" style="background: linear-gradient(135deg, #059669, #10b981);">💾 Save to Server</button>
            </div>
        </div>
    </div>

    <!-- Manage Users List Modal -->
    <div id="userMgmtModal" class="modal-overlay hidden">
        <div class="modal-card modal-xl" style="max-width: 1000px;">
            <div class="modal-header">
                <div>
                    <h3>👥 User Accounts & Dashboard Privileges</h3>
                    <div class="modal-sub-info">Create user accounts, set role templates, and configure allowed dashboard functions & system privileges</div>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #059669, #10b981);" onclick="openUserEditModal()">+ Add New User</button>
                    <button class="modal-close-btn" onclick="closeUserMgmtModal()">&times;</button>
                </div>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Display Name</th>
                                <th>Role Template</th>
                                <th>Allowed Functions</th>
                                <th>Allowed Systems</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="userMgmtTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Loading user accounts...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeUserMgmtModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Add / Edit User Modal -->
    <div id="userEditModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 680px;">
            <div class="modal-header">
                <div>
                    <h3 id="userEditModalTitle">👤 Add New User Account</h3>
                    <div class="modal-sub-info">Configure user login details, role preset, dashboard functions, and system access</div>
                </div>
                <button class="modal-close-btn" onclick="closeUserEditModal()">&times;</button>
            </div>
            <form id="userEditForm">
                <div class="modal-body" style="max-height: 72vh; overflow-y: auto;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label for="umUsernameInput" class="form-label">Username *</label>
                            <input type="text" id="umUsernameInput" class="form-input" placeholder="e.g. jared_dev" required>
                        </div>
                        <div class="form-group">
                            <label for="umNameInput" class="form-label">Display Full Name *</label>
                            <input type="text" id="umNameInput" class="form-input" placeholder="e.g. Jared Vance" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                        <div class="form-group">
                            <label for="umPasswordInput" class="form-label">Account Password</label>
                            <input type="password" id="umPasswordInput" class="form-input" placeholder="••••••••">
                            <small id="umPasswordHelpText" class="form-help">Required for new user. Leave blank to keep existing password when editing.</small>
                        </div>
                        <div class="form-group">
                            <label for="umRoleSelect" class="form-label">Role Template Preset</label>
                            <select id="umRoleSelect" class="form-input">
                                <option value="admin">⭐ Administrator (Full Access)</option>
                                <option value="deployer">🚀 Release Operator (Deployer)</option>
                                <option value="viewer" selected>👁️ Auditor / Viewer (Read-only)</option>
                                <option value="custom">⚙️ Custom Granular Privileges</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dashboard Allowed Functions Grid -->
                    <div style="margin-top: 18px;">
                        <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>🔒 Allowed Dashboard Functions</span>
                            <small style="color: var(--text-muted);">Unchecked functions will be completely hidden from the user UI</small>
                        </label>
                        <div class="perm-grid">
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="sites" checked>
                                <div class="perm-card-content">
                                    <div class="perm-card-title">🚀 Configured Websites</div>
                                    <div class="perm-card-desc">View dashboard & trigger site deployments</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="add_edit_sites">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">➕ Add & Edit Sites</div>
                                    <div class="perm-card-desc">Add, modify, and delete website configurations</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="pm2">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">⚡ PM2 Process Manager</div>
                                    <div class="perm-card-desc">Monitor & control PM2 Node/Python daemons</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="db_backups">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">🗄️ Database Backups</div>
                                    <div class="perm-card-desc">Access MySQL database dumps & downloads</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="vps_ports">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">🌐 VPS Open Ports</div>
                                    <div class="perm-card-desc">View listening ports & system process scanner</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="deploy_history">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">📜 Deployment Audit History</div>
                                    <div class="perm-card-desc">View past deployment execution logs</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="script_gen">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">📄 Script Generator</div>
                                    <div class="perm-card-desc">Access bash deployment script builder GUI</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="update_system">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">🔄 Update System</div>
                                    <div class="perm-card-desc">Trigger 1-Click software updates from GitHub</div>
                                </div>
                            </label>
                            <label class="perm-card">
                                <input type="checkbox" name="um_func" value="user_mgmt">
                                <div class="perm-card-content">
                                    <div class="perm-card-title">👥 Manage Users</div>
                                    <div class="perm-card-desc">Admin tool: Manage user accounts & permissions</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- System / Site Privileges Selector -->
                    <div style="margin-top: 18px;">
                        <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>🌐 System / Site Privileges</span>
                            <small style="color: var(--text-muted);">Choose which website systems user is authorized to manage</small>
                        </label>
                        <div style="margin-bottom: 8px;">
                            <label class="form-checkbox-label">
                                <input type="checkbox" id="umAllSystemsCheck" checked>
                                <strong>All Systems / Sites (Full Access - <code>*</code>)</strong>
                            </label>
                        </div>
                        <div id="umSpecificSystemsContainer" class="perm-grid hidden" style="grid-template-columns: repeat(2, 1fr);">
                            <!-- Dynamically populated configured site options -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUserEditModal()">Cancel</button>
                    <button type="submit" id="umSaveSubmitBtn" class="btn btn-primary">Save User Account</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/assets/app.js?v=<?= time() ?>"></script>
</body>
</html>
