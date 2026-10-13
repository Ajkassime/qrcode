<?php
/**
 * CLI Tool: Safe Historical Statistics Geolocation Repair
 *
 * Reprocesses historical stats records that have missing/unknown cities ONLY when
 * the raw client IP is legitimately and legally stored (i.e. unhashed).
 *
 * Strict Safety & Privacy Rules:
 *   - Uses standalone `scripts/cli-bootstrap.php` (never uses test bootstrap or test state).
 *   - Refuses execution with non-zero exit code (exit 1) if the target database is unavailable.
 *   - NEVER reconstructs or guesses cities from hashed IPs (MD5/SHA).
 *   - Supports `--status` (read-only), `--dry-run` (read-only default), and `--apply --confirm`.
 *
 * Usage:
 *   php scripts/geo-repair.php --status                # Read-only summary of repairable records
 *   php scripts/geo-repair.php --dry-run               # Read-only simulation on database records (default)
 *   php scripts/geo-repair.php --apply --confirm       # Apply verified repairs to database
 *   php scripts/geo-repair.php --limit=500             # Process up to N records
 */

require_once __DIR__ . '/cli-bootstrap.php';

$args = array_slice($argv, 1);
$isStatus = in_array('--status', $args, true);
$isDryRun = in_array('--dry-run', $args, true);
$isApply = in_array('--apply', $args, true);
$isConfirmed = in_array('--confirm', $args, true);
$limit = 1000;

foreach ($args as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, (int) substr($arg, 8));
    }
}

if (!$isStatus && !$isDryRun && !$isApply) {
    $isDryRun = true;
}

if ($isApply && !$isConfirmed) {
    fwrite(STDERR, "[ERROR] Applying repairs to the database requires explicit `--confirm` flag.\n");
    fwrite(STDERR, "Usage: php scripts/geo-repair.php --apply --confirm\n");
    exit(1);
}

try {
    $pdo = cliConnectDatabase();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] Database connection unavailable: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Repair aborted: refusing to simulate fake database repairs without an active database connection.\n");
    exit(1);
}

try {
    $prefix = defined('DBprefix') ? DBprefix : '';
    $table = $prefix . 'stats';

    $existing = cliGetTableColumns($pdo, $table);
    if (empty($existing)) {
        fwrite(STDERR, "[ERROR] Target table `{$table}` does not exist or has no readable columns.\n");
        exit(1);
    }

    $hasRegion = in_array('region', $existing, true);
    $hasGeoSource = in_array('geo_source', $existing, true);
    $hasAccuracy = in_array('accuracy_radius', $existing, true);

    $stmtCount = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE (city IS NULL OR city = '' OR LOWER(city) IN ('unknown', 'inconnu', 'n/a', 'none'))");
    $totalCandidates = $stmtCount ? (int) $stmtCount->fetchColumn() : 0;

    if ($isStatus) {
        echo "======================================================================\n";
        echo "       HISTORICAL GEO REPAIR STATUS (READ-ONLY)                       \n";
        echo "======================================================================\n";
        echo "Target Table                  : `{$table}`\n";
        echo "Extended Columns Present      : region=" . ($hasRegion ? 'YES' : 'NO') . ", geo_source=" . ($hasGeoSource ? 'YES' : 'NO') . "\n";
        echo "Records Missing City          : {$totalCandidates}\n";
        exit(0);
    }

    echo "======================================================================\n";
    echo "       MISSION CODEX — HISTORICAL GEO REPAIR TOOL                     \n";
    echo "======================================================================\n";
    echo "Mode  : " . ($isApply ? "LIVE APPLY (Writing to `{$table}`)" : "DRY-RUN (Read-only, no DB writes)") . "\n";
    echo "Limit : {$limit} records (Total candidates in DB: {$totalCandidates})\n\n";

    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE (city IS NULL OR city = '' OR LOWER(city) IN ('unknown', 'inconnu', 'n/a', 'none')) LIMIT :lim");
    $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $stats = [
        'inspected'       => count($records),
        'hashed_skipped'  => 0,
        'private_skipped' => 0,
        'city_resolved'   => 0,
        'region_resolved' => 0,
        'undetermined'    => 0,
        'updated_rows'    => 0,
    ];

    foreach ($records as $row) {
        $id = $row['id'] ?? null;
        $ip = trim((string) ($row['ip'] ?? ''));

        // Never attempt to reverse or guess hashed IPs (MD5 32 hex or SHA-256 64 hex)
        if (preg_match('/^[a-f0-9]{32}$/i', $ip) || preg_match('/^[a-f0-9]{64}$/i', $ip)) {
            $stats['hashed_skipped']++;
            continue;
        }

        // Skip private or reserved IPs
        if (!\Helpers\GeoLocation::isPublicIp($ip)) {
            $stats['private_skipped']++;
            continue;
        }

        $geo = \Helpers\GeoLocation::locate($ip);

        if (!empty($geo['city'])) {
            $stats['city_resolved']++;
            if ($isApply && $id !== null) {
                $fields = ['city = :city'];
                $params = [':city' => $geo['city'], ':id' => $id];

                if (!empty($geo['country'])) {
                    $fields[] = 'country = :country';
                    $params[':country'] = strtolower($geo['country']);
                }
                if ($hasRegion && !empty($geo['region'])) {
                    $fields[] = 'region = :region';
                    $params[':region'] = $geo['region'];
                }
                if ($hasGeoSource) {
                    $fields[] = 'geo_source = :geo_source';
                    $params[':geo_source'] = 'maxmind_repair';
                }
                if ($hasAccuracy && isset($geo['accuracy_radius'])) {
                    $fields[] = 'accuracy_radius = :accuracy_radius';
                    $params[':accuracy_radius'] = (int) $geo['accuracy_radius'];
                }

                $upd = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $fields) . " WHERE id = :id");
                $upd->execute($params);
                $stats['updated_rows']++;
            }
        } elseif (!empty($geo['region'])) {
            $stats['region_resolved']++;
            if ($isApply && $hasRegion && $id !== null) {
                $fields = ['region = :region'];
                $params = [':region' => $geo['region'], ':id' => $id];

                if (!empty($geo['country'])) {
                    $fields[] = 'country = :country';
                    $params[':country'] = strtolower($geo['country']);
                }
                if ($hasGeoSource) {
                    $fields[] = 'geo_source = :geo_source';
                    $params[':geo_source'] = 'maxmind_repair';
                }
                if ($hasAccuracy && isset($geo['accuracy_radius'])) {
                    $fields[] = 'accuracy_radius = :accuracy_radius';
                    $params[':accuracy_radius'] = (int) $geo['accuracy_radius'];
                }

                $upd = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $fields) . " WHERE id = :id");
                $upd->execute($params);
                $stats['updated_rows']++;
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
    echo "  Database rows updated       : {$stats['updated_rows']}\n";

    if (!$isApply) {
        echo "\n* To apply these repairs to your database, re-run with: php scripts/geo-repair.php --apply --confirm\n";
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] Repair execution failed: " . $e->getMessage() . "\n");
    exit(1);
}
