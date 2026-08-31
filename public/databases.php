<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY - Dedicated MySQL Database Management & Backup Suite Page
 */

$config = require_once dirname(__DIR__) . '/app/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$authService = new AuthService($config['config_dir'] . '/users.json');
if (!$authService->isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

if (!$authService->hasPermission('db_backups')) {
    header('Location: /');
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
    <title>Database Backups - LightDeploy by Blue Octopus</title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= time() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script>
        function openUserProfileModal() {
            var modal = document.getElementById('userProfileModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '100001', 'important');
            }
            if (window.loadUserProfileData) {
                window.loadUserProfileData();
            }
        }
        function closeUserProfileModal() {
            var modal = document.getElementById('userProfileModal');
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
        function openDbBackupsListModal() {
            var modal = document.getElementById('dbBackupsListModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
        }
        function closeDbBackupsListModal() {
            var modal = document.getElementById('dbBackupsListModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openGlobalScheduleModal() {
            var modal = document.getElementById('globalScheduleModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
        }
        function closeGlobalScheduleModal() {
            var modal = document.getElementById('globalScheduleModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openMasterCredsModal() {
            var modal = document.getElementById('masterCredsModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
            if (window.loadMasterCredentials) {
                window.loadMasterCredentials();
            }
        }
        function closeMasterCredsModal() {
            var modal = document.getElementById('masterCredsModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            }
        }
        function openMasterBackupModal() {
            var modal = document.getElementById('masterBackupModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.setProperty('display', 'flex', 'important');
                modal.style.setProperty('visibility', 'visible', 'important');
                modal.style.setProperty('opacity', '1', 'important');
                modal.style.setProperty('z-index', '99999', 'important');
            }
            if (window.loadMasterBackupHistory) {
                window.loadMasterBackupHistory();
            }
        }
        function closeMasterBackupModal() {
            var modal = document.getElementById('masterBackupModal');
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
    </script>
</head>
<body class="dashboard-body" data-user-role="<?= htmlspecialchars($user['role']) ?>" data-username="<?= htmlspecialchars($user['username']) ?>" data-csrf-token="<?= htmlspecialchars($csrfToken) ?>" data-allowed-functions='<?= json_encode($user['allowed_functions'] ?? ['*']) ?>' data-allowed-systems='<?= json_encode($user['allowed_systems'] ?? ['*']) ?>'>
    <!-- Top Navigation Bar -->
    <header class="app-header">
        <div class="header-left">
            <a href="/" class="brand" style="text-decoration: none; color: inherit;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
                <span class="brand-title">LIGHTDEPLOY <span style="font-size: 0.62rem; color: #a855f7; font-weight: 600; letter-spacing: 0.5px; background: rgba(168, 85, 247, 0.15); padding: 2px 7px; border-radius: 4px; margin-left: 4px; border: 1px solid rgba(168, 85, 247, 0.3); text-transform: uppercase;">BY BLUE OCTOPUS</span></span>
                <span class="badge badge-version">v1.2.5</span>
            </a>
        </div>

        <div class="header-center">
            <div class="header-status-pill" title="Live Server Status">
                <span class="status-pulse"></span>
                <span style="font-size: 0.75rem; font-weight: 700; color: #6ee7b7; letter-spacing: 0.5px;">SYSTEM ONLINE</span>
            </div>
        </div>

        <div class="header-right">
            <a href="/" class="btn btn-secondary btn-sm" style="margin-right: 6px; text-decoration: none;">← Back to Dashboard</a>
            <button id="headerViewPortsBtn" class="btn btn-secondary btn-sm btn-view-ports" style="margin-right: 6px;" onclick="openVpsPortsModal()">🌐 VPS Ports</button>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <button id="headerUpdateSystemBtn" class="btn btn-primary btn-sm" style="margin-right: 6px; background: linear-gradient(135deg, #059669, #10b981);" onclick="openUpdateSystemModal()">🔄 Update System</button>
            <?php endif; ?>
            <button id="userProfileHeaderBtn" class="btn btn-secondary btn-sm" style="margin-right: 6px; background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.15);" onclick="openUserProfileModal()">👤 Profile</button>
            <div class="user-info" onclick="openUserProfileModal()" style="cursor: pointer;" title="Click to view & edit your profile">
                <span class="user-name header-user-name-display"><?= htmlspecialchars($user['name']) ?></span>
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
                <h2>🗄️ MySQL Database Backup & Automated Retention Manager</h2>
                <p class="section-desc">Manage database credentials, 1-Click full dumps, automated schedules & 7-day backup rotation</p>
            </div>
            <div class="section-actions" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <a href="/" class="btn btn-secondary btn-sm" style="text-decoration: none;">← Back to Dashboard</a>
                <?php if (in_array(($user['role'] ?? ''), ['admin', 'deployer'], true)): ?>
                    <button id="masterBackupBtn" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #8b5cf6, #6366f1);" onclick="openMasterBackupModal()" title="View Master VPS Database Backups grouped by Date & Time and trigger 1-Click backups">📦 Master VPS Backups</button>
                    <button id="masterCredsBtn" class="btn btn-secondary btn-sm" onclick="openMasterCredsModal()" title="Configure Master MySQL Root/Admin user credentials">🔑 Master DB User</button>
                    <button id="backupAllDbsBtn" class="btn btn-secondary btn-sm" style="background: linear-gradient(135deg, #0284c7, #06b6d4);" title="Generate separate phpMyAdmin ready .sql files for configured databases">⚡ Backup Configured DBs</button>
                    <button id="globalScheduleBtn" class="btn btn-secondary btn-sm" onclick="openGlobalScheduleModal()" title="Set automated backup frequency for databases at once">⚙️ Bulk Schedule</button>
                    <button id="addDbConfigBtn" class="btn btn-secondary btn-sm" onclick="openAddDbModal()">+ Add Database</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Global Toast Alert Container -->
        <div id="toastContainer" class="toast-container"></div>

        <!-- Automated Retention Policy Callout Banner -->
        <div class="alert-box alert-success" style="margin-bottom: 24px; padding: 14px 18px; border-radius: var(--radius-md); background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3);">
            <strong>💡 Automated Retention Policy:</strong> Each database backup is automatically compressed (`.sql.gz`) and kept for <strong>7 days only</strong>. Backups older than 7 days are automatically pruned to preserve disk space.
        </div>

        <!-- Database Cards & Backup Archives Container -->
        <div id="dbContainer" style="display: flex; flex-direction: column; gap: 24px;">
            <div style="text-align: center; color: var(--text-muted); padding: 40px;">Loading database backup configurations...</div>
        </div>
    </main>

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
                            <small id="dbPassHelpText" class="form-help">Leave blank to keep existing password when editing.</small>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                        <div class="form-group">
                            <label for="dbScheduleInput" class="form-label">Automated Backup Schedule</label>
                            <select id="dbScheduleInput" name="schedule" class="form-input">
                                <option value="5m">⚡ Every 5 Minutes (High Frequency)</option>
                                <option value="6h">⚡ Every 6 Hours</option>
                                <option value="12h">⏰ Every 12 Hours</option>
                                <option value="daily" selected>📅 Daily (Every 24 Hours)</option>
                                <option value="weekly">🗓️ Weekly (Every 7 Days)</option>
                                <option value="disabled">⏸️ Disabled (Manual Only)</option>
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
                <div class="alert-box alert-success" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
                    <div>
                        <strong>💡 Available Free Ports for New Applications:</strong>
                        <div id="freePortsList" style="margin-top: 6px; font-family: var(--font-mono); font-size: 0.9rem; gap: 6px; display: flex; flex-wrap: wrap;">
                            <span class="badge badge-version">Scanning...</span>
                        </div>
                    </div>
                    <small style="color: var(--text-muted);">Use these unassigned ports when setting up new app configurations.</small>
                </div>

                <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                    <input type="text" id="portSearchInput" class="form-input" placeholder="🔍 Search port number, process name (e.g. node, php, 3000, 8085)..." style="flex: 1;">
                    <button id="refreshPortsModalBtn" class="btn btn-secondary btn-sm">🔄 Refresh Ports</button>
                </div>

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

    <!-- Backup Files List Popup Modal -->
    <div id="dbBackupsListModal" class="modal-overlay hidden">
        <div class="modal-card modal-xl" style="max-width: 900px;">
            <div class="modal-header">
                <div>
                    <h3 id="dbBackupsListModalTitle">📁 Database Backup Archives</h3>
                    <div id="dbBackupsListModalSubtitle" class="modal-sub-info">Download, view, and manage backup files</div>
                </div>
                <button class="modal-close-btn" onclick="closeDbBackupsListModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Backup File Name</th>
                                <th>Format / Type</th>
                                <th>Size</th>
                                <th>Created At</th>
                                <th>Retention Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="dbBackupsListTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Loading backups list...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <div id="dbBackupsModalActions" style="display: flex; gap: 8px;">
                    <!-- Action buttons dynamically injected -->
                </div>
                <button class="btn btn-secondary" onclick="closeDbBackupsListModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Global Backup Schedule Config Modal -->
    <div id="globalScheduleModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 500px;">
            <div class="modal-header">
                <div>
                    <h3>⚙️ Set Bulk Backup Schedule for All Databases</h3>
                    <div class="modal-sub-info">Apply backup frequency across all configured MySQL databases at once</div>
                </div>
                <button class="modal-close-btn" onclick="closeGlobalScheduleModal()">&times;</button>
            </div>
            <form id="globalScheduleForm">
                <div class="modal-body">
                    <div class="alert-box alert-success" style="margin-bottom: 14px;">
                        <strong>💡 Independent phpMyAdmin Ready Dump Files:</strong> Scheduled backups produce individual, unmerged <code>.sql</code> files for each database, ready for direct import via phpMyAdmin or MySQL CLI.
                    </div>
                    <div class="form-group">
                        <label for="bulkScheduleInput" class="form-label">Select Backup Frequency for All Databases</label>
                        <select id="bulkScheduleInput" name="schedule" class="form-input">
                            <option value="5m">⚡ Every 5 Minutes (High Frequency)</option>
                            <option value="6h">⚡ Every 6 Hours</option>
                            <option value="12h">⏰ Every 12 Hours</option>
                            <option value="daily" selected>📅 Daily (Every 24 Hours)</option>
                            <option value="weekly">🗓️ Weekly (Every 7 Days)</option>
                            <option value="disabled">⏸️ Disabled (Manual Only)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeGlobalScheduleModal()">Cancel</button>
                    <button type="submit" id="bulkScheduleSubmitBtn" class="btn btn-primary">Apply to All Databases</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Master MySQL Credentials Modal -->
    <div id="masterCredsModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 540px;">
            <div class="modal-header">
                <div>
                    <h3>🔑 Master MySQL Administrator Credentials</h3>
                    <div class="modal-sub-info">Use a MySQL Root / Admin user to auto-discover and backup ALL databases on this VPS</div>
                </div>
                <button class="modal-close-btn" onclick="closeMasterCredsModal()">&times;</button>
            </div>
            <form id="masterCredsForm">
                <div class="modal-body">
                    <div class="alert-box alert-success" style="margin-bottom: 16px;">
                        <strong>💡 How Master Database Backup Works:</strong><br>
                        Providing Master MySQL credentials (e.g. <code>root</code> or an admin user) allows LightDeploy to query <code>SHOW DATABASES;</code>, discover all MySQL databases hosted on your VPS, and dump <strong>every database one by one into separate phpMyAdmin-ready <code>.sql</code> files</strong>.
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="masterHostInput" class="form-label">Master Host</label>
                            <input type="text" id="masterHostInput" name="db_host" class="form-input" value="127.0.0.1" required>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="masterPortInput" class="form-label">Port</label>
                            <input type="number" id="masterPortInput" name="db_port" class="form-input" value="3306" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="masterUserInput" class="form-label">Master Username (e.g. root)</label>
                            <input type="text" id="masterUserInput" name="db_user" class="form-input" placeholder="root" required>
                        </div>
                        <div class="form-group">
                            <label for="masterPassInput" class="form-label">Master Password</label>
                            <input type="password" id="masterPassInput" name="db_pass" class="form-input" placeholder="••••••••">
                            <small id="masterPassHelpText" class="form-help">Leave blank if keeping existing password.</small>
                        </div>
                    </div>

                    <!-- Connection Status / Discovered DBs Callout -->
                    <div id="masterTestResultBox" style="display: none; margin-top: 10px; padding: 12px; border-radius: 6px; font-size: 0.88rem;"></div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                    <button type="button" id="testMasterCredsBtn" class="btn btn-secondary">🧪 Test Connection & Discover DBs</button>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-secondary" onclick="closeMasterCredsModal()">Cancel</button>
                        <button type="submit" id="saveMasterCredsBtn" class="btn btn-primary">Save Master Credentials</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Master VPS Database Backup History Modal -->
    <div id="masterBackupModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 840px; width: 90%;">
            <div class="modal-header">
                <div>
                    <h3>📦 Master VPS Database Backup Sessions</h3>
                    <div class="modal-sub-info">Backups grouped by Date & Time. Dump all VPS databases into separate .sql files.</div>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button id="runMasterBackupModalBtn" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #8b5cf6, #6366f1);">⚡ Run Master Backup Now (.sql)</button>
                    <button class="modal-close-btn" onclick="closeMasterBackupModal()">&times;</button>
                </div>
            </div>
            <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                <div id="masterSessionsContainer">
                    <div style="text-align: center; color: var(--text-muted); padding: 20px;">Loading backup sessions...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMasterBackupModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- User Profile Self-Management Modal -->
    <div id="userProfileModal" class="modal-overlay hidden">
        <div class="modal-card" style="max-width: 520px;">
            <div class="modal-header">
                <div>
                    <h3>👤 User Profile Settings</h3>
                    <div class="modal-sub-info">View your account details, edit display name, and update your password</div>
                </div>
                <button class="modal-close-btn" onclick="closeUserProfileModal()">&times;</button>
            </div>
            <form id="userProfileForm">
                <div class="modal-body">
                    <div class="alert-box alert-success" style="margin-bottom: 16px; padding: 10px 14px;">
                        <strong>🔒 Security & Privilege Protection:</strong> Your Username and Role/Permissions are managed by policy and cannot be edited here.
                    </div>

                    <div class="form-group">
                        <label for="profileUsernameInput" class="form-label">Username (Account Identifier)</label>
                        <input type="text" id="profileUsernameInput" class="form-input" value="<?= htmlspecialchars($user['username']) ?>" readonly style="background: rgba(255,255,255,0.05); cursor: not-allowed; opacity: 0.8;" title="Username cannot be changed">
                        <small class="form-help">Username cannot be edited.</small>
                    </div>

                    <div class="form-group" style="margin-top: 12px;">
                        <label for="profileRoleInput" class="form-label">Role & System Permissions</label>
                        <input type="text" id="profileRoleInput" class="form-input" value="<?= strtoupper(htmlspecialchars($user['role'])) ?> (Managed by Administrator)" readonly style="background: rgba(255,255,255,0.05); cursor: not-allowed; opacity: 0.8;" title="Permissions cannot be changed">
                        <small class="form-help">Access permissions are controlled by administrator.</small>
                    </div>

                    <div class="form-group" style="margin-top: 12px;">
                        <label for="profileNameInput" class="form-label">Display Name / Full Name *</label>
                        <input type="text" id="profileNameInput" name="name" class="form-input" value="<?= htmlspecialchars($user['name']) ?>" required placeholder="e.g. John Doe">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px;">
                        <div class="form-group">
                            <label for="profilePasswordInput" class="form-label">New Password</label>
                            <input type="password" id="profilePasswordInput" name="password" class="form-input" placeholder="Leave blank to keep current">
                            <small class="form-help">Min 6 chars if changing.</small>
                        </div>
                        <div class="form-group">
                            <label for="profileConfirmPasswordInput" class="form-label">Confirm New Password</label>
                            <input type="password" id="profileConfirmPasswordInput" class="form-input" placeholder="Re-enter new password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUserProfileModal()">Cancel</button>
                    <button type="submit" id="saveProfileSubmitBtn" class="btn btn-primary">Save Profile Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/assets/app.js?v=<?= time() ?>"></script>
</body>
</html>
