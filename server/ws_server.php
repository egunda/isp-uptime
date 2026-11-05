#!/usr/bin/env php
<?php
/**
 * ISP Monitor - Standalone Pure PHP WebSocket Server
 * Handles multi-client ISP connections and dashboard subscriptions.
 * Zero external composer dependencies required.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
init_data_storage();

class IspWebSocketServer {
    private $host;
    private $port;
    private $masterSocket;
    private $clients = [];      // array of socket resources
    private $clientMeta = [];  // socket_id => metadata
    private $ispState = [];    // isp_id => state array
    private $running = true;
    private $lastTick = 0;

    public function __construct($host = WS_HOST, $port = WS_PORT) {
        $this->host = $host;
        $this->port = $port;
        $this->loadStateFromFile();
    }

    private function log($message, $level = 'INFO') {
        $time = date('Y-m-d H:i:s');
        echo "[{$time}] [{$level}] {$message}\n";
    }

    private function loadStateFromFile() {
        if (file_exists(STATE_FILE)) {
            $content = @file_get_contents(STATE_FILE);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $this->ispState = $decoded;
            }
        }
    }

    private function saveStateToFile() {
        @file_put_contents(STATE_FILE, json_encode($this->ispState, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function start() {
        $address = "tcp://{$this->host}:{$this->port}";
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'backlog' => 128
            ]
        ]);

        $this->masterSocket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (!$this->masterSocket) {
            $this->log("Failed to create master socket: {$errstr} ({$errno})", 'ERROR');
            exit(1);
        }

        stream_set_blocking($this->masterSocket, 0);
        $this->clients[(int)$this->masterSocket] = $this->masterSocket;

        $this->log("=================================================");
        $this->log("ISP Monitoring WebSocket Server started on {$address}");
        $this->log("Storage Directory: " . DATA_DIR);
        $this->log("Heartbeat Timeout: " . HEARTBEAT_TIMEOUT_SEC . " seconds");
        $this->log("=================================================");

        // Setup signal handling if available
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $read = $this->clients;
            $write = null;
            $except = null;

            // Wait up to 500ms for incoming data
            $changed = @stream_select($read, $write, $except, 0, 500000);

            if ($changed === false) {
                continue;
            }

            if ($changed > 0) {
                foreach ($read as $sock) {
                    $sockId = (int)$sock;

                    if ($sock === $this->masterSocket) {
                        $this->acceptNewClient();
                    } else {
                        $this->readClientData($sock);
                    }
                }
            }

            // Periodic heartbeat / timeout check (runs once per second)
            $now = time();
            if ($now - $this->lastTick >= 1) {
                $this->lastTick = $now;
                $this->checkTimeouts();
            }
        }

        $this->shutdown();
    }

    public function handleSignal($signal) {
        $this->log("Signal {$signal} received. Initiating graceful shutdown...", 'WARN');
        $this->running = false;
    }

    private function acceptNewClient() {
        $clientSocket = @stream_socket_accept($this->masterSocket, 0, $peerName);
        if ($clientSocket) {
            stream_set_blocking($clientSocket, 0);
            $sockId = (int)$clientSocket;
            $this->clients[$sockId] = $clientSocket;
            $this->clientMeta[$sockId] = [
                'id' => $sockId,
                'ip' => $peerName,
                'handshake' => false,
                'buffer' => '',
                'type' => 'unknown', // 'isp' or 'dashboard'
                'isp_id' => null,
                'isp_name' => null,
                'connected_at' => time(),
                'last_seen' => time()
            ];
            $this->log("New TCP connection from {$peerName} (ID: {$sockId})");
        }
    }

    private function readClientData($sock) {
        $sockId = (int)$sock;
        $data = @fread($sock, 8192);

        if ($data === false || $data === '') {
            $this->disconnectClient($sock, 'Client closed connection');
            return;
        }

        $meta = &$this->clientMeta[$sockId];
        $meta['buffer'] .= $data;

        if (!$meta['handshake']) {
            $this->performHandshake($sock);
            return;
        }

        // Process WebSocket Frames
        while (!empty($meta['buffer'])) {
            $frame = $this->decodeFrame($meta['buffer']);
            if ($frame === false) {
                // Incomplete frame, wait for more data
                break;
            }

            $meta['buffer'] = $frame['remaining'];
            $meta['last_seen'] = time();

            switch ($frame['opcode']) {
                case 0x1: // Text frame
                    $this->handleTextMessage($sock, $frame['payload']);
                    break;
                case 0x8: // Close frame
                    $this->disconnectClient($sock, 'Client sent close frame');
                    return;
                case 0x9: // Ping frame
                    $this->sendFrame($sock, $frame['payload'], 0xA); // Pong
                    break;
                case 0xA: // Pong frame
                    break;
            }
        }
    }

    private function performHandshake($sock) {
        $sockId = (int)$sock;
        $meta = &$this->clientMeta[$sockId];
        $headers = $meta['buffer'];

        if (strpos($headers, "\r\n\r\n") === false) {
            return; // Wait for complete HTTP headers
        }

        // Parse Sec-WebSocket-Key
        if (preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $headers, $matches)) {
            $key = trim($matches[1]);
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

            $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                        "Upgrade: websocket\r\n" .
                        "Connection: Upgrade\r\n" .
                        "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";

            @fwrite($sock, $response);
            $meta['handshake'] = true;
            $meta['buffer'] = substr($headers, strpos($headers, "\r\n\r\n") + 4);
            $this->log("Handshake successful for client {$sockId} ({$meta['ip']})");
        } else {
            $this->log("Invalid handshake from client {$sockId}", 'WARN');
            $this->disconnectClient($sock, 'Invalid WebSocket handshake');
        }
    }

    private function decodeFrame($data) {
        $len = strlen($data);
        if ($len < 2) return false;

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $fin = ($firstByte & 0x80) === 0x80;
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) === 0x80;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < 4) return false;
            $payloadLen = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) return false;
            $arr = unpack('N2', substr($data, 2, 8));
            $payloadLen = ($arr[1] << 32) | $arr[2];
            $offset = 10;
        }

        $maskKey = '';
        if ($masked) {
            if ($len < $offset + 4) return false;
            $maskKey = substr($data, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $payloadLen) {
            return false; // Incomplete payload
        }

        $payload = substr($data, $offset, $payloadLen);
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        $remaining = substr($data, $offset + $payloadLen);

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'payload' => $payload,
            'remaining' => $remaining
        ];
    }

    private function encodeFrame($payload, $opcode = 0x1) {
        $firstByte = 0x80 | ($opcode & 0x0F); // FIN + opcode
        $len = strlen($payload);

        if ($len <= 125) {
            $header = chr($firstByte) . chr($len);
        } elseif ($len <= 65535) {
            $header = chr($firstByte) . chr(126) . pack('n', $len);
        } else {
            $header = chr($firstByte) . chr(127) . pack('J', $len);
        }

        return $header . $payload;
    }

    private function sendFrame($sock, $payload, $opcode = 0x1) {
        $frame = $this->encodeFrame($payload, $opcode);
        @fwrite($sock, $frame);
    }

    private function broadcastToDashboards($data) {
        $json = is_string($data) ? $data : json_encode($data);
        foreach ($this->clients as $sockId => $sock) {
            if ($sock !== $this->masterSocket && isset($this->clientMeta[$sockId])) {
                if ($this->clientMeta[$sockId]['type'] === 'dashboard' && $this->clientMeta[$sockId]['handshake']) {
                    $this->sendFrame($sock, $json);
                }
            }
        }
    }

    private function handleTextMessage($sock, $message) {
        $sockId = (int)$sock;
        $meta = &$this->clientMeta[$sockId];
        $data = json_decode($message, true);

        if (!is_array($data) || !isset($data['type'])) {
            $this->log("Received unparsable message from client {$sockId}: {$message}", 'WARN');
            return;
        }

        $type = $data['type'];

        switch ($type) {
            case 'subscribe_dashboard':
                // Dashboard web interface connecting
                $meta['type'] = 'dashboard';
                $this->log("Client {$sockId} subscribed as Web Dashboard");

                // Send current state immediately
                $this->sendFrame($sock, json_encode([
                    'type' => 'init_state',
                    'state' => $this->ispState,
                    'timestamp' => time()
                ]));
                break;

            case 'register':
                // ISP Client connecting
                $ispId = trim($data['isp_id'] ?? '');
                $ispName = trim($data['isp_name'] ?? $ispId);
                $token = $data['token'] ?? '';

                if (AUTH_TOKEN !== '' && $token !== AUTH_TOKEN) {
                    $this->log("Authentication failed for ISP {$ispId} from {$meta['ip']}", 'WARN');
                    $this->sendFrame($sock, json_encode([
                        'type' => 'auth_error',
                        'message' => 'Invalid authentication token'
                    ]));
                    $this->disconnectClient($sock, 'Auth failed');
                    return;
                }

                if (empty($ispId)) {
                    $this->disconnectClient($sock, 'Missing isp_id');
                    return;
                }

                $meta['type'] = 'isp';
                $meta['isp_id'] = $ispId;
                $meta['isp_name'] = $ispName;
                $meta['last_seen'] = time();

                $this->log("ISP Client Registered: {$ispName} (ID: {$ispId}) from {$meta['ip']}");

                // Update ISP state to UP
                $this->onIspConnected($ispId, $ispName, $meta['ip'], $sockId);

                // Send acknowledgement
                $this->sendFrame($sock, json_encode([
                    'type' => 'registered',
                    'status' => 'OK',
                    'isp_id' => $ispId,
                    'server_time' => time()
                ]));
                break;

            case 'heartbeat':
                $ispId = $meta['isp_id'] ?? ($data['isp_id'] ?? null);
                if (!$ispId) return;

                if (isset($this->ispState[$ispId])) {
                    unset($this->ispState[$ispId]['pending_disconnect_since'], $this->ispState[$ispId]['pending_disconnect_reason']);
                }

                $meta['last_seen'] = time();
                $latencyMs = isset($data['ws_latency_ms']) ? floatval($data['ws_latency_ms']) : (isset($data['latency_ms']) ? floatval($data['latency_ms']) : null);
                $clientTimeMs = $data['client_time_ms'] ?? null;

                // If ISP was recorded as DOWN, restore to UP
                if (!isset($this->ispState[$ispId]) || $this->ispState[$ispId]['status'] !== 'UP') {
                    $ispName = $meta['isp_name'] ?? ($data['isp_name'] ?? $ispId);
                    $this->onIspConnected($ispId, $ispName, $meta['ip'], $sockId);
                }

                if (isset($this->ispState[$ispId])) {
                    $this->ispState[$ispId]['last_seen'] = time();
                    $this->ispState[$ispId]['last_seen_datetime'] = date('Y-m-d H:i:s');
                    if ($latencyMs !== null) {
                        $this->ispState[$ispId]['latency_ms'] = $latencyMs;
                    }
                    $this->ispState[$ispId]['client_ip'] = $meta['ip'];
                    $this->saveStateToFile();
                }

                // Send heartbeat response echoing client_time_ms for WebSocket RTT calculation
                $this->sendFrame($sock, json_encode([
                    'type' => 'heartbeat_ack',
                    'client_time_ms' => $clientTimeMs,
                    'timestamp' => time()
                ]));

                // Notify dashboards
                $this->broadcastToDashboards([
                    'type' => 'isp_heartbeat',
                    'isp_id' => $ispId,
                    'last_seen' => time(),
                    'last_seen_datetime' => date('Y-m-d H:i:s'),
                    'latency_ms' => $latencyMs
                ]);
                break;

            case 'outage_report':
                // Client syncs local outage report
                $this->handleClientOutageReport($data, $meta);
                break;
        }
    }

    private function onIspConnected($ispId, $ispName, $clientIp, $sockId) {
        $now = time();
        $nowStr = date('Y-m-d H:i:s');
        $wasDown = false;
        $downtimeSec = 0;

        // Clear any pending disconnect grace timer
        if (isset($this->ispState[$ispId])) {
            $hadPendingDisconnect = !empty($this->ispState[$ispId]['pending_disconnect_since']);
            unset($this->ispState[$ispId]['pending_disconnect_since'], $this->ispState[$ispId]['pending_disconnect_reason']);
            if ($hadPendingDisconnect && ($this->ispState[$ispId]['status'] ?? '') === 'UP') {
                $this->log(">>> [RECOVERED] ISP '{$ispName}' reconnected seamlessly within grace period. False alarm prevented.");
            }
        }

        // Check if ISP had an ongoing outage
        if (isset($this->ispState[$ispId]) && $this->ispState[$ispId]['status'] === 'DOWN') {
            $wasDown = true;
            $downSince = $this->ispState[$ispId]['current_state_since'] ?? $now;
            $downtimeSec = max(0, $now - $downSince);
            $this->resolveOngoingOutage($ispId, $now, $nowStr, $downtimeSec);
        }

        // Format downtime readable string
        $downtimeReadable = $this->formatDuration($downtimeSec);

        // Update state
        $this->ispState[$ispId] = [
            'isp_id' => $ispId,
            'isp_name' => $ispName,
            'status' => 'UP',
            'last_seen' => $now,
            'last_seen_datetime' => $nowStr,
            'current_state_since' => $now,
            'current_state_since_datetime' => $nowStr,
            'client_ip' => $clientIp,
            'latency_ms' => null,
            'active_socket_id' => $sockId
        ];
        $this->saveStateToFile();

        // Log UP event to events.csv
        $details = $wasDown ? "Reconnected after {$downtimeReadable} outage" : "Connection established";
        $this->appendEventLog($now, $nowStr, $ispId, $ispName, 'UP', $downtimeSec, $details);

        $this->log(">>> [UP] ISP '{$ispName}' is ONLINE. " . ($wasDown ? "Outage resolved: {$downtimeReadable}" : ""));

        // Broadcast to dashboards
        $this->broadcastToDashboards([
            'type' => 'isp_status',
            'isp_id' => $ispId,
            'isp_name' => $ispName,
            'status' => 'UP',
            'timestamp' => $now,
            'datetime' => $nowStr,
            'downtime_seconds' => $downtimeSec,
            'details' => $details
        ]);
    }

    private function onIspDisconnected($ispId, $reason = 'Connection lost / timeout', $downTimestamp = null) {
        if (!isset($this->ispState[$ispId])) return;

        $isp = &$this->ispState[$ispId];
        if ($isp['status'] === 'DOWN') {
            return; // Already marked down
        }

        $now = $downTimestamp ?: time();
        $nowStr = date('Y-m-d H:i:s', $now);
        $ispName = $isp['isp_name'];

        $isp['status'] = 'DOWN';
        $isp['current_state_since'] = $now;
        $isp['current_state_since_datetime'] = $nowStr;
        unset($isp['pending_disconnect_since'], $isp['pending_disconnect_reason']);
        $this->saveStateToFile();

        // Create new outage record in outages.csv
        $outageId = $this->createOutageRecord($ispId, $ispName, $now, $nowStr, $reason);
        $isp['current_outage_id'] = $outageId;
        $this->saveStateToFile();

        // Append DOWN event to events.csv
        $this->appendEventLog($now, $nowStr, $ispId, $ispName, 'DOWN', 0, $reason);

        $this->log("<<< [DOWN] ISP '{$ispName}' went OFFLINE. Reason: {$reason}", 'WARN');

        // Broadcast to dashboards
        $this->broadcastToDashboards([
            'type' => 'isp_status',
            'isp_id' => $ispId,
            'isp_name' => $ispName,
            'status' => 'DOWN',
            'timestamp' => $now,
            'datetime' => $nowStr,
            'downtime_seconds' => 0,
            'details' => $reason
        ]);
    }

    private function checkTimeouts() {
        $now = time();
        $graceSec = defined('DISCONNECT_GRACE_SEC') ? DISCONNECT_GRACE_SEC : 5;

        foreach ($this->ispState as $ispId => &$state) {
            if ($state['status'] === 'UP') {
                if (!empty($state['pending_disconnect_since'])) {
                    $pendingDiff = $now - $state['pending_disconnect_since'];
                    if ($pendingDiff >= $graceSec) {
                        $reason = $state['pending_disconnect_reason'] ?? 'Connection closed by client';
                        $discSince = $state['pending_disconnect_since'];
                        unset($state['pending_disconnect_since'], $state['pending_disconnect_reason']);
                        $this->onIspDisconnected($ispId, "Client disconnected: {$reason}", $discSince);
                    }
                } else {
                    $lastSeen = $state['last_seen'] ?? 0;
                    $diff = $now - $lastSeen;
                    if ($diff > HEARTBEAT_TIMEOUT_SEC) {
                        $this->onIspDisconnected($ispId, "Heartbeat timeout (no response for {$diff}s)");
                    }
                }
            }
        }
    }

    private function disconnectClient($sock, $reason = '') {
        $sockId = (int)$sock;
        if (isset($this->clientMeta[$sockId])) {
            $meta = $this->clientMeta[$sockId];
            $this->log("Client {$sockId} ({$meta['ip']}) disconnected: {$reason}");

            if ($meta['type'] === 'isp' && !empty($meta['isp_id'])) {
                $ispId = $meta['isp_id'];
                // Check if this socket was the active socket for this ISP
                if (isset($this->ispState[$ispId]) && ($this->ispState[$ispId]['active_socket_id'] ?? null) == $sockId) {
                    $this->ispState[$ispId]['active_socket_id'] = null;
                    $graceSec = defined('DISCONNECT_GRACE_SEC') ? DISCONNECT_GRACE_SEC : 5;
                    if ($graceSec > 0 && ($this->ispState[$ispId]['status'] ?? '') === 'UP') {
                        $this->ispState[$ispId]['pending_disconnect_since'] = time();
                        $this->ispState[$ispId]['pending_disconnect_reason'] = $reason ?: 'Connection closed';
                        $this->log("ISP '{$ispId}' disconnected ({$reason}). Waiting {$graceSec}s grace period for client self-recovery...", 'INFO');
                    } else {
                        $this->onIspDisconnected($ispId, "Client disconnected: {$reason}");
                    }
                }
            }

            unset($this->clientMeta[$sockId]);
        }

        if (isset($this->clients[$sockId])) {
            @fclose($sock);
            unset($this->clients[$sockId]);
        }
    }

    private function createOutageRecord($ispId, $ispName, $downTimestamp, $downDatetime, $reason) {
        $id = time() . '_' . substr(md5(uniqid('', true)), 0, 4);
        $row = [
            $id,
            $ispId,
            $ispName,
            $downTimestamp,
            $downDatetime,
            '', // up_timestamp (empty while ongoing)
            '', // up_datetime
            0,  // duration_seconds
            'ONGOING',
            str_replace([",", "\n", "\r"], " ", $reason)
        ];

        $fp = @fopen(OUTAGES_FILE, 'a');
        if ($fp && @flock($fp, LOCK_EX)) {
            fputcsv($fp, $row);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }

        return $id;
    }

    private function resolveOngoingOutage($ispId, $upTimestamp, $upDatetime, $durationSec) {
        if (!file_exists(OUTAGES_FILE)) return;

        $fp = @fopen(OUTAGES_FILE, 'r+');
        if (!$fp) return;

        if (@flock($fp, LOCK_EX)) {
            $rows = [];
            while (($row = fgetcsv($fp)) !== false) {
                $rows[] = $row;
            }

            // Find ongoing outage for this ISP from end to beginning
            for ($i = count($rows) - 1; $i >= 0; $i--) {
                if (isset($rows[$i][1]) && $rows[$i][1] === $ispId && isset($rows[$i][8]) && $rows[$i][8] === 'ONGOING') {
                    $rows[$i][5] = $upTimestamp;
                    $rows[$i][6] = $upDatetime;
                    $downTime = intval($rows[$i][3]);
                    $actualDuration = max(0, $upTimestamp - $downTime);
                    $rows[$i][7] = $actualDuration;
                    $rows[$i][8] = 'RESOLVED';
                    break;
                }
            }

            // Rewrite file
            ftruncate($fp, 0);
            rewind($fp);
            foreach ($rows as $r) {
                fputcsv($fp, $r);
            }

            @flock($fp, LOCK_UN);
        }
        @fclose($fp);
    }

    private function handleClientOutageReport($data, $meta) {
        $ispId = $meta['isp_id'] ?? ($data['isp_id'] ?? null);
        $ispName = $meta['isp_name'] ?? ($data['isp_name'] ?? $ispId);
        $downTime = intval($data['down_timestamp'] ?? 0);
        $upTime = intval($data['up_timestamp'] ?? 0);
        $duration = max(0, $upTime - $downTime);
        $reason = $data['reason'] ?? 'Local network outage reported by client';

        if ($downTime > 0 && $upTime > $downTime) {
            if (!file_exists(OUTAGES_FILE)) return;

            $fp = @fopen(OUTAGES_FILE, 'r+');
            if (!$fp) return;

            if (@flock($fp, LOCK_EX)) {
                $rows = [];
                while (($row = fgetcsv($fp)) !== false) {
                    $rows[] = $row;
                }

                // Check if the most recent outage for this ISP overlaps or was just resolved within 60s
                $merged = false;
                for ($i = count($rows) - 1; $i >= 0; $i--) {
                    if (isset($rows[$i][1]) && $rows[$i][1] === $ispId) {
                        $existingDown = intval($rows[$i][3]);
                        $existingUp = !empty($rows[$i][5]) ? intval($rows[$i][5]) : time();

                        // Overlap or close window match (within 60s of reconnection)
                        if (abs($existingUp - $upTime) <= 60 || ($downTime <= ($existingUp + 5) && $upTime >= ($existingDown - 5))) {
                            $earliestDown = min($existingDown, $downTime);
                            $latestUp = max($existingUp, $upTime);
                            $rows[$i][3] = $earliestDown;
                            $rows[$i][4] = date('Y-m-d H:i:s', $earliestDown);
                            $rows[$i][5] = $latestUp;
                            $rows[$i][6] = date('Y-m-d H:i:s', $latestUp);
                            $rows[$i][7] = max(0, $latestUp - $earliestDown);
                            $rows[$i][8] = 'RESOLVED';
                            $rows[$i][9] = str_replace([",", "\n", "\r"], " ", $reason);
                            $merged = true;
                            $this->log("Reconciled existing outage record {$rows[$i][0]} for {$ispId}: adjusted duration to " . $this->formatDuration($rows[$i][7]));
                            break;
                        }
                    }
                }

                if (!$merged) {
                    $id = time() . '_' . substr(md5(uniqid('', true)), 0, 4);
                    $rows[] = [
                        $id,
                        $ispId,
                        $ispName,
                        $downTime,
                        date('Y-m-d H:i:s', $downTime),
                        $upTime,
                        date('Y-m-d H:i:s', $upTime),
                        $duration,
                        'RESOLVED',
                        str_replace([",", "\n", "\r"], " ", $reason)
                    ];
                    $this->log("Recorded client-reported historical outage for {$ispId}: " . $this->formatDuration($duration));
                }

                ftruncate($fp, 0);
                rewind($fp);
                foreach ($rows as $r) {
                    fputcsv($fp, $r);
                }

                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }
    }

    private function appendEventLog($timestamp, $datetime, $ispId, $ispName, $eventType, $durationSec, $details) {
        $row = [
            $timestamp,
            $datetime,
            $ispId,
            $ispName,
            $eventType,
            $durationSec,
            str_replace([",", "\n", "\r"], " ", $details)
        ];

        $fp = @fopen(EVENTS_FILE, 'a');
        if ($fp && @flock($fp, LOCK_EX)) {
            fputcsv($fp, $row);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    private function formatDuration($seconds) {
        $seconds = intval($seconds);
        if ($seconds < 60) return "{$seconds}s";
        $mins = floor($seconds / 60);
        $secs = $seconds % 60;
        if ($mins < 60) return "{$mins}m {$secs}s";
        $hours = floor($mins / 60);
        $mins = $mins % 60;
        return "{$hours}h {$mins}m {$secs}s";
    }

    private function shutdown() {
        $this->log("Closing all client sockets...");
        foreach ($this->clients as $sockId => $sock) {
            if ($sock !== $this->masterSocket) {
                $this->sendFrame($sock, json_encode(['type' => 'server_shutdown']), 0x8);
                @fclose($sock);
            }
        }
        if ($this->masterSocket) {
            @fclose($this->masterSocket);
        }
        $this->log("WebSocket Server safely stopped.");
    }
}

// Instantiate and start
$server = new IspWebSocketServer();
$server->start();
