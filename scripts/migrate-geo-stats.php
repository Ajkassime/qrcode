<?php
/**
 * Safe, Reversible Database Migration: Add Extended Geolocation Columns
 *
 * Adds optional columns `region`, `geo_source`, and `accuracy_radius` to the `stats` table.
 *
 * Strict Safety Rules:
 *   - Uses standalone `scripts/cli-bootstrap.php` (never uses test bootstrap or test state).
 *   - Refuses execution with non-zero exit code (exit 1) if the target database is unavailable.
 *   - Never simulates a fake "successful" migration when no database was modified.
 *   - Requires explicit `--apply --confirm` (or `--rollback --confirm`) to alter schema.
 *
 * Usage:
 *   php scripts/migrate-geo-stats.php --status             # Read-only schema status check
 *   php scripts/migrate-geo-stats.php --dry-run            # Read-only preview of pending DDL statements
 *   php scripts/migrate-geo-stats.php --apply --confirm    # Execute migration on target database
 *   php scripts/migrate-geo-stats.php --rollback --confirm # Rollback migration on target database
 */

require_once __DIR__ . '/cli-bootstrap.php';

$args = array_slice($argv, 1);
$isStatus = in_array('--status', $args, true);
$isDryRun = in_array('--dry-run', $args, true);
$isApply = in_array('--apply', $args, true);
$isRollback = in_array('--rollback', $args, true);
$isConfirmed = in_array('--confirm', $args, true);

// Default to --dry-run if no mode flag was specified
if (!$isStatus && !$isDryRun && !$isApply && !$isRollback) {
    $isDryRun = true;
}

try {
    $pdo = cliConnectDatabase();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] Database connection unavailable: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Migration aborted: refusing to report success without an active database connection.\n");
    exit(1);
}

try {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $prefix = defined('DBprefix') ? DBprefix : '';
    $table = $prefix . 'stats';

    $existing = cliGetTableColumns($pdo, $table);
    if (empty($existing)) {
        fwrite(STDERR, "[ERROR] Target table `{$table}` does not exist or has no readable columns.\n");
        exit(1);
    }

    $targetColumns = ['region', 'geo_source', 'accuracy_radius'];
    $missingColumns = array_values(array_diff($targetColumns, $existing));
    $presentColumns = array_values(array_intersect($targetColumns, $existing));

    if ($isStatus) {
        echo "======================================================================\n";
        echo "       GEO STATS MIGRATION STATUS (READ-ONLY)                         \n";
        echo "======================================================================\n";
        echo "Database Driver : {$driver}\n";
        echo "Target Table    : `{$table}`\n";
        echo "Columns Status  :\n";
        foreach ($targetColumns as $col) {
            echo sprintf("  - %-16s : %s\n", $col, in_array($col, $existing, true) ? 'PRESENT' : 'MISSING');
        }
        echo "Migration State : " . (empty($missingColumns) ? "COMPLETE" : "PENDING (" . count($missingColumns) . " missing)") . "\n";
        exit(0);
    }

    // Build driver-compatible DDL statements
    $pendingStatements = [];
    if ($isRollback) {
        foreach (array_reverse($targetColumns) as $col) {
            if (in_array($col, $existing, true)) {
                $pendingStatements[$col] = "ALTER TABLE `{$table}` DROP COLUMN `{$col}`";
            }
        }
    } else {
        if (!in_array('region', $existing, true)) {
            if ($driver === 'sqlite') {
                $pendingStatements['region'] = "ALTER TABLE `{$table}` ADD COLUMN `region` VARCHAR(191) NULL DEFAULT NULL";
                $pendingStatements['idx_stats_region'] = "CREATE INDEX IF NOT EXISTS `idx_stats_region` ON `{$table}` (`region`)";
            } else {
                $pendingStatements['region'] = "ALTER TABLE `{$table}` ADD COLUMN `region` VARCHAR(191) NULL DEFAULT NULL AFTER `city`, ADD INDEX `idx_stats_region` (`region`)";
            }
        }
        if (!in_array('geo_source', $existing, true)) {
            $pendingStatements['geo_source'] = $driver === 'sqlite'
                ? "ALTER TABLE `{$table}` ADD COLUMN `geo_source` VARCHAR(64) NULL DEFAULT NULL"
                : "ALTER TABLE `{$table}` ADD COLUMN `geo_source` VARCHAR(64) NULL DEFAULT NULL AFTER `region`";
        }
        if (!in_array('accuracy_radius', $existing, true)) {
            $pendingStatements['accuracy_radius'] = $driver === 'sqlite'
                ? "ALTER TABLE `{$table}` ADD COLUMN `accuracy_radius` INT NULL DEFAULT NULL"
                : "ALTER TABLE `{$table}` ADD COLUMN `accuracy_radius` INT NULL DEFAULT NULL AFTER `geo_source`";
        }
    }

    if ($isDryRun) {
        echo "======================================================================\n";
        echo "       GEO STATS MIGRATION — DRY-RUN (READ-ONLY)                      \n";
        echo "======================================================================\n";
        echo "Target Table: `{$table}` ({$driver})\n\n";
        if (empty($pendingStatements)) {
            echo "No schema changes needed. All extended geolocation columns are already present.\n";
        } else {
            echo "Pending SQL statements to execute:\n";
            foreach ($pendingStatements as $key => $sql) {
                echo "  [{$key}] {$sql};\n";
            }
            echo "\nTo apply these changes, run:\n";
            echo "  php scripts/migrate-geo-stats.php --apply --confirm\n";
        }
        exit(0);
    }

    if (($isApply || $isRollback) && !$isConfirmed) {
        fwrite(STDERR, "[ERROR] Schema modification requires explicit confirmation flag `--confirm`.\n");
        fwrite(STDERR, "Usage: php scripts/migrate-geo-stats.php " . ($isRollback ? "--rollback" : "--apply") . " --confirm\n");
        exit(1);
    }

    if ($isRollback) {
        echo "==> Rolling back extended geo columns on `{$table}`...\n";
        if (empty($pendingStatements)) {
            echo "  * Nothing to roll back (columns already absent).\n";
        } else {
            foreach ($pendingStatements as $col => $sql) {
                $pdo->exec($sql);
                echo "  - Dropped `{$col}`\n";
            }
        }
        \Helpers\GeoLocation::resetColumnCache();
        echo "Rollback completed successfully.\n";
        exit(0);
    }

    echo "==> Applying extended geo columns migration on `{$table}`...\n";
    if (empty($pendingStatements)) {
        echo "  * All extended geo columns (`region`, `geo_source`, `accuracy_radius`) already exist.\n";
    } else {
        foreach ($pendingStatements as $key => $sql) {
            $pdo->exec($sql);
            echo "  + Applied `{$key}`\n";
        }
    }

    \Helpers\GeoLocation::resetColumnCache();
    echo "Migration completed successfully.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] Database error during migration: " . $e->getMessage() . "\n");
    exit(1);
}
