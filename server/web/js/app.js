/**
 * ISP Monitor - Dashboard Application Logic
 */

let currentPeriod = '24h';
let selectedIsp = 'all';
let currentStats = null;
let liveIsps = {};
let wsClient = null;

document.addEventListener('DOMContentLoaded', () => {
    initClock();
    initFilterTabs();
    initIspSelector();
    initSetupModal();
    initCleanModal();

    // Initialize WebSocket with fallback
    wsClient = new IspWebSocketClient({
        onConnectionState: (state, text) => {
            const badge = document.getElementById('ws-badge');
            if (!badge) return;
            badge.className = `badge ${state}`;
            badge.innerHTML = `<span class="dot-glow ${state === 'connected' ? 'green' : (state === 'connecting' ? 'yellow' : 'red')}"></span> ${text}`;
        },
        onStatusChange: (payload, isInitial) => {
            if (isInitial) {
                liveIsps = payload || {};
            } else if (payload && payload.isp_id) {
                if (!liveIsps[payload.isp_id]) {
                    liveIsps[payload.isp_id] = {};
                }
                liveIsps[payload.isp_id].status = payload.status;
                liveIsps[payload.isp_id].isp_name = payload.isp_name;
                liveIsps[payload.isp_id].current_state_since = payload.timestamp;
                liveIsps[payload.isp_id].last_seen = payload.timestamp;

                appendLog(`[${payload.status}] ${payload.isp_name} status changed to ${payload.status}: ${payload.details || ''}`, payload.status);
            }
            renderIspCards();
            refreshStats(); // update timeline & tables on status change
        },
        onHeartbeat: (payload) => {
            if (payload && payload.isp_id && liveIsps[payload.isp_id]) {
                liveIsps[payload.isp_id].last_seen = payload.last_seen;
                liveIsps[payload.isp_id].latency_ms = payload.latency_ms;
                updateIspHeartbeatUI(payload.isp_id);
            }
        }
    });
    wsClient.connect();

    // Initial data load
    loadIspsList();
    fetchCurrentState();
    refreshStats();
    loadEventsLog();

    // Polling intervals
    setInterval(tickUptimeCounters, 1000);
    setInterval(refreshStats, 30000); // Background refresh every 30s
});

function initClock() {
    const clockEl = document.getElementById('live-clock');
    if (!clockEl) return;
    const updateTime = () => {
        const now = new Date();
        clockEl.textContent = now.toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        });
    };
    updateTime();
    setInterval(updateTime, 1000);
}

function initFilterTabs() {
    const tabs = document.querySelectorAll('.btn-tab');
    const customBox = document.getElementById('custom-date-box');

    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentPeriod = tab.getAttribute('data-period');

            if (currentPeriod === 'custom') {
                customBox.classList.add('active');
            } else {
                customBox.classList.remove('active');
                refreshStats();
            }
        });
    });

    const applyBtn = document.getElementById('btn-apply-custom');
    if (applyBtn) {
        applyBtn.addEventListener('click', () => {
            refreshStats();
        });
    }

    const refreshBtn = document.getElementById('btn-refresh');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            fetchCurrentState();
            refreshStats();
            loadEventsLog();
        });
    }
}

function initIspSelector() {
    const select = document.getElementById('isp-filter-select');
    if (!select) return;
    select.addEventListener('change', (e) => {
        selectedIsp = e.target.value;
        refreshStats();
        loadEventsLog();
    });
}

function getPeriodTimestamps() {
    const now = Math.floor(Date.now() / 1000);
    let from = now - (24 * 3600);

    switch (currentPeriod) {
        case '24h':
            from = now - (24 * 3600);
            break;
        case '2d':
            from = now - (2 * 86400);
            break;
        case '3d':
            from = now - (3 * 86400);
            break;
        case '7d':
            from = now - (7 * 86400);
            break;
        case '30d':
            from = now - (30 * 86400);
            break;
        case 'custom':
            const fromInput = document.getElementById('custom-from').value;
            const toInput = document.getElementById('custom-to').value;
            if (fromInput) {
                from = Math.floor(new Date(fromInput).getTime() / 1000);
            }
            if (toInput) {
                const to = Math.floor(new Date(toInput).getTime() / 1000);
                return { from, to };
            }
            break;
    }

    return { from, to: now };
}

window.fetchCurrentState = function() {
    fetch('api.php?action=get_state')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                liveIsps = data.data;
                renderIspCards();
            }
        })
        .catch(err => console.error('Error fetching state:', err));
};

