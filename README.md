# ⚡ ISP Downtime & Reliability Monitor

A real-time, multi-ISP downtime tracking and availability monitoring system built with **PHP, Apache, and WebSockets**.

Designed specifically for tracking frequent ISP disconnections, calculating mathematically exact downtime over any selected historical period (past 24h, 2 days, 3 days, 7 days, or custom ranges), and storing all records in **plain text files (CSV & JSON) without any database**.

---

## 🌟 Key Features

- **Accurate Up & Down Tracking**:
  - Outages are logged to the exact second using persistent WebSocket connections and heartbeat pings.
  - Immediately detects connection loss (TCP break, timeout) and logs `DOWN` state.
  - Automatically detects reconnection, logs `UP` state, and calculates total downtime.
- **Custom Period Downtime Analysis**:
  - Filter by Past 24 Hours, Past 2 Days, Past 3 Days, Past 7 Days, Past 30 Days, or any Custom Date/Time Range.
  - Calculates Total Downtime, Network Availability Uptime %, Outage Incident Count, Average Outage Duration, and Longest Outage.
- **Zero Database (Plain Text Storage)**:
  - All data is stored in human-readable plain text files (`outages.csv`, `events.csv`, `state.json`).
  - Safe concurrent file locking (`flock()`) prevents data corruption.
  - Easily viewable with `cat`, `grep`, or importable into Microsoft Excel / Google Sheets.
- **Multiple ISPs Support**:
  - Deploy lightweight monitoring agents on multiple Linux machines across different ISPs (e.g., Airtel, Jio, ACT, Starlink).
  - Filter stats globally across all ISPs or drill down into a single provider.
- **Live Real-time Dashboard (PHP + Apache)**:
  - Glowing status indicators (ONLINE / OFFLINE) with live ticking uptime/downtime streak counters.
  - Interactive visual outage timeline with color-coded UP and DOWN segments.
  - Export historical data directly to CSV.
  - Live system event stream.
- **Resilient Client Agent**:
  - Python 3 daemon with automatic reconnection loop.
  - Non-root DNS latency checks (Cloudflare 1.1.1.1, Google 8.8.8.8).
  - Built-in zero-dependency RFC 6455 pure Python socket fallback (runs on any Linux box even without pip/virtualenvs).

---

## 📂 Project Structure

```
isp/
├── README.md                      # Documentation & Architecture Guide
├── install.sh                     # Master interactive installer
├── start.sh                       # Master starter script
├── stop.sh                        # Master stopper script
│
├── server/                        # Server deployment (Host on VPS or central server)
│   ├── install.sh                 # Checks & installs Apache, PHP, and sets permissions
│   ├── start.sh                   # Starts Apache and launches WebSocket daemon in background
│   ├── stop.sh                    # Gracefully stops WebSocket daemon
│   ├── config.php                 # Server configuration (ports, data paths, timeouts)
│   ├── ws_server.php              # Pure PHP WebSocket daemon (RFC 6455 compliant)
│   ├── data/                      # Plain text storage (NO DATABASE)
│   │   ├── outages.csv            # Computed historical outages (start, end, duration)
│   │   ├── events.csv             # Sequential state transition event log
│   │   └── state.json             # Real-time state cache of all ISPs
│   └── web/                       # Apache DocumentRoot web interface
│       ├── index.php              # Modern web dashboard
│       ├── api.php                # REST API for calculating stats & querying logs
│       ├── css/style.css          # Dark-mode dashboard styling
│       └── js/
│           ├── app.js             # Dashboard UI logic & timeline rendering
│           └── websocket.js       # Live WebSocket handler with HTTP fallback
│
└── client/                        # Client deployment (Run on machines under monitored ISPs)
    ├── install.sh                 # Checks & installs Python 3 & dependencies
    ├── start.sh                   # Starts client background daemon
    ├── stop.sh                    # Gracefully stops client daemon
    ├── config.json                # Client configuration (Server URL, ISP ID, timers)
    ├── client.py                  # Client monitoring agent with auto-reconnect
    └── requirements.txt           # Python dependencies
```

---

## 🚀 Quick Start Guide

### 1. Server Setup (On your central Linux server / VPS)

