<?php
/**
 * ISP Monitor - REST API Endpoint
 * Parses plain text files (CSV/JSON) and computes downtime and availability statistics.
 * No database required.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/../config.php';
init_data_storage();

$action = $_GET['action'] ?? 'get_stats';

function formatDuration($seconds) {
    $seconds = max(0, intval($seconds));
    if ($seconds === 0) return "0s";
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $mins = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($mins > 0) $parts[] = "{$mins}m";
    if ($secs > 0 || empty($parts)) $parts[] = "{$secs}s";

    return implode(' ', $parts);
}

function parseTimestamp($input, $default) {
    if (empty($input)) return $default;
    if (is_numeric($input)) return intval($input);
    $parsed = strtotime($input);
    return $parsed !== false ? $parsed : $default;
}

switch ($action) {
    case 'get_state':
        $state = [];
        if (file_exists(STATE_FILE)) {
            $raw = @file_get_contents(STATE_FILE);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        // Add real-time duration to state
        $now = time();
        foreach ($state as $id => &$info) {
            $since = $info['current_state_since'] ?? $now;
            $info['current_streak_seconds'] = max(0, $now - $since);
            $info['current_streak_formatted'] = formatDuration($now - $since);

            // Also check if timeout exceeded without server daemon updating
            if ($info['status'] === 'UP' && ($now - ($info['last_seen'] ?? 0)) > HEARTBEAT_TIMEOUT_SEC) {
                $info['status'] = 'DOWN';
                $info['notes'] = 'Heartbeat missed';
            }
        }

        echo json_encode(['status' => 'success', 'data' => $state, 'server_time' => $now]);
        break;

    case 'get_isps':
        $isps = [];
        if (file_exists(STATE_FILE)) {
            $raw = @file_get_contents(STATE_FILE);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $val) {
                    $isps[$id] = $val['isp_name'] ?? $id;
                }
            }
        }
        // Also check outages.csv for historical ISPs
        if (file_exists(OUTAGES_FILE)) {
            if (($fp = fopen(OUTAGES_FILE, 'r')) !== false) {
                $header = fgetcsv($fp);
                while (($row = fgetcsv($fp)) !== false) {
                    if (isset($row[1]) && !empty($row[1])) {
                        $id = $row[1];
                        $name = $row[2] ?? $id;
                        if (!isset($isps[$id])) {
                            $isps[$id] = $name;
                        }
                    }
                }
                fclose($fp);
            }
        }

        echo json_encode(['status' => 'success', 'data' => $isps]);
        break;

    case 'get_stats':
        $now = time();
        // Default time window: past 24 hours
        $defaultFrom = $now - (24 * 3600);
        $from = parseTimestamp($_GET['from'] ?? null, $defaultFrom);
        $to = parseTimestamp($_GET['to'] ?? null, $now);
        $filterIsp = trim($_GET['isp_id'] ?? 'all');

        if ($from >= $to) {
            $from = $to - 3600; // Minimum 1 hour fallback
        }

        $windowSeconds = max(1, $to - $from);
        $outages = [];
        $totalDowntimeSec = 0;
        $longestOutageSec = 0;

        if (file_exists(OUTAGES_FILE)) {
            if (($fp = fopen(OUTAGES_FILE, 'r')) !== false) {
                $header = fgetcsv($fp); // skip header
                while (($row = fgetcsv($fp)) !== false) {
                    if (count($row) < 5) continue;

                    $id = $row[0];
                    $ispId = $row[1];
                    $ispName = $row[2] ?? $ispId;
                    $downTime = intval($row[3]);
                    $downDatetime = $row[4];
                    $upTime = !empty($row[5]) ? intval($row[5]) : $now;
                    $upDatetime = !empty($row[6]) ? $row[6] : 'Currently Down';
                    $status = $row[8] ?? ($upTime === $now ? 'ONGOING' : 'RESOLVED');
                    $reason = $row[9] ?? 'Connection lost';

                    // Apply ISP filter
                    if ($filterIsp !== 'all' && $filterIsp !== '' && $ispId !== $filterIsp) {
                        continue;
                    }

                    // Check for overlap with the query window [from, to]
                    $overlapStart = max($downTime, $from);
                    $overlapEnd = min($upTime, $to);

                    if ($overlapEnd > $overlapStart) {
                        $overlapDuration = $overlapEnd - $overlapStart;
                        $totalDowntimeSec += $overlapDuration;
                        $fullDuration = max(0, $upTime - $downTime);

                        if ($fullDuration > $longestOutageSec) {
                            $longestOutageSec = $fullDuration;
                        }

                        $outages[] = [
                            'id' => $id,
                            'isp_id' => $ispId,
                            'isp_name' => $ispName,
                            'down_timestamp' => $downTime,
                            'down_datetime' => $downDatetime,
                            'up_timestamp' => $upTime,
                            'up_datetime' => $upDatetime,
                            'full_duration_seconds' => $fullDuration,
                            'full_duration_formatted' => formatDuration($fullDuration),
                            'window_downtime_seconds' => $overlapDuration,
                            'window_downtime_formatted' => formatDuration($overlapDuration),
                            'status' => $status,
                            'reason' => $reason
                        ];
                    }
                }
                fclose($fp);
            }
        }

        // Reverse to show most recent outages first
        usort($outages, function($a, $b) {
            return $b['down_timestamp'] - $a['down_timestamp'];
        });

        // Compute aggregate metrics
        $outageCount = count($outages);
        $uptimeSec = max(0, $windowSeconds - $totalDowntimeSec);
        $availabilityPct = round(($uptimeSec / $windowSeconds) * 100, 3);
        if ($availabilityPct > 100) $availabilityPct = 100.0;
        $avgOutageSec = $outageCount > 0 ? round($totalDowntimeSec / $outageCount) : 0;

        echo json_encode([
            'status' => 'success',
            'query' => [
                'from_timestamp' => $from,
                'from_datetime' => date('Y-m-d H:i:s', $from),
                'to_timestamp' => $to,
                'to_datetime' => date('Y-m-d H:i:s', $to),
                'window_seconds' => $windowSeconds,
                'window_formatted' => formatDuration($windowSeconds),
                'isp_id' => $filterIsp
            ],
            'summary' => [
                'total_downtime_seconds' => $totalDowntimeSec,
                'total_downtime_formatted' => formatDuration($totalDowntimeSec),
                'uptime_seconds' => $uptimeSec,
                'uptime_formatted' => formatDuration($uptimeSec),
                'availability_percentage' => $availabilityPct,
                'outage_count' => $outageCount,
                'longest_outage_seconds' => $longestOutageSec,
                'longest_outage_formatted' => formatDuration($longestOutageSec),
                'average_outage_seconds' => $avgOutageSec,
                'average_outage_formatted' => formatDuration($avgOutageSec)
            ],
            'outages' => $outages
        ]);
        break;

    case 'get_events':
        $limit = intval($_GET['limit'] ?? 100);
        $filterIsp = trim($_GET['isp_id'] ?? 'all');
        $events = [];

        if (file_exists(EVENTS_FILE)) {
            if (($fp = fopen(EVENTS_FILE, 'r')) !== false) {
                $header = fgetcsv($fp);
                while (($row = fgetcsv($fp)) !== false) {
                    if (count($row) < 5) continue;
                    $ispId = $row[2];
                    if ($filterIsp !== 'all' && $filterIsp !== '' && $ispId !== $filterIsp) {
                        continue;
                    }
                    $events[] = [
                        'timestamp' => intval($row[0]),
                        'datetime' => $row[1],
                        'isp_id' => $row[2],
                        'isp_name' => $row[3],
                        'event_type' => $row[4],
                        'duration_seconds' => intval($row[5] ?? 0),
                        'duration_formatted' => formatDuration(intval($row[5] ?? 0)),
                        'details' => $row[6] ?? ''
                    ];
                }
                fclose($fp);
            }
        }

        // Return latest events first
        $events = array_reverse($events);
        if ($limit > 0 && count($events) > $limit) {
            $events = array_slice($events, 0, $limit);
        }

        echo json_encode(['status' => 'success', 'data' => $events]);
        break;

    case 'export_csv':
        $from = parseTimestamp($_GET['from'] ?? null, time() - (7 * 86400));
        $to = parseTimestamp($_GET['to'] ?? null, time());
        $filterIsp = trim($_GET['isp_id'] ?? 'all');

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="isp_outages_export_' . date('Ymd_His') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Outage ID', 'ISP ID', 'ISP Name', 'Down Timestamp', 'Down Datetime', 'Up Timestamp', 'Up Datetime', 'Duration (Seconds)', 'Duration (Formatted)', 'Status', 'Reason']);

        if (file_exists(OUTAGES_FILE)) {
            if (($fp = fopen(OUTAGES_FILE, 'r')) !== false) {
                fgetcsv($fp); // skip header
                while (($row = fgetcsv($fp)) !== false) {
                    if (count($row) < 5) continue;
                    $ispId = $row[1];
                    $downTime = intval($row[3]);
                    $upTime = !empty($row[5]) ? intval($row[5]) : time();

                    if ($filterIsp !== 'all' && $filterIsp !== '' && $ispId !== $filterIsp) {
                        continue;
                    }

                    if ($upTime >= $from && $downTime <= $to) {
                        $duration = max(0, $upTime - $downTime);
                        $rowExport = [
                            $row[0],
                            $row[1],
                            $row[2] ?? $row[1],
                            $row[3],
                            $row[4],
                            $row[5],
                            $row[6],
                            $duration,
                            formatDuration($duration),
                            $row[8] ?? '',
                            $row[9] ?? ''
                        ];
                        fputcsv($output, $rowExport);
                    }
                }
                fclose($fp);
            }
        }
        fclose($output);
        exit;

    default:
        echo json_encode(['status' => 'error', 'message' => "Unknown action '{$action}'"]);
        break;
}