function loadIspsList() {
    fetch('api.php?action=get_isps')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const select = document.getElementById('isp-filter-select');
                if (select) {
                    const currentVal = select.value;
                    select.innerHTML = '<option value="all">All Monitored ISPs</option>';
                    for (const [id, name] of Object.entries(data.data)) {
                        const opt = document.createElement('option');
                        opt.value = id;
                        opt.textContent = `${name} (${id})`;
                        select.appendChild(opt);
                    }
                    select.value = currentVal;
                }

                const purgeSelect = document.getElementById('clean-purge-isp-select');
                if (purgeSelect) {
                    purgeSelect.innerHTML = '<option value="">Select ISP to purge...</option>';
                    for (const [id, name] of Object.entries(data.data)) {
                        const opt = document.createElement('option');
                        opt.value = id;
                        opt.textContent = `${name} (${id})`;
                        purgeSelect.appendChild(opt);
                    }
                }
            }
        });
}

function refreshStats() {
    const { from, to } = getPeriodTimestamps();
    const url = `api.php?action=get_stats&from=${from}&to=${to}&isp_id=${encodeURIComponent(selectedIsp)}`;

    // Update CSV export link
    const exportBtn = document.getElementById('btn-export-csv');
    if (exportBtn) {
        exportBtn.href = `api.php?action=export_csv&from=${from}&to=${to}&isp_id=${encodeURIComponent(selectedIsp)}`;
    }

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                currentStats = data;
                renderKPIs(data);
                renderTimeline(data);
                renderOutagesTable(data.outages);
            }
        })
        .catch(err => console.error('Error loading stats:', err));
}

function renderKPIs(data) {
    const summary = data.summary;
    const query = data.query;

    const downtimeEl = document.getElementById('kpi-downtime');
    const availEl = document.getElementById('kpi-avail');
    const outagesEl = document.getElementById('kpi-outages');
    const avgOutageEl = document.getElementById('kpi-avg-outage');
    const longestOutageEl = document.getElementById('kpi-longest-outage');
    const windowEl = document.getElementById('kpi-window');

    if (downtimeEl) {
        downtimeEl.textContent = summary.total_downtime_formatted;
        downtimeEl.className = summary.total_downtime_seconds > 0 ? 'kpi-value red' : 'kpi-value green';
    }

    if (availEl) {
        availEl.textContent = `${summary.availability_percentage}%`;
        if (summary.availability_percentage >= 99.5) {
            availEl.className = 'kpi-value green';
        } else if (summary.availability_percentage >= 95.0) {
            availEl.className = 'kpi-value yellow';
        } else {
            availEl.className = 'kpi-value red';
        }
    }

    if (outagesEl) {
        outagesEl.textContent = summary.outage_count;
        outagesEl.className = summary.outage_count > 0 ? 'kpi-value yellow' : 'kpi-value green';
    }

    if (avgOutageEl) {
        avgOutageEl.textContent = summary.average_outage_formatted;
    }

    if (longestOutageEl) {
        longestOutageEl.textContent = summary.longest_outage_formatted;
    }

    if (windowEl) {
        windowEl.textContent = query.window_formatted;
    }
}

