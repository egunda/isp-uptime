<?php
/**
 * ISP Monitor - Data Cleaning & Maintenance CLI Tool
 *
 * Usage:
 *   php clean.php --auto                 # Recommended: purge orphaned ISPs, close stuck outages, remove noise (<3s)
 *   php clean.php --purge-isp=isp_1      # Purge all history for a specific obsolete/test ISP
 *   php clean.php --min-duration=5       # Remove all outages shorter than 5 seconds (restart glitches)
 *   php clean.php --fix-stale            # Close stuck 'ONGOING' outages whose ISPs are not currently down
 *   php clean.php --dedup                # Deduplicate overlapping/duplicate outage intervals
 *   php clean.php --reset                # Factory reset: Wipe all outages and event logs (start fresh)
 *   php clean.php --dry-run              # Preview changes without modifying files
 */

if (php_sapi_name() !== 'cli' && empty($_GET['web_auth'])) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/config.php';
init_data_storage();

// Parse CLI options
$options = getopt('', [
    'auto',
    'purge-isp:',
    'min-duration:',
    'fix-stale',
    'dedup',
    'reset',
    'dry-run',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
ISP Downtime Monitor - Data Cleaner Tool
========================================
Usage: php clean.php [OPTIONS]

Options:
  --auto                Run all safe cleanups: purge inactive ISPs, resolve stuck outages,
                        deduplicate overlapping records, and prune noise < 3s.
  --purge-isp=<id>      Completely remove an ISP ID (e.g. 'isp_1') from outages, events & state.
  --min-duration=<sec>  Remove outages with duration < N seconds (e.g. 3 or 5 for restart spikes).
  --fix-stale           Close orphaned ONGOING outages that never received an UP event.
  --dedup               Merge/remove duplicate and overlapping outage intervals for each ISP.
  --reset               WIPE ALL DATA: Empty outages.csv and events.csv for a completely fresh start.
  --dry-run             Simulate the cleanup without saving changes to disk.
  --help                Show this help message.

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);

function getActiveIsps() {
    $active = [];
    if (file_exists(STATE_FILE)) {
        $raw = @file_get_contents(STATE_FILE);
        $state = json_decode($raw, true);
        if (is_array($state)) {
            foreach ($state as $id => $info) {
                if (($info['status'] ?? '') === 'UP') {
                    $active[$id] = $info;
                }
            }
        }
    }
    return $active;
}

function getAllStateIsps() {
    $all = [];
    if (file_exists(STATE_FILE)) {
        $raw = @file_get_contents(STATE_FILE);
        $state = json_decode($raw, true);
        if (is_array($state)) {
            $all = $state;
        }
    }
    return $all;
}

// 1. Factory Reset
if (isset($options['reset'])) {
    echo "[!] FACTORY RESET: Wiping all outages, events, and state...\n";
    if (!$dryRun) {
        $outageHeader = "id,isp_id,isp_name,down_timestamp,down_datetime,up_timestamp,up_datetime,duration_seconds,status,reason\n";
        file_put_contents(OUTAGES_FILE, $outageHeader, LOCK_EX);

        $eventHeader = "timestamp,datetime,isp_id,isp_name,event_type,duration_seconds,details\n";
        file_put_contents(EVENTS_FILE, $eventHeader, LOCK_EX);

        file_put_contents(STATE_FILE, json_encode(new stdClass(), JSON_PRETTY_PRINT), LOCK_EX);
        echo "[OK] Successfully reset all plain text storage files. Ready for fresh monitoring.\n";
    } else {
        echo "[DRY-RUN] Would clear outages.csv, events.csv, and state.json.\n";
    }
    exit(0);
}

// 2. Read Outages
if (!file_exists(OUTAGES_FILE)) {
    echo "[ERROR] Outages file does not exist at " . OUTAGES_FILE . "\n";
    exit(1);
}

$fp = fopen(OUTAGES_FILE, 'r+');
if (!$fp || !flock($fp, LOCK_EX)) {
    echo "[ERROR] Could not obtain lock on " . OUTAGES_FILE . "\n";
    exit(1);
}

$header = fgetcsv($fp);
$rows = [];
while (($row = fgetcsv($fp)) !== false) {
    if (count($row) >= 5) {
        $rows[] = $row;
    }
}

$initialCount = count($rows);
$activeIsps = getActiveIsps();
$stateIsps = getAllStateIsps();
$now = time();

$isAuto = isset($options['auto']);
$purgeIsp = $options['purge-isp'] ?? null;
$minDuration = isset($options['min-duration']) ? intval($options['min-duration']) : ($isAuto ? 3 : null);
$fixStale = isset($options['fix-stale']) || $isAuto;
$dedup = isset($options['dedup']) || $isAuto;

$removedCount = 0;
$fixedCount = 0;
$dedupCount = 0;
$keptRows = [];

// Determine obsolete ISPs if auto mode:
// Any ISP that has no active socket or is not registered in state.json but has records
$autoPurgeIsps = [];
if ($isAuto) {
    foreach ($rows as $r) {
        $ispId = $r[1];
        // If ISP is 'isp_1' (default boilerplate) or never in state.json
        if ($ispId === 'isp_1' || (!isset($stateIsps[$ispId]) && !isset($activeIsps[$ispId]))) {
            $autoPurgeIsps[$ispId] = true;
        }
    }
}

foreach ($rows as $row) {
    $id = $row[0];
    $ispId = $row[1];
    $ispName = $row[2] ?? $ispId;
    $downTime = intval($row[3]);
    $upTime = !empty($row[5]) ? intval($row[5]) : null;
    $duration = intval($row[7] ?? 0);
    $status = $row[8] ?? ($upTime ? 'RESOLVED' : 'ONGOING');

    // Filter by specific purge-isp
    if ($purgeIsp && $ispId === $purgeIsp) {
        $removedCount++;
        echo "  - Removed record {$id} for purged ISP '{$ispId}'\n";
        continue;
    }

    // Filter obsolete ISPs in auto mode
    if ($isAuto && isset($autoPurgeIsps[$ispId])) {
        $removedCount++;
        echo "  - Removed orphaned record {$id} for obsolete ISP '{$ispId}'\n";
        continue;
    }

    // Fix stuck ONGOING outages
    if ($status === 'ONGOING' || empty($upTime)) {
        $isCurrentlyDown = isset($stateIsps[$ispId]) && ($stateIsps[$ispId]['status'] ?? '') === 'DOWN';
        if (!$isCurrentlyDown) {
            if ($fixStale) {
                // If it was just a transient disconnect that lost its UP packet, close it at downTime + 30s or last seen
                $resolvedUp = max($downTime + 1, min($now, $downTime + 60));
                $row[5] = $resolvedUp;
                $row[6] = date('Y-m-d H:i:s', $resolvedUp);
                $row[7] = $resolvedUp - $downTime;
                $row[8] = 'RESOLVED';
                $row[9] = ($row[9] ?? '') . ' (Auto-closed stale outage)';
                $duration = $row[7];
                $upTime = $resolvedUp;
                $fixedCount++;
                echo "  ~ Closed stuck ONGOING outage {$id} for '{$ispId}' (downtime capped to {$row[7]}s)\n";
            }
        }
    }

    // Filter short noise outages (< minDuration)
    if ($minDuration !== null && $duration > 0 && $duration < $minDuration && $status === 'RESOLVED') {
        $removedCount++;
        echo "  - Pruned short glitch outage {$id} ({$duration}s < {$minDuration}s) for '{$ispId}'\n";
        continue;
    }

    $keptRows[] = $row;
}

// Deduplicate / Merge overlapping intervals for the same ISP
if ($dedup && count($keptRows) > 1) {
    // Group by ISP
    $byIsp = [];
    foreach ($keptRows as $r) {
        $byIsp[$r[1]][] = $r;
    }

    $finalRows = [];
    foreach ($byIsp as $ispId => $ispOutages) {
        // Sort chronologically
        usort($ispOutages, function($a, $b) {
            return intval($a[3]) - intval($b[3]);
        });

        $mergedForIsp = [];
        foreach ($ispOutages as $curr) {
            if (empty($mergedForIsp)) {
                $mergedForIsp[] = $curr;
                continue;
            }

            $lastIdx = count($mergedForIsp) - 1;
            $prev = &$mergedForIsp[$lastIdx];

            $prevDown = intval($prev[3]);
            $prevUp = !empty($prev[5]) ? intval($prev[5]) : $now;
            $currDown = intval($curr[3]);
            $currUp = !empty($curr[5]) ? intval($curr[5]) : $now;

            // Check if current overlaps or is within 5 seconds of previous
            if ($currDown <= ($prevUp + 5)) {
                // Merge into prev
                $newDown = min($prevDown, $currDown);
                $newUp = max($prevUp, $currUp);
                $prev[3] = $newDown;
                $prev[4] = date('Y-m-d H:i:s', $newDown);
                $prev[5] = $newUp;
                $prev[6] = date('Y-m-d H:i:s', $newUp);
                $prev[7] = max(0, $newUp - $newDown);
                $prev[8] = ($prev[8] === 'ONGOING' || $curr[8] === 'ONGOING') ? 'ONGOING' : 'RESOLVED';
                $dedupCount++;
                echo "  ~ Merged overlapping outage {$curr[0]} into {$prev[0]} for '{$ispId}'\n";
            } else {
                $mergedForIsp[] = $curr;
            }
        }

        foreach ($mergedForIsp as $m) {
            $finalRows[] = $m;
        }
    }
    $keptRows = $finalRows;
}

// Write back to outages.csv
if (!$dryRun) {
    ftruncate($fp, 0);
    rewind($fp);
    fputcsv($fp, $header);
    foreach ($keptRows as $r) {
        fputcsv($fp, $r);
    }
}
flock($fp, LOCK_UN);
fclose($fp);

// Also clean events.csv if purging a specific ISP or auto-cleaning
if ($purgeIsp || !empty($autoPurgeIsps)) {
    $purgedIds = $purgeIsp ? [$purgeIsp => true] : $autoPurgeIsps;
    if (file_exists(EVENTS_FILE)) {
        $efp = fopen(EVENTS_FILE, 'r+');
        if ($efp && flock($efp, LOCK_EX)) {
            $eHeader = fgetcsv($efp);
            $eRows = [];
            $eventsRemoved = 0;
            while (($row = fgetcsv($efp)) !== false) {
                if (count($row) >= 4) {
                    if (isset($purgedIds[$row[2]])) {
                        $eventsRemoved++;
                        continue;
                    }
                    $eRows[] = $row;
                }
            }
            if (!$dryRun) {
                ftruncate($efp, 0);
                rewind($efp);
                fputcsv($efp, $eHeader);
                foreach ($eRows as $r) {
                    fputcsv($efp, $r);
                }
            }
            flock($efp, LOCK_UN);
            fclose($efp);
            echo "[OK] Cleaned {$eventsRemoved} event logs for obsolete ISPs.\n";
        }
    }

    // Clean state.json
    if (file_exists(STATE_FILE)) {
        $raw = @file_get_contents(STATE_FILE);
        $state = json_decode($raw, true);
        if (is_array($state)) {
            $stateModified = false;
            foreach ($purgedIds as $pId => $true) {
                if (isset($state[$pId])) {
                    unset($state[$pId]);
                    $stateModified = true;
                }
            }
            if ($stateModified && !$dryRun) {
                file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
                echo "[OK] Removed obsolete ISPs from state.json.\n";
            }
        }
    }
}

echo "\n===========================================================\n";
echo "   Cleanup Summary " . ($dryRun ? "(DRY RUN - No files changed)" : "(COMPLETED)") . "\n";
echo "===========================================================\n";
echo "  Total records before: {$initialCount}\n";
echo "  Garbage records removed: {$removedCount}\n";
echo "  Stuck outages closed:   {$fixedCount}\n";
echo "  Duplicates merged:       {$dedupCount}\n";
echo "  Clean records remaining: " . count($keptRows) . "\n";
echo "===========================================================\n";
