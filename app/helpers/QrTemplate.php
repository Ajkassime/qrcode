<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  QR Code Templates Helper (qrtemplates)
 *  Packaging compliance, contrast ratio analysis, error correction enforcement.
 *  Independent internal development.
 * ====================================================================================
 */

namespace Helpers;

use Core\DB;
use Core\Helper;

class QrTemplate {

    /**
     * Contrast ratio minimum recommended for retail packaging (ISO/IEC 18004)
     */
    const MIN_CONTRAST_RATIO = 3.0;

    /**
     * Ensure database table exists
     */
    public static function createTable(): void {
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();
        if (!$pdo) return;

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT(11) AUTO_INCREMENT PRIMARY KEY';

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}qrtemplates` (
            `id` {$autoInc},
            `userid` INT(11) NOT NULL DEFAULT 0,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `data` TEXT NOT NULL,
            `preview_svg` MEDIUMTEXT NULL,
            `status` INT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL
        )";
        $pdo->exec($sql);
    }

    /**
     * Compute relative luminance of an sRGB hex color (#rrggbb)
     */
    public static function relativeLuminance(string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return 0.0;
        }

        $r = hexdec(substr($hex, 0, 2)) / 255.0;
        $g = hexdec(substr($hex, 2, 2)) / 255.0;
        $b = hexdec(substr($hex, 4, 2)) / 255.0;

        $transform = function(float $c): float {
            return ($c <= 0.03928) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
        };

        return (0.2126 * $transform($r)) + (0.7152 * $transform($g)) + (0.0722 * $transform($b));
    }

    /**
     * Calculate contrast ratio between two hex colors (1:1 to 21:1)
     */
    public static function contrastRatio(string $color1, string $color2): float {
        $l1 = self::relativeLuminance($color1);
        $l2 = self::relativeLuminance($color2);

        $lighter = max($l1, $l2);
        $darker  = min($l1, $l2);

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    /**
     * Validate packaging decodability & compliance
     */
    public static function validatePackagingDecodability(array $design): array {
        $issues = [];
        $fg = $design['color'] ?? '#000000';
        $bg = $design['bg'] ?? '#ffffff';

        // Check contrast
        $contrast = self::contrastRatio($fg, $bg);
        if ($contrast < self::MIN_CONTRAST_RATIO) {
            $issues[] = "Faible contraste détecté ({$contrast}:1). Le contraste minimum recommandé pour l'emballage est de 3.0:1.";
        }

        // Automatic error correction level elevation if logo is attached
        $hasLogo = !empty($design['logo']) || !empty($design['logo_data']);
        $ecLevel = strtoupper($design['error_correction'] ?? 'M');

        if ($hasLogo && $ecLevel !== 'H') {
            $design['error_correction'] = 'H'; // Elevate to 30% recovery capability
        }

        return [
            'valid'                 => empty($issues),
            'contrast_ratio'        => $contrast,
            'contrast_compliant'    => ($contrast >= self::MIN_CONTRAST_RATIO),
            'error_correction'      => $design['error_correction'] ?? 'M',
            'has_logo'              => $hasLogo,
            'issues'                => $issues,
            'adjusted_design'       => $design,
        ];
    }

    /**
     * Save a new template
     */
    public static function saveTemplate(string $name, array $data, int $userid = 0, string $description = ''): int {
        self::createTable();

        // Enforce packaging safety rules
        $validation = self::validatePackagingDecodability($data);
        $finalData = $validation['adjusted_design'];

        // Generate SVG preview if possible
        $previewSvg = null;
        try {
            $qrObj = \Helpers\QR::factory([
                'type' => 'text',
                'data' => 'https://example.com/template-preview',
                'size' => 200,
            ], $finalData)->format('svg');
            $previewSvg = is_object($qrObj) && method_exists($qrObj, 'string') ? $qrObj->string() : (string)$qrObj;
        } catch (\Throwable $e) {}

        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}qrtemplates` (`userid`, `name`, `description`, `data`, `preview_svg`, `status`, `created_at`) VALUES (:userid, :name, :description, :data, :preview_svg, 1, :created_at)");
        $stmt->execute([
            ':userid'      => $userid,
            ':name'        => Helper::clean($name, 3),
            ':description' => Helper::clean($description, 3),
            ':data'        => json_encode($finalData),
            ':preview_svg' => $previewSvg,
            ':created_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Get template by ID with access check
     */
    public static function getTemplate(int $id, ?int $userid = null): ?object {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $stmt = $pdo->prepare("SELECT * FROM `{$prefix}qrtemplates` WHERE `id` = :id AND `status` = 1 LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$row) return null;

        // Access control: public templates (userid = 0) are readable by all;
        // user-owned templates require matching userid or admin access
        if ($userid !== null && (int)$row->userid !== 0 && (int)$row->userid !== (int)$userid) {
            return null;
        }

        $row->design = json_decode($row->data, true) ?: [];
        return $row;
    }

    /**
     * List templates accessible to a user (global system templates + user's own)
     */
    public static function listTemplates(?int $userid = null): array {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        if ($userid === null || $userid === 0) {
            $stmt = $pdo->query("SELECT * FROM `{$prefix}qrtemplates` WHERE `status` = 1 ORDER BY `userid` ASC, `name` ASC");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `{$prefix}qrtemplates` WHERE `status` = 1 AND (`userid` = 0 OR `userid` = :uid) ORDER BY `userid` ASC, `name` ASC");
            $stmt->execute([':uid' => $userid]);
        }

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
            $row->design = json_decode($row->data, true) ?: [];
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Delete template
     */
    public static function deleteTemplate(int $id, int $userid = 0, bool $isAdmin = false): bool {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        if ($isAdmin) {
            $stmt = $pdo->prepare("DELETE FROM `{$prefix}qrtemplates` WHERE `id` = :id");
            return $stmt->execute([':id' => $id]);
        }

        $stmt = $pdo->prepare("DELETE FROM `{$prefix}qrtemplates` WHERE `id` = :id AND `userid` = :uid");
        return $stmt->execute([':id' => $id, ':uid' => $userid]);
    }
}
