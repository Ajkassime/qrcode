<?php
/**
 * CLI Tool: Safe Historical Statistics Geolocation Repair
 *
 * Reprocesses historical stats records that have missing/unknown cities ONLY when
 * the raw client IP is legitimately and legally stored (i.e. unhashed).
 *
 * Strict Security Rules:
 *   - NEVER reconstructs or guesses cities from hashed IPs (MD5/SHA).
 *   - Runs in DRY-RUN mode by default unless --apply is explicitly specified.
 *
 * Usage:
 *   php scripts/geo-repair.php               # Dry-run simulation (default)
 *   php scripts/geo-repair.php --apply       # Apply updates to database
 *   php scripts/geo-repair.php --limit=500   # Process up to N records
 */

require_once dirname(__DIR__) . "/tests/bootstrap.php";
require_once APP . "/helpers/GeoLocation.php";

if (file_exists(ROOT . "/config.php")) {
    @include_once ROOT . "/config.php";
}

$args = array_slice($argv, 1);
$isApply = in_array('--apply', $args);
$limit = 1000;

foreach ($args as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, (int) substr($arg, 8));
    }
}

echo "======================================================================\n";
echo "       MISSION CODEX — HISTORICAL GEO REPAIR TOOL                     \n";
echo "======================================================================\n";
echo "Mode: " . ($isApply ? "LIVE (Applying changes to database)" : "DRY-RUN (Simulation only, no DB writes)") . "\n";
echo "Limit: {$limit} records\n\n";

$runOfflineSimulation = false;
if (!defined('DBhost') || empty(constant('DBhost'))) {
    $runOfflineSimulation = true;
    echo "Notice: DBhost not configured. Running in offline simulation mode.\n";
} else {
    try {
        \Core\DB::Connect();
        // Ping connection
        \Core\DB::forTable('stats')->rawQuery("SELECT 1")->findArray();
    } catch (\Throwable $e) {
        $runOfflineSimulation = true;
        echo "Notice: Database connection unavailable (" . $e->getMessage() . "). Running in offline simulation mode.\n";
    }
}

if ($runOfflineSimulation) {
    echo "Example test run on historical sample values:\n\n";

    $sampleRecords = [
        ['id' => 101, 'ip' => '102.68.192.228', 'country' => 'Madagascar', 'city' => '', 'region' => ''],
        ['id' => 102, 'ip' => '196.192.32.1',   'country' => 'Madagascar', 'city' => 'Unknown', 'region' => ''],
        ['id' => 103, 'ip' => 'd41d8cd98f00b204e9800998ecf8427e', 'country' => 'Madagascar', 'city' => '', 'region' => ''], // Hashed
        ['id' => 104, 'ip' => '192.168.1.50',    'country' => 'Madagascar', 'city' => '', 'region' => ''], // Private
    ];

    $stats = [
        'inspected'       => count($sampleRecords),
        'hashed_skipped'  => 0,
        'private_skipped' => 0,
        'city_resolved'   => 0,
        'region_resolved' => 0,
        'undetermined'    => 0,
    ];

    foreach ($sampleRecords as $rec) {
        $ip = trim((string) $rec['ip']);
        echo "[ID {$rec['id']}] IP: " . (strlen($ip) === 32 ? substr($ip, 0, 8) . "..." : $ip) . " | ";

        if (preg_match('/^[a-f0-9]{32}$/i', $ip)) {
            $stats['hashed_skipped']++;
            echo "SKIPPED: Hashed IP (irreversible per privacy rules)\n";
            continue;
        }

        if (!\Helpers\GeoLocation::isPublicIp($ip)) {
            $stats['private_skipped']++;
            echo "SKIPPED: Private/Reserved IP\n";
            continue;
        }

        $geo = \Helpers\GeoLocation::locate($ip);
        if (!empty($geo['city'])) {
            $stats['city_resolved']++;
            echo "REPAIRED: City identified -> '{$geo['city']}', Region: '" . ($geo['region'] ?: 'N/A') . "', Country: '{$geo['country']}'\n";
        } elseif (!empty($geo['region'])) {
            $stats['region_resolved']++;
            echo "REPAIRED: Region identified -> '{$geo['region']}', City: Undetermined, Country: '{$geo['country']}'\n";
        } else {
            $stats['undetermined']++;
            echo "UNCHANGED: City & Region remain undetermined in MaxMind\n";
        }
    }

    echo "\n----------------------------------------------------------------------\n";
    echo "SUMMARY:\n";
    echo "  Total records inspected     : {$stats['inspected']}\n";
    echo "  Hashed IPs skipped          : {$stats['hashed_skipped']} (100% privacy preserved)\n";
    echo "  Private/local IPs skipped   : {$stats['private_skipped']}\n";
    echo "  Cities successfully resolved: {$stats['city_resolved']}\n";
    echo "  Regions resolved            : {$stats['region_resolved']}\n";
    echo "  Remaining undetermined      : {$stats['undetermined']}\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

try {
    \Core\DB::Connect();

    $table = (defined('DBprefix') ? DBprefix : '') . 'stats';
    $cols = \Core\DB::forTable('stats')->rawQuery("SHOW COLUMNS FROM `{$table}`")->findArray();
    $existing = array_column($cols, 'Field');
    $hasRegion = in_array('region', $existing);

    $records = \Core\DB::stats()
        ->whereRaw("(city IS NULL OR city = '' OR LOWER(city) = 'unknown' OR LOWER(city) = 'inconnu')")
        ->limit($limit)
        ->findMany();

    $stats = [
        'inspected'       => count($records),
        'hashed_skipped'  => 0,
        'private_skipped' => 0,
        'city_resolved'   => 0,
        'region_resolved' => 0,
        'undetermined'    => 0,
    ];

    foreach ($records as $row) {
        $ip = trim((string) $row->ip);

        // Check if IP is hashed (MD5)
        if (preg_match('/^[a-f0-9]{32}$/i', $ip)) {
            $stats['hashed_skipped']++;
            continue;
        }

        // Check if IP is valid and public
        if (!\Helpers\GeoLocation::isPublicIp($ip)) {
            $stats['private_skipped']++;
            continue;
        }

        $geo = \Helpers\GeoLocation::locate($ip);

        if (!empty($geo['city'])) {
            $stats['city_resolved']++;
            if ($isApply) {
                $row->city = $geo['city'];
                $row->country = $geo['country'] ?: $row->country;
                if ($hasRegion) {
                    $row->region = $geo['region'] ?? null;
                    $row->geo_source = 'maxmind_repair';
                }
                $row->save();
            }
        } elseif (!empty($geo['region'])) {
            $stats['region_resolved']++;
            if ($isApply && $hasRegion) {
                $row->region = $geo['region'];
                $row->country = $geo['country'] ?: $row->country;
                $row->geo_source = 'maxmind_repair';
                $row->save();
            }
        } else {
            $stats['undetermined']++;
        }
    }

    echo "SUMMARY:\n";
    echo "  Total records inspected     : {$stats['inspected']}\n";
    echo "  Hashed IPs skipped          : {$stats['hashed_skipped']} (100% privacy preserved)\n";
    echo "  Private/local IPs skipped   : {$stats['private_skipped']}\n";
    echo "  Cities successfully resolved: {$stats['city_resolved']}\n";
    echo "  Regions resolved            : {$stats['region_resolved']}\n";
    echo "  Remaining undetermined      : {$stats['undetermined']}\n";

    if (!$isApply) {
        echo "\n* To apply these repairs to your database, re-run with: php scripts/geo-repair.php --apply\n";
    }

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