1. Navigate to the `server` directory and run the installer:
   ```bash
   cd /path/to/isp/server
   ./install.sh
   ```
   *The installer checks your Linux distribution (Ubuntu/Debian, CentOS/RHEL, Arch, etc.), installs Apache and PHP (with CLI, cURL, JSON extensions), initializes the plain text data files, and configures permissions.*

2. Start the server services:
   ```bash
   ./start.sh
   ```
   This starts the Apache web server and launches the PHP WebSocket server in the background on port `8080`.

3. Access the Web Dashboard:
   - If using Apache: `http://YOUR_SERVER_IP/isp-monitor/`
   - If using the standalone fallback: `http://YOUR_SERVER_IP:8000/`

---

### 2. Client Setup (On the Linux machine connected to the ISP)

You can run the client on any Linux machine (Ubuntu, Debian, Raspberry Pi, home server, mini PC, etc.).

1. Copy the `client` folder to your client machine:
   ```bash
   scp -r /path/to/isp/client user@client-machine:~/isp-client
   ```

2. On the client machine, run `install.sh`:
   ```bash
   cd ~/isp-client
   ./install.sh
   ```

3. Edit `config.json` with your server's IP and ISP details:
   ```json
   {
     "server_url": "ws://YOUR_SERVER_IP:8080",
     "isp_id": "airtel_fiber",
     "isp_name": "Airtel Xstream Fiber",
     "heartbeat_interval_seconds": 3,
     "reconnect_delay_seconds": 2
   }
   ```

4. Start the client daemon:
   ```bash
   ./start.sh
   ```

5. Check live logs:
   ```bash
   tail -f client.log
   ```

---

## ⏱️ How Pure WebSocket Outage Detection Works

1. **Persistent Connection as Source of Truth**: The client machine maintains an open WebSocket connection to `ws://YOUR_SERVER_IP:8080`.
2. **WebSocket Round-Trip Keep-Alive**: Every 3 seconds, the client sends a lightweight heartbeat frame over the WebSocket. The server replies with an ACK, allowing the client to measure direct WebSocket connection latency with **zero external pings**.
3. **Instant Disconnection Detection**:
   - When the ISP connection drops or router restarts, the TCP socket breaks immediately and the server is notified in milliseconds.
   - For silent drops (e.g. power loss or cut cable), if no heartbeat frame is received within `10 seconds` (configurable via `HEARTBEAT_TIMEOUT_SEC`), the server marks the ISP as **`DOWN`**.
   - An outage entry is recorded in `server/data/outages.csv` and `server/data/events.csv` with the exact down timestamp.
   - The web dashboard is notified in real time via WebSocket broadcast.
4. **Reconnection & Downtime Calculation**:
   - As soon as internet connectivity returns, the client automatically reconnects and re-registers.
   - The server marks the ISP as **`UP`**, calculates the exact downtime duration (`up_timestamp - down_timestamp`), resolves the outage record, and updates the timeline.

---

## 📊 Plain Text Storage Format (No Database)

### 1. `server/data/outages.csv`
Contains paired outage records for calculating total downtime:
```csv
id,isp_id,isp_name,down_timestamp,down_datetime,up_timestamp,up_datetime,duration_seconds,status,reason
1730360000_a1b2,airtel_fiber,Airtel Xstream Fiber,1730360000,2026-10-31 12:00:00,1730360900,2026-10-31 12:15:00,900,RESOLVED,Heartbeat timeout
```

### 2. `server/data/events.csv`
Append-only log of every state transition:
```csv
timestamp,datetime,isp_id,isp_name,event_type,duration_seconds,details
1730360000,2026-10-31 12:00:00,airtel_fiber,Airtel Xstream Fiber,DOWN,0,Heartbeat timeout
1730360900,2026-10-31 12:15:00,airtel_fiber,Airtel Xstream Fiber,UP,900,Reconnected after 15m 0s outage
```

### 3. `server/data/state.json`
Real-time snapshot of currently connected ISPs, last heartbeat timestamps, and latency.

---

## 🛑 Stopping Services

- **To stop the server**:
  ```bash
  cd /path/to/isp/server && ./stop.sh
  ```
- **To stop a client**:
  ```bash
  cd /path/to/isp/client && ./stop.sh
  ```
- **Master stop script**:
  ```bash
  ./stop.sh
  ```
