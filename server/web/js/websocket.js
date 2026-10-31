/**
 * ISP Monitor - Real-time WebSocket Client
 * Maintains persistent connection to PHP WebSocket server daemon with automatic fallback polling.
 */

class IspWebSocketClient {
    constructor(options = {}) {
        this.port = options.port || 8080;
        this.host = options.host || window.location.hostname || '127.0.0.1';
        this.wsUrl = `ws://${this.host}:${this.port}`;
        this.socket = null;
        this.isConnected = false;
        this.reconnectInterval = 3000;
        this.fallbackPollingInterval = 4000;
        this.pollingTimer = null;
        this.onStatusChange = options.onStatusChange || null;
        this.onHeartbeat = options.onHeartbeat || null;
        this.onConnectionState = options.onConnectionState || null;
    }

    connect() {
        if (this.socket) {
            try { this.socket.close(); } catch(e) {}
        }

        this.setConnectionState('connecting', 'Connecting to WebSocket...');

        try {
            this.socket = new WebSocket(this.wsUrl);

            this.socket.onopen = () => {
                this.isConnected = true;
                this.setConnectionState('connected', 'Live WebSocket Connected');
                this.stopFallbackPolling();

                // Subscribe as dashboard viewer
                this.socket.send(JSON.stringify({
                    type: 'subscribe_dashboard',
                    client_time: Math.floor(Date.now() / 1000)
                }));
            };

            this.socket.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleMessage(data);
                } catch (err) {
                    console.warn('[WS] Error parsing message:', err, event.data);
                }
            };

            this.socket.onclose = () => {
                this.isConnected = false;
                this.setConnectionState('disconnected', 'WebSocket Disconnected (Polling Active)');
                this.startFallbackPolling();
                setTimeout(() => this.connect(), this.reconnectInterval);
            };

            this.socket.onerror = (err) => {
                console.warn('[WS] Socket error, falling back to HTTP polling.');
                this.socket.close();
            };

        } catch (e) {
            this.setConnectionState('disconnected', 'WebSocket Unavailable (Polling Active)');
            this.startFallbackPolling();
            setTimeout(() => this.connect(), this.reconnectInterval);
        }
    }

    handleMessage(data) {
        if (!data || !data.type) return;

        switch (data.type) {
            case 'init_state':
                if (this.onStatusChange) {
                    this.onStatusChange(data.state, true);
                }
                break;

            case 'isp_status':
                if (this.onStatusChange) {
                    this.onStatusChange(data, false);
                }
                break;

            case 'isp_heartbeat':
                if (this.onHeartbeat) {
                    this.onHeartbeat(data);
                }
                break;
        }
    }

    setConnectionState(state, text) {
        if (this.onConnectionState) {
            this.onConnectionState(state, text);
        }
    }

    startFallbackPolling() {
        if (this.pollingTimer) return;
        this.pollingTimer = setInterval(() => {
            if (!this.isConnected && typeof window.fetchCurrentState === 'function') {
                window.fetchCurrentState();
            }
        }, this.fallbackPollingInterval);
    }

    stopFallbackPolling() {
        if (this.pollingTimer) {
            clearInterval(this.pollingTimer);
            this.pollingTimer = null;
        }
    }
}