function renderIspCards() {
    const container = document.getElementById('isp-cards-container');
    if (!container) return;

    const ids = Object.keys(liveIsps);
    if (ids.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1;">
                <div class="empty-state-icon">📡</div>
                <div style="font-weight: 600;">No ISP client connected yet</div>
                <div style="font-size: 13px; color: var(--text-muted); margin-top: 6px;">
                    Start the client daemon on your client machine to begin tracking.
                </div>
            </div>`;
        return;
    }

    let html = '';
    const now = Math.floor(Date.now() / 1000);

    for (const id of ids) {
        const isp = liveIsps[id];
        const isUp = isp.status === 'UP';
        const streakSec = Math.max(0, now - (isp.current_state_since || now));
        const streakText = formatDurationJS(streakSec);
        const latency = isp.latency_ms !== null && isp.latency_ms !== undefined ? `${isp.latency_ms} ms` : 'N/A';
        const lastSeenDiff = Math.max(0, now - (isp.last_seen || now));

        html += `
            <div class="isp-card ${isUp ? 'status-up' : 'status-down'}" id="isp-card-${id}">
                <div class="isp-card-header">
                    <div>
                        <div class="isp-info-name">${escapeHtml(isp.isp_name || id)}</div>
                        <div class="isp-info-id">ID: ${escapeHtml(id)}</div>
                    </div>
                    <div class="status-indicator ${isUp ? 'up' : 'down'}">
                        <span class="dot-glow ${isUp ? 'green' : 'red'}"></span>
                        ${isUp ? 'ONLINE' : 'OFFLINE'}
                    </div>
                </div>

                <div class="isp-meta-row">
                    <span class="isp-meta-label">Current State:</span>
                    <span class="isp-meta-value" style="color: ${isUp ? 'var(--status-up)' : 'var(--status-down)'};">
                        ${isUp ? 'UP' : 'DOWN'} for <span id="streak-${id}">${streakText}</span>
                    </span>
                </div>

                <div class="isp-meta-row">
                    <span class="isp-meta-label">WS Latency:</span>
                    <span class="isp-meta-value" id="latency-${id}">${latency}</span>
                </div>

                <div class="isp-meta-row">
                    <span class="isp-meta-label">Last Heartbeat:</span>
                    <span class="isp-meta-value" id="lastseen-${id}">${lastSeenDiff}s ago (${isp.last_seen_datetime || 'N/A'})</span>
                </div>

                <div class="isp-meta-row" style="margin-bottom: 0;">
                    <span class="isp-meta-label">Client IP:</span>
                    <span class="isp-meta-value">${escapeHtml(isp.client_ip || 'Unknown')}</span>
                </div>
            </div>
        `;
    }

    container.innerHTML = html;
}

function updateIspHeartbeatUI(ispId) {
    const latencyEl = document.getElementById(`latency-${ispId}`);
    const lastseenEl = document.getElementById(`lastseen-${ispId}`);
    const isp = liveIsps[ispId];

    if (isp) {
        if (latencyEl && isp.latency_ms !== null) {
            latencyEl.textContent = `${isp.latency_ms} ms`;
        }
        if (lastseenEl) {
            lastseenEl.textContent = `0s ago (${isp.last_seen_datetime || ''})`;
        }
    }
}

function tickUptimeCounters() {
    const now = Math.floor(Date.now() / 1000);
    for (const [id, isp] of Object.entries(liveIsps)) {
        const streakEl = document.getElementById(`streak-${id}`);
        if (streakEl) {
            const streakSec = Math.max(0, now - (isp.current_state_since || now));
            streakEl.textContent = formatDurationJS(streakSec);
        }

        const lastseenEl = document.getElementById(`lastseen-${id}`);
        if (lastseenEl && isp.last_seen) {
            const diff = Math.max(0, now - isp.last_seen);
            lastseenEl.textContent = `${diff}s ago`;
        }
    }
}

function renderTimeline(data) {
    const bar = document.getElementById('timeline-bar');
    const startLabel = document.getElementById('timeline-start');
    const endLabel = document.getElementById('timeline-end');
    if (!bar) return;

    const from = data.query.from_timestamp;
    const to = data.query.to_timestamp;
    const windowSec = data.query.window_seconds;

    if (startLabel) startLabel.textContent = data.query.from_datetime;
    if (endLabel) endLabel.textContent = data.query.to_datetime;

    const outages = data.outages || [];

    if (outages.length === 0) {
        bar.innerHTML = `<div class="timeline-block up" style="width: 100%;" title="100% Uptime over selected period"></div>`;
        return;
    }

    // Sort outages chronologically
    const sorted = [...outages].sort((a, b) => a.down_timestamp - b.down_timestamp);
    let segments = [];
    let currentPointer = from;

    for (const out of sorted) {
        const outStart = Math.max(out.down_timestamp, from);
        const outEnd = Math.min(out.up_timestamp, to);

        if (outStart > currentPointer) {
            // Up segment before this outage
            segments.push({
                type: 'up',
                start: currentPointer,
                end: outStart,
                duration: outStart - currentPointer
            });
        }

        // Outage segment
        if (outEnd > outStart) {
            segments.push({
                type: 'down',
                start: outStart,
                end: outEnd,
                duration: outEnd - outStart,
                isp_name: out.isp_name,
                reason: out.reason,
                full_duration: out.full_duration_formatted
            });
            currentPointer = Math.max(currentPointer, outEnd);
        }
    }

    if (currentPointer < to) {
        segments.push({
            type: 'up',
            start: currentPointer,
            end: to,
            duration: to - currentPointer
        });
    }

    let barHtml = '';
    for (const seg of segments) {
        const pct = ((seg.duration / windowSec) * 100).toFixed(2);
        if (seg.type === 'up') {
            barHtml += `<div class="timeline-block up" style="width: ${pct}%;" title="Uptime (${formatDurationJS(seg.duration)})"></div>`;
        } else {
            const title = `OUTAGE: ${seg.isp_name} | ${formatDurationJS(seg.duration)} | ${seg.reason || ''}`;
            barHtml += `<div class="timeline-block down" style="width: ${pct}%;" title="${escapeHtml(title)}"></div>`;
        }
    }

    bar.innerHTML = barHtml;
}

function renderOutagesTable(outages) {
    const tbody = document.getElementById('outages-tbody');
    if (!tbody) return;

    if (!outages || outages.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="empty-state">No outages recorded during this period! 🎉</td></tr>`;
        return;
    }

    let html = '';
    for (const out of outages) {
        const isResolved = out.status === 'RESOLVED';
        html += `
            <tr>
                <td><strong>${escapeHtml(out.isp_name)}</strong></td>
                <td><span class="tag-status ${isResolved ? 'resolved' : 'ongoing'}">${out.status}</span></td>
                <td><code>${escapeHtml(out.down_datetime)}</code></td>
                <td><code>${escapeHtml(out.up_datetime)}</code></td>
                <td><strong>${escapeHtml(out.full_duration_formatted)}</strong></td>
                <td><small>${escapeHtml(out.window_downtime_formatted)}</small></td>
                <td style="color: var(--text-secondary);">${escapeHtml(out.reason)}</td>
                <td style="text-align: center;">
                    <button class="btn-delete-row" data-id="${escapeHtml(out.id)}" title="Delete this outage record">🗑️</button>
                </td>
            </tr>
        `;
    }
    tbody.innerHTML = html;

    // Attach click listeners to delete buttons
    tbody.querySelectorAll('.btn-delete-row').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-id');
            if (!id) return;
            if (confirm(`Are you sure you want to delete outage record ${id}?`)) {
                btn.disabled = true;
                fetch(`api.php?action=delete_outage&id=${encodeURIComponent(id)}`, { method: 'POST' })
                    .then(res => res.json())
                    .then(res => {
                        if (res.status === 'success') {
                            refreshStats();
                            loadIspsList();
                        } else {
                            alert(res.message || 'Failed to delete outage');
                        }
                    })
                    .catch(err => alert('Error deleting outage: ' + err));
            }
        });
    });
}

