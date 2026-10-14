<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  StatsDashboard Helper (Module 4B)
 *  Enriched analytics: Human vs Social crawler segmentation, geographical accuracy,
 *  and dark-mode theme compatibility tokens for Rohy theme.
 * ====================================================================================
 */

namespace Helpers;

use Core\DB;

class StatsDashboard {

    /**
     * Compute comprehensive statistics breakdown
     */
    public static function computeAnalytics(array $statsRows): array {
        $totalScans = count($statsRows);
        $humanScans = 0;
        $previewScans = 0;
        $botBlocks = 0;

        $countries = [];
        $regions = [];
        $cities = [];
        $accuracySum = 0;
        $accuracyCount = 0;
        $sources = [];

        foreach ($statsRows as $row) {
            $isRow = is_object($row);
            $country = $isRow ? ($row->country ?? '') : ($row['country'] ?? '');
            $region  = $isRow ? ($row->region ?? '') : ($row['region'] ?? '');
            $city    = $isRow ? ($row->city ?? '') : ($row['city'] ?? '');
            $source  = $isRow ? ($row->geo_source ?? '') : ($row['geo_source'] ?? '');
            $radius  = $isRow ? ($row->accuracy_radius ?? null) : ($row['accuracy_radius'] ?? null);
            $browser = $isRow ? ($row->browser ?? '') : ($row['browser'] ?? '');

            // Human vs Preview classification
            if (stripos($browser, 'facebookexternalhit') !== false || stripos($browser, 'preview') !== false || stripos($browser, 'bot') !== false) {
                $previewScans++;
            } else {
                $humanScans++;
            }

            // Country
            if (!empty($country)) {
                $countries[$country] = ($countries[$country] ?? 0) + 1;
            }

            // Region
            if (!empty($region)) {
                $regions[$region] = ($regions[$region] ?? 0) + 1;
            }

            // City
            if (!empty($city)) {
                $cities[$city] = ($cities[$city] ?? 0) + 1;
            }

            // Accuracy radius
            if ($radius !== null && (int)$radius > 0) {
                $accuracySum += (int)$radius;
                $accuracyCount++;
            }

            // Source
            if (!empty($source)) {
                $sources[$source] = ($sources[$source] ?? 0) + 1;
            }
        }

        arsort($countries);
        arsort($regions);
        arsort($cities);

        $avgAccuracy = ($accuracyCount > 0) ? round($accuracySum / $accuracyCount, 1) : null;

        return [
            'total_scans'       => $totalScans,
            'human_scans'       => $humanScans,
            'preview_scans'     => $previewScans,
            'human_ratio_pct'   => ($totalScans > 0) ? round(($humanScans / $totalScans) * 100, 1) : 0.0,
            'top_countries'     => array_slice($countries, 0, 10, true),
            'top_regions'       => array_slice($regions, 0, 10, true),
            'top_cities'        => array_slice($cities, 0, 10, true),
            'average_radius_km' => $avgAccuracy,
            'geo_sources'       => $sources,
        ];
    }

    /**
     * Return Dark Mode CSS variables compatible with Rohy theme (storage/themes/default/)
     */
    public static function getDarkModeCssVariables(): string {
        return <<<CSS
:root[data-theme="dark"] {
    --bs-body-bg: #121824;
    --bs-body-color: #e2e8f0;
    --bs-card-bg: #1e293b;
    --qr-card-bg: #1e293b;
    --bs-border-color: #334155;
    --qr-card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
    --qr-brand-primary: #3b82f6;
    --qr-stat-badge-bg: #0f172a;
}
CSS;
    }
}
