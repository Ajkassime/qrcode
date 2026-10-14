<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  Phase 4 Automated Test Suite
 *  - Advanced Combined Targeting (Module 4A: Country + Region + Device + Language + Time)
 *  - Stats Dashboard & Deep Analytics Breakdown (Module 4B: Human vs Preview, Geo Precision)
 *  - Plugin Manager Local Inspection & Security Policy (Module 4C)
 * ====================================================================================
 */

namespace Tests;

use Helpers\AdvancedTargeting;
use Helpers\StatsDashboard;
use Helpers\PluginManager;

class Phase4ModernizationTest {

    public static function run(&$results) {
        self::testAdvancedCombinedTargeting($results);
        self::testStatsDashboardAndAnalyticsBreakdown($results);
        self::testLocalPluginManagerAndSecurityPolicy($results);
    }

    private static function assert(bool $condition, string $message, array &$results): void {
        if ($condition) {
            echo "  [PASS] {$message}\n";
            $results['passed'] = ($results['passed'] ?? 0) + 1;
        } else {
            echo "  [FAIL] {$message}\n";
            $results['failed'] = ($results['failed'] ?? 0) + 1;
        }
    }

    public static function testAdvancedCombinedTargeting(&$results): void {
        echo "Running Phase4ModernizationTest::testAdvancedCombinedTargeting...\n";

        $url = (object)[
            'url'     => 'https://example.com/default-global',
            'options' => json_encode([
                'advanced_targeting_rules' => [
                    [
                        'name'        => 'Madagascar iPhone App Store',
                        'country'     => 'madagascar',
                        'device'      => 'ios',
                        'destination' => 'https://apps.apple.com/app/madagascar-store',
                    ],
                    [
                        'name'        => 'Madagascar Android Google Play',
                        'country'     => 'madagascar',
                        'device'      => 'android',
                        'destination' => 'https://play.google.com/store/apps/madagascar-app',
                    ],
                    [
                        'name'        => 'Analamanga Regional Promotion',
                        'country'     => 'madagascar',
                        'region'      => 'analamanga',
                        'destination' => 'https://example.com/promo-tana',
                    ],
                    [
                        'name'        => 'France French Language Portal',
                        'country'     => 'france',
                        'language'    => 'fr',
                        'destination' => 'https://example.com/fr-portal',
                    ],
                ],
            ]),
        ];

        // 1. Madagascar + iPhone matches Rule 1
        $dest1 = AdvancedTargeting::resolve($url, [
            'country'  => 'madagascar',
            'device'   => 'ios',
            'os'       => 'ios',
            'language' => 'fr',
        ]);
        self::assert($dest1 === 'https://apps.apple.com/app/madagascar-store', "Visitor from Madagascar on iPhone resolves to iOS App Store", $results);

        // 2. Madagascar + Android matches Rule 2
        $dest2 = AdvancedTargeting::resolve($url, [
            'country'  => 'madagascar',
            'device'   => 'android',
            'os'       => 'android',
            'language' => 'fr',
        ]);
        self::assert($dest2 === 'https://play.google.com/store/apps/madagascar-app', "Visitor from Madagascar on Android resolves to Google Play", $results);

        // 3. Madagascar + desktop in Analamanga matches Rule 3
        $dest3 = AdvancedTargeting::resolve($url, [
            'country'  => 'madagascar',
            'region'   => 'analamanga',
            'device'   => 'desktop',
            'language' => 'fr',
        ]);
        self::assert($dest3 === 'https://example.com/promo-tana', "Visitor in Analamanga on Desktop matches regional promo", $results);

        // 4. CRITICAL: Visitor in Madagascar with UNKNOWN region must NEVER match Analamanga rule
        $destUnknownRegion = AdvancedTargeting::resolve($url, [
            'country'  => 'madagascar',
            'region'   => 'unknown',
            'device'   => 'desktop',
            'language' => 'en',
        ]);
        self::assert(
            $destUnknownRegion === 'https://example.com/default-global',
            "Visitor with 'unknown' region is strictly blocked from matching specific region rule and falls back to default",
            $results
        );

        // 5. France + French language matches Rule 4
        $dest4 = AdvancedTargeting::resolve($url, [
            'country'  => 'france',
            'device'   => 'desktop',
            'language' => 'fr-FR',
        ]);
        self::assert($dest4 === 'https://example.com/fr-portal', "Visitor from France in French resolves to fr-portal", $results);

        // 6. Visitor not matching any rule cleanly resolves to default URL
        $destDefault = AdvancedTargeting::resolve($url, [
            'country'  => 'germany',
            'device'   => 'desktop',
            'language' => 'de',
        ]);
        self::assert($destDefault === 'https://example.com/default-global', "Non-matching visitor cleanly falls back to default global URL", $results);
    }

