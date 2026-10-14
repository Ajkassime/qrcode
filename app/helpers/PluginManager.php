<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  PluginManager Helper (Module 4C)
 *  Safe local plugin inspection, validation and toggle.
 *  Zero remote code loading, zero unverified execution, path-traversal resistant.
 * ====================================================================================
 */

namespace Helpers;

use Core\Helper;

class PluginManager {

    /**
     * Get path to plugins directory
     */
    public static function getPluginsPath(): string {
        return defined('PLUGIN') ? PLUGIN : (STORAGE . '/plugins');
    }

    /**
     * List all locally installed plugins with manifest metadata
     */
    public static function listInstalledPlugins(): array {
        $baseDir = self::getPluginsPath();
        if (!is_dir($baseDir)) {
            return [];
        }

        $activePlugins = config('plugins') ?: [];
        $plugins = [];

        $entries = scandir($baseDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($baseDir . '/' . $entry)) {
                continue;
            }

            // Path traversal protection
            $slug = preg_replace('/[^a-z0-9_-]/i', '', $entry);
            if ($slug !== $entry) {
                continue;
            }

            $pluginDir = $baseDir . '/' . $slug;
            $configFile = $pluginDir . '/config.json';
            $mainFile = $pluginDir . '/plugin.php';

            $meta = [
                'slug'        => $slug,
                'name'        => ucfirst($slug),
                'version'     => '1.0',
                'author'      => 'Inconnu',
                'description' => '',
                'has_entry'   => file_exists($mainFile),
                'active'      => isset($activePlugins[$slug]) || in_array($slug, $activePlugins, true),
                'compatible'  => true,
            ];

            if (file_exists($configFile)) {
                $raw = file_get_contents($configFile);
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $meta['name']        = $json['name'] ?? $meta['name'];
                    $meta['version']     = $json['version'] ?? $meta['version'];
                    $meta['author']      = $json['author'] ?? $meta['author'];
                    $meta['description'] = $json['description'] ?? $meta['description'];
                    $meta['link']        = $json['link'] ?? '';
                }
            }

            $plugins[$slug] = $meta;
        }

        return $plugins;
    }

    /**
     * Validate plugin security & integrity
     */
    public static function validatePlugin(string $slug): array {
        // Enforce safe slug format
        if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) {
            return ['valid' => false, 'error' => 'Identifiant de plugin invalide (caractères non autorisés).'];
        }

        $baseDir = self::getPluginsPath();
        $targetDir = realpath($baseDir . '/' . $slug);

        // Path traversal check
        if (!$targetDir || strpos($targetDir, realpath($baseDir)) !== 0) {
            return ['valid' => false, 'error' => 'Tentative de dépassement de répertoire détectée.'];
        }

        if (!file_exists($targetDir . '/plugin.php')) {
            return ['valid' => false, 'error' => 'Fichier point d\'entrée plugin.php manquant.'];
        }

        return ['valid' => true, 'path' => $targetDir];
    }

    /**
     * Security policy assertion:
     * Remote plugin installation or unverified remote execution is strictly disabled.
     */
    public static function isRemoteInstallationAllowed(): bool {
        return false; // Absolute refusal of remote unsigned code downloads
    }
}
