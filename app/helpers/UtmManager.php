<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  UTM & URL Parameter Templates Helper (paramtemplates)
 *  Independent internal development.
 * ====================================================================================
 */

namespace Helpers;

use Core\DB;
use Core\Helper;

class UtmManager {

    /**
     * Create table if needed
     */
    public static function createTable(): void {
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();
        if (!$pdo) return;

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT(11) AUTO_INCREMENT PRIMARY KEY';

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}paramtemplates` (
            `id` {$autoInc},
            `userid` INT(11) NOT NULL DEFAULT 0,
            `name` VARCHAR(255) NOT NULL,
            `utm_source` VARCHAR(255) NULL,
            `utm_medium` VARCHAR(255) NULL,
            `utm_campaign` VARCHAR(255) NULL,
            `utm_term` VARCHAR(255) NULL,
            `utm_content` VARCHAR(255) NULL,
            `custom_params` TEXT NULL,
            `created_at` DATETIME NOT NULL
        )";
        $pdo->exec($sql);
    }

    /**
     * Save a reusable parameter template
     */
    public static function saveTemplate(int $userid, string $name, array $params): int {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $custom = [];
        $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        foreach ($params as $k => $v) {
            if (!in_array($k, $utmKeys, true) && !empty($v)) {
                $custom[$k] = Helper::clean($v, 3);
            }
        }

        $stmt = $pdo->prepare("INSERT INTO `{$prefix}paramtemplates` (`userid`, `name`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `custom_params`, `created_at`) VALUES (:uid, :name, :source, :medium, :campaign, :term, :content, :custom, :created)");
        $stmt->execute([
            ':uid'      => $userid,
            ':name'     => Helper::clean($name, 3),
            ':source'   => !empty($params['utm_source']) ? Helper::clean($params['utm_source'], 3) : null,
            ':medium'   => !empty($params['utm_medium']) ? Helper::clean($params['utm_medium'], 3) : null,
            ':campaign' => !empty($params['utm_campaign']) ? Helper::clean($params['utm_campaign'], 3) : null,
            ':term'     => !empty($params['utm_term']) ? Helper::clean($params['utm_term'], 3) : null,
            ':content'  => !empty($params['utm_content']) ? Helper::clean($params['utm_content'], 3) : null,
            ':custom'   => !empty($custom) ? json_encode($custom) : null,
            ':created'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Get a template by ID
     */
    public static function getTemplate(int $id, ?int $userid = null): ?object {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $stmt = $pdo->prepare("SELECT * FROM `{$prefix}paramtemplates` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$row) return null;

        if ($userid !== null && (int)$row->userid !== 0 && (int)$row->userid !== (int)$userid) {
            return null;
        }

        $row->params = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
            if (!empty($row->$k)) $row->params[$k] = $row->$k;
        }
        if (!empty($row->custom_params)) {
            $c = json_decode($row->custom_params, true);
            if (is_array($c)) $row->params = array_merge($row->params, $c);
        }

        return $row;
    }

    /**
     * List templates accessible to user
     */
    public static function listTemplates(?int $userid = null): array {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        if ($userid === null || $userid === 0) {
            $stmt = $pdo->query("SELECT * FROM `{$prefix}paramtemplates` ORDER BY `name` ASC");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `{$prefix}paramtemplates` WHERE `userid` = 0 OR `userid` = :uid ORDER BY `name` ASC");
            $stmt->execute([':uid' => $userid]);
        }

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
            $row->params = [];
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
                if (!empty($row->$k)) $row->params[$k] = $row->$k;
            }
            if (!empty($row->custom_params)) {
                $c = json_decode($row->custom_params, true);
                if (is_array($c)) $row->params = array_merge($row->params, $c);
            }
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Delete template
     */
    public static function deleteTemplate(int $id, int $userid = 0): bool {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $stmt = $pdo->prepare("DELETE FROM `{$prefix}paramtemplates` WHERE `id` = :id AND (`userid` = :uid OR :uid = 0)");
        return $stmt->execute([':id' => $id, ':uid' => $userid]);
    }

    /**
     * Apply parameter template safely to target URL
     * Preserves existing query parameters, URL fragments (#hash), and WhatsApp payloads
     */
    public static function applyParameters(string $url, array $params): string {
        if (empty($params)) {
            return $url;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        // 1. Separate fragment (#hash)
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        // 2. Parse existing query string
        $existingQuery = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $existingQuery);
        }

        // 3. WhatsApp Target Protection:
        // If target is wa.me or whatsapp://, we must ensure 'text' is never mangled
        $isWhatsApp = false;
        $host = strtolower($parsed['host'] ?? '');
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($host === 'wa.me' || $scheme === 'whatsapp') {
            $isWhatsApp = true;
        }

        // 4. Merge parameters (new parameters take precedence, but keep existing non-conflicting ones)
        $mergedQuery = array_merge($existingQuery, $params);

        // Filter out empty parameters
        $filteredQuery = array_filter($mergedQuery, function($v) {
            return $v !== null && $v !== '';
        });

        // 5. Build reconstructed URL
        $reconstructed = '';
        if (!empty($parsed['scheme'])) {
            $reconstructed .= $parsed['scheme'] . '://';
        }
        if (!empty($parsed['user'])) {
            $reconstructed .= $parsed['user'];
            if (!empty($parsed['pass'])) {
                $reconstructed .= ':' . $parsed['pass'];
            }
            $reconstructed .= '@';
        }
        if (!empty($parsed['host'])) {
            $reconstructed .= $parsed['host'];
        }
        if (!empty($parsed['port'])) {
            $reconstructed .= ':' . $parsed['port'];
        }
        if (!empty($parsed['path'])) {
            $reconstructed .= $parsed['path'];
        }

        if (!empty($filteredQuery)) {
            $reconstructed .= '?' . http_build_query($filteredQuery);
        }

        // Append fragment at the very end
        $reconstructed .= $fragment;

        return $reconstructed;
    }
}
