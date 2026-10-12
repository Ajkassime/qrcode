<?php
/**
 * Test Suite: GeoLocation Service, Hardened IP Detection & Quality Metrics
 */

class GeoLocationTest {

    public static function run(&$results) {
        self::testIpDetectionAndSpoofingProtection($results);
        self::testMaxmindResolutionAndMadagascarIps($results);
        self::testGeographicHierarchyFormatting($results);
        self::testDataQualityIndicators($results);
        self::testBrowserConsentCoordinatesResolution($results);
        self::testConsentGateAndDirectRedirectPreservation($results);
    }

    private static function assert($condition, $message, &$results) {
        if ($condition) {
            $results['passed']++;
            echo "  [PASS] {$message}\n";
        } else {
            $results['failed']++;
            echo "  [FAIL] {$message}\n";
        }
    }

    public static function testIpDetectionAndSpoofingProtection(&$results) {
        echo "Running GeoLocationTest::testIpDetectionAndSpoofingProtection...\n";

        // 1. Untrusted connection with spoofed headers must ignore them
        $_SERVER['REMOTE_ADDR'] = '203.0.113.195';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 1.1.1.1';
        $_SERVER['HTTP_X_REAL_IP'] = '9.9.9.9';
        $_SERVER['HTTP_CLIENT_IP'] = '4.4.4.4';

        $req = new \Core\Request();
        self::assert($req->ip() === '203.0.113.195', "Untrusted connection strictly uses REMOTE_ADDR ignoring CF-Connecting-IP, X-Forwarded-For, and X-Real-IP", $results);

        // 2. Untrusted client pretending to be behind Cloudflare when REMOTE_ADDR is not in CF CIDR range
        $_SERVER['REMOTE_ADDR'] = '198.51.100.22';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '196.192.32.1';
        $req = new \Core\Request();
        self::assert($req->ip() === '198.51.100.22', "Client pretending to be Cloudflare with non-CF REMOTE_ADDR is rejected from using CF-Connecting-IP", $results);

        // 3. Legitimate Cloudflare proxy IP (173.245.48.10 is in 173.245.48.0/20)
        \Core\Request::setTrustedProxies(['ips' => [], 'trust_cloudflare' => true]);
        $_SERVER['REMOTE_ADDR'] = '173.245.48.10';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '196.192.32.1';
        $req = new \Core\Request();
        self::assert($req->ip() === '196.192.32.1', "Legitimate Cloudflare proxy connection safely resolves client IP via CF-Connecting-IP when enabled", $results);
        \Core\Request::setTrustedProxies(null);

        // 4. IPv4-mapped IPv6 normalization
        $_SERVER['REMOTE_ADDR'] = '::ffff:196.192.32.1';
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CLIENT_IP']);
        $req = new \Core\Request();
        self::assert($req->ip() === '196.192.32.1', "IPv4-mapped IPv6 address (::ffff:) is normalized to IPv4", $results);

        // 5. Public vs Private/Reserved IP checking
        self::assert(\Helpers\GeoLocation::isPublicIp('127.0.0.1') === false, "isPublicIp rejects loopback 127.0.0.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('192.168.1.1') === false, "isPublicIp rejects private class C 192.168.1.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('10.0.0.50') === false, "isPublicIp rejects private class A 10.0.0.50", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('172.16.0.1') === false, "isPublicIp rejects private class B 172.16.0.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('196.192.32.1') === true, "isPublicIp accepts public Madagascar IP 196.192.32.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('not-an-ip') === false, "isPublicIp rejects invalid string", $results);
    }