function loadEventsLog() {
    fetch(`api.php?action=get_events&limit=40&isp_id=${encodeURIComponent(selectedIsp)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const logBody = document.getElementById('terminal-log-body');
                if (!logBody) return;
                logBody.innerHTML = '';
                for (const ev of data.data) {
                    appendLog(`[${ev.event_type}] ${ev.isp_name}: ${ev.details}`, ev.event_type, ev.datetime);
                }
            }
        });
}

function appendLog(message, type = 'INFO', customTime = null) {
    const logBody = document.getElementById('terminal-log-body');
    if (!logBody) return;

    const time = customTime || new Date().toISOString().replace('T', ' ').substring(0, 19);
    const line = document.createElement('div');
    line.className = 'log-line';

    let typeClass = 'log-info';
    if (type === 'UP') typeClass = 'log-up';
    if (type === 'DOWN') typeClass = 'log-down';

    line.innerHTML = `<span class="log-time">[${time}]</span> <span class="${typeClass}">[${type}]</span> ${escapeHtml(message)}`;
    logBody.insertBefore(line, logBody.firstChild);

    // Keep max 100 log items
    if (logBody.children.length > 100) {
        logBody.removeChild(logBody.lastChild);
    }
}

function initSetupModal() {
    const btnOpen = document.getElementById('btn-open-setup');
    const modal = document.getElementById('setup-modal');
    const btnClose = document.getElementById('btn-close-modal');

    if (btnOpen && modal) {
        btnOpen.addEventListener('click', () => modal.classList.add('open'));
    }
    if (btnClose && modal) {
        btnClose.addEventListener('click', () => modal.classList.remove('open'));
    }
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        });
    }
}

function initCleanModal() {
    const btnOpen = document.getElementById('btn-open-clean-modal');
    const modal = document.getElementById('clean-modal');
    const btnClose = document.getElementById('btn-close-clean-modal');

    if (btnOpen && modal) {
        btnOpen.addEventListener('click', () => {
            loadIspsList();
            modal.classList.add('open');
        });
    }
    if (btnClose && modal) {
        btnClose.addEventListener('click', () => modal.classList.remove('open'));
    }
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        });
    }

    // Auto Clean
    const btnAuto = document.getElementById('btn-run-auto-clean');
    if (btnAuto) {
        btnAuto.addEventListener('click', () => {
            btnAuto.disabled = true;
            btnAuto.textContent = 'Cleaning...';
            fetch('api.php?action=clean_garbage', { method: 'POST' })
                .then(r => r.json())
                .then(res => {
                    btnAuto.disabled = false;
                    btnAuto.textContent = 'Run Auto Cleanup';
                    if (res.status === 'success') {
                        alert(`Cleanup Complete!\n- Outages removed: ${res.stats.records_removed}\n- Stuck outages closed: ${res.stats.stuck_outages_closed}\n- Duplicates merged: ${res.stats.duplicates_merged}`);
                        modal.classList.remove('open');
                        loadIspsList();
                        refreshStats();
                        loadEventsLog();
                    } else {
                        alert(res.message || 'Failed to clean data');
                    }
                })
                .catch(err => {
                    btnAuto.disabled = false;
                    btnAuto.textContent = 'Run Auto Cleanup';
                    alert('Error running cleanup: ' + err);
                });
        });
    }

    // Prune noise
    const btnPrune = document.getElementById('btn-run-prune-noise');
    const selectNoise = document.getElementById('clean-noise-duration');
    if (btnPrune && selectNoise) {
        btnPrune.addEventListener('click', () => {
            const minDur = parseInt(selectNoise.value, 10) || 5;
            if (confirm(`Remove all historical outages shorter than ${minDur} seconds?`)) {
                btnPrune.disabled = true;
                fetch(`api.php?action=clean_garbage&min_duration=${minDur}`, { method: 'POST' })
                    .then(r => r.json())
                    .then(res => {
                        btnPrune.disabled = false;
                        if (res.status === 'success') {
                            alert(`Pruned short outages!\n- Records removed: ${res.stats.records_removed}`);
                            modal.classList.remove('open');
                            refreshStats();
                        } else {
                            alert(res.message || 'Failed to prune');
                        }
                    })
                    .catch(err => {
                        btnPrune.disabled = false;
                        alert('Error pruning: ' + err);
                    });
            }
        });
    }

    // Purge specific ISP
    const btnPurge = document.getElementById('btn-run-purge-isp');
    const selectPurge = document.getElementById('clean-purge-isp-select');
    if (btnPurge && selectPurge) {
        btnPurge.addEventListener('click', () => {
            const ispId = selectPurge.value;
            if (!ispId) {
                alert('Please select an ISP to purge.');
                return;
            }
            if (confirm(`Are you SURE you want to permanently purge all data for '${ispId}'?`)) {
                btnPurge.disabled = true;
                fetch(`api.php?action=purge_isp&isp_id=${encodeURIComponent(ispId)}`, { method: 'POST' })
                    .then(r => r.json())
                    .then(res => {
                        btnPurge.disabled = false;
                        if (res.status === 'success') {
                            alert(res.message);
                            modal.classList.remove('open');
                            loadIspsList();
                            fetchCurrentState();
                            refreshStats();
                            loadEventsLog();
                        } else {
                            alert(res.message || 'Failed to purge ISP');
                        }
                    })
                    .catch(err => {
                        btnPurge.disabled = false;
                        alert('Error purging ISP: ' + err);
                    });
            }
        });
    }

    // Reset all
    const btnReset = document.getElementById('btn-run-reset-all');
    if (btnReset) {
        btnReset.addEventListener('click', () => {
            if (confirm('⚠️ WARNING: This will permanently wipe all outage records and logs!\n\nAre you sure you want to reset everything?')) {
                btnReset.disabled = true;
                fetch('api.php?action=reset_all', { method: 'POST' })
                    .then(r => r.json())
                    .then(res => {
                        btnReset.disabled = false;
                        if (res.status === 'success') {
                            alert('All records have been reset to factory state!');
                            modal.classList.remove('open');
                            loadIspsList();
                            fetchCurrentState();
                            refreshStats();
                            loadEventsLog();
                        } else {
                            alert(res.message || 'Failed to reset data');
                        }
                    })
                    .catch(err => {
                        btnReset.disabled = false;
                        alert('Error resetting data: ' + err);
                    });
            }
        });
    }
}

function formatDurationJS(seconds) {
    seconds = Math.max(0, parseInt(seconds, 10));
    if (seconds === 0) return '0s';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    let parts = [];
    if (days > 0) parts.push(`${days}d`);
    if (hours > 0) parts.push(`${hours}h`);
    if (mins > 0) parts.push(`${mins}m`);
    if (secs > 0 || parts.length === 0) parts.push(`${secs}s`);

    return parts.join(' ');
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
