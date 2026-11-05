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
        $longestOutageSec = 0;
        $ispIntervals = [];

        // Load current live state to identify truly active / down ISPs vs abandoned ones
        $currentState = [];
        if (file_exists(STATE_FILE)) {
            $rawState = @file_get_contents(STATE_FILE);
            $decodedState = json_decode($rawState, true);
            if (is_array($decodedState)) {
                $currentState = $decodedState;
            }
        }

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

                    // Check status & whether outage is genuinely ongoing
                    if (empty($row[5]) || ($row[8] ?? '') === 'ONGOING') {
                        $isActuallyDown = isset($currentState[$ispId]) && (($currentState[$ispId]['status'] ?? '') === 'DOWN');
                        if ($isActuallyDown) {
                            $upTime = $now;
                            $upDatetime = 'Currently Down';
                            $status = 'ONGOING';
                            $reason = $row[9] ?? 'Connection lost';
                        } else {
                            // Abandoned test ISP or stale outage that never received an UP event
                            $upTime = max($downTime + 1, min($now, $downTime + 60));
                            $upDatetime = date('Y-m-d H:i:s', $upTime);
                            $status = 'RESOLVED';
                            $reason = ($row[9] ?? 'Connection lost') . ' (Stale/Transient)';
                        }
                    } else {
                        $upTime = intval($row[5]);
                        $upDatetime = $row[6] ?? date('Y-m-d H:i:s', $upTime);
                        $status = $row[8] ?? 'RESOLVED';
                        $reason = $row[9] ?? 'Connection lost';
                    }

                    // Apply ISP filter
                    if ($filterIsp !== 'all' && $filterIsp !== '' && $ispId !== $filterIsp) {
                        continue;
                    }

                    // Check for overlap with the query window [from, to]
                    $overlapStart = max($downTime, $from);
                    $overlapEnd = min($upTime, $to);

                    if ($overlapEnd > $overlapStart) {
                        $overlapDuration = $overlapEnd - $overlapStart;
                        $ispIntervals[$ispId][] = [$overlapStart, $overlapEnd];
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

        // Mathematically merge overlapping outage intervals to prevent double-counting
        $totalDowntimeSec = 0;
        foreach ($ispIntervals as $id => $intervals) {
            usort($intervals, function($a, $b) { return $a[0] - $b[0]; });
            $merged = [];
            foreach ($intervals as $iv) {
                if (empty($merged)) {
                    $merged[] = $iv;
                    continue;
                }
                $last = &$merged[count($merged) - 1];
                if ($iv[0] <= $last[1]) {
                    $last[1] = max($last[1], $iv[1]);
                } else {
                    $merged[] = $iv;
                }
            }
            foreach ($merged as $iv) {
                $totalDowntimeSec += ($iv[1] - $iv[0]);
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

    case 'delete_outage':
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing outage id']);
            break;
        }

        $deleted = false;
        if (file_exists(OUTAGES_FILE)) {
            $fp = fopen(OUTAGES_FILE, 'r+');
            if ($fp && flock($fp, LOCK_EX)) {
                $header = fgetcsv($fp);
                $rows = [];
                while (($row = fgetcsv($fp)) !== false) {
                    if (count($row) >= 1 && $row[0] === $id) {
                        $deleted = true;
                        continue;
                    }
                    $rows[] = $row;
                }
                ftruncate($fp, 0);
                rewind($fp);
                fputcsv($fp, $header);
                foreach ($rows as $r) {
                    fputcsv($fp, $r);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        echo json_encode([
            'status' => $deleted ? 'success' : 'error',
            'message' => $deleted ? "Outage {$id} successfully deleted." : "Outage {$id} not found."
        ]);
        break;

    case 'purge_isp':
        $ispId = trim($_REQUEST['isp_id'] ?? '');
        if (empty($ispId)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing isp_id']);
            break;
        }

        $outagesRemoved = 0;
        if (file_exists(OUTAGES_FILE)) {
            $fp = fopen(OUTAGES_FILE, 'r+');
            if ($fp && flock($fp, LOCK_EX)) {
                $header = fgetcsv($fp);
                $rows = [];
                while (($row = fgetcsv($fp)) !== false) {
                    if (isset($row[1]) && $row[1] === $ispId) {
                        $outagesRemoved++;
                        continue;
                    }
                    $rows[] = $row;
                }
                ftruncate($fp, 0);
                rewind($fp);
                fputcsv($fp, $header);
                foreach ($rows as $r) {
                    fputcsv($fp, $r);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        // Clean events.csv
        $eventsRemoved = 0;
        if (file_exists(EVENTS_FILE)) {
            $efp = fopen(EVENTS_FILE, 'r+');
            if ($efp && flock($efp, LOCK_EX)) {
                $eHeader = fgetcsv($efp);
                $eRows = [];
                while (($row = fgetcsv($efp)) !== false) {
                    if (isset($row[2]) && $row[2] === $ispId) {
                        $eventsRemoved++;
                        continue;
                    }
                    $eRows[] = $row;
                }
                ftruncate($efp, 0);
                rewind($efp);
                fputcsv($efp, $eHeader);
                foreach ($eRows as $r) {
                    fputcsv($efp, $r);
                }
                flock($efp, LOCK_UN);
                fclose($efp);
            }
        }

        // Clean state.json
        if (file_exists(STATE_FILE)) {
            $raw = @file_get_contents(STATE_FILE);
            $st = json_decode($raw, true);
            if (is_array($st) && isset($st[$ispId])) {
                unset($st[$ispId]);
                file_put_contents(STATE_FILE, json_encode($st, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => "Purged ISP '{$ispId}' ({$outagesRemoved} outage records and {$eventsRemoved} event logs removed)."
        ]);
        break;

    case 'clean_garbage':
        $minDuration = isset($_REQUEST['min_duration']) ? intval($_REQUEST['min_duration']) : 3;
        $stateIsps = [];
        $activeIsps = [];
        if (file_exists(STATE_FILE)) {
            $rawState = @file_get_contents(STATE_FILE);
            $decodedState = json_decode($rawState, true);
            if (is_array($decodedState)) {
                $stateIsps = $decodedState;
                foreach ($stateIsps as $id => $info) {
                    if (($info['status'] ?? '') === 'UP') {
                        $activeIsps[$id] = true;
                    }
                }
            }
        }

        $now = time();
        $removedCount = 0;
        $fixedCount = 0;
        $dedupCount = 0;

        if (file_exists(OUTAGES_FILE)) {
            $fp = fopen(OUTAGES_FILE, 'r+');
            if ($fp && flock($fp, LOCK_EX)) {
                $header = fgetcsv($fp);
                $rows = [];
                while (($row = fgetcsv($fp)) !== false) {
                    if (count($row) >= 5) $rows[] = $row;
                }

                $kept = [];
                foreach ($rows as $r) {
                    $id = $r[0];
                    $ispId = $r[1];
                    $downTime = intval($r[3]);
                    $upTime = !empty($r[5]) ? intval($r[5]) : null;
                    $dur = intval($r[7] ?? 0);
                    $status = $r[8] ?? ($upTime ? 'RESOLVED' : 'ONGOING');

                    // 1. Remove obsolete test ISP 'isp_1' or completely untracked ISPs
                    if ($ispId === 'isp_1' || (!isset($stateIsps[$ispId]) && empty($activeIsps[$ispId]))) {
                        $removedCount++;
                        continue;
                    }

                    // 2. Fix stuck ongoing outages for ISPs that are actually UP
                    if ($status === 'ONGOING' || empty($upTime)) {
                        $isDown = isset($stateIsps[$ispId]) && ($stateIsps[$ispId]['status'] ?? '') === 'DOWN';
                        if (!$isDown) {
                            $resolvedUp = max($downTime + 1, min($now, $downTime + 60));
                            $r[5] = $resolvedUp;
                            $r[6] = date('Y-m-d H:i:s', $resolvedUp);
                            $r[7] = $resolvedUp - $downTime;
                            $r[8] = 'RESOLVED';
                            $r[9] = ($r[9] ?? '') . ' (Auto-closed stale outage)';
                            $dur = $r[7];
                            $upTime = $resolvedUp;
                            $fixedCount++;
                        }
                    }

                    // 3. Remove short noise glitch outages (< minDuration)
                    if ($minDuration > 0 && $dur > 0 && $dur < $minDuration && $status === 'RESOLVED') {
                        $removedCount++;
                        continue;
                    }

                    $kept[] = $r;
                }

                // 4. Merge overlapping/duplicate records for the same ISP
                $byIsp = [];
                foreach ($kept as $r) {
                    $byIsp[$r[1]][] = $r;
                }

                $finalRows = [];
                foreach ($byIsp as $ispId => $ispOutages) {
                    usort($ispOutages, function($a, $b) { return intval($a[3]) - intval($b[3]); });
                    $merged = [];
                    foreach ($ispOutages as $curr) {
                        if (empty($merged)) {
                            $merged[] = $curr;
                            continue;
                        }
                        $prev = &$merged[count($merged) - 1];
                        $prevUp = !empty($prev[5]) ? intval($prev[5]) : $now;
                        $currDown = intval($curr[3]);
                        $currUp = !empty($curr[5]) ? intval($curr[5]) : $now;

                        // If current overlaps or is within 5 seconds of previous
                        if ($currDown <= ($prevUp + 5)) {
                            $newDown = min(intval($prev[3]), $currDown);
                            $newUp = max($prevUp, $currUp);
                            $prev[3] = $newDown;
                            $prev[4] = date('Y-m-d H:i:s', $newDown);
                            $prev[5] = $newUp;
                            $prev[6] = date('Y-m-d H:i:s', $newUp);
                            $prev[7] = max(0, $newUp - $newDown);
                            $prev[8] = ($prev[8] === 'ONGOING' || $curr[8] === 'ONGOING') ? 'ONGOING' : 'RESOLVED';
                            $dedupCount++;
                        } else {
                            $merged[] = $curr;
                        }
                    }
                    foreach ($merged as $m) $finalRows[] = $m;
                }

                ftruncate($fp, 0);
                rewind($fp);
                fputcsv($fp, $header);
                foreach ($finalRows as $r) fputcsv($fp, $r);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        // Clean obsolete isp_1 from events
        if (file_exists(EVENTS_FILE)) {
            $efp = fopen(EVENTS_FILE, 'r+');
            if ($efp && flock($efp, LOCK_EX)) {
                $eHeader = fgetcsv($efp);
                $eRows = [];
                while (($row = fgetcsv($efp)) !== false) {
                    if (isset($row[2]) && ($row[2] === 'isp_1' || (!isset($stateIsps[$row[2]]) && empty($activeIsps[$row[2]])))) {
                        continue;
                    }
                    $eRows[] = $row;
                }
                ftruncate($efp, 0);
                rewind($efp);
                fputcsv($efp, $eHeader);
                foreach ($eRows as $r) fputcsv($efp, $r);
                flock($efp, LOCK_UN);
                fclose($efp);
            }
        }

        // Clean obsolete isp_1 from state
        if (file_exists(STATE_FILE)) {
            $raw = @file_get_contents(STATE_FILE);
            $st = json_decode($raw, true);
            if (is_array($st) && isset($st['isp_1'])) {
                unset($st['isp_1']);
                file_put_contents(STATE_FILE, json_encode($st, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Cleaned garbage records successfully.',
            'stats' => [
                'records_removed' => $removedCount,
                'stuck_outages_closed' => $fixedCount,
                'duplicates_merged' => $dedupCount
            ]
        ]);
        break;

    case 'reset_all':
        $outageHeader = "id,isp_id,isp_name,down_timestamp,down_datetime,up_timestamp,up_datetime,duration_seconds,status,reason\n";
        file_put_contents(OUTAGES_FILE, $outageHeader, LOCK_EX);

        $eventHeader = "timestamp,datetime,isp_id,isp_name,event_type,duration_seconds,details\n";
        file_put_contents(EVENTS_FILE, $eventHeader, LOCK_EX);

        file_put_contents(STATE_FILE, json_encode(new stdClass(), JSON_PRETTY_PRINT), LOCK_EX);

        echo json_encode([
            'status' => 'success',
            'message' => 'All outage and event data has been reset to factory state.'
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => "Unknown action '{$action}'"]);
        break;
}
