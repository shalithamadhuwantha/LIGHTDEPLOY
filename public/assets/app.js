/**
 * LIGHTDEPLOY Application JavaScript Engine
 * Vanilla JS SPA Engine supporting SSE streaming, automatic reconnection, and live metrics.
 */

document.addEventListener('DOMContentLoaded', () => {
    const userRole = document.body.dataset.userRole || 'viewer';
    const currentUsername = document.body.dataset.username || 'admin';
    const csrfToken = document.body.dataset.CsrfToken || document.body.dataset.csrfToken || '';

    let allowedFunctions = ['*'];
    let allowedSystems = ['*'];
    try {
        allowedFunctions = JSON.parse(document.body.dataset.allowedFunctions || '["*"]');
    } catch (e) {
        allowedFunctions = ['*'];
    }
    try {
        allowedSystems = JSON.parse(document.body.dataset.allowedSystems || '["*"]');
    } catch (e) {
        allowedSystems = ['*'];
    }

    function hasPermission(funcKey) {
        if (userRole === 'admin') return true;
        if (allowedFunctions.includes('*')) return true;
        return allowedFunctions.includes(funcKey);
    }

    function hasSystemAccess(siteId) {
        if (userRole === 'admin') return true;
        if (allowedSystems.includes('*')) return true;
        return allowedSystems.includes(siteId);
    }

    // State Variables
    let activeEventSource = null;
    let currentDeploymentId = null;
    let currentSiteId = null;
    let cachedSites = {};

    // DOM Elements
    const sitesGrid = document.getElementById('sitesGrid');
    const refreshSitesBtn = document.getElementById('refreshSitesBtn');
    const viewHistoryBtn = document.getElementById('viewHistoryBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    
    // Modals
    const deploymentModal = document.getElementById('deploymentModal');
    const historyModal = document.getElementById('historyModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const modalCloseFooterBtn = document.getElementById('modalCloseFooterBtn');
    const closeHistoryBtn = document.getElementById('closeHistoryBtn');
    const closeHistoryFooterBtn = document.getElementById('closeHistoryFooterBtn');
    
    // Terminal Elements
    const terminalOutput = document.getElementById('terminalOutput');
    const autoscrollCheck = document.getElementById('autoscrollCheck');
    const modalSiteTitle = document.getElementById('modalSiteTitle');
    const modalStatusBadge = document.getElementById('modalStatusBadge');
    const modalDepId = document.getElementById('modalDepId');
    const modalStartTime = document.getElementById('modalStartTime');
    
    // Action Buttons
    const modalCancelBtn = document.getElementById('modalCancelBtn');
    const modalRollbackBtn = document.getElementById('modalRollbackBtn');
    const modalDeployAgainBtn = document.getElementById('modalDeployAgainBtn');
    const historyTableBody = document.getElementById('historyTableBody');

    // Global Toast Notification System
    function showToast(message, type = 'info') {
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 999999; display: flex; flex-direction: column; gap: 8px; max-width: 380px; width: 100%; pointer-events: none;';
            document.body.appendChild(toastContainer);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        let bgColor = '#1e293b';
        let borderColor = '#3b82f6';
        let icon = 'ℹ️';

        if (type === 'success') {
            bgColor = '#064e3b';
            borderColor = '#10b981';
            icon = '✅';
        } else if (type === 'danger' || type === 'error') {
            bgColor = '#7f1d1d';
            borderColor = '#ef4444';
            icon = '❌';
        } else if (type === 'warning') {
            bgColor = '#78350f';
            borderColor = '#f59e0b';
            icon = '⚠️';
        }

        toast.style.cssText = `background: ${bgColor}; border-left: 4px solid ${borderColor}; color: #f8fafc; padding: 12px 16px; border-radius: 6px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); font-size: 0.85rem; line-height: 1.4; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; pointer-events: auto; font-family: var(--font-sans, sans-serif);`;
        toast.innerHTML = `<strong>${icon} ${message}</strong>`;

        toastContainer.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    window.showToast = showToast;

    // Helper: Standard Fetch Wrapper with CSRF header
    async function apiFetch(url, options = {}) {
        options.credentials = 'same-origin';
        options.headers = options.headers || {};
        const activeCsrfToken = document.body?.getAttribute('data-csrf-token') || document.body?.dataset?.csrfToken || csrfToken || '';
        if (activeCsrfToken) {
            options.headers['X-CSRF-Token'] = activeCsrfToken;
        }

        const res = await fetch(url, options);
        const data = await res.json().catch(() => ({ success: false, error: { message: 'Invalid response from server' } }));
        
        if (!res.ok && data.error?.code === 'UNAUTHORIZED') {
            window.location.href = '/login.php';
            return data;
        }
        
        return { ok: res.ok, status: res.status, data };
    }

    // 1. Fetch & Poll Server Performance Metrics
    async function updateServerMetrics() {
        const { ok, data } = await apiFetch('/api/server_status.php');
        if (ok && data.success) {
            const m = data;

            const setMeter = (meterId, valNum, valText) => {
                const meter = document.getElementById(meterId);
                if (!meter) return;
                const valEl = meter.querySelector('.metric-value');
                const fillBar = meter.querySelector('.meter-fill');
                
                if (valEl && meterId !== 'metricAppRam') valEl.textContent = valText;

                if (fillBar) {
                    const pct = Math.min(100, Math.max(0, valNum));
                    fillBar.style.width = `${pct}%`;

                    fillBar.classList.remove('meter-green', 'meter-warning', 'meter-danger');
                    if (pct < 50) {
                        fillBar.classList.add('meter-green');
                    } else if (pct < 80) {
                        fillBar.classList.add('meter-warning');
                    } else {
                        fillBar.classList.add('meter-danger');
                    }
                }
            };

            const cpuVal = parseFloat(m.cpu?.load_1m) || 0;
            const ramVal = parseFloat(m.memory?.percentage) || 0;
            const diskVal = parseFloat(m.disk?.percentage) || 0;
            const overallVal = typeof m.overall_load !== 'undefined' ? parseFloat(m.overall_load) : Math.round((cpuVal * 0.45) + (ramVal * 0.45) + (diskVal * 0.10));

            setMeter('metricOverall', overallVal, `${overallVal}%`);
            setMeter('metricCpu', cpuVal, `${m.cpu?.load_1m ?? 0}%`);
            setMeter('metricRam', ramVal, `${m.memory?.percentage ?? 0}%`);
            setMeter('metricDisk', diskVal, `${m.disk?.percentage ?? 0}%`);

            const uptimeVal = document.getElementById('bodyUptimeVal');
            if (uptimeVal) uptimeVal.textContent = m.uptime;

            const uptimeMeter = document.getElementById('metricUptime');
            if (uptimeMeter) {
                const valEl = uptimeMeter.querySelector('.metric-value');
                if (valEl) valEl.textContent = m.uptime;
            }

            if (m.app_resources) {
                const appRamVal = document.getElementById('metricAppRamVal');
                const appMeter = document.getElementById('metricAppRam');
                if (appRamVal) appRamVal.textContent = `${m.app_resources.rss_mb} MB`;
                if (appMeter) {
                    const mb = parseFloat(m.app_resources.rss_mb) || 0;
                    const pct = Math.min(100, Math.max(10, (mb / 500) * 100));
                    const fillCircle = appMeter.querySelector('.circle-chart-fill');
                    if (fillCircle) {
                        const circumference = 113.1;
                        fillCircle.style.strokeDashoffset = circumference - (circumference * pct / 100);
                    }
                }
            }
        }
    }

    // View Mode State
    let currentViewMode = localStorage.getItem('lightdeploy_view_mode') || 'card';
    let selectedSiteIds = new Set();
    const sitesSearchInput = document.getElementById('sitesSearchInput');
    const sitesSearchCount = document.getElementById('sitesSearchCount');
    const sitesCountLabel = document.getElementById('sitesCountLabel');
    const viewCardBtn = document.getElementById('viewCardBtn');
    const viewListBtn = document.getElementById('viewListBtn');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const bulkSelectAll = document.getElementById('bulkSelectAll');
    const bulkSelectedCount = document.getElementById('bulkSelectedCount');
    const bulkDeployBtn = document.getElementById('bulkDeployBtn');
    const bulkDeselectBtn = document.getElementById('bulkDeselectBtn');

    // Initialize view toggle state
    function initViewToggle() {
        if (currentViewMode === 'list') {
            viewCardBtn?.classList.remove('active');
            viewListBtn?.classList.add('active');
        } else {
            viewCardBtn?.classList.add('active');
            viewListBtn?.classList.remove('active');
        }
    }
    initViewToggle();

    if (viewCardBtn) viewCardBtn.addEventListener('click', () => {
        currentViewMode = 'card';
        localStorage.setItem('lightdeploy_view_mode', 'card');
        viewCardBtn.classList.add('active');
        viewListBtn?.classList.remove('active');
        renderSites();
    });

    if (viewListBtn) viewListBtn.addEventListener('click', () => {
        currentViewMode = 'list';
        localStorage.setItem('lightdeploy_view_mode', 'list');
        viewListBtn.classList.add('active');
        viewCardBtn?.classList.remove('active');
        renderSites();
    });

    // Search — debounced filtering
    let searchDebounce = null;
    if (sitesSearchInput) {
        sitesSearchInput.addEventListener('input', () => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => renderSites(), 150);
        });
    }

    // Multi-select helpers
    function updateBulkUI() {
        const count = selectedSiteIds.size;
        if (bulkSelectedCount) bulkSelectedCount.textContent = `${count} selected`;
        if (bulkDeployBtn) bulkDeployBtn.disabled = count === 0;
        if (bulkActionsBar) {
            if (count > 0) {
                bulkActionsBar.classList.remove('hidden');
            } else {
                bulkActionsBar.classList.add('hidden');
            }
        }
        // Update card visuals
        document.querySelectorAll('.site-card-checkbox').forEach(cb => {
            const card = cb.closest('.site-card');
            if (card) card.classList.toggle('card-selected', cb.checked);
        });
        document.querySelectorAll('.sites-list-table .list-row-checkbox').forEach(cb => {
            const row = cb.closest('tr');
            if (row) row.classList.toggle('site-row-selected', cb.checked);
        });
        // Sync select-all
        const allIds = Object.keys(cachedSites);
        if (bulkSelectAll) bulkSelectAll.checked = allIds.length > 0 && allIds.every(id => selectedSiteIds.has(id));
    }

    if (bulkSelectAll) {
        bulkSelectAll.addEventListener('change', () => {
            if (bulkSelectAll.checked) {
                Object.keys(cachedSites).forEach(id => selectedSiteIds.add(id));
            } else {
                selectedSiteIds.clear();
            }
            // Sync all checkboxes
            document.querySelectorAll('.site-card-checkbox, .list-row-checkbox').forEach(cb => {
                cb.checked = bulkSelectAll.checked;
            });
            updateBulkUI();
        });
    }

    if (bulkDeselectBtn) {
        bulkDeselectBtn.addEventListener('click', () => {
            selectedSiteIds.clear();
            document.querySelectorAll('.site-card-checkbox, .list-row-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateBulkUI();
        });
    }

    if (bulkDeployBtn) {
        bulkDeployBtn.addEventListener('click', async () => {
            const ids = Array.from(selectedSiteIds);
            if (ids.length === 0) return;
            if (!confirm(`Deploy ${ids.length} selected site(s)?\n\n${ids.join(', ')}`)) return;

            bulkDeployBtn.disabled = true;
            bulkDeployBtn.textContent = '⏳ Deploying...';

            for (const siteId of ids) {
                await executeDeploymentDirect(siteId, 'deploy', currentUsername);
            }

            selectedSiteIds.clear();
            updateBulkUI();
            bulkDeployBtn.textContent = '🚀 Deploy Selected';
            showToast(`Bulk deployment triggered for ${ids.length} site(s)`, 'success');
        });
    }

    // 2. Fetch & Render Configured Sites List
    async function loadSites() {
        const { ok, data } = await apiFetch('/api/sites.php');
        if (!ok || !data.success) {
            sitesGrid.innerHTML = `<div class="alert-box alert-danger">Failed to load configured sites. ${data.error?.message || ''}</div>`;
            return;
        }

        cachedSites = data.sites || {};
        renderSites();
    }

    // Render sites (supports both card + list views, and search filtering)
    function renderSites() {
        const sites = cachedSites;
        const searchTerm = (sitesSearchInput?.value || '').trim().toLowerCase();
        const totalCount = Object.keys(sites).length;

        if (sitesCountLabel) sitesCountLabel.textContent = `${totalCount} site${totalCount !== 1 ? 's' : ''}`;

        if (totalCount === 0) {
            sitesGrid.innerHTML = `<div class="alert-box alert-danger">No websites configured in config/sites.json.</div>`;
            if (sitesSearchCount) sitesSearchCount.classList.add('hidden');
            return;
        }

        // Filter by search term
        const filteredEntries = Object.entries(sites).filter(([siteId, site]) => {
            if (!searchTerm) return true;
            return siteId.toLowerCase().includes(searchTerm) ||
                   (site.name || '').toLowerCase().includes(searchTerm) ||
                   (site.domain || '').toLowerCase().includes(searchTerm);
        });

        // Show search result count
        if (searchTerm) {
            if (sitesSearchCount) {
                sitesSearchCount.textContent = `${filteredEntries.length} of ${totalCount}`;
                sitesSearchCount.classList.remove('hidden');
            }
        } else {
            if (sitesSearchCount) sitesSearchCount.classList.add('hidden');
        }

        if (filteredEntries.length === 0) {
            sitesGrid.className = 'sites-grid';
            sitesGrid.innerHTML = `
                <div class="sites-no-results">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                    No sites match "<strong>${escapeHtml(searchTerm)}</strong>"
                </div>`;
            return;
        }

        if (currentViewMode === 'list') {
            renderListView(filteredEntries);
        } else {
            renderCardView(filteredEntries);
        }

        attachSiteActionListeners();
    }

    // ── Card View Renderer ──────────────────────────────────────────────────
    function renderCardView(entries) {
        sitesGrid.className = 'sites-grid';
        sitesGrid.innerHTML = '';

        for (const [siteId, site] of entries) {
            const card = document.createElement('div');
            card.className = 'site-card' + (selectedSiteIds.has(siteId) ? ' card-selected' : '');

            const statusClass = site.is_locked ? 'badge-status-running' : (site.last_deployment ? `badge-status-${site.last_deployment.status}` : 'badge-status-idle');
            const statusLabel = site.is_locked ? 'RUNNING' : (site.last_deployment ? site.last_deployment.status.toUpperCase() : 'IDLE');

            const canDeploy = hasPermission('sites') && hasSystemAccess(siteId) && !site.is_locked && site.enabled;
            const canRollback = hasPermission('sites') && hasPermission('add_edit_sites') && hasSystemAccess(siteId) && !site.is_locked && site.has_rollback && site.enabled;
            const canEdit = hasPermission('add_edit_sites') && hasSystemAccess(siteId);

            card.innerHTML = `
                <input type="checkbox" class="site-card-checkbox" data-site-id="${siteId}" ${selectedSiteIds.has(siteId) ? 'checked' : ''}>
                <div class="card-top">
                    <div class="card-title-row">
                        <span class="site-name">${escapeHtml(site.name)}</span>
                        <span class="badge badge-status ${statusClass}">${statusLabel}</span>
                    </div>
                    <div class="site-domain">${escapeHtml(site.domain)}</div>
                    
                    <div class="card-meta-list">
                        <div class="meta-item">
                            <span class="meta-label">Health Check</span>
                            <span class="meta-val">${site.health_check_enabled ? 'Enabled (HTTP GET)' : 'Disabled'}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">PM2 Control</span>
                            <span class="meta-val">${site.pm2_enabled ? '⚡ Managed' : 'None'}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Rollback Script</span>
                            <span class="meta-val">${site.has_rollback ? 'Configured' : 'None'}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Last Deployment</span>
                            <span class="meta-val">${site.last_deployment ? escapeHtml(site.last_deployment.start_time) : 'Never'}</span>
                        </div>
                    </div>
                </div>

                <div class="card-actions">
                    <button class="btn btn-primary btn-sm btn-deploy" data-site-id="${siteId}" ${!canDeploy ? 'disabled' : ''}>
                        ${site.is_locked ? 'Deploying...' : 'Deploy'}
                    </button>
                    ${site.has_rollback ? `
                        <button class="btn btn-warning btn-sm btn-rollback" data-site-id="${siteId}" ${!canRollback ? 'disabled' : ''}>
                            Rollback
                        </button>
                    ` : ''}
                    ${site.last_deployment ? `
                        <button class="btn btn-secondary btn-sm btn-view-log" data-dep-id="${site.last_deployment.deployment_id}" data-site-id="${siteId}">
                            Log
                        </button>
                    ` : ''}
                    ${site.pm2_enabled ? `
                        <button class="btn btn-secondary btn-sm btn-pm2-reload" data-pm2-target="${escapeHtml(site.pm2_name || siteId)}" data-target="${escapeHtml(site.pm2_name || siteId)}">
                            ⚡ PM2 Reload
                        </button>
                        <button class="btn btn-secondary btn-sm btn-pm2-logs" data-pm2-target="${escapeHtml(site.pm2_name || siteId)}" data-target="${escapeHtml(site.pm2_name || siteId)}">
                            📄 PM2 Logs
                        </button>
                    ` : ''}
                    ${canEdit ? `
                        <button class="btn btn-secondary btn-sm btn-edit-site" data-site-id="${siteId}">
                            ⚙️ Edit
                        </button>
                    ` : ''}
                </div>
            `;

            sitesGrid.appendChild(card);
        }

        // Checkbox event listeners
        document.querySelectorAll('.site-card-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                const id = cb.dataset.siteId;
                if (cb.checked) selectedSiteIds.add(id);
                else selectedSiteIds.delete(id);
                updateBulkUI();
            });
        });
    }

    // ── List View Renderer ──────────────────────────────────────────────────
    function renderListView(entries) {
        sitesGrid.className = 'sites-grid view-list';

        const checkboxCol = hasPermission('sites') ? '<th style="width:36px;"></th>' : '';

        let rows = '';
        for (const [siteId, site] of entries) {
            const statusClass = site.is_locked ? 'badge-status-running' : (site.last_deployment ? `badge-status-${site.last_deployment.status}` : 'badge-status-idle');
            const statusLabel = site.is_locked ? 'RUNNING' : (site.last_deployment ? site.last_deployment.status.toUpperCase() : 'IDLE');
            const canDeploy = hasPermission('sites') && hasSystemAccess(siteId) && !site.is_locked && site.enabled;
            const canRollback = hasPermission('sites') && hasPermission('add_edit_sites') && hasSystemAccess(siteId) && !site.is_locked && site.has_rollback && site.enabled;
            const canEdit = hasPermission('add_edit_sites') && hasSystemAccess(siteId);
            const isSelected = selectedSiteIds.has(siteId);

            const checkboxTd = hasPermission('sites')
                ? `<td><input type="checkbox" class="list-row-checkbox" data-site-id="${siteId}" ${isSelected ? 'checked' : ''} style="accent-color: var(--accent-blue); cursor: pointer;"></td>`
                : '';

            rows += `
                <tr class="${isSelected ? 'site-row-selected' : ''}">
                    ${checkboxTd}
                    <td>
                        <span class="list-site-name">${escapeHtml(site.name)}</span>
                        <div class="list-site-domain">${escapeHtml(site.domain || siteId)}</div>
                    </td>
                    <td><span class="badge badge-status ${statusClass}">${statusLabel}</span></td>
                    <td style="font-size:0.78rem; color: var(--text-muted);">${site.health_check_enabled ? '✅' : '—'}</td>
                    <td style="font-size:0.78rem; color: var(--text-muted);">${site.pm2_enabled ? '⚡' : '—'}</td>
                    <td style="font-size:0.78rem; color: var(--text-muted);">${site.has_rollback ? '↩️' : '—'}</td>
                    <td style="font-size:0.78rem; font-family: var(--font-mono); color: var(--text-muted);">${site.last_deployment ? escapeHtml(site.last_deployment.start_time) : '—'}</td>
                    <td>
                        <div class="list-actions">
                            <button class="btn btn-primary btn-sm btn-deploy" data-site-id="${siteId}" ${!canDeploy ? 'disabled' : ''}>${site.is_locked ? '⏳' : '🚀'}</button>
                            ${site.has_rollback ? `<button class="btn btn-warning btn-sm btn-rollback" data-site-id="${siteId}" ${!canRollback ? 'disabled' : ''}>↩️</button>` : ''}
                            ${site.last_deployment ? `<button class="btn btn-secondary btn-sm btn-view-log" data-dep-id="${site.last_deployment.deployment_id}" data-site-id="${siteId}">📋</button>` : ''}
                            ${canEdit ? `<button class="btn btn-secondary btn-sm btn-edit-site" data-site-id="${siteId}">⚙️</button>` : ''}
                        </div>
                    </td>
                </tr>`;
        }

        sitesGrid.innerHTML = `
            <div class="table-responsive">
                <table class="sites-list-table">
                    <thead>
                        <tr>
                            ${checkboxCol}
                            <th>Site</th>
                            <th>Status</th>
                            <th>Health</th>
                            <th>PM2</th>
                            <th>Rollback</th>
                            <th>Last Deploy</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;

        // Checkbox event listeners
        document.querySelectorAll('.list-row-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                const id = cb.dataset.siteId;
                if (cb.checked) selectedSiteIds.add(id);
                else selectedSiteIds.delete(id);
                updateBulkUI();
            });
        });
    }

    // ── Attach action listeners to both views ───────────────────────────────
    function attachSiteActionListeners() {
        document.querySelectorAll('#sitesGrid .btn-deploy').forEach(btn => {
            btn.addEventListener('click', () => triggerDeployment(btn.dataset.siteId));
        });

        document.querySelectorAll('#sitesGrid .btn-rollback').forEach(btn => {
            btn.addEventListener('click', () => triggerRollback(btn.dataset.siteId));
        });

        document.querySelectorAll('#sitesGrid .btn-view-log').forEach(btn => {
            btn.addEventListener('click', () => viewDeploymentLog(btn.dataset.depId, btn.dataset.siteId));
        });

        document.querySelectorAll('#sitesGrid .btn-pm2-reload').forEach(btn => {
            btn.addEventListener('click', () => executePm2Action('reload', btn.dataset.pm2Target || btn.dataset.target));
        });

        document.querySelectorAll('#sitesGrid .btn-pm2-logs').forEach(btn => {
            btn.addEventListener('click', () => openPm2LogsModal(btn.dataset.pm2Target || btn.dataset.target));
        });

        document.querySelectorAll('#sitesGrid .btn-edit-site').forEach(btn => {
            btn.addEventListener('click', () => {
                const siteId = btn.dataset.siteId;
                const site = cachedSites[siteId];
                if (site) openEditSiteModal(siteId, site);
            });
        });
    }

    let activeDeployTarget = null;
    const triggerDeployModal = document.getElementById('triggerDeployModal');
    const triggerDeployForm = document.getElementById('triggerDeployForm');
    const closeTriggerDeployBtn = document.getElementById('closeTriggerDeployBtn');
    const closeTriggerDeployFooterBtn = document.getElementById('closeTriggerDeployFooterBtn');

    async function executeDeploymentDirect(siteId, mode, deployedBy) {
        const endpoint = mode === 'rollback' ? '/api/rollback.php' : '/api/deploy.php';
        const payload = { site_id: siteId };
        if (deployedBy) payload.deployed_by = deployedBy;

        const { ok, data } = await apiFetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (ok && data.success) {
            if (triggerDeployModal) triggerDeployModal.classList.add('hidden');
            currentDeploymentId = data.deployment_id;
            currentSiteId = siteId;
            localStorage.setItem('lightdeploy_active_dep', data.deployment_id);
            openDeploymentModal(data.deployment_id, siteId, 'running');
            connectSSEStream(data.deployment_id);
            loadSites();
        } else {
            showToast(data.error?.message || `${mode === 'rollback' ? 'Rollback' : 'Deployment'} failed.`, 'danger');
        }
    }

    function openTriggerDeployModal(siteId, mode = 'deploy') {
        activeDeployTarget = { siteId, mode };
        const site = cachedSites[siteId] || {};
        const siteName = site.name ? `${site.name} (${siteId})` : siteId;

        const titleEl = document.getElementById('triggerDeployTitle');
        if (titleEl) titleEl.textContent = mode === 'rollback' ? '⏪ Confirm Site Rollback' : '🚀 Confirm Site Deployment';

        const subInfoEl = document.getElementById('triggerDeploySubInfo');
        if (subInfoEl) subInfoEl.textContent = `Site ID: ${siteId}`;

        const siteNameEl = document.getElementById('triggerDeploySiteName');
        if (siteNameEl) siteNameEl.textContent = siteName;

        const submitBtn = document.getElementById('triggerDeploySubmitBtn');
        if (submitBtn) {
            if (mode === 'rollback') {
                submitBtn.textContent = '⏪ Execute Rollback';
                submitBtn.className = 'btn btn-warning';
            } else {
                submitBtn.textContent = '🚀 Execute Deployment';
                submitBtn.className = 'btn btn-primary';
            }
        }

        const inputEl = document.getElementById('triggerDeployedByInput');
        if (inputEl && !inputEl.value) {
            inputEl.value = currentUsername;
        }

        if (triggerDeployModal) {
            triggerDeployModal.classList.remove('hidden');
        } else {
            // Direct execution fallback if modal element is missing
            executeDeploymentDirect(siteId, mode, currentUsername);
        }
    }

    if (closeTriggerDeployBtn) closeTriggerDeployBtn.addEventListener('click', () => triggerDeployModal.classList.add('hidden'));
    if (closeTriggerDeployFooterBtn) closeTriggerDeployFooterBtn.addEventListener('click', () => triggerDeployModal.classList.add('hidden'));

    if (triggerDeployForm) {
        triggerDeployForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!activeDeployTarget) return;

            const submitBtn = document.getElementById('triggerDeploySubmitBtn');
            if (submitBtn) submitBtn.disabled = true;

            const { siteId, mode } = activeDeployTarget;
            const inputEl = document.getElementById('triggerDeployedByInput');
            const deployedBy = inputEl ? inputEl.value.trim() : currentUsername;

            await executeDeploymentDirect(siteId, mode, deployedBy);
            if (submitBtn) submitBtn.disabled = false;
        });
    }

    // 3. Trigger Deployment Execution
    async function triggerDeployment(siteId) {
        if (!confirm(`Are you sure you want to trigger DEPLOYMENT for site: ${siteId}?`)) {
            return;
        }
        await executeDeploymentDirect(siteId, 'deploy', currentUsername);
    }

    // 4. Trigger Rollback Execution
    async function triggerRollback(siteId) {
        if (!confirm(`WARNING: Are you sure you want to execute ROLLBACK for site: ${siteId}?`)) {
            return;
        }
        await executeDeploymentDirect(siteId, 'rollback', currentUsername);
    }

    function formatSriLankaTime(dateObj = new Date()) {
        try {
            return dateObj.toLocaleTimeString('en-US', { timeZone: 'Asia/Colombo', hour12: false });
        } catch (e) {
            return dateObj.toLocaleTimeString();
        }
    }

    // 5. Connect Server-Sent Events (SSE) Live Log Stream
    function connectSSEStream(depId) {
        if (activeEventSource) {
            activeEventSource.close();
        }

        terminalOutput.textContent = `[${formatSriLankaTime()}] [SYSTEM] Opening SSE real-time stream connection for ${depId}...\n`;

        activeEventSource = new EventSource(`/api/stream.php?deployment_id=${encodeURIComponent(depId)}`);

        activeEventSource.addEventListener('log', (e) => {
            try {
                const payload = JSON.parse(e.data);
                appendTerminalLine(payload.line);
            } catch (err) {
                appendTerminalLine(e.data);
            }
        });

        activeEventSource.addEventListener('status', (e) => {
            try {
                const meta = JSON.parse(e.data);
                updateModalStatusUI(meta);
            } catch (err) {}
        });

        activeEventSource.addEventListener('end', (e) => {
            try {
                const meta = JSON.parse(e.data);
                updateModalStatusUI(meta);
            } catch (err) {}
            activeEventSource.close();
            activeEventSource = null;
            localStorage.removeItem('lightdeploy_active_dep');
            loadSites();
        });

        activeEventSource.onerror = (err) => {
            // Stream closed or completed
            activeEventSource.close();
            activeEventSource = null;
        };
    }

    function highlightLogLine(line) {
        if (line === undefined || line === null) return '';
        const safeLine = String(line).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        if (/\[DIAGNOSTIC SUMMARY\]/i.test(line)) {
            return `<div class="log-diagnostic">${safeLine}</div>`;
        }
        if (/\[ERROR\]|🔴|ERR!|FAILED|fatal:|command not found|Permission denied|No such file|syntax error/i.test(line)) {
            return `<div class="log-line log-error">${safeLine}</div>`;
        }
        if (/\[SUCCESS\]|🟢|\[OK\]|PASSED|\[DONE\]/i.test(line)) {
            return `<div class="log-line log-success">${safeLine}</div>`;
        }
        if (/\[WARNING\]|⚠️|WARN/i.test(line)) {
            return `<div class="log-line log-warning">${safeLine}</div>`;
        }
        if (/\[SYSTEM\]|\[HEALTH\]/i.test(line)) {
            return `<div class="log-line log-system">${safeLine}</div>`;
        }
        if (line.includes('===') || line.includes('---')) {
            return `<div class="log-line" style="color: var(--accent-primary); font-weight: bold;">${safeLine}</div>`;
        }
        return `<div class="log-line">${safeLine}</div>`;
    }

    // Append formatted line to visual terminal
    function appendTerminalLine(line) {
        if (line === undefined || line === null) return;
        
        if (terminalOutput.dataset.raw === 'true') {
            terminalOutput.innerHTML = '';
            terminalOutput.dataset.raw = 'false';
        }

        const html = highlightLogLine(line);
        terminalOutput.insertAdjacentHTML('beforeend', html);
        if (autoscrollCheck.checked) {
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        }
    }

    function setTerminalContent(fullText) {
        terminalOutput.dataset.raw = 'false';
        if (!fullText) {
            terminalOutput.innerHTML = '<div class="log-line">No output captured.</div>';
            return;
        }

        const lines = fullText.split(/\r?\n/);
        const html = lines.map(line => highlightLogLine(line)).join('');
        terminalOutput.innerHTML = html;
        if (autoscrollCheck.checked) {
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        }
    }

    // Update Modal Action Buttons and Badges based on status
    function updateModalStatusUI(meta) {
        const status = (meta.status || 'running').toLowerCase();
        modalStatusBadge.className = `badge badge-status badge-status-${status}`;
        modalStatusBadge.textContent = status.toUpperCase();

        if (status === 'running') {
            modalCancelBtn.classList.remove('hidden');
            modalRollbackBtn.classList.add('hidden');
            modalDeployAgainBtn.classList.add('hidden');
        } else {
            modalCancelBtn.classList.add('hidden');
            modalDeployAgainBtn.classList.remove('hidden');
            
            if ((status === 'failed' || status === 'health_check_failed') && userRole === 'admin') {
                modalRollbackBtn.classList.remove('hidden');
            } else {
                modalRollbackBtn.classList.add('hidden');
            }
        }
    }

    // Open Deployment Modal
    function openDeploymentModal(depId, siteId, initialStatus = 'running') {
        modalDepId.textContent = depId;
        modalSiteTitle.textContent = `Deployment - ${siteId}`;
        modalStartTime.textContent = formatSriLankaTime();
        updateModalStatusUI({ status: initialStatus });
        terminalOutput.dataset.raw = 'true';

        deploymentModal.classList.remove('hidden');
    }

    // View Past Deployment Log
    async function viewDeploymentLog(depId, siteId) {
        openDeploymentModal(depId, siteId, 'loading');
        setTerminalContent('Loading archived log file...');

        const { ok, data } = await apiFetch(`/api/deployment.php?id=${encodeURIComponent(depId)}`);
        if (ok && data.success) {
            updateModalStatusUI(data.deployment);
            modalStartTime.textContent = data.deployment.start_time || '--';
            setTerminalContent(data.output || 'No output captured.');
        } else {
            setTerminalContent(`[ERROR] Failed to load log: ${data.error?.message || 'Not found'}`);
        }
    }

    // Cancel Deployment Execution
    if (modalCancelBtn) {
        modalCancelBtn.addEventListener('click', async () => {
            if (!currentDeploymentId) return;
            if (!confirm('Are you sure you want to CANCEL this active deployment process?')) return;

            modalCancelBtn.disabled = true;
            const { ok, data } = await apiFetch('/api/cancel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ deployment_id: currentDeploymentId })
            });

            modalCancelBtn.disabled = false;
            if (ok && data.success) {
                appendTerminalLine(`[${formatSriLankaTime()}] [SYSTEM] Cancellation command issued.`);
            } else {
                alert(`Cancel failed: ${data.error?.message || 'Unknown error'}`);
            }
        });
    }

    if (modalRollbackBtn) {
        modalRollbackBtn.addEventListener('click', () => {
            if (currentSiteId) {
                if (deploymentModal) deploymentModal.classList.add('hidden');
                triggerRollback(currentSiteId);
            }
        });
    }

    if (modalDeployAgainBtn) {
        modalDeployAgainBtn.addEventListener('click', () => {
            if (currentSiteId) {
                if (deploymentModal) deploymentModal.classList.add('hidden');
                triggerDeployment(currentSiteId);
            }
        });
    }

    // Modal Close Listeners
    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', () => {
            if (activeEventSource) {
                if (!confirm('Deployment is still running in the background. Close modal window?')) {
                    return;
                }
            }
            if (deploymentModal) deploymentModal.classList.add('hidden');
        });
    }

    if (modalCloseFooterBtn) {
        modalCloseFooterBtn.addEventListener('click', () => {
            if (deploymentModal) deploymentModal.classList.add('hidden');
        });
    }

    // 6. Audit History View
    if (viewHistoryBtn) {
        viewHistoryBtn.addEventListener('click', async () => {
            if (historyModal) historyModal.classList.remove('hidden');
            if (historyTableBody) historyTableBody.innerHTML = `<tr><td colspan="7" class="text-center">Loading audit history...</td></tr>`;

            const { ok, data } = await apiFetch('/api/history.php');
            if (!ok || !data.success) {
                if (historyTableBody) historyTableBody.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Failed to load history.</td></tr>`;
                return;
            }

            const history = data.history || [];
            if (history.length === 0) {
                if (historyTableBody) historyTableBody.innerHTML = `<tr><td colspan="7" class="text-center">No deployment history recorded yet.</td></tr>`;
                return;
            }

            if (historyTableBody) historyTableBody.innerHTML = '';
            history.forEach(h => {
                const tr = document.createElement('tr');
                const statusClass = `badge-status-${(h.status || 'unknown').toLowerCase()}`;

                tr.innerHTML = `
                    <td>${escapeHtml(h.start_time || '')}</td>
                    <td><code>${escapeHtml(h.deployment_id || '')}</code></td>
                    <td><strong>${escapeHtml(h.site_name || h.site_id || '')}</strong></td>
                    <td>${escapeHtml(h.user || '')}</td>
                    <td><span class="badge badge-status ${statusClass}">${escapeHtml((h.status || '').toUpperCase())}</span></td>
                    <td>${h.duration ? `${h.duration}s` : '--'}</td>
                    <td>
                        <button class="btn btn-secondary btn-sm hist-log-btn" data-dep-id="${h.deployment_id}" data-site-id="${h.site_id}">
                            View Log
                        </button>
                    </td>
                `;
                if (historyTableBody) historyTableBody.appendChild(tr);
            });

            document.querySelectorAll('.hist-log-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (historyModal) historyModal.classList.add('hidden');
                    viewDeploymentLog(btn.dataset.depId, btn.dataset.siteId);
                });
            });
        });
    }

    if (closeHistoryBtn) closeHistoryBtn.addEventListener('click', () => { if (historyModal) historyModal.classList.add('hidden'); });
    if (closeHistoryFooterBtn) closeHistoryFooterBtn.addEventListener('click', () => { if (historyModal) historyModal.classList.add('hidden'); });

    // Add Site Modal Handlers
    const addSiteBtn = document.getElementById('addSiteBtn');
    const addSiteModal = document.getElementById('addSiteModal');
    const closeAddSiteBtn = document.getElementById('closeAddSiteBtn');
    const closeAddSiteFooterBtn = document.getElementById('closeAddSiteFooterBtn');
    const addSiteForm = document.getElementById('addSiteForm');
    const healthCheckEnableInput = document.getElementById('healthCheckEnableInput');
    const healthCheckUrlGroup = document.getElementById('healthCheckUrlGroup');

    if (addSiteBtn) {
        addSiteBtn.addEventListener('click', () => {
            if (addSiteForm) addSiteForm.reset();
            const modalTitle = addSiteModal.querySelector('.modal-header h3');
            if (modalTitle) modalTitle.textContent = 'Add New Website';

            const siteIdInput = document.getElementById('siteIdInput');
            if (siteIdInput) siteIdInput.readOnly = false;

            if (healthCheckUrlGroup) healthCheckUrlGroup.classList.add('hidden');
            const pm2EnableInput = document.getElementById('pm2EnableInput');
            const pm2OptionsGroup = document.getElementById('pm2OptionsGroup');
            if (pm2EnableInput) pm2EnableInput.checked = false;
            if (pm2OptionsGroup) pm2OptionsGroup.classList.add('hidden');

            const pm2EcosystemInput = document.getElementById('pm2EcosystemInput');
            if (pm2EcosystemInput) pm2EcosystemInput.value = '';

            const deleteBtn = document.getElementById('deleteSiteModalBtn');
            if (deleteBtn) deleteBtn.classList.add('hidden');

            if (addSiteModal) addSiteModal.classList.remove('hidden');
        });
    }

    function openEditSiteModal(siteId, site) {
        if (!addSiteModal) return;

        const modalTitle = addSiteModal.querySelector('.modal-header h3');
        if (modalTitle) modalTitle.textContent = `Edit Website Configuration: ${site.name || siteId}`;

        const siteIdInput = document.getElementById('siteIdInput');
        if (siteIdInput) {
            siteIdInput.value = siteId;
            siteIdInput.readOnly = true;
        }

        document.getElementById('siteNameInput').value = site.name || '';
        document.getElementById('siteDomainInput').value = site.domain || '';
        document.getElementById('siteScriptInput').value = site.script || '';
        document.getElementById('siteRollbackInput').value = site.rollback_script || '';

        const healthCheckCheckbox = document.getElementById('healthCheckEnableInput');
        if (healthCheckCheckbox) healthCheckCheckbox.checked = !!site.health_check_enabled;

        const healthCheckUrlGroup = document.getElementById('healthCheckUrlGroup');
        if (healthCheckUrlGroup) {
            if (site.health_check_enabled) {
                healthCheckUrlGroup.classList.remove('hidden');
            } else {
                healthCheckUrlGroup.classList.add('hidden');
            }
        }
        document.getElementById('siteHealthCheckInput').value = site.health_check || '';

        const pm2EnableInput = document.getElementById('pm2EnableInput');
        if (pm2EnableInput) pm2EnableInput.checked = !!site.pm2_enabled;

        const pm2OptionsGroup = document.getElementById('pm2OptionsGroup');
        if (pm2OptionsGroup) {
            if (site.pm2_enabled) pm2OptionsGroup.classList.remove('hidden');
            else pm2OptionsGroup.classList.add('hidden');
        }

        const pm2EcosystemInput = document.getElementById('pm2EcosystemInput');
        if (pm2EcosystemInput) pm2EcosystemInput.value = site.pm2_ecosystem || '';

        const loadPm2TemplateBtn = document.getElementById('loadPm2TemplateBtn');
        if (loadPm2TemplateBtn) {
            loadPm2TemplateBtn.addEventListener('click', () => {
                const siteIdInput = document.getElementById('siteIdInput');
                const siteNameInput = document.getElementById('siteNameInput');
                const siteId = siteIdInput?.value.trim() || 'solar-backend';
                const siteName = siteNameInput?.value.trim() || siteId;
                
                const template = `module.exports = {
  apps: [{
    name: ${JSON.stringify(siteName)},
    script: 'src/index.ts',
    interpreter: 'node',
    interpreter_args: '--require esbuild-register',
    cwd: '/www/wwwroot/${siteId}',
    
    instances: 1,
    exec_mode: 'fork',
    watch: false,
    max_memory_restart: '1G',
    
    env: {
      NODE_ENV: 'production',
      PORT: 3000,
    },
    
    error_file: '/var/log/${siteId}-error.log',
    out_file: '/var/log/${siteId}-out.log',
    log_file: '/var/log/${siteId}-combined.log',
    time: true,
    
    autorestart: true,
    max_restarts: 10,
    min_uptime: '10s',
    
    kill_timeout: 5000,
    listen_timeout: 3000,
    
    merge_logs: true,
  }]
};`;
                if (pm2EcosystemInput) {
                    pm2EcosystemInput.value = template;
                    showToast('Loaded base PM2 ecosystem script template!', 'success');
                }
            });
        }

        const deleteBtn = document.getElementById('deleteSiteModalBtn');
        if (deleteBtn) deleteBtn.classList.remove('hidden');

        addSiteModal.classList.remove('hidden');
    }

    const deleteSiteModalBtn = document.getElementById('deleteSiteModalBtn');
    if (deleteSiteModalBtn) {
        deleteSiteModalBtn.addEventListener('click', async () => {
            const siteId = document.getElementById('siteIdInput').value;
            const siteName = document.getElementById('siteNameInput').value || siteId;
            if (!confirm(`Are you sure you want to delete website '${siteName}' (${siteId})? This action cannot be undone.`)) {
                return;
            }

            deleteSiteModalBtn.disabled = true;
            const { ok, data } = await apiFetch('/api/delete_site.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ site_id: siteId })
            });
            deleteSiteModalBtn.disabled = false;

            if (!ok || !data.success) {
                showToast(data.error?.message || 'Failed to delete site.', 'danger');
                return;
            }

            showToast(data.message || 'Site deleted successfully.', 'success');
            if (addSiteModal) addSiteModal.classList.add('hidden');
            loadSites();
        });
    }

    if (closeAddSiteBtn) {
        closeAddSiteBtn.addEventListener('click', () => {
            if (addSiteModal) addSiteModal.classList.add('hidden');
        });
    }

    if (closeAddSiteFooterBtn) {
        closeAddSiteFooterBtn.addEventListener('click', () => {
            if (addSiteModal) addSiteModal.classList.add('hidden');
        });
    }

    if (healthCheckEnableInput) {
        healthCheckEnableInput.addEventListener('change', (e) => {
            if (e.target.checked) {
                healthCheckUrlGroup.classList.remove('hidden');
            } else {
                healthCheckUrlGroup.classList.add('hidden');
            }
        });
    }

    const pm2EnableInput = document.getElementById('pm2EnableInput');
    const pm2OptionsGroup = document.getElementById('pm2OptionsGroup');
    if (pm2EnableInput && pm2OptionsGroup) {
        pm2EnableInput.addEventListener('change', (e) => {
            if (e.target.checked) {
                pm2OptionsGroup.classList.remove('hidden');
            } else {
                pm2OptionsGroup.classList.add('hidden');
            }
        });
    }

    if (addSiteForm) {
        addSiteForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('saveSiteSubmitBtn');
            if (submitBtn) submitBtn.disabled = true;

            const payload = {
                site_id: document.getElementById('siteIdInput').value.trim(),
                name: document.getElementById('siteNameInput').value.trim(),
                domain: document.getElementById('siteDomainInput').value.trim(),
                script: document.getElementById('siteScriptInput').value.trim(),
                rollback_script: document.getElementById('siteRollbackInput').value.trim(),
                health_check_enabled: document.getElementById('healthCheckEnableInput').checked,
                health_check: document.getElementById('siteHealthCheckInput').value.trim(),
                pm2_enabled: document.getElementById('pm2EnableInput')?.checked || false,
                pm2_ecosystem: document.getElementById('pm2EcosystemInput')?.value || ''
            };

            const { ok, data } = await apiFetch('/api/save_site.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (submitBtn) submitBtn.disabled = false;

            if (!ok || !data.success) {
                showToast(data.error?.message || 'Failed to save site configuration.', 'danger');
                return;
            }

            showToast(data.message || 'Site added successfully!', 'success');
            if (addSiteModal) addSiteModal.classList.add('hidden');
            addSiteForm.reset();
            loadSites();
        });
    }

    // Logout Action
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async () => {
            await apiFetch('/api/logout.php', { method: 'POST' });
            window.location.href = '/login.php';
        });
    }

    // =========================================================================
    // PM2 PROCESS MANAGER ENGINE
    // =========================================================================
    const pm2StatusBadge = document.getElementById('pm2StatusBadge');
    const pm2TableBody = document.getElementById('pm2TableBody');
    const refreshPm2Btn = document.getElementById('refreshPm2Btn');
    const startPm2AppBtn = document.getElementById('startPm2AppBtn');
    const pm2StartModal = document.getElementById('pm2StartModal');
    const closePm2StartBtn = document.getElementById('closePm2StartBtn');
    const closePm2StartFooterBtn = document.getElementById('closePm2StartFooterBtn');
    const pm2StartForm = document.getElementById('pm2StartForm');
    const pm2LogsModal = document.getElementById('pm2LogsModal');
    const closePm2LogsBtn = document.getElementById('closePm2LogsBtn');
    const closePm2LogsFooterBtn = document.getElementById('closePm2LogsFooterBtn');
    const refreshPm2LogsBtn = document.getElementById('refreshPm2LogsBtn');
    const pm2LogsOutput = document.getElementById('pm2LogsOutput');
    const pm2LogsTitle = document.getElementById('pm2LogsTitle');

    let currentPm2LogTarget = 'all';

    function formatUptime(seconds) {
        if (!seconds || seconds <= 0) return '0s';
        const d = Math.floor(seconds / (3600*24));
        const h = Math.floor((seconds % (3600*24)) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);

        const parts = [];
        if (d > 0) parts.push(`${d}d`);
        if (h > 0) parts.push(`${h}h`);
        if (m > 0) parts.push(`${m}m`);
        if (s > 0 || parts.length === 0) parts.push(`${s}s`);
        return parts.join(' ');
    }

    async function loadPm2Data() {
        if (!pm2TableBody) return;
        const { ok, data } = await apiFetch('/api/pm2.php');
        if (!ok || !data.success) {
            if (pm2StatusBadge) {
                pm2StatusBadge.textContent = 'OFFLINE';
                pm2StatusBadge.className = 'badge badge-status badge-status-failed';
            }
            pm2TableBody.innerHTML = `<tr><td colspan="9" class="text-center text-muted">Failed to query PM2 process manager.</td></tr>`;
            return;
        }

        if (!data.installed) {
            if (pm2StatusBadge) {
                pm2StatusBadge.textContent = 'NOT INSTALLED';
                pm2StatusBadge.className = 'badge badge-status badge-status-cancelled';
            }
            pm2TableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center" style="padding: 24px;">
                        <div style="margin-bottom: 12px; color: var(--text-muted);">
                            PM2 Process Manager is not installed on this server.
                        </div>
                        ${userRole === 'admin' ? `
                            <button id="installPm2Btn" class="btn btn-primary btn-sm">Install PM2 Globally (via npm)</button>
                        ` : '<small class="text-muted">Contact administrator to install PM2.</small>'}
                    </td>
                </tr>
            `;

            const installPm2Btn = document.getElementById('installPm2Btn');
            if (installPm2Btn) {
                installPm2Btn.addEventListener('click', async () => {
                    installPm2Btn.disabled = true;
                    installPm2Btn.textContent = 'Installing PM2...';
                    const { ok: insOk, data: insData } = await apiFetch('/api/pm2.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'install' })
                    });
                    installPm2Btn.disabled = false;
                    if (insOk && insData.success) {
                        showToast('PM2 Installed successfully!', 'success');
                        loadPm2Data();
                    } else {
                        showToast(insData.error?.message || 'PM2 installation failed.', 'danger');
                    }
                });
            }
            return;
        }

        if (pm2StatusBadge) {
            pm2StatusBadge.textContent = `v${data.version || 'Active'} (ONLINE)`;
            pm2StatusBadge.className = 'badge badge-status badge-status-success';
        }

        const processes = data.processes || [];
        currentPm2Processes = processes;

        if (processes.length === 0) {
            pm2TableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center" style="padding: 20px; color: var(--text-muted);">
                        No active PM2 processes found. Launch a new app using the button above.
                    </td>
                </tr>
            `;
            return;
        }

        pm2TableBody.innerHTML = '';
        processes.forEach(proc => {
            const tr = document.createElement('tr');

            let statusClass = 'badge-status-idle';
            if (proc.status === 'online') statusClass = 'badge-status-success';
            else if (proc.status === 'stopped') statusClass = 'badge-status-cancelled';
            else if (proc.status === 'errored') statusClass = 'badge-status-failed';

            const canControl = userRole === 'admin' || userRole === 'deployer';

            tr.innerHTML = `
                <td><code>${proc.id}</code></td>
                <td><strong>${escapeHtml(proc.name)}</strong></td>
                <td><code style="font-size: 0.8rem;">${proc.pid || '-'}</code></td>
                <td><span class="badge badge-status ${statusClass}">${proc.status.toUpperCase()}</span></td>
                <td><span class="meta-val">${proc.cpu}%</span></td>
                <td><span class="meta-val">${proc.memory} MB</span></td>
                <td><span class="meta-val">${formatUptime(proc.uptime)}</span></td>
                <td><span class="meta-val">${proc.restarts}</span></td>
                <td style="text-align: right;">
                    <div style="display: inline-flex; gap: 6px;">
                        ${canControl ? `
                            ${proc.status === 'online' ? `
                                <button class="btn btn-secondary btn-sm btn-pm2-action" data-action="restart" data-target="${proc.id}" title="Restart">🔄</button>
                                <button class="btn btn-secondary btn-sm btn-pm2-action" data-action="reload" data-target="${proc.id}" title="Reload">⚡</button>
                                <button class="btn btn-warning btn-sm btn-pm2-action" data-action="stop" data-target="${proc.id}" title="Stop">⏹️</button>
                            ` : `
                                <button class="btn btn-primary btn-sm btn-pm2-action" data-action="start" data-target="${proc.id}" title="Start">▶️</button>
                            `}
                            <button class="btn btn-secondary btn-sm btn-pm2-edit" data-name="${escapeHtml(proc.name)}" title="Ecosystem Settings">⚙️</button>
                        ` : ''}
                        <button class="btn btn-secondary btn-sm btn-pm2-logs" data-target="${escapeHtml(proc.name)}" data-pm2-target="${escapeHtml(proc.name)}" title="View Logs">📜</button>
                        ${userRole === 'admin' ? `
                            <button class="btn btn-danger btn-sm btn-pm2-action" data-action="delete" data-target="${proc.id}" title="Delete">🗑️</button>
                        ` : ''}
                    </div>
                </td>
            `;
            pm2TableBody.appendChild(tr);
        });

        // Event Listeners for PM2 Row Action Buttons
        document.querySelectorAll('#pm2TableBody .btn-pm2-action').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                const target = btn.dataset.target;
                if (action === 'delete' && !confirm(`Are you sure you want to remove PM2 process ${target}?`)) {
                    return;
                }
                executePm2Action(action, target);
            });
        });

        document.querySelectorAll('#pm2TableBody .btn-pm2-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                openPm2EditModal(btn.dataset.name);
            });
        });

        document.querySelectorAll('#pm2TableBody .btn-pm2-logs').forEach(btn => {
            btn.addEventListener('click', () => {
                openPm2LogsModal(btn.dataset.target || btn.dataset.pm2Target);
            });
        });
    }

    let currentPm2Processes = [];
    const pm2EditModal = document.getElementById('pm2EditModal');
    const pm2EditForm = document.getElementById('pm2EditForm');
    const closePm2EditBtn = document.getElementById('closePm2EditBtn');
    const closePm2EditFooterBtn = document.getElementById('closePm2EditFooterBtn');

    function openPm2EditModal(procName) {
        const proc = currentPm2Processes.find(p => p.name === procName) || {};

        document.getElementById('pm2EditNameInput').value = proc.name || procName || '';
        document.getElementById('pm2EditScriptInput').value = proc.script || '';
        document.getElementById('pm2EditCwdInput').value = proc.cwd || '';
        document.getElementById('pm2EditArgsInput').value = proc.args || '';
        document.getElementById('pm2EditInterpreterInput').value = proc.interpreter || 'node';
        document.getElementById('pm2EditInstancesInput').value = proc.instances || '1';
        document.getElementById('pm2EditMemInput').value = proc.max_memory_restart || '';
        document.getElementById('pm2EditCronInput').value = proc.cron_restart || '';
        document.getElementById('pm2EditRestartDelayInput').value = proc.restart_delay || '';
        document.getElementById('pm2EditAutoRestartInput').checked = proc.autorestart !== false;
        document.getElementById('pm2EditOutLogInput').value = proc.output_log || '';
        document.getElementById('pm2EditErrLogInput').value = proc.error_log || '';

        // Format environment object to string lines
        let envStr = '';
        if (proc.env && typeof proc.env === 'object') {
            const ignored = ['PM2_HOME', 'pm_id', 'status', 'unique_id', 'NODE_ENV'];
            const lines = [];
            for (const [k, v] of Object.entries(proc.env)) {
                if (!ignored.includes(k) && typeof v !== 'object') {
                    lines.push(`${k}=${v}`);
                }
            }
            envStr = lines.join('\n');
        }
        document.getElementById('pm2EditEnvInput').value = envStr;

        if (pm2EditModal) pm2EditModal.classList.remove('hidden');
    }

    if (closePm2EditBtn) closePm2EditBtn.addEventListener('click', () => pm2EditModal.classList.add('hidden'));
    if (closePm2EditFooterBtn) closePm2EditFooterBtn.addEventListener('click', () => pm2EditModal.classList.add('hidden'));

    if (pm2EditForm) {
        pm2EditForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('pm2EditSubmitBtn');
            if (submitBtn) submitBtn.disabled = true;

            // Parse environment variables string into key-value map
            const rawEnvStr = document.getElementById('pm2EditEnvInput').value;
            const envMap = {};
            rawEnvStr.split(/[\r\n,]+/).forEach(line => {
                const parts = line.split('=');
                if (parts.length >= 2) {
                    const k = parts[0].trim();
                    const v = parts.slice(1).join('=').trim();
                    if (k) envMap[k] = v;
                }
            });

            const payload = {
                action: 'update_config',
                name: document.getElementById('pm2EditNameInput').value.trim(),
                script: document.getElementById('pm2EditScriptInput').value.trim(),
                cwd: document.getElementById('pm2EditCwdInput').value.trim(),
                args: document.getElementById('pm2EditArgsInput').value.trim(),
                interpreter: document.getElementById('pm2EditInterpreterInput').value.trim(),
                instances: document.getElementById('pm2EditInstancesInput').value.trim() || '1',
                max_memory_restart: document.getElementById('pm2EditMemInput').value.trim(),
                cron_restart: document.getElementById('pm2EditCronInput').value.trim(),
                restart_delay: parseInt(document.getElementById('pm2EditRestartDelayInput').value) || 0,
                autorestart: document.getElementById('pm2EditAutoRestartInput').checked,
                output_log: document.getElementById('pm2EditOutLogInput').value.trim(),
                error_log: document.getElementById('pm2EditErrLogInput').value.trim(),
                env: envMap
            };

            const { ok, data } = await apiFetch('/api/pm2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (submitBtn) submitBtn.disabled = false;

            if (!ok || !data.success) {
                showToast(data.error?.message || 'Failed to update PM2 process ecosystem settings.', 'danger');
                return;
            }

            showToast(data.message || 'PM2 ecosystem settings updated & applied!', 'success');
            if (pm2EditModal) pm2EditModal.classList.add('hidden');
            loadPm2Data();
        });
    }

    async function executePm2Action(action, target) {
        const { ok, data } = await apiFetch('/api/pm2.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, target })
        });

        if (!ok || !data.success) {
            showToast(data.error?.message || `PM2 ${action} action failed.`, 'danger');
            return;
        }

        showToast(data.message || `PM2 ${action} executed successfully.`, 'success');
        loadPm2Data();
    }

    async function openPm2LogsModal(target) {
        target = (target && target !== 'undefined') ? target : 'all';
        currentPm2LogTarget = target;
        if (pm2LogsTitle) pm2LogsTitle.textContent = `PM2 Output Logs: ${target}`;
        if (pm2LogsOutput) pm2LogsOutput.textContent = 'Fetching logs from PM2 daemon...';
        if (pm2LogsModal) pm2LogsModal.classList.remove('hidden');

        await refreshPm2Logs();
    }

    async function refreshPm2Logs() {
        if (!currentPm2LogTarget || !pm2LogsOutput) return;
        const { ok, data } = await apiFetch(`/api/pm2.php?action=logs&target=${encodeURIComponent(currentPm2LogTarget)}`);
        if (ok && data.success) {
            pm2LogsOutput.textContent = data.logs || 'No log entries found.';
            pm2LogsOutput.scrollTop = pm2LogsOutput.scrollHeight;
        } else {
            pm2LogsOutput.textContent = 'Failed to load PM2 logs.';
        }
    }

    if (refreshPm2Btn) refreshPm2Btn.addEventListener('click', loadPm2Data);
    if (refreshPm2LogsBtn) refreshPm2LogsBtn.addEventListener('click', refreshPm2Logs);
    if (closePm2LogsBtn) closePm2LogsBtn.addEventListener('click', () => pm2LogsModal.classList.add('hidden'));
    if (closePm2LogsFooterBtn) closePm2LogsFooterBtn.addEventListener('click', () => pm2LogsModal.classList.add('hidden'));

    if (startPm2AppBtn) {
        startPm2AppBtn.addEventListener('click', () => {
            if (pm2StartForm) pm2StartForm.reset();
            if (pm2StartModal) pm2StartModal.classList.remove('hidden');
        });
    }

    if (closePm2StartBtn) closePm2StartBtn.addEventListener('click', () => pm2StartModal.classList.add('hidden'));
    if (closePm2StartFooterBtn) closePm2StartFooterBtn.addEventListener('click', () => pm2StartModal.classList.add('hidden'));

    if (pm2StartForm) {
        pm2StartForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('pm2StartSubmitBtn');
            if (submitBtn) submitBtn.disabled = true;

            const payload = {
                action: 'start_app',
                script: document.getElementById('pm2ScriptInput').value.trim(),
                name: document.getElementById('pm2AppNameInput').value.trim(),
                cwd: document.getElementById('pm2CwdInput').value.trim()
            };

            const { ok, data } = await apiFetch('/api/pm2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (submitBtn) submitBtn.disabled = false;

            if (!ok || !data.success) {
                showToast(data.error?.message || 'Failed to launch process in PM2.', 'danger');
                return;
            }

            showToast(data.message || 'Process launched successfully!', 'success');
            if (pm2StartModal) pm2StartModal.classList.add('hidden');
            pm2StartForm.reset();
            loadPm2Data();
        });
    }

    // 7. Check Browser Reconnection on Load / Refresh
    async function checkActiveReconnection() {
        const savedDepId = localStorage.getItem('lightdeploy_active_dep');
        if (savedDepId) {
            const { ok, data } = await apiFetch(`/api/deployment.php?id=${encodeURIComponent(savedDepId)}`);
            if (ok && data.success && data.deployment.status === 'running') {
                currentDeploymentId = savedDepId;
                currentSiteId = data.deployment.site_id;
                openDeploymentModal(savedDepId, data.deployment.site_id, 'running');
                connectSSEStream(savedDepId);
            } else {
                localStorage.removeItem('lightdeploy_active_dep');
            }
        }
    }

    // 8. VPS Open Ports Engine
    const viewPortsBtn = document.getElementById('viewPortsBtn');
    const portsModal = document.getElementById('portsModal');
    const closePortsBtn = document.getElementById('closePortsBtn');
    const closePortsFooterBtn = document.getElementById('closePortsFooterBtn');
    const refreshPortsModalBtn = document.getElementById('refreshPortsModalBtn');
    const portSearchInput = document.getElementById('portSearchInput');
    const portsTableBody = document.getElementById('portsTableBody');
    const freePortsList = document.getElementById('freePortsList');

    let cachedPortsData = [];

    async function loadVpsPorts() {
        window.loadVpsPorts = loadVpsPorts;
        const pTableBody = document.getElementById('portsTableBody') || portsTableBody;
        if (!pTableBody) return;
        pTableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Scanning VPS active listening ports...</td></tr>`;

        const { ok, data } = await apiFetch('/api/ports.php');
        if (ok && data.success) {
            cachedPortsData = data.ports || [];
            
            // Render suggested free ports
            if (freePortsList) {
                const freePorts = data.suggested_free_ports || [];
                if (freePorts.length > 0) {
                    freePortsList.innerHTML = freePorts.map(p => `
                        <button class="btn btn-secondary btn-sm copy-port-badge" data-port="${p}" style="padding: 2px 8px; font-size: 0.8rem; border-color: rgba(16, 185, 129, 0.4); color: #34d399;" title="Click to copy port ${p}">
                            :${p} 📋
                        </button>
                    `).join('');

                    document.querySelectorAll('.copy-port-badge').forEach(btn => {
                        btn.addEventListener('click', () => {
                            navigator.clipboard?.writeText(btn.dataset.port);
                            showToast(`Port ${btn.dataset.port} copied to clipboard!`, 'success');
                        });
                    });
                } else {
                    freePortsList.innerHTML = '<span style="color: var(--text-muted);">None detected in standard range</span>';
                }
            }

            renderPortsTable(cachedPortsData);
        } else {
            portsTableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #f87171; padding: 20px;">Failed to scan VPS ports: ${escapeHtml(data.error?.message || 'Unknown error')}</td></tr>`;
        }
    }

    function renderPortsTable(ports) {
        if (!portsTableBody) return;
        const query = (portSearchInput?.value || '').toLowerCase().trim();

        const filtered = ports.filter(p => {
            if (!query) return true;
            return (
                String(p.port).includes(query) ||
                (p.process || '').toLowerCase().includes(query) ||
                (p.proto || '').toLowerCase().includes(query) ||
                (p.bind || '').toLowerCase().includes(query) ||
                (p.site_name || '').toLowerCase().includes(query)
            );
        });

        if (filtered.length === 0) {
            portsTableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">No open ports match query "${escapeHtml(query)}"</td></tr>`;
            return;
        }

        portsTableBody.innerHTML = filtered.map(p => `
            <tr>
                <td>
                    <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; font-weight: bold; font-family: var(--font-mono); font-size: 0.9rem;">
                        :${p.port}
                    </span>
                </td>
                <td>
                    <span class="badge" style="background: rgba(255, 255, 255, 0.05); color: var(--text-muted);">
                        ${escapeHtml(p.proto)}
                    </span>
                </td>
                <td>
                    ${p.is_public ? 
                        `<span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #fca5a5;" title="Accessible publicly over network">0.0.0.0 (Public VPS)</span>` : 
                        `<span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399;" title="Local server loopback only">${escapeHtml(p.bind)} (Local)</span>`}
                </td>
                <td>
                    <strong style="color: #e2e8f0;">${escapeHtml(p.process)}</strong>
                    ${p.pid ? `<span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 4px;">(PID ${p.pid})</span>` : ''}
                </td>
                <td>
                    ${p.site_name ? 
                        `<span class="badge badge-status-running">🚀 ${escapeHtml(p.site_name)}</span>` : 
                        `<span style="color: var(--text-muted); font-size: 0.85rem;">Unmapped / System Process</span>`}
                </td>
                <td>
                    <button class="btn btn-secondary btn-sm copy-single-port" data-port="${p.port}">
                        📋 Copy Port
                    </button>
                </td>
            </tr>
        `).join('');

        document.querySelectorAll('.copy-single-port').forEach(btn => {
            btn.addEventListener('click', () => {
                navigator.clipboard?.writeText(btn.dataset.port);
                showToast(`Port ${btn.dataset.port} copied to clipboard!`, 'success');
            });
        });
    }

    window.openVpsPortsModal = function() {
        const pm = document.getElementById('portsModal');
        if (pm) {
            pm.classList.remove('hidden');
            pm.style.display = 'flex';
        } else {
            console.error('[LIGHTDEPLOY] portsModal element not found in DOM');
        }
        loadVpsPorts();
    };

    window.closeVpsPortsModal = function() {
        const pm = document.getElementById('portsModal');
        if (pm) {
            pm.classList.add('hidden');
            pm.style.display = 'none';
        }
    };

    window.triggerSiteDeploy = function(siteId) {
        triggerDeployment(siteId);
    };

    window.triggerSiteRollback = function(siteId) {
        triggerRollback(siteId);
    };

    document.querySelectorAll('.btn-view-ports, #viewPortsBtn, #headerViewPortsBtn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            window.openVpsPortsModal();
        });
    });

    if (closePortsBtn) closePortsBtn.addEventListener('click', () => window.closeVpsPortsModal());
    if (closePortsFooterBtn) closePortsFooterBtn.addEventListener('click', () => window.closeVpsPortsModal());
    if (refreshPortsModalBtn) refreshPortsModalBtn.addEventListener('click', () => loadVpsPorts());
    if (portSearchInput) {
        portSearchInput.addEventListener('input', () => renderPortsTable(cachedPortsData));
    }

    // Utility: HTML Escaping
    function escapeHtml(str) {
        return (str || '').toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // ==========================================================================
    // MYSQL DATABASE BACKUP SUITE ENGINE
    // ==========================================================================
    const dbContainer = document.getElementById('dbContainer');
    const addDbForm = document.getElementById('addDbForm');

    async function loadDatabases() {
        window.loadDatabases = loadDatabases;
        if (!dbContainer) return;
        dbContainer.innerHTML = `<div style="text-align: center; color: var(--text-muted); padding: 30px;">Loading MySQL database backup configurations...</div>`;

        const { ok, data } = await apiFetch('/api/backups.php');
        if (ok && data.success) {
            renderDatabases(data.databases || {});
        } else {
            dbContainer.innerHTML = `<div class="alert-box alert-danger">Failed to load database configurations: ${escapeHtml(data.message || 'Unknown error')}</div>`;
        }
    }

    function showBackupsListModal(db) {
        const titleEl = document.getElementById('dbBackupsListModalTitle');
        const subTitleEl = document.getElementById('dbBackupsListModalSubtitle');
        const tableBody = document.getElementById('dbBackupsListTableBody');
        const actionsEl = document.getElementById('dbBackupsModalActions');

        if (titleEl) titleEl.textContent = `📁 Backup Archives for ${db.label}`;
        if (subTitleEl) subTitleEl.innerHTML = `Database: <code>${escapeHtml(db.db_name)}</code> | Host: <code>${escapeHtml(db.db_host)}:${escapeHtml(db.db_port)}</code>`;

        if (actionsEl && (userRole === 'admin' || userRole === 'deployer')) {
            actionsEl.innerHTML = `
                <button class="btn btn-primary btn-sm btn-run-backup-modal" data-id="${db.id}" data-format="sql">⚡ Dump .sql Now</button>
                <button class="btn btn-secondary btn-sm btn-run-backup-modal" data-id="${db.id}" data-format="sql.gz">📦 Dump .sql.gz Now</button>
            `;

            actionsEl.querySelectorAll('.btn-run-backup-modal').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const dbId = btn.dataset.id;
                    const format = btn.dataset.format || 'sql';
                    btn.disabled = true;
                    const origText = btn.textContent;
                    btn.textContent = '⏳ Dumping...';

                    const { ok, data } = await apiFetch('/api/backups.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'run_backup', id: dbId, format: format })
                    });

                    btn.disabled = false;
                    btn.textContent = origText;

                    if (ok && data.success) {
                        showToast(data.message || 'Backup generated successfully!', 'success');
                        const res = await apiFetch('/api/backups.php');
                        if (res.ok && res.data.success && res.data.databases[dbId]) {
                            showBackupsListModal(res.data.databases[dbId]);
                        }
                        loadDatabases();
                    } else {
                        showToast(data.message || 'Backup failed.', 'error');
                    }
                });
            });
        }

        const backups = db.backups || [];
        if (backups.length === 0) {
            if (tableBody) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            No backup archives created yet for this database.<br>
                            Click <strong>"⚡ Dump .sql Now"</strong> to generate your first backup file.
                        </td>
                    </tr>
                `;
            }
        } else {
            if (tableBody) {
                tableBody.innerHTML = backups.map(b => {
                    const isSql = b.filename.endsWith('.sql');
                    const fileIcon = isSql ? '📜' : '📦';
                    const fileBadge = isSql
                        ? '<span class="badge badge-version" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">📜 .SQL (phpMyAdmin Ready)</span>'
                        : '<span class="badge badge-version" style="background: rgba(168, 85, 247, 0.15); color: #c084fc;">📦 .SQL.GZ Archive</span>';

                    return `
                        <tr>
                            <td style="font-family: var(--font-mono); font-size: 0.85rem; font-weight: 500;">${fileIcon} ${escapeHtml(b.filename)}</td>
                            <td>${fileBadge}</td>
                            <td style="font-family: var(--font-mono); font-size: 0.85rem;">${escapeHtml(b.filesize_formatted)}</td>
                            <td style="font-size: 0.85rem; color: var(--text-muted);">${escapeHtml(b.created_at)}</td>
                            <td>
                                <span class="badge badge-version" style="background: rgba(52, 211, 153, 0.1); color: #34d399;">Active (${escapeHtml(b.age_days)} days old)</span>
                            </td>
                            <td style="text-align: right;">
                                <a href="/api/backups.php?action=download&filename=${encodeURIComponent(b.filename)}" class="btn btn-secondary btn-sm" style="padding: 4px 10px; font-size: 0.8rem; text-decoration: none;" title="Download file">📥 Download</a>
                                ${userRole === 'admin' || userRole === 'deployer' ? `
                                    <button class="btn btn-outline-danger btn-sm btn-delete-backup-modal" data-filename="${escapeHtml(b.filename)}" data-db-id="${db.id}" style="padding: 4px 10px; font-size: 0.8rem;" title="Delete backup archive">🗑️ Delete</button>
                                ` : ''}
                            </td>
                        </tr>
                    `;
                }).join('');

                tableBody.querySelectorAll('.btn-delete-backup-modal').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const filename = btn.dataset.filename;
                        const dbId = btn.dataset.dbId;
                        if (!confirm(`Are you sure you want to delete backup '${filename}'?`)) return;

                        btn.disabled = true;
                        const { ok, data } = await apiFetch('/api/backups.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_backup', filename: filename })
                        });

                        if (ok && data.success) {
                            showToast(data.message || 'Backup file deleted.', 'success');
                            const res = await apiFetch('/api/backups.php');
                            if (res.ok && res.data.success && res.data.databases[dbId]) {
                                showBackupsListModal(res.data.databases[dbId]);
                            }
                            loadDatabases();
                        } else {
                            showToast(data.message || 'Failed to delete backup file.', 'error');
                            btn.disabled = false;
                        }
                    });
                });
            }
        }

        if (window.openDbBackupsListModal) {
            window.openDbBackupsListModal();
        } else {
            const modal = document.getElementById('dbBackupsListModal');
            if (modal) modal.classList.remove('hidden');
        }
    }

    function renderDatabases(databases) {
        const dbKeys = Object.keys(databases);
        if (dbKeys.length === 0) {
            dbContainer.innerHTML = `
                <div style="text-align: center; padding: 40px 20px; background: rgba(255,255,255,0.02); border: 1px dashed var(--bg-card-border); border-radius: var(--radius-md);">
                    <div style="font-size: 2.5rem; margin-bottom: 10px;">🗄️</div>
                    <h4 style="margin: 0 0 8px; color: var(--text-main);">No MySQL Databases Configured</h4>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;">Add database login credentials to configure 1-Click backups and automated 7-day retention schedules.</p>
                    ${userRole === 'admin' || userRole === 'deployer' ? '<button class="btn btn-primary btn-sm" onclick="openAddDbModal()">+ Add First Database</button>' : ''}
                </div>
            `;
            return;
        }

        const scheduleBadgeMap = {
            '5m': '<span class="badge badge-version" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">⚡ Every 5 Mins</span>',
            'daily': '<span class="badge badge-version" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">📅 Daily (24h)</span>',
            '12h': '<span class="badge badge-version" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">⏰ Every 12 Hours</span>',
            '6h': '<span class="badge badge-version" style="background: rgba(168, 85, 247, 0.15); color: #c084fc;">⚡ Every 6 Hours</span>',
            'weekly': '<span class="badge badge-version" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">🗓️ Weekly</span>',
            'disabled': '<span class="badge badge-version" style="background: rgba(100, 116, 139, 0.15); color: #94a3b8;">⏸️ Manual Only</span>'
        };

        const cardsHtml = dbKeys.map(dbId => {
            const db = databases[dbId];
            const backups = db.backups || [];
            
            return `
                <div class="site-card db-card" style="padding: 22px; border: 1px solid var(--bg-card-border); border-radius: var(--radius-md); background: rgba(15, 23, 42, 0.65); display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; gap: 10px;">
                            <div>
                                <div style="font-size: 1.15rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                    🗄️ ${escapeHtml(db.label)}
                                </div>
                                <div style="font-family: var(--font-mono); font-size: 0.84rem; color: #38bdf8; margin-top: 4px;">
                                    Database: <strong>${escapeHtml(db.db_name)}</strong>
                                </div>
                            </div>
                            <div>
                                ${scheduleBadgeMap[db.schedule] || ''}
                            </div>
                        </div>

                        <div style="background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.05); padding: 12px 14px; border-radius: 8px; font-family: var(--font-mono); font-size: 0.82rem; margin-bottom: 16px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <span style="color: var(--text-muted);">Host / Port:</span>
                                <span style="color: var(--text-main); font-weight: 600;">${escapeHtml(db.db_host)}:${escapeHtml(db.db_port)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <span style="color: var(--text-muted);">User:</span>
                                <span style="color: var(--text-main);">${escapeHtml(db.db_user)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <span style="color: var(--text-muted);">Last Backup:</span>
                                <span style="color: #38bdf8;">${escapeHtml(db.last_backup_at || 'Never')}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--text-muted);">Backup Files:</span>
                                <span class="badge badge-version" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">📁 ${backups.length} Archives</span>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto;">
                        <button class="btn btn-primary btn-sm btn-open-db-backups-modal" data-db='${escapeHtml(JSON.stringify(db))}' style="flex: 1; text-align: center; justify-content: center; font-weight: 600;">
                            📁 View Backups (${backups.length})
                        </button>
                        ${userRole === 'admin' || userRole === 'deployer' ? `
                            <button class="btn btn-secondary btn-sm btn-run-backup" data-id="${db.id}" data-format="sql" title="Run 1-Click Backup Now (.sql)">⚡ Backup Now</button>
                            <button class="btn btn-secondary btn-sm btn-edit-db" data-db='${escapeHtml(JSON.stringify(db))}'>✏️ Edit</button>
                        ` : ''}
                        ${userRole === 'admin' ? `
                            <button class="btn btn-outline-danger btn-sm btn-delete-db" data-id="${db.id}">🗑️</button>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');

        dbContainer.innerHTML = `
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;">
                ${cardsHtml}
            </div>
        `;

        // Attach event handlers for "View Backups" popup modal
        document.querySelectorAll('.btn-open-db-backups-modal').forEach(btn => {
            btn.addEventListener('click', () => {
                try {
                    const db = JSON.parse(btn.dataset.db);
                    showBackupsListModal(db);
                } catch (e) {
                    console.error('Failed to parse database data for backups modal:', e);
                }
            });
        });

        // Attach event handlers for DB operations
        document.querySelectorAll('.btn-run-backup').forEach(btn => {
            btn.addEventListener('click', async () => {
                const dbId = btn.dataset.id;
                const format = btn.dataset.format || 'sql';
                btn.disabled = true;
                const origText = btn.textContent;
                btn.textContent = '⏳ Dumping...';

                const { ok, data } = await apiFetch('/api/backups.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'run_backup', id: dbId, format: format })
                });

                if (ok && data.success) {
                    showToast(data.message || 'Backup generated successfully!', 'success');
                    loadDatabases();
                } else {
                    showToast(data.message || 'Backup failed.', 'error');
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            });
        });

        document.querySelectorAll('.btn-edit-db').forEach(btn => {
            btn.addEventListener('click', () => {
                try {
                    const db = JSON.parse(btn.dataset.db);
                    document.getElementById('dbIdInput').value = db.id;
                    document.getElementById('dbLabelInput').value = db.label || '';
                    document.getElementById('dbHostInput').value = db.db_host || '127.0.0.1';
                    document.getElementById('dbPortInput').value = db.db_port || 3306;
                    document.getElementById('dbNameInput').value = db.db_name || '';
                    document.getElementById('dbUserInput').value = db.db_user || '';

                    const dbPassInput = document.getElementById('dbPassInput');
                    const dbPassHelpText = document.getElementById('dbPassHelpText');
                    if (dbPassInput) {
                        dbPassInput.value = '';
                        dbPassInput.placeholder = '•••••••• (Existing password preserved)';
                    }
                    if (dbPassHelpText) {
                        dbPassHelpText.innerHTML = '🔒 <strong>Password is saved.</strong> Leave blank to keep existing password, or enter a new password to update.';
                    }

                    document.getElementById('dbScheduleInput').value = db.schedule || 'daily';
                    document.getElementById('addDbModalTitle').textContent = '✏️ Edit MySQL Database Configuration';
                    openAddDbModal();
                } catch (e) {
                    console.error('Failed to parse database data for editing:', e);
                }
            });
        });

        document.querySelectorAll('.btn-delete-db').forEach(btn => {
            btn.addEventListener('click', async () => {
                const dbId = btn.dataset.id;
                if (!confirm('Are you sure you want to delete this database configuration?')) return;

                const { ok, data } = await apiFetch('/api/backups.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_db', id: dbId })
                });

                if (ok && data.success) {
                    showToast('Database configuration deleted.', 'success');
                    loadDatabases();
                } else {
                    showToast(data.message || 'Failed to delete database config.', 'error');
                }
            });
        });
    }

    if (addDbForm) {
        addDbForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('addDbSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            const formData = new FormData(addDbForm);
            const payload = {
                action: 'save_db',
                id: formData.get('id') || '',
                label: formData.get('label') || '',
                db_host: formData.get('db_host') || '127.0.0.1',
                db_port: parseInt(formData.get('db_port') || '3306', 10),
                db_name: formData.get('db_name') || '',
                db_user: formData.get('db_user') || '',
                db_pass: formData.get('db_pass') || '',
                schedule: formData.get('schedule') || 'daily',
                retention_days: 7
            };

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Database Config';

            if (ok && data.success) {
                showToast(data.message || 'Database configuration saved successfully!', 'success');
                addDbForm.reset();
                document.getElementById('dbIdInput').value = '';
                window.closeAddDbModal();
                loadDatabases();
            } else {
                showToast(data.message || 'Failed to save database config.', 'error');
            }
        });
    }

    const backupAllDbsBtn = document.getElementById('backupAllDbsBtn');
    if (backupAllDbsBtn) {
        backupAllDbsBtn.addEventListener('click', async () => {
            if (!confirm('Run 1-Click backup for ALL configured databases? Each database will be saved into its own separate phpMyAdmin ready .sql file.')) return;

            backupAllDbsBtn.disabled = true;
            const origText = backupAllDbsBtn.textContent;
            backupAllDbsBtn.textContent = '⏳ Backing up all...';

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'backup_all', format: 'sql' })
            });

            backupAllDbsBtn.disabled = false;
            backupAllDbsBtn.textContent = origText;

            if (ok && data.success) {
                showToast(data.message || 'All databases backed up into separate .sql files!', 'success');
                loadDatabases();
            } else {
                showToast(data.message || 'Backup all failed.', 'error');
            }
        });
    }

    const globalScheduleForm = document.getElementById('globalScheduleForm');
    if (globalScheduleForm) {
        globalScheduleForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('bulkScheduleSubmitBtn');
            const scheduleSelect = document.getElementById('bulkScheduleInput');
            const scheduleVal = scheduleSelect ? scheduleSelect.value : 'daily';

            submitBtn.disabled = true;
            submitBtn.textContent = 'Updating...';

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'bulk_schedule', schedule: scheduleVal })
            });

            submitBtn.disabled = false;
            submitBtn.textContent = 'Apply to All Databases';

            if (ok && data.success) {
                showToast(data.message || 'Global schedule updated successfully!', 'success');
                if (window.closeGlobalScheduleModal) window.closeGlobalScheduleModal();
                loadDatabases();
            } else {
                showToast(data.message || 'Failed to update bulk schedule.', 'error');
            }
        });
    }

    window.loadMasterCredentials = async function() {
        const { ok, data } = await apiFetch('/api/backups.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_master_creds' })
        });

        if (ok && data.success && data.master_credentials) {
            const creds = data.master_credentials;
            if (document.getElementById('masterHostInput')) document.getElementById('masterHostInput').value = creds.db_host || '127.0.0.1';
            if (document.getElementById('masterPortInput')) document.getElementById('masterPortInput').value = creds.db_port || 3306;
            if (document.getElementById('masterUserInput')) document.getElementById('masterUserInput').value = creds.db_user || 'root';
            
            const passInput = document.getElementById('masterPassInput');
            const helpText = document.getElementById('masterPassHelpText');
            if (passInput) {
                passInput.value = '';
                passInput.placeholder = creds.has_password ? '•••••••• (Password Saved)' : 'Enter master password';
            }
            if (helpText) {
                helpText.textContent = creds.has_password ? '🔒 Master password is saved. Leave blank to preserve.' : 'Enter MySQL root or admin password.';
            }
        }
    };

    const masterCredsForm = document.getElementById('masterCredsForm');
    if (masterCredsForm) {
        masterCredsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('saveMasterCredsBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            const formData = new FormData(masterCredsForm);
            const payload = {
                action: 'save_master_creds',
                enabled: true,
                db_host: formData.get('db_host') || '127.0.0.1',
                db_port: parseInt(formData.get('db_port') || '3306', 10),
                db_user: formData.get('db_user') || 'root',
                db_pass: formData.get('db_pass') || ''
            };

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Master Credentials';

            if (ok && data.success) {
                showToast(data.message || 'Master credentials saved!', 'success');
                if (window.closeMasterCredsModal) window.closeMasterCredsModal();
            } else {
                showToast(data.message || 'Failed to save master credentials.', 'error');
            }
        });
    }

    const testMasterCredsBtn = document.getElementById('testMasterCredsBtn');
    if (testMasterCredsBtn) {
        testMasterCredsBtn.addEventListener('click', async () => {
            testMasterCredsBtn.disabled = true;
            testMasterCredsBtn.textContent = '🧪 Testing...';
            const resultBox = document.getElementById('masterTestResultBox');
            if (resultBox) {
                resultBox.style.display = 'none';
            }

            const formData = masterCredsForm ? new FormData(masterCredsForm) : new FormData();
            const payload = {
                action: 'test_master_creds',
                db_host: formData.get('db_host') || '127.0.0.1',
                db_port: parseInt(formData.get('db_port') || '3306', 10),
                db_user: formData.get('db_user') || 'root',
                db_pass: formData.get('db_pass') || ''
            };

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            testMasterCredsBtn.disabled = false;
            testMasterCredsBtn.textContent = '🧪 Test Connection & Discover DBs';

            if (resultBox) {
                resultBox.style.display = 'block';
                if (ok && data.success && data.result) {
                    const res = data.result;
                    const dbsList = (res.user_databases || []).map(d => `<code>${escapeHtml(d)}</code>`).join(', ');
                    resultBox.style.background = 'rgba(16, 185, 129, 0.15)';
                    resultBox.style.border = '1px solid #10b981';
                    resultBox.style.color = '#34d399';
                    resultBox.innerHTML = `
                        <strong>✅ Connection Successful!</strong><br>
                        Discovered <strong>${res.user_database_count}</strong> user database(s) on <code>${escapeHtml(res.host)}:${res.port}</code> via user <code>${escapeHtml(res.user)}</code>.<br>
                        <div style="margin-top: 6px; font-size: 0.82rem;"><strong>Discovered DBs:</strong> ${dbsList || 'None'}</div>
                    `;
                } else {
                    resultBox.style.background = 'rgba(239, 68, 68, 0.15)';
                    resultBox.style.border = '1px solid #ef4444';
                    resultBox.style.color = '#f87171';
                    resultBox.innerHTML = `<strong>❌ Connection Failed:</strong> ${escapeHtml(data.message || 'Check master username & password.')}`;
                }
            }
        });
    }

    const masterBackupBtn = document.getElementById('masterBackupBtn');
    if (masterBackupBtn) {
        masterBackupBtn.addEventListener('click', async () => {
            if (!confirm('Run Master Backup for ALL databases on this VPS using Master credentials? Each database will be dumped into its own separate, standalone .sql file.')) return;

            masterBackupBtn.disabled = true;
            const origText = masterBackupBtn.textContent;
            masterBackupBtn.textContent = '⏳ Running Master Backup...';

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'run_master_backup', format: 'sql' })
            });

            masterBackupBtn.disabled = false;
            masterBackupBtn.textContent = origText;

            if (ok && data.success) {
                showToast(data.message || 'Master Backup completed! All VPS databases dumped into separate .sql files.', 'success');
                loadDatabases();
            } else {
                showToast(data.message || 'Master Backup failed. Ensure Master Credentials are saved & tested.', 'error');
            }
        });
    }

    window.loadMasterBackupHistory = async function() {
        const container = document.getElementById('masterSessionsContainer');
        if (!container) return;

        container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 20px;">⏳ Loading Master Backup History...</div>';

        const { ok, data } = await apiFetch('/api/backups.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_master_history' })
        });

        if (!ok || !data.success) {
            container.innerHTML = `<div class="alert-box alert-danger">Failed to load backup history: ${escapeHtml(data.message || 'Unknown error')}</div>`;
            return;
        }

        const sessions = data.sessions || [];
        if (sessions.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 32px 16px; background: rgba(15, 23, 42, 0.4); border: 1px dashed rgba(255,255,255,0.1); border-radius: 8px;">
                    <div style="font-size: 2rem; margin-bottom: 8px;">📦</div>
                    <div style="font-weight: 600; color: #f8fafc;">No Master VPS Backups Found</div>
                    <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 4px;">Click <strong>"⚡ Run Master Backup Now"</strong> above to dump all VPS databases into separate .sql files.</div>
                </div>
            `;
            return;
        }

        let html = '';
        sessions.forEach(session => {
            let filesRows = session.files.map(f => `
                <tr>
                    <td style="font-weight: 600; color: #38bdf8;">🗄️ ${escapeHtml(f.db_name)}</td>
                    <td><code style="font-size: 0.8rem;">${escapeHtml(f.filename)}</code></td>
                    <td><span class="badge badge-version">${escapeHtml(f.filesize_formatted)}</span></td>
                    <td style="text-align: right;">
                        <a href="/api/backups.php?action=download&filename=${encodeURIComponent(f.filename)}" class="btn btn-secondary btn-sm" style="margin-right: 4px; text-decoration: none; padding: 3px 8px; font-size: 0.78rem;">📥 Download</a>
                        <button class="btn btn-outline-danger btn-sm btn-delete-master-file" data-filename="${escapeHtml(f.filename)}" style="padding: 3px 8px; font-size: 0.78rem;">🗑️</button>
                    </td>
                </tr>
            `).join('');

            html += `
                <div class="card" style="margin-bottom: 16px; border: 1px solid rgba(139, 92, 246, 0.25); background: rgba(15, 23, 42, 0.6);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: rgba(139, 92, 246, 0.1); border-bottom: 1px solid rgba(139, 92, 246, 0.2);">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 1.1rem;">📅</span>
                            <strong style="font-size: 0.95rem; color: #c084fc;">${escapeHtml(session.timestamp_label)}</strong>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <span class="badge badge-version" style="background: rgba(139, 92, 246, 0.2); color: #c084fc;">🗄️ ${session.total_files} Database Dump(s)</span>
                            <span class="badge badge-version" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">💾 ${escapeHtml(session.total_size_formatted)}</span>
                        </div>
                    </div>
                    <div style="padding: 12px;">
                        <table class="table" style="margin: 0; font-size: 0.85rem;">
                            <thead>
                                <tr>
                                    <th>Database</th>
                                    <th>File Name</th>
                                    <th>Size</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${filesRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;

        container.querySelectorAll('.btn-delete-master-file').forEach(btn => {
            btn.addEventListener('click', async () => {
                const fname = btn.dataset.filename;
                if (!confirm(`Delete backup file '${fname}'?`)) return;

                const { ok, data } = await apiFetch('/api/backups.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_backup', filename: fname })
                });

                if (ok && data.success) {
                    showToast('Backup file deleted.', 'success');
                    window.loadMasterBackupHistory();
                    loadDatabases();
                } else {
                    showToast(data.message || 'Failed to delete file.', 'error');
                }
            });
        });
    };

    const runMasterBackupModalBtn = document.getElementById('runMasterBackupModalBtn');
    if (runMasterBackupModalBtn) {
        runMasterBackupModalBtn.addEventListener('click', async () => {
            runMasterBackupModalBtn.disabled = true;
            const origText = runMasterBackupModalBtn.textContent;
            runMasterBackupModalBtn.textContent = '⏳ Running Backup...';

            const { ok, data } = await apiFetch('/api/backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'run_master_backup', format: 'sql' })
            });

            runMasterBackupModalBtn.disabled = false;
            runMasterBackupModalBtn.textContent = origText;

            if (ok && data.success) {
                showToast(data.message || 'Master Backup completed successfully!', 'success');
                window.loadMasterBackupHistory();
                loadDatabases();
            } else {
                showToast(data.message || 'Master Backup failed. Check Master DB User credentials.', 'error');
            }
        });
    }

    // --------------------------------------------------------------------------
    // 1-CLICK GITHUB SYSTEM UPDATER ENGINE
    // --------------------------------------------------------------------------
    window.checkSystemUpdates = async function() {
        const repoCommitVal = document.getElementById('updateRepoCommitVal');
        const commitMsgBox = document.getElementById('updateCommitMsgBox');
        const executeBtn = document.getElementById('executeSystemUpdateBtn');
        const termContainer = document.getElementById('updateTerminalContainer');
        
        if (!repoCommitVal) return;

        repoCommitVal.textContent = 'Checking GitHub...';
        repoCommitVal.style.background = 'rgba(56, 189, 248, 0.15)';
        repoCommitVal.style.color = '#38bdf8';
        if (commitMsgBox) commitMsgBox.classList.add('hidden');
        if (termContainer) termContainer.classList.add('hidden');
        if (executeBtn) {
            executeBtn.disabled = false;
            executeBtn.textContent = '🚀 Update Now from GitHub';
        }

        try {
            const { ok, data } = await apiFetch('/api/update_system.php');
            if (ok && data.success) {
                if (data.latest_commit) {
                    repoCommitVal.textContent = `${data.latest_commit.sha} (${data.latest_commit.date.substring(0,10)})`;
                    if (commitMsgBox) {
                        commitMsgBox.classList.remove('hidden');
                        commitMsgBox.textContent = `Latest Commit: "${data.latest_commit.message}" by ${data.latest_commit.author}`;
                    }
                } else {
                    repoCommitVal.textContent = 'Connected (main branch)';
                }
            } else {
                repoCommitVal.textContent = 'GitHub Reachable';
            }
        } catch (err) {
            repoCommitVal.textContent = 'Ready to sync';
        }
    };

    window.triggerSystemUpdate = async function() {
        const executeBtn = document.getElementById('executeSystemUpdateBtn');
        const termContainer = document.getElementById('updateTerminalContainer');
        const termOutput = document.getElementById('updateTerminalOutput');

        if (!executeBtn || !termOutput) return;

        executeBtn.disabled = true;
        executeBtn.textContent = '⏳ Updating System...';
        if (termContainer) termContainer.classList.remove('hidden');
        termOutput.textContent = '🚀 Launching GitHub system updater...\nConnecting to GitHub repository...\n';

        try {
            const { ok, data } = await apiFetch('/api/update_system.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update' })
            });

            if (ok && data.success) {
                termOutput.textContent = data.logs || 'System updated successfully!';
                showToast(data.message || 'LightDeploy updated successfully from GitHub!', 'success');
                executeBtn.textContent = '✅ Update Complete!';
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            } else {
                termOutput.textContent += `\n❌ UPDATE FAILED: ${data.message || 'Unknown error occurred.'}`;
                showToast(data.message || 'Failed to update system from GitHub.', 'error');
                executeBtn.disabled = false;
                executeBtn.textContent = '🔄 Retry Update';
            }
        } catch (err) {
            termOutput.textContent += `\n❌ NETWORK ERROR: ${err.message}`;
            showToast('Network error during system update.', 'error');
            executeBtn.disabled = false;
            executeBtn.textContent = '🔄 Retry Update';
        }
    };

    // =========================================================================
    // DEPLOYMENT SCRIPT GENERATOR ENGINE
    // =========================================================================

    (function initScriptGenerator() {
        let currentScriptType = 'bash'; // 'bash' or 'pm2_ecosystem'

        const sgTypeBashBtn = document.getElementById('sgTypeBashBtn');
        const sgTypePm2Btn = document.getElementById('sgTypePm2Btn');
        const sgBashFormContainer = document.getElementById('sgBashFormContainer');
        const sgPm2FormContainer = document.getElementById('sgPm2FormContainer');
        const scriptGenTitle = document.getElementById('scriptGenTitle');
        const scriptGenSubInfo = document.getElementById('scriptGenSubInfo');
        const sgPreviewTitle = document.getElementById('sgPreviewTitle');

        const sgFields = {
            appDir: document.getElementById('sgAppDir'),
            repoUrl: document.getElementById('sgRepoUrl'),
            branch: document.getElementById('sgBranch'),
            envSource: document.getElementById('sgEnvSource'),
            hasNpm: document.getElementById('sgHasNpm'),
            hasBuild: document.getElementById('sgHasBuild'),
            hasPm2: document.getElementById('sgHasPm2'),
            appName: document.getElementById('sgAppName'),
            siteUser: document.getElementById('sgSiteUser'),
            siteGroup: document.getElementById('sgSiteGroup'),
            outputPath: document.getElementById('sgOutputPath'),
        };

        const sgPm2Fields = {
            appName: document.getElementById('sgPm2AppNameInput'),
            script: document.getElementById('sgPm2ScriptInput'),
            cwd: document.getElementById('sgPm2CwdInput'),
            interpreter: document.getElementById('sgPm2InterpreterInput'),
            interpreterArgs: document.getElementById('sgPm2InterpreterArgsInput'),
            instances: document.getElementById('sgPm2InstancesInput'),
            execMode: document.getElementById('sgPm2ExecModeInput'),
            maxMem: document.getElementById('sgPm2MaxMemInput'),
            watch: document.getElementById('sgPm2WatchCheck'),
            time: document.getElementById('sgPm2TimeCheck'),
            mergeLogs: document.getElementById('sgPm2MergeLogsCheck'),
            nodeEnv: document.getElementById('sgPm2NodeEnvInput'),
            port: document.getElementById('sgPm2PortInput'),
            errorFile: document.getElementById('sgPm2ErrorFileInput'),
            outFile: document.getElementById('sgPm2OutFileInput'),
            logFile: document.getElementById('sgPm2LogFileInput'),
            maxRestarts: document.getElementById('sgPm2MaxRestartsInput'),
            minUptime: document.getElementById('sgPm2MinUptimeInput'),
            killTimeout: document.getElementById('sgPm2KillTimeoutInput'),
            listenTimeout: document.getElementById('sgPm2ListenTimeoutInput'),
            autorestart: document.getElementById('sgPm2AutorestartCheck'),
            outputPath: document.getElementById('sgPm2OutputPathInput'),
        };

        const sgPreview = document.getElementById('sgPreviewOutput');
        const sgPm2Group = document.getElementById('sgPm2Group');
        const sgCopyBtn = document.getElementById('sgCopyBtn');
        const sgDownloadBtn = document.getElementById('sgDownloadBtn');
        const sgSaveBtn = document.getElementById('sgSaveBtn');

        if (!sgPreview) return; // Not on dashboard page

        function setScriptGenMode(mode) {
            currentScriptType = mode === 'pm2_ecosystem' ? 'pm2_ecosystem' : 'bash';
            if (currentScriptType === 'pm2_ecosystem') {
                if (sgTypeBashBtn) sgTypeBashBtn.className = 'btn btn-secondary btn-sm';
                if (sgTypePm2Btn) sgTypePm2Btn.className = 'btn btn-primary btn-sm';
                if (sgBashFormContainer) sgBashFormContainer.classList.add('hidden');
                if (sgPm2FormContainer) sgPm2FormContainer.classList.remove('hidden');
                if (scriptGenTitle) scriptGenTitle.textContent = '⚡ PM2 Ecosystem Script Creator';
                if (scriptGenSubInfo) scriptGenSubInfo.textContent = 'Configure and generate production PM2 ecosystem.config.js configurations';
                if (sgPreviewTitle) sgPreviewTitle.textContent = '📄 Live ecosystem.config.js Preview';
            } else {
                if (sgTypeBashBtn) sgTypeBashBtn.className = 'btn btn-primary btn-sm';
                if (sgTypePm2Btn) sgTypePm2Btn.className = 'btn btn-secondary btn-sm';
                if (sgBashFormContainer) sgBashFormContainer.classList.remove('hidden');
                if (sgPm2FormContainer) sgPm2FormContainer.classList.add('hidden');
                if (scriptGenTitle) scriptGenTitle.textContent = '📜 Deployment Script Generator';
                if (scriptGenSubInfo) scriptGenSubInfo.textContent = 'Generate production-grade deployment scripts with embedded configuration';
                if (sgPreviewTitle) sgPreviewTitle.textContent = '📄 Live deploy-*.sh Script Preview';
            }
            updateScriptPreview();
        }

        window.setScriptGenMode = setScriptGenMode;

        if (sgTypeBashBtn) sgTypeBashBtn.addEventListener('click', () => setScriptGenMode('bash'));
        if (sgTypePm2Btn) sgTypePm2Btn.addEventListener('click', () => setScriptGenMode('pm2_ecosystem'));

        // PM2 toggle visibility
        if (sgFields.hasPm2) {
            sgFields.hasPm2.addEventListener('change', () => {
                if (sgPm2Group) {
                    sgPm2Group.classList.toggle('hidden', !sgFields.hasPm2.checked);
                }
                updateScriptPreview();
            });
        }

        // Generate PM2 script content client-side
        function generatePm2ScriptContent() {
            const appName = sgPm2Fields.appName?.value.trim() || 'solar-backend';
            const script = sgPm2Fields.script?.value.trim() || 'src/index.ts';
            const interpreter = sgPm2Fields.interpreter?.value.trim() || 'node';
            const interpreterArgs = sgPm2Fields.interpreterArgs?.value.trim() || '';
            const cwd = sgPm2Fields.cwd?.value.trim() || '/www/wwwroot/apisolar.blueoctopus.site';
            const instances = parseInt(sgPm2Fields.instances?.value) || 1;
            const execMode = sgPm2Fields.execMode?.value || 'fork';
            const watch = !!sgPm2Fields.watch?.checked;
            const maxMem = sgPm2Fields.maxMem?.value.trim() || '1G';
            const nodeEnv = sgPm2Fields.nodeEnv?.value.trim() || 'production';
            const port = parseInt(sgPm2Fields.port?.value) || 3000;
            const errorFile = sgPm2Fields.errorFile?.value.trim() || `/var/log/${appName}-error.log`;
            const outFile = sgPm2Fields.outFile?.value.trim() || `/var/log/${appName}-out.log`;
            const logFile = sgPm2Fields.logFile?.value.trim() || `/var/log/${appName}-combined.log`;
            const time = !!sgPm2Fields.time?.checked;
            const autorestart = !!sgPm2Fields.autorestart?.checked;
            const maxRestarts = parseInt(sgPm2Fields.maxRestarts?.value) || 10;
            const minUptime = sgPm2Fields.minUptime?.value.trim() || '10s';
            const killTimeout = parseInt(sgPm2Fields.killTimeout?.value) || 5000;
            const listenTimeout = parseInt(sgPm2Fields.listenTimeout?.value) || 3000;
            const mergeLogs = !!sgPm2Fields.mergeLogs?.checked;

            let code = `module.exports = {\n`;
            code += `  apps: [{\n`;
            code += `    name: ${JSON.stringify(appName)},\n`;
            code += `    script: ${JSON.stringify(script)},\n`;
            code += `    interpreter: ${JSON.stringify(interpreter)},\n`;
            if (interpreterArgs) {
                code += `    interpreter_args: ${JSON.stringify(interpreterArgs)},\n`;
            }
            code += `    cwd: ${JSON.stringify(cwd)},\n`;
            code += `    \n`;
            code += `    instances: ${instances},\n`;
            code += `    exec_mode: ${JSON.stringify(execMode)},\n`;
            code += `    watch: ${watch},\n`;
            code += `    max_memory_restart: ${JSON.stringify(maxMem)},\n`;
            code += `    \n`;
            code += `    env: {\n`;
            code += `      NODE_ENV: ${JSON.stringify(nodeEnv)},\n`;
            code += `      PORT: ${port},\n`;
            code += `    },\n`;
            code += `    \n`;
            code += `    error_file: ${JSON.stringify(errorFile)},\n`;
            code += `    out_file: ${JSON.stringify(outFile)},\n`;
            code += `    log_file: ${JSON.stringify(logFile)},\n`;
            code += `    time: ${time},\n`;
            code += `    \n`;
            code += `    autorestart: ${autorestart},\n`;
            code += `    max_restarts: ${maxRestarts},\n`;
            code += `    min_uptime: ${JSON.stringify(minUptime)},\n`;
            code += `    \n`;
            code += `    kill_timeout: ${killTimeout},\n`;
            code += `    listen_timeout: ${listenTimeout},\n`;
            code += `    \n`;
            code += `    merge_logs: ${mergeLogs},\n`;
            code += `  }]\n`;
            code += `};\n`;

            return code;
        }

        // Collect current form values
        function getConfig() {
            return {
                appDir: sgFields.appDir?.value.trim() || '/www/wwwroot/example.com',
                repoUrl: sgFields.repoUrl?.value.trim() || 'https://github.com/user/repo.git',
                branch: sgFields.branch?.value.trim() || 'main',
                envSource: sgFields.envSource?.value.trim() || '',
                hasNpm: sgFields.hasNpm?.checked ? 'true' : 'false',
                hasBuild: sgFields.hasBuild?.checked ? 'true' : 'false',
                hasPm2: sgFields.hasPm2?.checked ? 'true' : 'false',
                appName: sgFields.appName?.value.trim() || '',
                siteUser: sgFields.siteUser?.value.trim() || 'www',
                siteGroup: sgFields.siteGroup?.value.trim() || 'www',
            };
        }

        // Generate the script content client-side (mirrors the backend template)
        function generateScriptContent(c) {
            return `#!/usr/bin/env bash

set -Eeuo pipefail

# ============================================================
# DEPLOYMENT SCRIPT
# ============================================================
# This script was auto-generated by LightDeploy Script Generator
# ============================================================

# ============================================================
# CONFIGURATION
# ============================================================

APP_DIR="${c.appDir}"
REPO_URL="${c.repoUrl}"
BRANCH="${c.branch}"
ENV_SOURCE="${c.envSource}"
HAS_NPM="${c.hasNpm}"
HAS_BUILD="${c.hasBuild}"
HAS_PM2="${c.hasPm2}"
APP_NAME="${c.appName}"
SITE_USER="${c.siteUser}"
SITE_GROUP="${c.siteGroup}"

GITHUB_TOKEN_FILE="/root/.github_token"
LOG_FILE="/var/log/deploy-$(basename "$APP_DIR").log"
TEMP_DIR=""

RED='\\033[0;31m'
GREEN='\\033[0;32m'
YELLOW='\\033[1;33m'
BLUE='\\033[0;34m'
NC='\\033[0m'

# ============================================================
# LOGGING FUNCTIONS
# ============================================================

log() {
    echo -e "\${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]\${NC} $1" | tee -a "$LOG_FILE"
}

success() {
    echo -e "\${GREEN}[SUCCESS]\${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "\${YELLOW}[WARNING]\${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "\${RED}[ERROR]\${NC} $1" | tee -a "$LOG_FILE" >&2
}

# ============================================================
# ERROR HANDLER
# ============================================================

error_handler() {
    local exit_code=$?
    local line_number="$1"
    error "Deployment failed at line \${line_number}."
    error "Exit code: \${exit_code}"
    if [[ -n "\${TEMP_DIR:-}" && -d "\${TEMP_DIR:-}" ]]; then
        warning "Removing temporary deployment directory..."
        rm -rf -- "$TEMP_DIR" 2>/dev/null || true
    fi
    error "Deployment aborted."
    exit "$exit_code"
}

trap 'error_handler $LINENO' ERR

# ============================================================
# CLEANUP
# ============================================================

cleanup() {
    if [[ -n "\${TEMP_DIR:-}" && -d "\${TEMP_DIR:-}" ]]; then
        rm -rf -- "$TEMP_DIR" 2>/dev/null || true
    fi
}

trap cleanup EXIT

# ============================================================
# START DEPLOYMENT
# ============================================================

log "============================================================"
log "Starting deployment"
log "============================================================"
log "Application directory: $APP_DIR"
log "Repository: $REPO_URL"
log "Branch: $BRANCH"
log "Has npm install: $HAS_NPM"
log "Has build: $HAS_BUILD"
log "Has PM2: $HAS_PM2"
if [[ -n "$ENV_SOURCE" ]]; then
    log "Environment source: $ENV_SOURCE"
fi
log "============================================================"

# ============================================================
# ROOT CHECK
# ============================================================

if [[ "$EUID" -ne 0 ]]; then
    error "This script must be run as root."
    exit 1
fi

# ============================================================
# DIRECTORY SAFETY CHECK
# ============================================================

if [[ ! -d "$APP_DIR" ]]; then
    error "Application directory does not exist:"
    error "$APP_DIR"
    exit 1
fi

# ============================================================
# REQUIRED COMMANDS
# ============================================================

REQUIRED_CMDS=("git" "find" "rm" "cp" "mktemp" "chown" "stat")

if [[ "$HAS_NPM" == "true" ]] || [[ "$HAS_BUILD" == "true" ]]; then
    REQUIRED_CMDS+=("npm")
fi

for cmd in "\${REQUIRED_CMDS[@]}"; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        error "Required command not found: $cmd"
        exit 1
    fi
done

# ============================================================
# CHECK WWW USER/GROUP
# ============================================================

if ! id "$SITE_USER" >/dev/null 2>&1; then
    error "User does not exist: $SITE_USER"
    exit 1
fi

if ! getent group "$SITE_GROUP" >/dev/null 2>&1; then
    error "Group does not exist: $SITE_GROUP"
    exit 1
fi

# ============================================================
# CHECK ENV FILE (if provided)
# ============================================================

if [[ -n "$ENV_SOURCE" ]]; then
    if [[ ! -f "$ENV_SOURCE" ]]; then
        error "Environment file does not exist:"
        error "$ENV_SOURCE"
        exit 1
    fi
else
    log "No environment file provided, skipping .env installation"
fi

# ============================================================
# CHECK GITHUB TOKEN
# ============================================================

if [[ ! -f "$GITHUB_TOKEN_FILE" ]]; then
    error "GitHub token file does not exist:"
    error "$GITHUB_TOKEN_FILE"
    error "Please create this file with your GitHub Personal Access Token"
    exit 1
fi

GITHUB_TOKEN=$(cat "$GITHUB_TOKEN_FILE" | tr -d '\\n\\r')

if [[ -z "$GITHUB_TOKEN" ]]; then
    error "GitHub token file is empty:"
    error "$GITHUB_TOKEN_FILE"
    exit 1
fi

success "Environment checks passed."

# ============================================================
# CLONE TO TEMPORARY DIRECTORY
# ============================================================

log "Creating temporary deployment directory..."
TEMP_DIR="$(mktemp -d /tmp/deploy-XXXXXXXX)"
log "Temporary directory: $TEMP_DIR"
log "Cloning repository..."

git clone \\
    --depth 1 \\
    --single-branch \\
    --branch "$BRANCH" \\
    "https://\${GITHUB_TOKEN}@\${REPO_URL#https://}" \\
    "$TEMP_DIR"

success "Git clone completed."

# ============================================================
# VALIDATE REPOSITORY
# ============================================================

if [[ ! -d "$TEMP_DIR/.git" ]]; then
    error "Git repository validation failed."
    exit 1
fi

success "Repository validated."

# ============================================================
# REMOVE IMMUTABLE FILES
# ============================================================

log "Checking for immutable files..."
while IFS= read -r -d '' file; do
    log "Removing immutable flag: $file"
    chattr -i "$file" 2>/dev/null || true
done < <(
    find "$APP_DIR" \\
        -type f \\
        \\( -name ".user.ini" -o -name ".htaccess" \\) \\
        -print0 \\
        2>/dev/null || true
)

# ============================================================
# CLEAN OLD DEPLOYMENT
# ============================================================

log "Cleaning old files..."
find "$APP_DIR" \\
    -mindepth 1 \\
    -maxdepth 1 \\
    -exec rm -rf -- {} +

success "Old files removed."

# ============================================================
# COPY APPLICATION FILES
# ============================================================

log "Copying application files..."
cp -R "$TEMP_DIR"/. "$APP_DIR"/
success "Application files copied."

# ============================================================
# COPY ENVIRONMENT FILE (if provided)
# ============================================================

if [[ -n "$ENV_SOURCE" ]]; then
    log "Installing environment configuration from: $ENV_SOURCE"
    cp "$ENV_SOURCE" "$APP_DIR/.env"
    success ".env installed to $APP_DIR/.env"
else
    log "No environment file provided, skipping .env installation"
fi

# ============================================================
# NPM INSTALL (if enabled)
# ============================================================

if [[ "$HAS_NPM" == "true" ]]; then
    log "Installing npm dependencies..."
    cd "$APP_DIR"
    if [[ ! -f "$APP_DIR/package.json" ]]; then
        error "package.json not found in application directory"
        exit 1
    fi
    npm install
    success "NPM dependencies installed."
else
    log "npm install skipped"
fi

# ============================================================
# NPM BUILD (if enabled)
# ============================================================

if [[ "$HAS_BUILD" == "true" ]]; then
    log "Building application..."
    cd "$APP_DIR"
    if grep -q '"build"' "$APP_DIR/package.json"; then
        npm run build
        success "Application build completed."
    else
        warning "No build script found in package.json"
    fi
else
    log "Build skipped"
fi

# ============================================================
# SET OWNERSHIP
# ============================================================

log "Setting ownership to \${SITE_USER}:\${SITE_GROUP}..."
chown -R "\${SITE_USER}:\${SITE_GROUP}" "$APP_DIR"
success "Ownership set to \${SITE_USER}:\${SITE_GROUP}."

# ============================================================
# VERIFY DEPLOYMENT
# ============================================================

if [[ -z "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    error "Application directory is empty."
    exit 1
fi
success "Deployment verified."

# ============================================================
# GIT COMMIT
# ============================================================

if [[ -d "$APP_DIR/.git" ]]; then
    COMMIT="$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo "unknown")"
    log "Deployed commit: $COMMIT"
fi

# ============================================================
# PM2 RESTART (if enabled)
# ============================================================

if [[ "$HAS_PM2" == "true" ]]; then
    log "Checking process manager..."
    if command -v pm2 >/dev/null 2>&1; then
        if pm2 list | grep -q "$APP_NAME"; then
            log "Restarting application with PM2..."
            pm2 restart "$APP_NAME"
            success "PM2 restart completed."
        else
            if [[ -f "$APP_DIR/index.js" ]]; then
                ENTRY_FILE="index.js"
            elif [[ -f "$APP_DIR/app.js" ]]; then
                ENTRY_FILE="app.js"
            elif [[ -f "$APP_DIR/server.js" ]]; then
                ENTRY_FILE="server.js"
            else
                ENTRY_FILE=""
            fi
            if [[ -n "$ENTRY_FILE" ]]; then
                log "Starting application with PM2..."
                pm2 start "$APP_DIR/$ENTRY_FILE" --name "$APP_NAME"
                success "PM2 started application."
            else
                warning "Could not find entry file for PM2. Please start manually."
            fi
        fi
        pm2 save
    else
        warning "PM2 not found. Please restart application manually."
    fi
else
    log "PM2 skipped"
    log "Checking web server status..."
    if command -v nginx >/dev/null 2>&1; then
        if systemctl is-active --quiet nginx 2>/dev/null; then
            log "Reloading Nginx configuration..."
            systemctl reload nginx || warning "Nginx reload failed"
            success "Nginx reloaded."
        fi
    elif command -v apache2 >/dev/null 2>&1; then
        if systemctl is-active --quiet apache2 2>/dev/null; then
            log "Reloading Apache configuration..."
            systemctl reload apache2 || warning "Apache reload failed"
            success "Apache reloaded."
        fi
    fi
fi

# ============================================================
# SUCCESS
# ============================================================

echo
echo "============================================================"
echo -e "\${GREEN}      DEPLOYMENT SUCCESSFUL\${NC}"
echo "============================================================"
echo "Application : $APP_DIR"
echo "Repository  : $REPO_URL"
echo "Branch      : $BRANCH"
echo "Owner       : \${SITE_USER}:\${SITE_GROUP}"
echo "NPM install : $HAS_NPM"
echo "Build       : $HAS_BUILD"
echo "PM2         : $HAS_PM2"${c.hasPm2 === 'true' ? `\necho "PM2 App     : $APP_NAME"` : ''}
echo "Time        : $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"

exit 0`;
        }

        // Syntax highlight the script preview for Bash
        function highlightBash(text) {
            const escaped = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            return escaped
                // Comments
                .replace(/^(#.*)$/gm, '<span class="sg-comment">$1</span>')
                // Strings in double quotes (handles multi-word)
                .replace(/"([^"\\]*(\\.[^"\\]*)*)"/g, '<span class="sg-string">"$1"</span>')
                // Strings in single quotes
                .replace(/'([^'\\]*(\\.[^'\\]*)*)'/g, '<span class="sg-string">\'$1\'</span>')
                // Variables
                .replace(/(\$\{[^}]+\}|\$[A-Z_][A-Z_0-9]*)/g, '<span class="sg-variable">$1</span>')
                // Keywords
                .replace(/\b(if|then|else|elif|fi|for|do|done|while|case|esac|function|return|exit|local|trap|set)\b/g, '<span class="sg-keyword">$1</span>')
                // Function names (word followed by "()")
                .replace(/^(\s*[a-z_][a-z_0-9]*)\(\)/gm, '<span class="sg-function">$1</span>()');
        }

        // Syntax highlight the script preview for JavaScript / PM2 Ecosystem
        function highlightJs(text) {
            const escaped = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            return escaped
                .replace(/(\/\/.+$|\/\*[\s\S]*?\*\/)/gm, '<span class="sg-comment">$1</span>')
                .replace(/("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')/g, '<span class="sg-string">$1</span>')
                .replace(/\b(module|exports|apps|true|false|null|undefined)\b/g, '<span class="sg-keyword">$1</span>')
                .replace(/\b([a-zA-Z_][a-zA-Z0-9_]*)(?=\s*:)/g, '<span class="sg-variable">$1</span>')
                .replace(/\b(\d+)\b/g, '<span class="sg-function">$1</span>');
        }

        // Update preview with current form data
        function updateScriptPreview() {
            if (currentScriptType === 'pm2_ecosystem') {
                const raw = generatePm2ScriptContent();
                sgPreview.innerHTML = highlightJs(raw);
            } else {
                const c = getConfig();
                const hasRequired = sgFields.appDir?.value.trim() && sgFields.repoUrl?.value.trim();

                if (!hasRequired) {
                    sgPreview.innerHTML = '<span class="sg-comment"># Fill in the Application Directory and Repository URL\n# to generate a deployment script preview...</span>';
                    return;
                }

                const raw = generateScriptContent(c);
                sgPreview.innerHTML = highlightBash(raw);
            }
        }

        // Expose globally for modal open trigger
        window.updateScriptPreview = updateScriptPreview;

        // Debounce helper
        let sgDebounce = null;
        function debouncedUpdate() {
            clearTimeout(sgDebounce);
            sgDebounce = setTimeout(updateScriptPreview, 200);
        }

        // Attach input listeners for live preview across both forms
        Object.values(sgFields).concat(Object.values(sgPm2Fields)).forEach(el => {
            if (!el) return;
            if (el.type === 'checkbox' || el.tagName === 'SELECT') {
                el.addEventListener('change', debouncedUpdate);
            } else {
                el.addEventListener('input', debouncedUpdate);
            }
        });

        // Copy to clipboard
        if (sgCopyBtn) {
            sgCopyBtn.addEventListener('click', () => {
                const raw = currentScriptType === 'pm2_ecosystem' ? generatePm2ScriptContent() : generateScriptContent(getConfig());
                navigator.clipboard.writeText(raw).then(() => {
                    sgCopyBtn.textContent = '✅ Copied!';
                    showToast('Script copied to clipboard!', 'success');
                    setTimeout(() => { sgCopyBtn.textContent = '📋 Copy'; }, 2000);
                }).catch(() => {
                    showToast('Failed to copy to clipboard.', 'error');
                });
            });
        }

        // Download as file
        if (sgDownloadBtn) {
            sgDownloadBtn.addEventListener('click', () => {
                if (currentScriptType === 'pm2_ecosystem') {
                    const appName = sgPm2Fields.appName?.value.trim() || 'solar-backend';
                    const raw = generatePm2ScriptContent();
                    const filename = `ecosystem.${appName}.config.js`;
                    const blob = new Blob([raw], { type: 'application/javascript' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    showToast(`PM2 script downloaded as ${filename}`, 'success');
                } else {
                    const c = getConfig();
                    if (!sgFields.appDir?.value.trim() || !sgFields.repoUrl?.value.trim()) {
                        showToast('Please fill in Application Directory and Repository URL.', 'warning');
                        return;
                    }
                    const raw = generateScriptContent(c);
                    const appBasename = c.appDir.split('/').filter(Boolean).pop() || 'app';
                    const filename = `deploy-${appBasename}.sh`;
                    const blob = new Blob([raw], { type: 'application/x-sh' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    showToast(`Script downloaded as ${filename}`, 'success');
                }
            });
        }

        // Save to server
        if (sgSaveBtn) {
            sgSaveBtn.addEventListener('click', async () => {
                if (currentScriptType === 'pm2_ecosystem') {
                    const outputPath = sgPm2Fields.outputPath?.value.trim();
                    if (!outputPath) {
                        showToast('Enter a server file path in Section 6 to save.', 'warning');
                        return;
                    }
                    if (!outputPath.endsWith('.js') && !outputPath.endsWith('.cjs')) {
                        showToast('Output path must end with .config.js or .js extension.', 'warning');
                        return;
                    }

                    sgSaveBtn.disabled = true;
                    sgSaveBtn.textContent = '⏳ Saving...';

                    const payload = {
                        action: 'save',
                        script_type: 'pm2_ecosystem',
                        app_name: sgPm2Fields.appName?.value.trim() || 'solar-backend',
                        pm2_script: sgPm2Fields.script?.value.trim() || 'src/index.ts',
                        pm2_interpreter: sgPm2Fields.interpreter?.value.trim() || 'node',
                        pm2_interpreter_args: sgPm2Fields.interpreterArgs?.value.trim() || '',
                        app_dir: sgPm2Fields.cwd?.value.trim() || '',
                        pm2_instances: parseInt(sgPm2Fields.instances?.value) || 1,
                        pm2_exec_mode: sgPm2Fields.execMode?.value || 'fork',
                        pm2_watch: !!sgPm2Fields.watch?.checked,
                        pm2_max_memory_restart: sgPm2Fields.maxMem?.value.trim() || '1G',
                        pm2_node_env: sgPm2Fields.nodeEnv?.value.trim() || 'production',
                        pm2_port: parseInt(sgPm2Fields.port?.value) || 3000,
                        pm2_error_file: sgPm2Fields.errorFile?.value.trim() || '',
                        pm2_out_file: sgPm2Fields.outFile?.value.trim() || '',
                        pm2_log_file: sgPm2Fields.logFile?.value.trim() || '',
                        pm2_time: !!sgPm2Fields.time?.checked,
                        pm2_autorestart: !!sgPm2Fields.autorestart?.checked,
                        pm2_max_restarts: parseInt(sgPm2Fields.maxRestarts?.value) || 10,
                        pm2_min_uptime: sgPm2Fields.minUptime?.value.trim() || '10s',
                        pm2_kill_timeout: parseInt(sgPm2Fields.killTimeout?.value) || 5000,
                        pm2_listen_timeout: parseInt(sgPm2Fields.listenTimeout?.value) || 3000,
                        pm2_merge_logs: !!sgPm2Fields.mergeLogs?.checked,
                        output_path: outputPath,
                    };

                    try {
                        const { ok, data } = await apiFetch('/api/generate_script.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
                        });

                        if (ok && data.success) {
                            showToast(data.message || 'PM2 Ecosystem script saved to server!', 'success');
                        } else {
                            showToast(data.error?.message || 'Failed to save script.', 'error');
                        }
                    } catch (err) {
                        showToast('Network error while saving script.', 'error');
                    }

                    sgSaveBtn.disabled = false;
                    sgSaveBtn.textContent = '💾 Save to Server';
                } else {
                    const outputPath = sgFields.outputPath?.value.trim();
                    if (!outputPath) {
                        showToast('Enter a server file path in Section 6 to save.', 'warning');
                        return;
                    }
                    if (!outputPath.endsWith('.sh')) {
                        showToast('Output path must end with .sh extension.', 'warning');
                        return;
                    }
                    if (!sgFields.appDir?.value.trim() || !sgFields.repoUrl?.value.trim()) {
                        showToast('Please fill in Application Directory and Repository URL.', 'warning');
                        return;
                    }

                    sgSaveBtn.disabled = true;
                    sgSaveBtn.textContent = '⏳ Saving...';

                    const c = getConfig();
                    const payload = {
                        action: 'save',
                        script_type: 'bash',
                        app_dir: c.appDir,
                        repo_url: c.repoUrl,
                        branch: c.branch,
                        env_source: c.envSource,
                        has_npm: c.hasNpm === 'true',
                        has_build: c.hasBuild === 'true',
                        has_pm2: c.hasPm2 === 'true',
                        app_name: c.appName,
                        site_user: c.siteUser,
                        site_group: c.siteGroup,
                        output_path: outputPath,
                    };

                    try {
                        const { ok, data } = await apiFetch('/api/generate_script.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
                        });

                        if (ok && data.success) {
                            showToast(data.message || 'Script saved to server!', 'success');
                        } else {
                            showToast(data.error?.message || 'Failed to save script.', 'error');
                        }
                    } catch (err) {
                        showToast('Network error while saving script.', 'error');
                    }

                    sgSaveBtn.disabled = false;
                    sgSaveBtn.textContent = '💾 Save to Server';
                }
            });
        }

        // Load Script from server file path
        async function loadScriptFromFile(filePath, type) {
            if (!filePath) {
                showToast('Please enter a valid file path to read.', 'warning');
                return;
            }
            showToast(`Loading ${type === 'pm2_ecosystem' ? 'PM2 Ecosystem' : 'Deployment'} script from server...`, 'info');
            try {
                const { ok, data } = await apiFetch('/api/generate_script.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'read', file_path: filePath })
                });

                if (ok && data.success && data.content) {
                    showToast(`Script successfully loaded from ${filePath}!`, 'success');
                    if (type === 'pm2_ecosystem') {
                        sgPreview.innerHTML = highlightJs(data.content);
                    } else {
                        sgPreview.innerHTML = highlightBash(data.content);
                    }
                } else {
                    showToast(data.error?.message || 'Failed to load script file from server.', 'error');
                }
            } catch (err) {
                showToast('Network error while reading script file.', 'error');
            }
        }

        const sgLoadBashBtn = document.getElementById('sgLoadBashBtn');
        if (sgLoadBashBtn) {
            sgLoadBashBtn.addEventListener('click', () => {
                const path = sgFields.outputPath?.value.trim();
                loadScriptFromFile(path, 'bash');
            });
        }

        const sgLoadPm2Btn = document.getElementById('sgLoadPm2Btn');
        if (sgLoadPm2Btn) {
            sgLoadPm2Btn.addEventListener('click', () => {
                const path = sgPm2Fields.outputPath?.value.trim();
                loadScriptFromFile(path, 'pm2_ecosystem');
            });
        }

        // Site Quick Select Dropdown for loading existing site configs
        function populateSgSiteDropdown() {
            const dropdown = document.getElementById('sgSiteQuickSelect');
            if (!dropdown) return;
            dropdown.innerHTML = '<option value="">-- Choose an Existing Site to Load & Edit --</option>';
            if (typeof cachedSites === 'object') {
                for (const [siteId, site] of Object.entries(cachedSites)) {
                    const opt = document.createElement('option');
                    opt.value = siteId;
                    opt.textContent = `${site.name || siteId} (${site.domain || siteId})`;
                    dropdown.appendChild(opt);
                }
            }
        }

        const sgSiteQuickSelect = document.getElementById('sgSiteQuickSelect');
        if (sgSiteQuickSelect) {
            sgSiteQuickSelect.addEventListener('change', (e) => {
                const siteId = e.target.value;
                if (!siteId || !cachedSites[siteId]) return;
                const site = cachedSites[siteId];

                if (currentScriptType === 'bash') {
                    if (sgFields.appDir) sgFields.appDir.value = site.deploy_path || `/www/wwwroot/${site.domain || siteId}`;
                    if (sgFields.repoUrl) sgFields.repoUrl.value = site.repo_url || '';
                    if (sgFields.branch) sgFields.branch.value = site.branch || 'main';
                    if (sgFields.appName) sgFields.appName.value = site.name || siteId;
                    const scriptPath = site.deploy_script_path || `/www/wwwroot/${site.domain || siteId}/deploy.sh`;
                    if (sgFields.outputPath) sgFields.outputPath.value = scriptPath;
                    updateScriptPreview();
                    if (scriptPath) loadScriptFromFile(scriptPath, 'bash');
                } else {
                    if (sgPm2Fields.appName) sgPm2Fields.appName.value = site.name || siteId;
                    if (sgPm2Fields.cwd) sgPm2Fields.cwd.value = site.deploy_path || `/www/wwwroot/${site.domain || siteId}`;
                    const configPath = `/www/wwwroot/${site.domain || siteId}/ecosystem.config.js`;
                    if (sgPm2Fields.outputPath) sgPm2Fields.outputPath.value = configPath;
                    if (site.pm2_ecosystem) {
                        sgPreview.innerHTML = highlightJs(site.pm2_ecosystem);
                    } else {
                        updateScriptPreview();
                        loadScriptFromFile(configPath, 'pm2_ecosystem');
                    }
                }
            });
        }

        // Add Site Modal: Edit in Generator buttons
        const editDeployScriptBtn = document.getElementById('editDeployScriptBtn');
        if (editDeployScriptBtn) {
            editDeployScriptBtn.addEventListener('click', () => {
                const scriptPath = document.getElementById('siteScriptInput')?.value.trim() || '';
                if (window.openScriptGenModal) window.openScriptGenModal('bash');
                if (scriptPath && sgFields.outputPath) {
                    sgFields.outputPath.value = scriptPath;
                    loadScriptFromFile(scriptPath, 'bash');
                }
            });
        }

        const openInPm2GenBtn = document.getElementById('openInPm2GenBtn');
        if (openInPm2GenBtn) {
            openInPm2GenBtn.addEventListener('click', () => {
                const pm2Content = document.getElementById('pm2EcosystemInput')?.value.trim() || '';
                if (window.openScriptGenModal) window.openScriptGenModal('pm2_ecosystem');
                if (pm2Content) {
                    sgPreview.innerHTML = highlightJs(pm2Content);
                }
            });
        }

        window.populateSgSiteDropdown = populateSgSiteDropdown;
    })();

    // Global Modal Escape key listener and Window helpers
    window.closeAddDbModal = function() {
        const modal = document.getElementById('addDbModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.setProperty('display', 'none', 'important');
        }
    };

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(modal => {
                modal.classList.add('hidden');
                modal.style.setProperty('display', 'none', 'important');
            });
        }
    });

    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.add('hidden');
            e.target.style.setProperty('display', 'none', 'important');
        }
    });

    // ── USER MANAGEMENT MODULE ──────────────────────────────────────────────
    async function loadUsersList() {
        const tbody = document.getElementById('userMgmtTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Loading user accounts...</td></tr>';
        
        const { ok, data } = await apiFetch('/api/users.php');
        if (!ok || !data.success) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #ef4444; padding: 20px;">Failed to load user list: ${escapeHtml(data.error?.message || 'Unauthorized')}</td></tr>`;
            return;
        }

        const users = data.users || [];
        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">No user accounts found.</td></tr>';
            return;
        }

        tbody.innerHTML = users.map(u => {
            const funcs = u.allowed_functions.includes('*') 
                ? '<span class="perm-tag" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">⭐ All Functions (*)</span>' 
                : u.allowed_functions.map(f => `<span class="perm-tag">${escapeHtml(f)}</span>`).join('');
                
            const systems = u.allowed_systems.includes('*') 
                ? '<span class="perm-tag" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">🌐 All Systems (*)</span>' 
                : u.allowed_systems.map(s => `<span class="perm-tag">${escapeHtml(s)}</span>`).join('');

            return `
                <tr>
                    <td style="font-weight: 600;">${escapeHtml(u.username)}</td>
                    <td>${escapeHtml(u.name || u.username)}</td>
                    <td><span class="badge badge-role badge-role-${escapeHtml(u.role)}">${escapeHtml(u.role.toUpperCase())}</span></td>
                    <td><div style="max-width: 240px; display: flex; flex-wrap: wrap;">${funcs}</div></td>
                    <td><div style="max-width: 240px; display: flex; flex-wrap: wrap;">${systems}</div></td>
                    <td style="text-align: right;">
                        <button class="btn btn-secondary btn-sm um-edit-btn" data-username="${escapeHtml(u.username)}" style="padding: 4px 10px; font-size: 0.75rem;">✏️ Edit</button>
                        ${u.username !== currentUsername ? `
                            <button class="btn btn-outline-danger btn-sm um-delete-btn" data-username="${escapeHtml(u.username)}" style="padding: 4px 10px; font-size: 0.75rem; margin-left: 4px;">🗑️ Delete</button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');

        // Attach event listeners
        tbody.querySelectorAll('.um-edit-btn').forEach(btn => {
            btn.addEventListener('click', () => openUserEditModal(btn.dataset.username));
        });

        tbody.querySelectorAll('.um-delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const targetUser = btn.dataset.username;
                if (!confirm(`Are you sure you want to delete user account "${targetUser}"?`)) return;
                
                const { ok, data } = await apiFetch(`/api/users.php?username=${encodeURIComponent(targetUser)}`, {
                    method: 'DELETE'
                });

                if (ok && data.success) {
                    showToast(`User "${targetUser}" deleted successfully.`, 'success');
                    loadUsersList();
                } else {
                    showToast(data.error?.message || 'Failed to delete user.', 'error');
                }
            });
        });
    }

    window.loadUsersList = loadUsersList;

    let editingUsername = null;

    async function prepareUserEditModal(usernameToEdit = null) {
        editingUsername = usernameToEdit;
        const modalTitle = document.getElementById('userEditModalTitle');
        const usernameInput = document.getElementById('umUsernameInput');
        const nameInput = document.getElementById('umNameInput');
        const passInput = document.getElementById('umPasswordInput');
        const passHelp = document.getElementById('umPasswordHelpText');
        const roleSelect = document.getElementById('umRoleSelect');
        const allSystemsCheck = document.getElementById('umAllSystemsCheck');
        const specificSystemsContainer = document.getElementById('umSpecificSystemsContainer');

        // Populate dynamic sites checklist
        if (specificSystemsContainer) {
            specificSystemsContainer.innerHTML = '';
            Object.keys(cachedSites).forEach(siteId => {
                const site = cachedSites[siteId];
                const label = document.createElement('label');
                label.className = 'perm-card';
                label.innerHTML = `
                    <input type="checkbox" name="um_sys" value="${escapeHtml(siteId)}">
                    <div class="perm-card-content">
                        <div class="perm-card-title">${escapeHtml(site.name || siteId)}</div>
                        <div class="perm-card-desc">${escapeHtml(site.domain || siteId)}</div>
                    </div>
                `;
                specificSystemsContainer.appendChild(label);
            });
        }

        if (editingUsername) {
            if (modalTitle) modalTitle.textContent = `✏️ Edit User: ${editingUsername}`;
            if (usernameInput) {
                usernameInput.value = editingUsername;
                usernameInput.readOnly = true;
            }
            if (passInput) passInput.value = '';
            if (passHelp) passHelp.textContent = 'Leave blank to keep existing password.';

            const { ok, data } = await apiFetch(`/api/users.php?username=${encodeURIComponent(editingUsername)}`);
            if (ok && data.success && data.user) {
                const u = data.user;
                if (nameInput) nameInput.value = u.name || '';
                if (roleSelect) roleSelect.value = u.role || 'viewer';

                // Set functions checkboxes
                const funcCheckboxes = document.querySelectorAll('input[name="um_func"]');
                const isAllFuncs = u.allowed_functions.includes('*');
                funcCheckboxes.forEach(cb => {
                    cb.checked = isAllFuncs || u.allowed_functions.includes(cb.value);
                });

                // Set systems checkboxes
                const isAllSystems = u.allowed_systems.includes('*');
                if (allSystemsCheck) allSystemsCheck.checked = isAllSystems;
                if (specificSystemsContainer) {
                    if (isAllSystems) {
                        specificSystemsContainer.classList.add('hidden');
                    } else {
                        specificSystemsContainer.classList.remove('hidden');
                        const sysCheckboxes = document.querySelectorAll('input[name="um_sys"]');
                        sysCheckboxes.forEach(cb => {
                            cb.checked = u.allowed_systems.includes(cb.value);
                        });
                    }
                }
            }
        } else {
            if (modalTitle) modalTitle.textContent = '👤 Add New User Account';
            if (usernameInput) {
                usernameInput.value = '';
                usernameInput.readOnly = false;
            }
            if (nameInput) nameInput.value = '';
            if (passInput) passInput.value = '';
            if (passHelp) passHelp.textContent = 'Password required for new account.';
            if (roleSelect) {
                roleSelect.value = 'viewer';
                applyRolePreset('viewer');
            }
        }
    }

    window.prepareUserEditModal = prepareUserEditModal;

    function applyRolePreset(role) {
        const funcCheckboxes = document.querySelectorAll('input[name="um_func"]');
        const allSystemsCheck = document.getElementById('umAllSystemsCheck');
        const specificSystemsContainer = document.getElementById('umSpecificSystemsContainer');

        if (role === 'admin') {
            funcCheckboxes.forEach(cb => cb.checked = true);
            if (allSystemsCheck) allSystemsCheck.checked = true;
            if (specificSystemsContainer) specificSystemsContainer.classList.add('hidden');
        } else if (role === 'deployer') {
            const deployerFuncs = ['sites', 'add_edit_sites', 'pm2', 'script_gen', 'db_backups', 'vps_ports', 'deploy_history'];
            funcCheckboxes.forEach(cb => cb.checked = deployerFuncs.includes(cb.value));
            if (allSystemsCheck) allSystemsCheck.checked = true;
            if (specificSystemsContainer) specificSystemsContainer.classList.add('hidden');
        } else if (role === 'viewer') {
            const viewerFuncs = ['sites', 'pm2', 'vps_ports', 'deploy_history'];
            funcCheckboxes.forEach(cb => cb.checked = viewerFuncs.includes(cb.value));
            if (allSystemsCheck) allSystemsCheck.checked = true;
            if (specificSystemsContainer) specificSystemsContainer.classList.add('hidden');
        }
    }

    const umRoleSelect = document.getElementById('umRoleSelect');
    if (umRoleSelect) {
        umRoleSelect.addEventListener('change', (e) => {
            if (e.target.value !== 'custom') {
                applyRolePreset(e.target.value);
            }
        });
    }

    const umAllSystemsCheck = document.getElementById('umAllSystemsCheck');
    if (umAllSystemsCheck) {
        umAllSystemsCheck.addEventListener('change', (e) => {
            const container = document.getElementById('umSpecificSystemsContainer');
            if (container) {
                if (e.target.checked) {
                    container.classList.add('hidden');
                } else {
                    container.classList.remove('hidden');
                }
            }
        });
    }

    const userEditForm = document.getElementById('userEditForm');
    if (userEditForm) {
        userEditForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('umSaveSubmitBtn');
            if (submitBtn) submitBtn.disabled = true;

            const username = document.getElementById('umUsernameInput').value.trim();
            const name = document.getElementById('umNameInput').value.trim();
            const password = document.getElementById('umPasswordInput').value;
            const role = document.getElementById('umRoleSelect').value;

            const funcCbs = document.querySelectorAll('input[name="um_func"]:checked');
            const allowedFunctions = Array.from(funcCbs).map(cb => cb.value);

            let allowedSystems = ['*'];
            const allSystems = document.getElementById('umAllSystemsCheck').checked;
            if (!allSystems) {
                const sysCbs = document.querySelectorAll('input[name="um_sys"]:checked');
                allowedSystems = Array.from(sysCbs).map(cb => cb.value);
            }

            const payload = {
                username,
                name,
                role,
                allowed_functions: role === 'admin' ? ['*'] : allowedFunctions,
                allowed_systems: role === 'admin' ? ['*'] : allowedSystems
            };

            if (password) {
                payload.password = password;
            }

            const { ok, data } = await apiFetch('/api/users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (submitBtn) submitBtn.disabled = false;

            if (ok && data.success) {
                showToast(`User "${username}" saved successfully!`, 'success');
                if (window.closeUserEditModal) window.closeUserEditModal();
                loadUsersList();
            } else {
                showToast(data.error?.message || 'Failed to save user account.', 'error');
            }
        });
    }

    // Initial Execution
    window.loadVpsPorts = loadVpsPorts;
    window.loadDatabases = loadDatabases;
    updateServerMetrics();
    if (sitesGrid) loadSites();
    if (pm2TableBody) loadPm2Data();
    if (dbContainer) loadDatabases();
    checkActiveReconnection();

    // Periodic Server Status Polling (every 15s)
    setInterval(updateServerMetrics, 15000);
    // Periodic Sites refresh (every 30s)
    setInterval(loadSites, 30000);
    // Periodic PM2 refresh (every 15s)
    setInterval(loadPm2Data, 15000);
});
