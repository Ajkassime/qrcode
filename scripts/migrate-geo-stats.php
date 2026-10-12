<?php
/**
 * Safe, Reversible Database Migration: Add Extended Geolocation Columns
 *
 * Adds optional columns `region`, `geo_source`, and `accuracy_radius` to the `stats` table.
 *
 * Usage:
 *   php scripts/migrate-geo-stats.php          # Apply migration
 *   php scripts/migrate-geo-stats.php --rollback # Rollback migration
 *   php scripts/migrate-geo-stats.php --status   # Check status
 */

require_once dirname(__DIR__) . "/tests/bootstrap.php";

if (file_exists(ROOT . "/config.php")) {
    @include_once ROOT . "/config.php";
}

$args = array_slice($argv, 1);
$isRollback = in_array('--rollback', $args);
$isStatus = in_array('--status', $args);

$runOffline = false;
if (!defined('DBhost') || empty(constant('DBhost'))) {
    $runOffline = true;
} else {
    try {
        \Core\DB::Connect();
        \Core\DB::forTable('stats')->rawQuery("SELECT 1")->findArray();
    } catch (\Throwable $e) {
        $runOffline = true;
    }
}

if ($runOffline) {
    echo "Notice: Database connection unavailable (offline environment).\n";
    echo "SQL queries for manual execution on MySQL/MariaDB:\n\n";
    if ($isRollback) {
        echo "ALTER TABLE `stats` DROP COLUMN IF EXISTS `accuracy_radius`, DROP COLUMN IF EXISTS `geo_source`, DROP COLUMN IF EXISTS `region`;\n";
    } else {
        echo "ALTER TABLE `stats` ADD COLUMN IF NOT EXISTS `region` VARCHAR(191) NULL DEFAULT NULL AFTER `city`, ADD KEY IF NOT EXISTS `idx_stats_region` (`region`);\n";
        echo "ALTER TABLE `stats` ADD COLUMN IF NOT EXISTS `geo_source` VARCHAR(64) NULL DEFAULT NULL AFTER `region`;\n";
        echo "ALTER TABLE `stats` ADD COLUMN IF NOT EXISTS `accuracy_radius` INT NULL DEFAULT NULL AFTER `geo_source`;\n";
    }
    exit(0);
}

try {
    $prefix = defined('DBprefix') ? DBprefix : '';
    $table = $prefix . 'stats';

    $cols = \Core\DB::forTable('stats')->rawQuery("SHOW COLUMNS FROM `{$table}`")->findArray();
    $existing = array_column($cols, 'Field');

    if ($isStatus) {
        echo "Status for table `{$table}`:\n";
        echo "  - region         : " . (in_array('region', $existing) ? "PRESENT" : "MISSING") . "\n";
        echo "  - geo_source     : " . (in_array('geo_source', $existing) ? "PRESENT" : "MISSING") . "\n";
        echo "  - accuracy_radius: " . (in_array('accuracy_radius', $existing) ? "PRESENT" : "MISSING") . "\n";
        exit(0);
    }

    if ($isRollback) {
        echo "==> Rolling back extended geo columns on `{$table}`...\n";
        if (in_array('accuracy_radius', $existing)) {
            \Core\DB::forTable('stats')->rawQuery("ALTER TABLE `{$table}` DROP COLUMN `accuracy_radius`")->findArray();
            echo "  - Dropped `accuracy_radius`\n";
        }
        if (in_array('geo_source', $existing)) {
            \Core\DB::forTable('stats')->rawQuery("ALTER TABLE `{$table}` DROP COLUMN `geo_source`")->findArray();
            echo "  - Dropped `geo_source`\n";
        }
        if (in_array('region', $existing)) {
            \Core\DB::forTable('stats')->rawQuery("ALTER TABLE `{$table}` DROP COLUMN `region`")->findArray();
            echo "  - Dropped `region`\n";
        }
        echo "Rollback completed.\n";
        exit(0);
    }

    echo "==> Applying extended geo columns migration on `{$table}`...\n";
    if (!in_array('region', $existing)) {
        \Core\DB::forTable('stats')->rawQuery("ALTER TABLE `{$table}` ADD COLUMN `region` VARCHAR(191) NULL DEFAULT NULL AFTER `city`, ADD INDEX `idx_stats_region` (`region`)")->findArray();
        echo "  + Added `region` column and index\n";
    } else {
        echo "  * `region` already exists\n";
    }

    if (!in_array('geo_source', $existing)) {
        \Core\DB::forTable('stats')->rawQuery("ALTER TABLE `{$table}` ADD COLUMN `geo_source` VARCHAR(64) NULL DEFAULT NULL AFTER `region`")->findArray();
        echo "  + Added `geo_source` column\n";
    } else {
        echo "  * `geo_source` already exists\n";
    }

    if (!in_array('accuracy_radius', $existing)) {
        \Core\DB::forTable('stats')->rawQuery("ALTER TABLE `{$table}` ADD COLUMN `accuracy_radius` INT NULL DEFAULT NULL AFTER `geo_source`")->findArray();
        echo "  + Added `accuracy_radius` column\n";
    } else {
        echo "  * `accuracy_radius` already exists\n";
    }

    echo "Migration completed successfully.\n";
} catch (\Throwable $e) {
    echo "Database error during migration: " . $e->getMessage() . "\n";
    exit(1);
}
