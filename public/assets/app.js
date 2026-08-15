/**
 * LIGHTDEPLOY Application JavaScript Engine
 * Vanilla JS SPA Engine supporting SSE streaming, automatic reconnection, and live metrics.
 */

document.addEventListener('DOMContentLoaded', () => {
    const userRole = document.body.dataset.userRole || 'viewer';
    const csrfToken = document.body.dataset.CsrfToken || document.body.dataset.csrfToken || '';

    // State Variables
    let activeEventSource = null;
    let currentDeploymentId = null;
    let currentSiteId = null;

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

    // Helper: Standard Fetch Wrapper with CSRF header
    async function apiFetch(url, options = {}) {
        options.headers = options.headers || {};
        if (csrfToken) {
            options.headers['X-CSRF-Token'] = csrfToken;
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
            document.getElementById('metricCpu').querySelector('.metric-value').textContent = `${m.cpu.load_1m}%`;
            document.getElementById('metricRam').querySelector('.metric-value').textContent = `${m.memory.percentage}%`;
            document.getElementById('metricDisk').querySelector('.metric-value').textContent = `${m.disk.percentage}%`;
            document.getElementById('metricUptime').querySelector('.metric-value').textContent = m.uptime;
        }
    }

    // 2. Fetch & Render Configured Sites List
    async function loadSites() {
        const { ok, data } = await apiFetch('/api/sites.php');
        if (!ok || !data.success) {
            sitesGrid.innerHTML = `<div class="alert-box alert-danger">Failed to load configured sites. ${data.error?.message || ''}</div>`;
            return;
        }

        const sites = data.sites;
        sitesGrid.innerHTML = '';

        if (Object.keys(sites).length === 0) {
            sitesGrid.innerHTML = `<div class="alert-box alert-danger">No websites configured in config/sites.json.</div>`;
            return;
        }

        for (const [siteId, site] of Object.entries(sites)) {
            const card = document.createElement('div');
            card.className = 'site-card';

            const statusClass = site.is_locked ? 'badge-status-running' : (site.last_deployment ? `badge-status-${site.last_deployment.status}` : 'badge-status-idle');
            const statusLabel = site.is_locked ? 'RUNNING' : (site.last_deployment ? site.last_deployment.status.toUpperCase() : 'IDLE');

            const canDeploy = (userRole === 'admin' || userRole === 'deployer') && !site.is_locked && site.enabled;
            const canRollback = userRole === 'admin' && !site.is_locked && site.has_rollback && site.enabled;

            card.innerHTML = `
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
                </div>
            `;

            sitesGrid.appendChild(card);
        }

        // Attach Card Action Listeners
        document.querySelectorAll('.btn-deploy').forEach(btn => {
            btn.addEventListener('click', () => triggerDeployment(btn.dataset.siteId));
        });

        document.querySelectorAll('.btn-rollback').forEach(btn => {
            btn.addEventListener('click', () => triggerRollback(btn.dataset.siteId));
        });

        document.querySelectorAll('.btn-view-log').forEach(btn => {
            btn.addEventListener('click', () => viewDeploymentLog(btn.dataset.depId, btn.dataset.siteId));
        });
    }

    // 3. Trigger Deployment Execution
    async function triggerDeployment(siteId) {
        if (!confirm(`Are you sure you want to trigger deployment for site: ${siteId}?`)) {
            return;
        }

        const { ok, data } = await apiFetch('/api/deploy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ site_id: siteId })
        });

        if (ok && data.success) {
            currentDeploymentId = data.deployment_id;
            currentSiteId = siteId;
            localStorage.setItem('lightdeploy_active_dep', data.deployment_id);
            openDeploymentModal(data.deployment_id, siteId, 'running');
            connectSSEStream(data.deployment_id);
            loadSites();
        } else {
            alert(`Deployment error: ${data.error?.message || 'Unknown error'}`);
        }
    }

    // 4. Trigger Rollback Execution
    async function triggerRollback(siteId) {
        if (!confirm(`WARNING: Are you sure you want to execute ROLLBACK for site: ${siteId}?`)) {
            return;
        }

        const { ok, data } = await apiFetch('/api/rollback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ site_id: siteId })
        });

        if (ok && data.success) {
            currentDeploymentId = data.deployment_id;
            currentSiteId = siteId;
            localStorage.setItem('lightdeploy_active_dep', data.deployment_id);
            openDeploymentModal(data.deployment_id, siteId, 'running');
            connectSSEStream(data.deployment_id);
            loadSites();
        } else {
            alert(`Rollback error: ${data.error?.message || 'Unknown error'}`);
        }
    }

    // 5. Connect Server-Sent Events (SSE) Live Log Stream
    function connectSSEStream(depId) {
        if (activeEventSource) {
            activeEventSource.close();
        }

        terminalOutput.textContent = `[${new Date().toLocaleTimeString()}] [SYSTEM] Opening SSE real-time stream connection for ${depId}...\n`;

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

    // Append formatted line to visual terminal
    function appendTerminalLine(line) {
        if (line === undefined || line === null) return;
        terminalOutput.textContent += line + '\n';
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
        modalStartTime.textContent = new Date().toLocaleTimeString();
        updateModalStatusUI({ status: initialStatus });

        deploymentModal.classList.remove('hidden');
    }

    // View Past Deployment Log
    async function viewDeploymentLog(depId, siteId) {
        openDeploymentModal(depId, siteId, 'loading');
        terminalOutput.textContent = 'Loading archived log file...';

        const { ok, data } = await apiFetch(`/api/deployment.php?id=${encodeURIComponent(depId)}`);
        if (ok && data.success) {
            updateModalStatusUI(data.deployment);
            modalStartTime.textContent = data.deployment.start_time || '--';
            terminalOutput.textContent = data.output || 'No output captured.';
            if (autoscrollCheck.checked) {
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            }
        } else {
            terminalOutput.textContent = `Failed to load log: ${data.error?.message || 'Not found'}`;
        }
    }

    // Cancel Deployment Execution
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
            appendTerminalLine(`[${new Date().toLocaleTimeString()}] [SYSTEM] Cancellation command issued.`);
        } else {
            alert(`Cancel failed: ${data.error?.message || 'Unknown error'}`);
        }
    });

    modalRollbackBtn.addEventListener('click', () => {
        if (currentSiteId) {
            deploymentModal.classList.add('hidden');
            triggerRollback(currentSiteId);
        }
    });

    modalDeployAgainBtn.addEventListener('click', () => {
        if (currentSiteId) {
            deploymentModal.classList.add('hidden');
            triggerDeployment(currentSiteId);
        }
    });

    // Modal Close Listeners
    closeModalBtn.addEventListener('click', () => {
        if (activeEventSource) {
            if (!confirm('Deployment is still running in the background. Close modal window?')) {
                return;
            }
        }
        deploymentModal.classList.add('hidden');
    });

    modalCloseFooterBtn.addEventListener('click', () => {
        deploymentModal.classList.add('hidden');
    });

    // 6. Audit History View
    viewHistoryBtn.addEventListener('click', async () => {
        historyModal.classList.remove('hidden');
        historyTableBody.innerHTML = `<tr><td colspan="7" class="text-center">Loading audit history...</td></tr>`;

        const { ok, data } = await apiFetch('/api/history.php');
        if (!ok || !data.success) {
            historyTableBody.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Failed to load history.</td></tr>`;
            return;
        }

        const history = data.history;
        if (history.length === 0) {
            historyTableBody.innerHTML = `<tr><td colspan="7" class="text-center">No deployment history recorded yet.</td></tr>`;
            return;
        }

        historyTableBody.innerHTML = '';
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
            historyTableBody.appendChild(tr);
        });

        document.querySelectorAll('.hist-log-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                historyModal.classList.add('hidden');
                viewDeploymentLog(btn.dataset.depId, btn.dataset.siteId);
            });
        });
    });

    closeHistoryBtn.addEventListener('click', () => historyModal.classList.add('hidden'));
    closeHistoryFooterBtn.addEventListener('click', () => historyModal.classList.add('hidden'));

    // Logout Action
    logoutBtn.addEventListener('click', async () => {
        await apiFetch('/api/logout.php', { method: 'POST' });
        window.location.href = '/login.php';
    });

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

    // Utility: HTML Escaping
    function escapeHtml(str) {
        return (str || '').toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Initial Execution
    updateServerMetrics();
    loadSites();
    checkActiveReconnection();

    // Periodic Server Status Polling (every 15s)
    setInterval(updateServerMetrics, 15000);
    // Periodic Sites refresh (every 30s)
    setInterval(loadSites, 30000);
});