    public static function testMaxmindResolutionAndMadagascarIps(&$results) {
        echo "Running GeoLocationTest::testMaxmindResolutionAndMadagascarIps...\n";

        // Test real MaxMind lookup on Madagascar subnet 196.192.32.1
        $loc = \Helpers\GeoLocation::locate('196.192.32.1');
        self::assert($loc['country'] === 'Madagascar', "MaxMind correctly identifies country 'Madagascar'", $results);
        self::assert($loc['country_code'] === 'MG', "MaxMind correctly identifies ISO code 'MG'", $results);
        self::assert($loc['city'] === null, "City is null/empty for carrier block without city POP (never inventing city)", $results);
        self::assert($loc['precision'] === 'country', "Precision level is accurately tagged as 'country'", $results);
        self::assert($loc['missing_reason'] === 'country_level_only', "Missing reason is explicitly classified as 'country_level_only'", $results);
        self::assert($loc['source'] === 'maxmind', "Source is tagged as 'maxmind'", $results);

        // Test private IP lookup
        $privateLoc = \Helpers\GeoLocation::locate('192.168.1.100');
        self::assert($privateLoc['missing_reason'] === 'private_or_reserved_ip', "Private IP is short-circuited with 'private_or_reserved_ip'", $results);
        self::assert($privateLoc['city'] === null && $privateLoc['country'] === null, "Private IP returns null geographic fields", $results);

        // Test invalid IP lookup
        $invalidLoc = \Helpers\GeoLocation::locate('invalid');
        self::assert($invalidLoc['missing_reason'] === 'invalid_ip', "Invalid IP is caught with 'invalid_ip'", $results);
    }

    public static function testGeographicHierarchyFormatting(&$results) {
        echo "Running GeoLocationTest::testGeographicHierarchyFormatting...\n";

        // Case A: City known -> "City, Country"
        $formattedA = \Helpers\GeoLocation::formatLocationName('Antananarivo', 'Analamanga', 'Madagascar');
        self::assert($formattedA === 'Antananarivo, Madagascar', "Case A: Known city formatted as 'Antananarivo, Madagascar'", $results);

        $formattedA2 = \Helpers\GeoLocation::formatLocationName('mananjary', '', 'Madagascar');
        self::assert($formattedA2 === 'Mananjary, Madagascar', "Case A: Title-cased city when region is empty", $results);

        // Case B: City unknown, Region known -> "Région : Region — ville non déterminée, Country"
        $formattedB = \Helpers\GeoLocation::formatLocationName(null, 'Analamanga', 'Madagascar');
        self::assert(strpos($formattedB, 'Région : Analamanga — ville non déterminée, Madagascar') !== false, "Case B: Unknown city with known region formatted hierarchically", $results);

        $formattedB2 = \Helpers\GeoLocation::formatLocationName('Unknown', 'Diana', 'Madagascar');
        self::assert(strpos($formattedB2, 'Région : Diana — ville non déterminée, Madagascar') !== false, "Case B: 'Unknown' string city normalized to Region level hierarchy", $results);

        // Case C: Country only -> "Country — ville non déterminée"
        $formattedC = \Helpers\GeoLocation::formatLocationName(null, null, 'Madagascar');
        self::assert($formattedC === 'Madagascar — ville non déterminée', "Case C: Country only formatted as 'Madagascar — ville non déterminée'", $results);

        $formattedC2 = \Helpers\GeoLocation::formatLocationName('Unknown', 'Inconnu', 'Madagascar');
        self::assert($formattedC2 === 'Madagascar — ville non déterminée', "Case C: Normalized unknown placeholders to 'Madagascar — ville non déterminée'", $results);
    }