    public static function testStatsDashboardAndAnalyticsBreakdown(&$results): void {
        echo "Running Phase4ModernizationTest::testStatsDashboardAndAnalyticsBreakdown...\n";

        // Simulated stats dataset
        $sampleStats = [
            // 3 Human scans in Madagascar (Analamanga / Antananarivo) with 10km GPS accuracy
            (object)['country' => 'Madagascar', 'region' => 'Analamanga', 'city' => 'Antananarivo', 'geo_source' => 'maxmind_plus_gps', 'accuracy_radius' => 10, 'browser' => 'Mobile Safari'],
            (object)['country' => 'Madagascar', 'region' => 'Analamanga', 'city' => 'Antananarivo', 'geo_source' => 'maxmind_plus_gps', 'accuracy_radius' => 15, 'browser' => 'Chrome Mobile'],
            (object)['country' => 'Madagascar', 'region' => 'Vatovavy', 'city' => 'Mananjary', 'geo_source' => 'opencage', 'accuracy_radius' => 20, 'browser' => 'Chrome Mobile'],
            // 2 Social Preview bots (Facebook & WhatsApp preview)
            (object)['country' => 'United States', 'region' => 'California', 'city' => 'Menlo Park', 'geo_source' => 'maxmind', 'accuracy_radius' => 50, 'browser' => 'facebookexternalhit/1.1'],
            (object)['country' => 'United States', 'region' => 'California', 'city' => 'Mountain View', 'geo_source' => 'maxmind', 'accuracy_radius' => 50, 'browser' => 'WhatsApp/2.21.1 preview'],
        ];

        $analytics = StatsDashboard::computeAnalytics($sampleStats);

        self::assert($analytics['total_scans'] === 5, "Total scans computed as 5", $results);
        self::assert($analytics['human_scans'] === 3, "Human scans correctly isolated as 3 (60.0%)", $results);
        self::assert($analytics['preview_scans'] === 2, "Social preview crawler scans isolated as 2 (40.0%)", $results);
        self::assert($analytics['human_ratio_pct'] === 60.0, "Human scan ratio calculated as 60.0%", $results);

        self::assert($analytics['top_countries']['Madagascar'] === 3, "Top country identified as Madagascar (3 scans)", $results);
        self::assert($analytics['top_cities']['Antananarivo'] === 2, "Top city identified as Antananarivo (2 scans)", $results);
        self::assert($analytics['average_radius_km'] === 29.0, "Average geographical accuracy computed accurately (29.0 km)", $results);

        // Dark mode CSS integration
        $darkModeCss = StatsDashboard::getDarkModeCssVariables();
        self::assert(strpos($darkModeCss, '--qr-card-bg') !== false, "Dark mode theme tokens generated for Rohy theme", $results);
    }

    public static function testLocalPluginManagerAndSecurityPolicy(&$results): void {
        echo "Running Phase4ModernizationTest::testLocalPluginManagerAndSecurityPolicy...\n";

        // 1. Inspect installed local plugins
        $plugins = PluginManager::listInstalledPlugins();
        self::assert(is_array($plugins), "listInstalledPlugins returns array of local plugins", $results);
        self::assert(isset($plugins['helloworld']), "helloworld plugin discovered in storage/plugins", $results);
        self::assert($plugins['helloworld']['name'] === 'Hello World', "helloworld metadata accurately parsed from config.json", $results);

        // 2. Validate valid local plugin
        $valOk = PluginManager::validatePlugin('helloworld');
        self::assert($valOk['valid'] === true, "Valid local plugin passes security validation", $results);

        // 3. Path traversal attack attempt blocked
        $valAttack = PluginManager::validatePlugin('../../../etc');
        self::assert($valAttack['valid'] === false, "Path traversal attempt is strictly blocked", $results);

        // 4. Remote installation prohibition
        self::assert(
            PluginManager::isRemoteInstallationAllowed() === false,
            "Security Policy: Remote unverified plugin installations are strictly prohibited",
            $results
        );
    }
}
