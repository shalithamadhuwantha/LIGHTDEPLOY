/**
 * LIGHTDEPLOY Application JavaScript Engine
 * Vanilla JS SPA Engine supporting SSE streaming, automatic reconnection, and live metrics.
 */

document.addEventListener('DOMContentLoaded', () => {
    const userRole = document.body.dataset.userRole || 'viewer';
    const currentUsername = document.body.dataset.username || 'admin';
    const csrfToken = document.body.dataset.CsrfToken || document.body.dataset.csrfToken || '';

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
            document.getElementById('metricCpu').querySelector('.metric-value').textContent = `${m.cpu.load_1m}%`;
            document.getElementById('metricRam').querySelector('.metric-value').textContent = `${m.memory.percentage}%`;
            document.getElementById('metricDisk').querySelector('.metric-value').textContent = `${m.disk.percentage}%`;
            document.getElementById('metricUptime').querySelector('.metric-value').textContent = m.uptime;

            if (m.app_resources) {
                const appRamVal = document.getElementById('metricAppRamVal');
                if (appRamVal) {
                    appRamVal.textContent = `${m.app_resources.rss_mb} MB`;
                }
            }
        }
    }

    // 2. Fetch & Render Configured Sites List
    async function loadSites() {
        const { ok, data } = await apiFetch('/api/sites.php');
        if (!ok || !data.success) {
            sitesGrid.innerHTML = `<div class="alert-box alert-danger">Failed to load configured sites. ${data.error?.message || ''}</div>`;
            return;
        }

        cachedSites = data.sites || {};
        const sites = cachedSites;
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
                        <button class="btn btn-secondary btn-sm btn-pm2-reload" data-pm2-target="${escapeHtml(site.pm2_name || siteId)}">
                            ⚡ PM2 Reload
                        </button>
                        <button class="btn btn-secondary btn-sm btn-pm2-logs" data-pm2-target="${escapeHtml(site.pm2_name || siteId)}">
                            📄 PM2 Logs
                        </button>
                    ` : ''}
                    ${userRole === 'admin' ? `
                        <button class="btn btn-secondary btn-sm btn-edit-site" data-site-id="${siteId}">
                            ⚙️ Edit
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

        document.querySelectorAll('.btn-pm2-reload').forEach(btn => {
            btn.addEventListener('click', () => executePm2Action('reload', btn.dataset.pm2Target));
        });

        document.querySelectorAll('.btn-pm2-logs').forEach(btn => {
            btn.addEventListener('click', () => openPm2LogsModal(btn.dataset.pm2Target));
        });

        document.querySelectorAll('.btn-edit-site').forEach(btn => {
            btn.addEventListener('click', () => {
                const siteId = btn.dataset.siteId;
                const site = cachedSites[siteId];
                if (site) {
                    openEditSiteModal(siteId, site);
                }
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
    logoutBtn.addEventListener('click', async () => {
        await apiFetch('/api/logout.php', { method: 'POST' });
        window.location.href = '/login.php';
    });

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
                        <button class="btn btn-secondary btn-sm btn-pm2-logs" data-target="${proc.name}" title="View Logs">📜</button>
                        ${userRole === 'admin' ? `
                            <button class="btn btn-danger btn-sm btn-pm2-action" data-action="delete" data-target="${proc.id}" title="Delete">🗑️</button>
                        ` : ''}
                    </div>
                </td>
            `;
            pm2TableBody.appendChild(tr);
        });

        // Event Listeners for PM2 Row Action Buttons
        document.querySelectorAll('.btn-pm2-action').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                const target = btn.dataset.target;
                if (action === 'delete' && !confirm(`Are you sure you want to remove PM2 process ${target}?`)) {
                    return;
                }
                executePm2Action(action, target);
            });
        });

        document.querySelectorAll('.btn-pm2-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                openPm2EditModal(btn.dataset.name);
            });
        });

        document.querySelectorAll('.btn-pm2-logs').forEach(btn => {
            btn.addEventListener('click', () => {
                openPm2LogsModal(btn.dataset.target);
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

        dbContainer.innerHTML = dbKeys.map(dbId => {
            const db = databases[dbId];
            const backups = db.backups || [];
            
            const scheduleBadgeMap = {
                'daily': '<span class="badge badge-version" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">📅 Daily (24h)</span>',
                '12h': '<span class="badge badge-version" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">⏰ Every 12 Hours</span>',
                '6h': '<span class="badge badge-version" style="background: rgba(168, 85, 247, 0.15); color: #c084fc;">⚡ Every 6 Hours</span>',
                'weekly': '<span class="badge badge-version" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">🗓️ Weekly</span>',
                'disabled': '<span class="badge badge-version" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">⏸️ Manual Only</span>'
            };

            const backupsRows = backups.length > 0 ? backups.map(b => {
                const isSql = b.filename.endsWith('.sql');
                const fileIcon = isSql ? '📜' : '📦';
                const fileBadge = isSql
                    ? '<span class="badge badge-version" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">📜 .SQL (phpMyAdmin Ready)</span>'
                    : '<span class="badge badge-version" style="background: rgba(168, 85, 247, 0.15); color: #c084fc;">📦 .SQL.GZ Archive</span>';

                return `
                <tr>
                    <td style="font-family: var(--font-mono); font-size: 0.85rem;">${fileIcon} ${escapeHtml(b.filename)}</td>
                    <td>${fileBadge}</td>
                    <td style="font-family: var(--font-mono); font-size: 0.85rem;">${escapeHtml(b.filesize_formatted)}</td>
                    <td style="font-size: 0.85rem; color: var(--text-muted);">${escapeHtml(b.created_at)}</td>
                    <td>
                        <span class="badge badge-version" style="background: rgba(52, 211, 153, 0.1); color: #34d399;">Active (${escapeHtml(b.age_days)} days old)</span>
                    </td>
                    <td style="text-align: right;">
                        <a href="/api/backups.php?action=download&filename=${encodeURIComponent(b.filename)}" class="btn btn-secondary btn-sm" style="padding: 2px 8px; font-size: 0.75rem;" title="Download ${isSql ? 'plain .sql file for phpMyAdmin import' : 'compressed archive'}">📥 Download ${isSql ? '.sql' : '.sql.gz'}</a>
                        ${userRole === 'admin' || userRole === 'deployer' ? `
                            <button class="btn btn-outline-danger btn-sm btn-delete-backup" data-filename="${escapeHtml(b.filename)}" style="padding: 2px 8px; font-size: 0.75rem;" title="Delete backup archive">🗑️</button>
                        ` : ''}
                    </td>
                </tr>
            `;
            }).join('') : `
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 16px;">No backup archives generated yet. Click "⚡ Backup Now (.sql)" to create your first phpMyAdmin compatible dump.</td>
                </tr>
            `;

            return `
                <div class="site-card" style="padding: 20px; border: 1px solid var(--bg-card-border); border-radius: var(--radius-md); background: rgba(15, 23, 42, 0.6);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <h4 style="margin: 0; font-size: 1.1rem; color: var(--text-main);">🗄️ ${escapeHtml(db.label)}</h4>
                                ${scheduleBadgeMap[db.schedule] || ''}
                                <span class="badge badge-version" style="background: rgba(255,255,255,0.05); color: var(--text-muted);">Retention: 7 Days</span>
                            </div>
                            <div style="margin-top: 6px; font-family: var(--font-mono); font-size: 0.85rem; color: var(--text-muted); display: flex; gap: 16px; flex-wrap: wrap;">
                                <span>Host: <strong>${escapeHtml(db.db_host)}:${escapeHtml(db.db_port)}</strong></span>
                                <span>Database: <strong>${escapeHtml(db.db_name)}</strong></span>
                                <span>Username: <strong>${escapeHtml(db.db_user)}</strong></span>
                                <span>Last Backup: <strong>${escapeHtml(db.last_backup_at || 'Never')}</strong></span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            ${userRole === 'admin' || userRole === 'deployer' ? `
                                <button class="btn btn-primary btn-sm btn-run-backup" data-id="${db.id}" data-format="sql" title="Generate phpMyAdmin ready .sql file">⚡ Backup Now (.sql)</button>
                                <button class="btn btn-secondary btn-sm btn-run-backup" data-id="${db.id}" data-format="sql.gz" title="Generate compressed .sql.gz file">📦 (.sql.gz)</button>
                                <button class="btn btn-secondary btn-sm btn-edit-db" data-db='${escapeHtml(JSON.stringify(db))}'>✏️ Edit</button>
                            ` : ''}
                            ${userRole === 'admin' ? `
                                <button class="btn btn-outline-danger btn-sm btn-delete-db" data-id="${db.id}">🗑️</button>
                            ` : ''}
                        </div>
                    </div>

                    <!-- Backup Archives Table -->
                    <div class="table-responsive" style="margin-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 10px;">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Backup File Name</th>
                                    <th>Format / phpMyAdmin Ready</th>
                                    <th>Size</th>
                                    <th>Created At (Sri Lanka Time)</th>
                                    <th>Retention Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${backupsRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }).join('');

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
                    document.getElementById('dbPassInput').value = '';
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

        document.querySelectorAll('.btn-delete-backup').forEach(btn => {
            btn.addEventListener('click', async () => {
                const filename = btn.dataset.filename;
                if (!confirm(`Delete backup file '${filename}'?`)) return;

                const { ok, data } = await apiFetch('/api/backups.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_backup', filename: filename })
                });

                if (ok && data.success) {
                    showToast('Backup file deleted.', 'success');
                    loadDatabases();
                } else {
                    showToast(data.message || 'Failed to delete backup file.', 'error');
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

    // Initial Execution
    window.loadVpsPorts = loadVpsPorts;
    window.loadDatabases = loadDatabases;
    updateServerMetrics();
    loadSites();
    loadPm2Data();
    checkActiveReconnection();

    // Periodic Server Status Polling (every 15s)
    setInterval(updateServerMetrics, 15000);
    // Periodic Sites refresh (every 30s)
    setInterval(loadSites, 30000);
    // Periodic PM2 refresh (every 15s)
    setInterval(loadPm2Data, 15000);
});
