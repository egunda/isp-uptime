<?php
require_once __DIR__ . '/../config.php';
init_data_storage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISP Reliability & Downtime Monitor</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220%22%20100%22><text y=%22.9em%22 font-size=%2290%22>⚡</text></svg>">
</head>
<body>
    <div class="container">
        <!-- Top Navigation / Header -->
        <header class="header">
            <div class="brand">
                <div class="brand-icon">⚡</div>
                <div>
                    <h1 class="brand-title">ISP Downtime & Reliability Monitor</h1>
                    <div class="brand-subtitle">Real-time WebSocket & Historical Outage Analysis (Zero DB / Plain Text)</div>
                </div>
            </div>

            <div class="header-status">
                <div id="ws-badge" class="badge connecting">
                    <span class="dot-glow yellow"></span> Connecting WebSocket...
                </div>
                <div class="clock-display" id="live-clock">--:--:--</div>
                <button class="btn-action btn-secondary" id="btn-refresh" title="Refresh data">🔄 Refresh</button>
                <button class="btn-action" id="btn-open-setup">➕ Add Client ISP</button>
            </div>
        </header>

        <!-- Filter & Control Bar -->
        <section class="controls-card">
            <div class="filter-group">
                <span class="filter-label">Time Window:</span>
                <div class="btn-group">
                    <button class="btn-tab active" data-period="24h">Past 24 Hours</button>
                    <button class="btn-tab" data-period="2d">Past 2 Days</button>
                    <button class="btn-tab" data-period="3d">Past 3 Days</button>
                    <button class="btn-tab" data-period="7d">Past 7 Days</button>
                    <button class="btn-tab" data-period="30d">Past 30 Days</button>
                    <button class="btn-tab" data-period="custom">Custom Range</button>
                </div>
            </div>

            <div class="filter-group custom-date-box" id="custom-date-box">
                <input type="datetime-local" class="form-input" id="custom-from">
                <span style="color: var(--text-muted); font-size: 12px;">to</span>
                <input type="datetime-local" class="form-input" id="custom-to">
                <button class="btn-action btn-secondary" id="btn-apply-custom">Apply</button>
            </div>

            <div class="filter-group">
                <span class="filter-label">Filter ISP:</span>
                <select class="form-select" id="isp-filter-select">
                    <option value="all">All Monitored ISPs</option>
                </select>
                <a href="#" class="btn-action btn-secondary" id="btn-export-csv" download title="Download CSV report">
                    📥 Export CSV
                </a>
                <button type="button" class="btn-action btn-clean" id="btn-open-clean-modal" title="Manage & Clean Garbage Downtimes">
                    🧹 Clean Data
                </button>
            </div>
        </section>

        <!-- KPI Metrics Grid -->
        <section class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-title">
                    <span>Total Downtime</span>
                    <span>⏳</span>
                </div>
                <div class="kpi-value red" id="kpi-downtime">0s</div>
                <div class="kpi-subtext">Cumulative outage duration in window</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-title">
                    <span>Availability Uptime</span>
                    <span>📈</span>
                </div>
                <div class="kpi-value green" id="kpi-avail">100.0%</div>
                <div class="kpi-subtext">Overall network uptime ratio</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-title">
                    <span>Outage Incidents</span>
                    <span>⚠️</span>
                </div>
                <div class="kpi-value" id="kpi-outages">0</div>
                <div class="kpi-subtext">Number of disconnect events</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-title">
                    <span>Average Outage</span>
                    <span>⏱️</span>
                </div>
                <div class="kpi-value" id="kpi-avg-outage">0s</div>
                <div class="kpi-subtext">Mean time to recovery</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-title">
                    <span>Longest Outage</span>
                    <span>📉</span>
                </div>
                <div class="kpi-value" id="kpi-longest-outage">0s</div>
                <div class="kpi-subtext">Maximum single drop duration</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-title">
                    <span>Selected Window</span>
                    <span>📅</span>
                </div>
                <div class="kpi-value" id="kpi-window">24h 0m</div>
                <div class="kpi-subtext">Observation period length</div>
            </div>
        </section>

        <!-- Active ISP Cards Grid -->
        <section>
            <div class="section-title">
                <span>Active Monitored ISP Connections</span>
                <small style="color: var(--text-muted); font-size: 13px;">Real-time heartbeat & connectivity</small>
            </div>
            <div class="isp-grid" id="isp-cards-container">
                <!-- Dynamically populated by app.js -->
            </div>
        </section>

        <!-- Visual Outage Timeline -->
        <section class="timeline-card">
            <div class="section-title" style="margin-bottom: 6px;">
                <span>Outage & Uptime Visual Timeline</span>
                <div style="font-size: 12px; font-weight: normal; color: var(--text-muted);">
                    <span style="display: inline-block; width: 10px; height: 10px; background: var(--status-up); border-radius: 2px; margin-right: 4px;"></span> UP
                    <span style="display: inline-block; width: 10px; height: 10px; background: var(--status-down); border-radius: 2px; margin-left: 12px; margin-right: 4px;"></span> DOWN
                </div>
            </div>
            <div class="timeline-bar-wrapper" id="timeline-bar">
                <div class="timeline-block up" style="width: 100%;" title="100% Uptime"></div>
            </div>
            <div class="timeline-labels">
                <span id="timeline-start">Loading...</span>
                <span id="timeline-end">Loading...</span>
            </div>
        </section>

        <!-- Outages History Table -->
        <section class="table-card">
            <div class="table-header-row">
                <div class="section-title" style="margin-bottom: 0;">Historical Outage Incidents</div>
                <small style="color: var(--text-muted);">Accurate start, end, and duration stored in plain text</small>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ISP Name</th>
                        <th>Status</th>
                        <th>Outage Start (DOWN)</th>
                        <th>Outage End (UP)</th>
                        <th>Total Outage Duration</th>
                        <th>Period Downtime</th>
                        <th>Cause / Reason</th>
                        <th style="width: 50px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody id="outages-tbody">
                    <tr><td colspan="8" class="empty-state">Loading historical records...</td></tr>
                </tbody>
            </table>
        </section>

        <!-- Real-time Event Stream / Terminal Log -->
        <section class="terminal-card">
            <div class="terminal-header">
                <div class="terminal-title">🖥️ Live System Event Stream &middot; plain-text: server/data/events.csv</div>
                <small style="color: #64748b; font-family: var(--font-mono); font-size: 11px;">WebSocket &middot; Heartbeats &middot; State Transitions</small>
            </div>
            <div class="terminal-body" id="terminal-log-body">
                <div class="log-line"><span class="log-time">[System]</span> <span class="log-info">Connecting to monitoring services...</span></div>
            </div>
        </section>
    </div>

    <!-- Client Setup Instructions Modal -->
    <div class="modal-overlay" id="setup-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🚀 How to Connect an ISP Client Machine</h3>
                <button class="modal-close" id="btn-close-modal">&times;</button>
            </div>
            <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px;">
                You can run the lightweight client agent on any Linux machine connected to your ISP (e.g., Raspberry Pi, home server, mini PC, or virtual machine).
            </p>

            <h4 style="font-size: 14px; margin-top: 14px; color: var(--text-primary);">Step 1: Copy the <code>client</code> folder</h4>
            <div class="code-box">scp -r /path/to/isp/client user@your-isp-client-machine:~/isp-client</div>

            <h4 style="font-size: 14px; margin-top: 14px; color: var(--text-primary);">Step 2: Run install on client machine</h4>
            <div class="code-box">cd ~/isp-client && ./install.sh</div>

            <h4 style="font-size: 14px; margin-top: 14px; color: var(--text-primary);">Step 3: Edit <code>config.json</code> with your server's IP and ISP name</h4>
            <div class="code-box">{
  "server_url": "ws://<?php echo $_SERVER['SERVER_ADDR'] ?? 'YOUR_SERVER_IP'; ?>:<?php echo WS_PORT; ?>",
  "isp_id": "airtel_office",
  "isp_name": "Airtel Xstream Fiber",
  "heartbeat_interval_seconds": 3,
  "reconnect_delay_seconds": 2
}</div>

            <h4 style="font-size: 14px; margin-top: 14px; color: var(--text-primary);">Step 4: Start client monitoring daemon</h4>
            <div class="code-box">./start.sh</div>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
                The client will maintain a persistent WebSocket connection to the server. If the ISP connection drops, the server immediately marks it DOWN and calculates downtime down to the exact second when it reconnects.
            </p>
        </div>
    </div>

    <!-- Clean Data / Maintenance Modal -->
    <div class="modal-overlay" id="clean-modal">
        <div class="modal-content" style="max-width: 580px;">
            <div class="modal-header">
                <h3>🧹 Clean Garbage Downtimes & Maintenance</h3>
                <button class="modal-close" id="btn-close-clean-modal">&times;</button>
            </div>
            <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">
                Clean up test records, restart spikes, and stale unclosed outages from plain-text CSV files.
            </p>

            <div class="clean-option-box">
                <div class="clean-option-header">
                    <strong>⚡ Recommended Auto-Clean</strong>
                    <span class="tag-status resolved">Safe</span>
                </div>
                <p style="font-size: 12px; color: var(--text-muted); margin: 6px 0 10px 0;">
                    Purges obsolete test ISP (<code>isp_1</code>), auto-closes stuck ongoing outages, deduplicates overlapping records, and trims glitches &lt; 3s.
                </p>
                <button type="button" class="btn-action" id="btn-run-auto-clean">Run Auto Cleanup</button>
            </div>

            <div class="clean-option-box" style="margin-top: 14px;">
                <div class="clean-option-header">
                    <strong>⏱️ Prune Short Noise Outages</strong>
                </div>
                <p style="font-size: 12px; color: var(--text-muted); margin: 6px 0 10px 0;">
                    Remove quick restart glitches or network blips shorter than:
                </p>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <select class="form-select" id="clean-noise-duration" style="width: 130px;">
                        <option value="3">Under 3s</option>
                        <option value="5" selected>Under 5s</option>
                        <option value="10">Under 10s</option>
                        <option value="30">Under 30s</option>
                    </select>
                    <button type="button" class="btn-action btn-secondary" id="btn-run-prune-noise">Prune Noise</button>
                </div>
            </div>

            <div class="clean-option-box" style="margin-top: 14px;">
                <div class="clean-option-header">
                    <strong>🗑️ Purge Specific ISP History</strong>
                </div>
                <p style="font-size: 12px; color: var(--text-muted); margin: 6px 0 10px 0;">
                    Completely remove all outage records and events for an old/inactive ISP ID:
                </p>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <select class="form-select" id="clean-purge-isp-select" style="flex: 1;">
                        <option value="">Select ISP to purge...</option>
                    </select>
                    <button type="button" class="btn-action btn-danger" id="btn-run-purge-isp">Purge ISP</button>
                </div>
            </div>

            <div class="clean-option-box danger-box" style="margin-top: 14px;">
                <div class="clean-option-header">
                    <strong style="color: #ef4444;">⚠️ Factory Reset All Records</strong>
                </div>
                <p style="font-size: 12px; color: var(--text-muted); margin: 6px 0 10px 0;">
                    Start 100% fresh with a clean slate: wipes all historical outages and events. Active clients will keep syncing live.
                </p>
                <button type="button" class="btn-action btn-danger" id="btn-run-reset-all">Wipe & Reset Everything</button>
            </div>
        </div>
    </div>

    <!-- JavaScript Bundle -->
    <script src="js/websocket.js"></script>
    <script src="js/app.js"></script>
</body>
</html>