    public static function testDataQualityIndicators(&$results) {
        echo "Running GeoLocationTest::testDataQualityIndicators...\n";

        $sampleAggregations = [
            ['city' => 'Antananarivo', 'region' => 'Analamanga', 'count' => 10],
            ['city' => 'Toamasina',    'region' => 'Atsinanana', 'count' => 5],
            ['city' => '',             'region' => 'Vakinankaratra', 'count' => 8],
            ['city' => 'Unknown',      'region' => 'Boeny',      'count' => 4],
            ['city' => '',             'region' => '',           'count' => 20],
            ['city' => 'Unknown',      'region' => 'Unknown',    'count' => 13],
        ];

        $metrics = \Helpers\GeoLocation::calculateQualityMetrics($sampleAggregations);

        self::assert($metrics['identified_city_count'] === 15, "Quality metrics identifies 15 city-level clicks (10+5)", $results);
        self::assert($metrics['region_only_count'] === 12, "Quality metrics identifies 12 region-only clicks (8+4)", $results);
        self::assert($metrics['undetermined_count'] === 33, "Quality metrics identifies 33 undetermined clicks (20+13)", $results);
        self::assert($metrics['total'] === 60, "Quality metrics total equals 60 clicks", $results);
        self::assert($metrics['completeness_percent'] === 45.0, "Completeness percentage correctly calculated as 45.0% ((15+12)/60)", $results);
    }

    public static function testBrowserConsentCoordinatesResolution(&$results) {
        echo "Running GeoLocationTest::testBrowserConsentCoordinatesResolution...\n";

        // Antananarivo center coordinates: -18.8792, 47.5079
        $tana = \Helpers\GeoLocation::resolveCoordinates(-18.8792, 47.5079, 15.0);
        self::assert($tana['country'] === 'Madagascar', "Coordinates in Antananarivo resolve to country 'Madagascar'", $results);
        self::assert($tana['region'] === 'Analamanga', "Coordinates resolve to region 'Analamanga'", $results);
        self::assert($tana['city'] === 'Antananarivo', "Coordinates resolve to city 'Antananarivo'", $results);
        self::assert($tana['source'] === 'browser_consent', "Source explicitly tagged as 'browser_consent'", $results);
        self::assert($tana['precision'] === 'city', "Precision is 'city'", $results);

        // Coordinates out of bounds
        $oob = \Helpers\GeoLocation::resolveCoordinates(999.0, -999.0);
        self::assert($oob['precision'] === 'unknown' && $oob['country'] === null, "Out-of-bounds coordinates return unknown precision", $results);
    }

    public static function testConsentGateAndDirectRedirectPreservation(&$results) {
        echo "Running GeoLocationTest::testConsentGateAndDirectRedirectPreservation...\n";

        // 1. Gate::consentLocation creates view
        $fakeUrl = new \stdClass();
        $fakeUrl->id = 999;
        $fakeUrl->alias = 'testgeo';
        $fakeUrl->custom = null;
        $fakeUrl->url = 'https://wa.me/261340000000?text=Bonjour';

        ob_start();
        \Helpers\Gate::consentLocation($fakeUrl);
        $view = ob_get_clean();
        self::assert(is_string($view) && strpos($view, 'Localisation géographique') !== false, "Gate::consentLocation renders consent interface", $results);
        self::assert(strpos($view, 'https://wa.me/261340000000?text=Bonjour') !== false, "Consent interface embeds direct target URL for skip action", $results);

        // 2. Target URL with WhatsApp protocol is preserved without mangling
        $waUrl = 'whatsapp://send?phone=261340000000&text=Commande';
        $fakeWa = clone $fakeUrl;
        $fakeWa->url = $waUrl;
        ob_start();
        \Helpers\Gate::consentLocation($fakeWa);
        $viewWa = ob_get_clean();
        self::assert(strpos($viewWa, 'whatsapp://send?phone=261340000000&amp;text=Commande') !== false || strpos($viewWa, 'whatsapp://send?phone=261340000000&text=Commande') !== false, "WhatsApp protocol links (whatsapp://) are fully preserved in consent view", $results);

        // 3. Database Info helper
        $dbInfo = \Helpers\GeoLocation::getDatabaseInfo();
        self::assert($dbInfo['exists'] === true, "GeoLite2-City.mmdb is confirmed existing on disk", $results);
        self::assert($dbInfo['readable'] === true, "GeoLite2-City.mmdb is readable", $results);
        self::assert($dbInfo['size_bytes'] > 10000000, "GeoLite2-City.mmdb size exceeds 10MB", $results);
    }
}
