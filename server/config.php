<?php
/**
 * ISP Monitor - Server Configuration
 * Plain text file-based monitoring system
 */

// WebSocket Server Configuration
define('WS_HOST', '0.0.0.0');
define('WS_PORT', 8080);

// Heartbeat & Timeout Settings
// If no heartbeat is received within this threshold (seconds), mark ISP as DOWN
define('HEARTBEAT_TIMEOUT_SEC', 10);

// Disconnect Grace Period (seconds)
// When a client disconnects unexpectedly, wait this many seconds for self-recovery
// before triggering a DOWN event and creating an outage record. Prevents false alarms.
define('DISCONNECT_GRACE_SEC', 5);

// Data Directory (Plain text storage - NO DATABASE)
define('DATA_DIR', __DIR__ . '/data');
define('EVENTS_FILE', DATA_DIR . '/events.csv');
define('OUTAGES_FILE', DATA_DIR . '/outages.csv');
define('STATE_FILE', DATA_DIR . '/state.json');

// Optional Security / Authentication Token
// Set to a string to require clients to match this token, or null/empty to allow any client
define('AUTH_TOKEN', '');

// Default Timezone
date_default_timezone_set('Asia/Kolkata');

// Helper function to ensure data files and directories exist
function init_data_storage() {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0777, true);
        @chmod(DATA_DIR, 0777);
    }

    // Initialize events.csv if not exists
    if (!file_exists(EVENTS_FILE)) {
        $header = "timestamp,datetime,isp_id,isp_name,event_type,duration_seconds,details\n";
        @file_put_contents(EVENTS_FILE, $header, LOCK_EX);
        @chmod(EVENTS_FILE, 0666);
    }

    // Initialize outages.csv if not exists
    if (!file_exists(OUTAGES_FILE)) {
        $header = "id,isp_id,isp_name,down_timestamp,down_datetime,up_timestamp,up_datetime,duration_seconds,status,reason\n";
        @file_put_contents(OUTAGES_FILE, $header, LOCK_EX);
        @chmod(OUTAGES_FILE, 0666);
    }

    // Initialize state.json if not exists
    if (!file_exists(STATE_FILE)) {
        @file_put_contents(STATE_FILE, json_encode(new stdClass(), JSON_PRETTY_PRINT), LOCK_EX);
        @chmod(STATE_FILE, 0666);
    }
}
