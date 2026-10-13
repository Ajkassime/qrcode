<?php
/**
 * Comprehensive Test Suite: Worldwide & Madagascar Geolocation, Polygon Boundary Engine,
 * Reverse Geocoding, Browser Consent Security, and CLI Migration/Repair Scripts
 */

class GeoLocationTest {

    public static function run(&$results) {
        self::testIpDetectionAndSpoofingProtection($results);
        self::testWorldwideMaxmindAndIpv6Resolution($results);
        self::testMadagascarPolygonBoundariesAndRuralPrecision($results);
        self::testWorldwideCoordinatesAndReverseGeocoder($results);
        self::testGpsAccuracyAndBorderProximityDegradation($results);
        self::testGeographicHierarchyAndGlobalQualityMetrics($results);
        self::testGeoConsentEndpointSecurityAndAntiReplay($results);
        self::testCliMigrationAndRepairScriptsOnRealDatabase($results);
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

        // 4. Multi-hop trusted reverse proxy X-Forwarded-For chain (right-to-left traversal)
        \Core\Request::setTrustedProxies(['ips' => ['10.0.0.0/8', '172.16.0.0/12'], 'trust_cloudflare' => false]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 80.12.34.56, 172.16.0.10';
        $req = new \Core\Request();
        self::assert($req->ip() === '80.12.34.56', "Multi-hop X-Forwarded-For traversal extracts first non-trusted IP from right, ignoring spoofed leftmost IP", $results);
        \Core\Request::setTrustedProxies(null);

        // 5. IPv4-mapped IPv6 normalization
        $_SERVER['REMOTE_ADDR'] = '::ffff:196.192.32.1';
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CLIENT_IP']);
        $req = new \Core\Request();
        self::assert($req->ip() === '196.192.32.1', "IPv4-mapped IPv6 address (::ffff:) is normalized to IPv4", $results);

        // 6. Public vs Private/Reserved IP checking (IPv4 and IPv6)
        self::assert(\Helpers\GeoLocation::isPublicIp('127.0.0.1') === false, "isPublicIp rejects loopback 127.0.0.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('::1') === false, "isPublicIp rejects IPv6 loopback ::1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('192.168.1.1') === false, "isPublicIp rejects private class C 192.168.1.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('10.0.0.50') === false, "isPublicIp rejects private class A 10.0.0.50", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('172.16.0.1') === false, "isPublicIp rejects private class B 172.16.0.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('196.192.32.1') === true, "isPublicIp accepts public Madagascar IPv4 196.192.32.1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('2a01:e00::1') === true, "isPublicIp accepts public European IPv6 2a01:e00::1", $results);
        self::assert(\Helpers\GeoLocation::isPublicIp('not-an-ip') === false, "isPublicIp rejects invalid string", $results);
    }

    public static function testWorldwideMaxmindAndIpv6Resolution(&$results) {
        echo "Running GeoLocationTest::testWorldwideMaxmindAndIpv6Resolution...\n";
        \Helpers\GeoLocation::clearCache();

        // 1. Africa — Madagascar mobile block without city POP (196.192.32.1)
        $mgMobile = \Helpers\GeoLocation::locate('196.192.32.1');
        self::assert($mgMobile['continent'] === 'Africa' && $mgMobile['continent_code'] === 'AF', "MaxMind resolves continent 'Africa' (AF) for 196.192.32.1", $results);
        self::assert($mgMobile['country'] === 'Madagascar' && $mgMobile['country_code'] === 'MG', "MaxMind resolves country 'Madagascar' (MG)", $results);
        self::assert($mgMobile['city'] === null, "City is null for mobile subnet without city POP (never inventing city)", $results);
        self::assert($mgMobile['precision'] === 'country' && $mgMobile['missing_reason'] === 'country_level_only', "Precision is 'country' with reason 'country_level_only'", $results);

        // 2. Africa — Madagascar fixed ISP with city POP (41.188.0.1)
        $mgCity = \Helpers\GeoLocation::locate('41.188.0.1');
        self::assert($mgCity['country'] === 'Madagascar' && $mgCity['city'] === 'Antananarivo' && $mgCity['precision'] === 'city', "MaxMind resolves city 'Antananarivo' for 41.188.0.1", $results);

        // 3. Africa — South Africa (41.0.0.1)
        $za = \Helpers\GeoLocation::locate('41.0.0.1');
        self::assert($za['continent_code'] === 'AF' && $za['country_code'] === 'ZA' && $za['city'] === 'Johannesburg', "MaxMind resolves Johannesburg, South Africa (AF/ZA)", $results);

        // 4. Europe — France IPv4 (80.12.34.56) and IPv6 (2a01:e00::1)
        $fr = \Helpers\GeoLocation::locate('80.12.34.56');
        self::assert($fr['continent_code'] === 'EU' && $fr['country_code'] === 'FR' && $fr['region'] === 'Île-de-France', "MaxMind resolves France IPv4 with region Île-de-France", $results);

        $frV6 = \Helpers\GeoLocation::locate('2a01:e00::1');
        self::assert($frV6['continent_code'] === 'EU' && $frV6['country'] === 'France' && $frV6['country_code'] === 'FR', "MaxMind resolves IPv6 address 2a01:e00::1 in France (EU)", $results);

        // 5. North America — United States (128.101.101.101)
        $us = \Helpers\GeoLocation::locate('128.101.101.101');
        self::assert($us['continent_code'] === 'NA' && $us['country_code'] === 'US' && $us['region'] === 'Minnesota' && $us['city'] === 'Minneapolis', "MaxMind resolves Minneapolis, Minnesota, US (NA)", $results);

        // 6. Asia — Japan (202.214.86.1)
        $jp = \Helpers\GeoLocation::locate('202.214.86.1');
        self::assert($jp['continent_code'] === 'AS' && $jp['country_code'] === 'JP' && $jp['region'] === 'Tokyo', "MaxMind resolves Tokyo, Japan (AS/JP)", $results);

        // 7. Oceania — Australia (1.128.0.1)
        $au = \Helpers\GeoLocation::locate('1.128.0.1');
        self::assert($au['continent_code'] === 'OC' && $au['country_code'] === 'AU' && $au['region'] === 'Queensland' && $au['city'] === 'Brisbane', "MaxMind resolves Brisbane, Queensland, Australia (OC/AU)", $results);

        // 8. South America — Brazil (200.160.2.3)
        $br = \Helpers\GeoLocation::locate('200.160.2.3');
        self::assert($br['continent_code'] === 'SA' && $br['country_code'] === 'BR' && $br['country'] === 'Brazil', "MaxMind resolves Brazil (SA/BR)", $results);

        // 9. Secondary IP Provider Fallback when primary has no city
        \Helpers\GeoLocation::setMockSecondary(function ($ip) {
            if ($ip === '196.192.32.1') {
                return [
                    'country'         => 'Madagascar',
                    'country_code'    => 'MG',
                    'region'          => 'Vatovavy',
                    'region_code'     => 'MG-VV',
                    'city'            => null,
                    'accuracy_radius' => 25,
                ];
            }
            return null;
        });
        $enriched = \Helpers\GeoLocation::locate('196.192.32.1', ['bypass_cache' => true]);
        self::assert($enriched['region'] === 'Vatovavy' && $enriched['city'] === null && $enriched['precision'] === 'region' && $enriched['source'] === 'secondary_provider', "Secondary provider enriches region without inventing city", $results);
        \Helpers\GeoLocation::clearCache();
    }

    public static function testMadagascarPolygonBoundariesAndRuralPrecision(&$results) {
        echo "Running GeoLocationTest::testMadagascarPolygonBoundariesAndRuralPrecision...\n";
        \Helpers\GeoLocation::clearCache();

        // 1. Mananjary -> Vatovavy
        $mananjary = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 20.0);
        self::assert($mananjary['country'] === 'Madagascar' && $mananjary['country_code'] === 'MG', "Mananjary resolves to Madagascar (MG)", $results);
        self::assert($mananjary['region'] === 'Vatovavy' && $mananjary['region_code'] === 'MG-VV', "Mananjary resolves to region 'Vatovavy' (MG-VV)", $results);
        self::assert($mananjary['city'] === 'Mananjary' && $mananjary['precision'] === 'city', "Mananjary urban commune resolves to city 'Mananjary'", $results);
        self::assert($mananjary['verified'] === false && $mananjary['source'] === 'browser_consent', "Browser coordinates marked verified=false and source='browser_consent'", $results);

        // 2. Manakara -> Fitovinany (Previously impossible under the old 12-city approximation!)
        $manakara = \Helpers\GeoLocation::resolveCoordinates(-22.148, 48.010, 25.0);
        self::assert($manakara['region'] === 'Fitovinany' && $manakara['region_code'] === 'MG-FV', "Manakara resolves to region 'Fitovinany' (MG-FV), NOT Vatovavy", $results);
        self::assert($manakara['city'] === 'Manakara' && $manakara['precision'] === 'city', "Manakara urban commune resolves to city 'Manakara'", $results);

        // 3. Antananarivo -> Analamanga
        $tana = \Helpers\GeoLocation::resolveCoordinates(-18.8792, 47.5079, 15.0);
        self::assert($tana['region'] === 'Analamanga' && $tana['city'] === 'Antananarivo' && $tana['precision'] === 'city', "Antananarivo resolves to Analamanga / Antananarivo", $results);

        // 4. Antsirabe -> Vakinankaratra
        $antsirabe = \Helpers\GeoLocation::resolveCoordinates(-19.8659, 47.0333, 20.0);
        self::assert($antsirabe['region'] === 'Vakinankaratra' && $antsirabe['city'] === 'Antsirabe' && $antsirabe['precision'] === 'city', "Antsirabe resolves to Vakinankaratra / Antsirabe", $results);

        // 5. Toamasina -> Atsinanana
        $toamasina = \Helpers\GeoLocation::resolveCoordinates(-18.1492, 49.4023, 20.0);
        self::assert($toamasina['region'] === 'Atsinanana' && $toamasina['city'] === 'Toamasina' && $toamasina['precision'] === 'city', "Toamasina resolves to Atsinanana / Toamasina", $results);

        // 6. Fianarantsoa -> Haute Matsiatra
        $fianar = \Helpers\GeoLocation::resolveCoordinates(-21.4536, 47.0858, 20.0);
        self::assert($fianar['region'] === 'Haute Matsiatra' && $fianar['city'] === 'Fianarantsoa' && $fianar['precision'] === 'city', "Fianarantsoa resolves to Haute Matsiatra / Fianarantsoa", $results);

        // 7. Mahajanga -> Boeny
        $mahajanga = \Helpers\GeoLocation::resolveCoordinates(-15.7167, 46.3167, 20.0);
        self::assert($mahajanga['region'] === 'Boeny' && $mahajanga['city'] === 'Mahajanga' && $mahajanga['precision'] === 'city', "Mahajanga resolves to Boeny / Mahajanga", $results);

        // 8. Rural area in Vatovavy (outside Mananjary commune, e.g. Ifanadiana rural zone -21.30, 47.63)
        $ruralVatovavy = \Helpers\GeoLocation::resolveCoordinates(-21.30, 47.63, 30.0);
        self::assert($ruralVatovavy['country'] === 'Madagascar' && $ruralVatovavy['region'] === 'Vatovavy' && $ruralVatovavy['city'] === null && $ruralVatovavy['precision'] === 'region', "Rural area in Vatovavy identifies region 'Vatovavy' with city=null (never invents Mananjary)", $results);

        // 9. Rural area in Fitovinany (outside Manakara commune, e.g. -22.30, 47.80)
        $ruralFitovinany = \Helpers\GeoLocation::resolveCoordinates(-22.30, 47.80, 30.0);
        self::assert($ruralFitovinany['region'] === 'Fitovinany' && $ruralFitovinany['city'] === null && $ruralFitovinany['precision'] === 'region', "Rural area in Fitovinany identifies region 'Fitovinany' with city=null", $results);

        // 10. Other regions across Madagascar (Bongolava, Androy, Sofia)
        $bongolava = \Helpers\GeoLocation::resolveCoordinates(-18.77, 46.05, 25.0);
        self::assert($bongolava['region'] === 'Bongolava' && $bongolava['city'] === 'Tsiroanomandidy', "Tsiroanomandidy resolves to region 'Bongolava' (not Analamanga or Vakinankaratra)", $results);

        $ruralAndroy = \Helpers\GeoLocation::resolveCoordinates(-24.80, 45.50, 30.0);
        self::assert($ruralAndroy['region'] === 'Androy' && $ruralAndroy['city'] === null && $ruralAndroy['precision'] === 'region', "Rural Androy resolves to region 'Androy' with city=null", $results);
    }

    public static function testWorldwideCoordinatesAndReverseGeocoder(&$results) {
        echo "Running GeoLocationTest::testWorldwideCoordinatesAndReverseGeocoder...\n";
        \Helpers\GeoLocation::clearCache();

        // 1. Europe: Paris, France
        $paris = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 20.0);
        self::assert($paris['continent_code'] === 'EU' && $paris['country'] === 'France' && $paris['country_code'] === 'FR' && $paris['region'] === 'Île-de-France' && $paris['city'] === 'Paris', "Worldwide polygon resolves Paris, Île-de-France, France (EU/FR)", $results);

        // 2. Europe: London, UK & Berlin, Germany
        $london = \Helpers\GeoLocation::resolveCoordinates(51.5074, -0.1278, 20.0);
        self::assert($london['country_code'] === 'GB' && $london['region'] === 'England' && $london['city'] === 'London', "Worldwide polygon resolves London, England, UK (GB)", $results);

        $berlin = \Helpers\GeoLocation::resolveCoordinates(52.5200, 13.4050, 20.0);
        self::assert($berlin['country_code'] === 'DE' && $berlin['region'] === 'Berlin' && $berlin['city'] === 'Berlin', "Worldwide polygon resolves Berlin, Germany (DE)", $results);

        // 3. Asia: Tokyo, Japan & Dubai, UAE
        $tokyo = \Helpers\GeoLocation::resolveCoordinates(35.6762, 139.6503, 20.0);
        self::assert($tokyo['continent_code'] === 'AS' && $tokyo['country_code'] === 'JP' && $tokyo['region'] === 'Tokyo' && $tokyo['city'] === 'Tokyo', "Worldwide polygon resolves Tokyo, Japan (AS/JP)", $results);

        $dubai = \Helpers\GeoLocation::resolveCoordinates(25.2048, 55.2708, 20.0);
        self::assert($dubai['continent_code'] === 'AS' && $dubai['country_code'] === 'AE' && $dubai['city'] === 'Dubai', "Worldwide polygon resolves Dubai, UAE (AS/AE)", $results);

        // 4. North America: New York, USA & Montréal, Canada
        $nyc = \Helpers\GeoLocation::resolveCoordinates(40.7128, -74.0060, 20.0);
        self::assert($nyc['continent_code'] === 'NA' && $nyc['country_code'] === 'US' && $nyc['region'] === 'New York' && $nyc['admin_type'] === 'state' && $nyc['city'] === 'New York', "Worldwide polygon resolves New York, NY State, US (NA/US)", $results);

        $mtl = \Helpers\GeoLocation::resolveCoordinates(45.5017, -73.5673, 20.0);
        self::assert($mtl['continent_code'] === 'NA' && $mtl['country_code'] === 'CA' && $mtl['region'] === 'Quebec' && $mtl['admin_type'] === 'province' && $mtl['city'] === 'Montréal', "Worldwide polygon resolves Montréal, Province of Quebec, Canada (NA/CA)", $results);

        // 5. South America: São Paulo, Brazil
        $sp = \Helpers\GeoLocation::resolveCoordinates(-23.5505, -46.6333, 20.0);
        self::assert($sp['continent_code'] === 'SA' && $sp['country_code'] === 'BR' && $sp['region'] === 'São Paulo' && $sp['city'] === 'São Paulo', "Worldwide polygon resolves São Paulo, Brazil (SA/BR)", $results);

        // 6. Africa: Johannesburg, South Africa
        $jhb = \Helpers\GeoLocation::resolveCoordinates(-26.2041, 28.0473, 20.0);
        self::assert($jhb['continent_code'] === 'AF' && $jhb['country_code'] === 'ZA' && $jhb['region'] === 'Gauteng' && $jhb['city'] === 'Johannesburg', "Worldwide polygon resolves Johannesburg, Gauteng, South Africa (AF/ZA)", $results);

        // 7. Oceania: Sydney, Australia
        $syd = \Helpers\GeoLocation::resolveCoordinates(-33.8688, 151.2093, 20.0);
        self::assert($syd['continent_code'] === 'OC' && $syd['country_code'] === 'AU' && $syd['region'] === 'New South Wales' && $syd['city'] === 'Sydney', "Worldwide polygon resolves Sydney, NSW, Australia (OC/AU)", $results);

        // 8. Configurable External Reverse Geocoder with Cache, Quota, Normalization & API Key Masking
        \Helpers\GeoLocation::setMockReverseGeocoder(function ($lat, $lon) {
            return [
                'results' => [[
                    'components' => [
                        'ISO_3166-1_alpha-2' => 'CH',
                        'ISO_3166-2' => ['CH-GE'],
                        'country' => 'Switzerland',
                        'state' => 'Geneva',
                        'city' => 'Genève',
                    ]
                ]]
            ];
        });
        $geneva1 = \Helpers\GeoLocation::resolveCoordinates(46.2044, 6.1432, 15.0);
        $geneva2 = \Helpers\GeoLocation::resolveCoordinates(46.2044, 6.1432, 15.0); // Should hit grid cache
        $stats = \Helpers\GeoLocation::getReverseGeocoderStats();
        self::assert($geneva1['country'] === 'Switzerland' && $geneva1['country_code'] === 'CH' && $geneva1['region'] === 'Geneva' && $geneva1['city'] === 'Genève', "External reverse geocoder resolves Genève, Switzerland with ISO codes", $results);
        self::assert($stats['requests_total'] === 1 && $stats['cache_hits'] === 1, "Reverse geocoder caches coordinate cell and increments cache_hits without duplicate API call", $results);
        self::assert(\Helpers\GeoLocation::maskApiKey('abcdef1234567890xyz') === 'abc*************xyz', "maskApiKey hides sensitive API key characters", $results);
        \Helpers\GeoLocation::clearCache();
    }

    public static function testGpsAccuracyAndBorderProximityDegradation(&$results) {
        echo "Running GeoLocationTest::testGpsAccuracyAndBorderProximityDegradation...\n";
        \Helpers\GeoLocation::clearCache();

        // 1. Very low GPS accuracy (> 50km, e.g. 75,000m) -> degrades to country only
        $coarseCountry = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 75000.0);
        self::assert($coarseCountry['country'] === 'Madagascar' && $coarseCountry['region'] === null && $coarseCountry['city'] === null && $coarseCountry['precision'] === 'country' && $coarseCountry['missing_reason'] === 'low_gps_accuracy', "GPS accuracy > 50km degrades to country only (never attributes uncertain region or city)", $results);

        // 2. Moderate GPS accuracy (e.g. 8,000m = 8km inside Mananjary) -> degrades city to null, keeps region Vatovavy
        $coarseCity = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 8000.0);
        self::assert($coarseCity['country'] === 'Madagascar' && $coarseCity['region'] === 'Vatovavy' && $coarseCity['city'] === null && $coarseCity['precision'] === 'region' && $coarseCity['missing_reason'] === 'low_gps_accuracy_for_city', "GPS accuracy > 5km degrades city to null while preserving region Vatovavy", $results);

        // 3. Proximity to regional border between Vatovavy and Fitovinany (border at lat = -21.60)
        // Visitor at lat = -21.595 (approx 0.55 km from border) with GPS uncertainty of 2,500m (2.5 km)
        $borderZone = \Helpers\GeoLocation::resolveCoordinates(-21.595, 48.00, 2500.0);
        self::assert($borderZone['country'] === 'Madagascar' && $borderZone['region'] === null && $borderZone['city'] === null && $borderZone['precision'] === 'country' && $borderZone['missing_reason'] === 'region_border_uncertainty', "Point near Vatovavy/Fitovinany border with overlapping GPS uncertainty degrades to country level", $results);

        // 4. Ocean / International waters (Indian Ocean: -20.0, 65.0)
        $ocean = \Helpers\GeoLocation::resolveCoordinates(-20.0, 65.0, 20.0);
        self::assert($ocean['country'] === null && $ocean['region'] === null && $ocean['city'] === null && $ocean['precision'] === 'unknown' && $ocean['missing_reason'] === 'out_of_coverage', "Ocean / out-of-coverage coordinates return null country/region/city and 'out_of_coverage'", $results);

        // 5. Invalid coordinates
        $oob = \Helpers\GeoLocation::resolveCoordinates(999.0, -999.0);
        self::assert($oob['precision'] === 'unknown' && $oob['missing_reason'] === 'invalid_coordinates', "Out-of-bounds coordinates return 'invalid_coordinates'", $results);
    }

    public static function testGeographicHierarchyAndGlobalQualityMetrics(&$results) {
        echo "Running GeoLocationTest::testGeographicHierarchyAndGlobalQualityMetrics...\n";

        // Level 1: City known -> "Mananjary, Madagascar"
        $l1 = \Helpers\GeoLocation::formatLocationName('Mananjary', 'Vatovavy', 'Madagascar');
        self::assert($l1 === 'Mananjary, Madagascar', "Level 1 (City identified): 'Mananjary, Madagascar'", $results);

        // Level 2: Region known, city unknown -> "Région : Vatovavy — ville non déterminée, Madagascar"
        $l2 = \Helpers\GeoLocation::formatLocationName(null, 'Vatovavy', 'Madagascar');
        self::assert($l2 === 'Région : Vatovavy — ville non déterminée, Madagascar', "Level 2 (Region identified, city unknown): 'Région : Vatovavy — ville non déterminée, Madagascar'", $results);

        // Level 3: Country only -> "Madagascar — ville non déterminée"
        $l3 = \Helpers\GeoLocation::formatLocationName(null, null, 'Madagascar');
        self::assert($l3 === 'Madagascar — ville non déterminée', "Level 3 (Country only): 'Madagascar — ville non déterminée'", $results);

        // Global quality metrics with percentages & continents
        $sampleAggregations = [
            ['city' => 'Mananjary', 'region' => 'Vatovavy',   'country' => 'Madagascar', 'country_code' => 'MG', 'count' => 15],
            ['city' => 'Paris',     'region' => 'Île-de-France', 'country' => 'France',  'country_code' => 'FR', 'count' => 10],
            ['city' => '',          'region' => 'Fitovinany', 'country' => 'Madagascar', 'country_code' => 'MG', 'count' => 15],
            ['city' => 'Unknown',   'region' => '',           'country' => 'Madagascar', 'country_code' => 'MG', 'count' => 10],
        ];
        $metrics = \Helpers\GeoLocation::calculateQualityMetrics($sampleAggregations);
        self::assert($metrics['identified_city_count'] === 25 && $metrics['city_percent'] === 50.0, "Quality metrics computes 25 city clicks (50.0%)", $results);
        self::assert($metrics['region_only_count'] === 15 && $metrics['region_percent'] === 30.0, "Quality metrics computes 15 region-only clicks (30.0%)", $results);
        self::assert($metrics['country_only_count'] === 10 && $metrics['country_only_percent'] === 20.0, "Quality metrics computes 10 country-only clicks (20.0%)", $results);
        self::assert($metrics['continents']['Africa'] === 40 && $metrics['continents']['Europe'] === 10, "Quality metrics aggregates scans by continent (Africa=40, Europe=10)", $results);
    }

    public static function testGeoConsentEndpointSecurityAndAntiReplay(&$results) {
        echo "Running GeoLocationTest::testGeoConsentEndpointSecurityAndAntiReplay...\n";

        // Set up an in-memory SQLite database via \Core\DB to test LinkController::geoConsent() end-to-end
        \Core\DB::configure('sqlite::memory:');
        \Core\DB::configure('return_result_sets', true);
        $pdo = \Core\DB::get_db();

        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo->exec("CREATE TABLE `{$prefix}url` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `userid` INTEGER DEFAULT 0,
            `alias` TEXT,
            `custom` TEXT,
            `url` TEXT,
            `options` TEXT,
            `status` INTEGER DEFAULT 1
        )");
        $pdo->exec("CREATE TABLE `{$prefix}stats` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `short` TEXT,
            `urlid` INTEGER,
            `urluserid` INTEGER DEFAULT 0,
            `date` TEXT,
            `ip` TEXT,
            `country` TEXT,
            `region` TEXT,
            `city` TEXT,
            `geo_source` TEXT,
            `accuracy_radius` INTEGER
        )");
        \Helpers\GeoLocation::resetColumnCache();

        // Insert Link 1 (geoconsent enabled, WhatsApp target) and Link 2 (geoconsent disabled)
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `options`) VALUES
            (1, 'wa-promo', 'https://wa.me/261340000000?text=Bonjour', '{\"geoconsent\":true}'),
            (2, 'no-consent', 'https://example.com/direct', '{}')
        ");

        // Insert Stat 10 for Link 1 (Visitor IP 196.192.32.1) and Stat 20 for Link 2 (Other visitor)
        $pdo->exec("INSERT INTO `{$prefix}stats` (`id`, `short`, `urlid`, `ip`, `country`, `region`, `city`, `geo_source`) VALUES
            (10, 'wa-promo', 1, '196.192.32.1', 'Madagascar', NULL, NULL, 'maxmind'),
            (20, 'no-consent', 2, '196.192.32.1', 'Madagascar', NULL, NULL, 'maxmind')
        ");

        require_once ROOT . '/app/controllers/LinkController.php';
        $controller = new \Link();

        $_SERVER['REMOTE_ADDR'] = '196.192.32.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Case 1: Link without geoconsent option enabled must be rejected (403)
        \Core\Request::setRawBody(json_encode(['latitude' => -21.223, 'longitude' => 48.341, 'consent_token' => 'any']));
        $req1 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req1, 'no-consent');
        $out1 = json_decode(ob_get_clean(), true);
        self::assert(\Core\Response::lastStatusCode() === 403 && !empty($out1['error']), "geoConsent rejects link where geoconsent option is not enabled (403)", $results);

        // Case 2: Request without valid session consent_token must be rejected (403)
        $_SESSION = [];
        \Core\Request::setRawBody(json_encode(['latitude' => -21.223, 'longitude' => 48.341, 'consent_token' => 'forged-token']));
        $req2 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req2, 'wa-promo');
        $out2 = json_decode(ob_get_clean(), true);
        self::assert(\Core\Response::lastStatusCode() === 403 && !empty($out2['error']), "geoConsent rejects request with missing/forged session consent_token (403)", $results);

        // Case 3: Cross-link stat modification attack (session points to stat 20 which belongs to url 2, while calling wa-promo url 1)
        $_SESSION = [
            'last_stat_1' => 20,
            'geoconsent_nonce_1' => 'valid-nonce-cross-link',
        ];
        \Core\Request::setRawBody(json_encode(['latitude' => -21.223, 'longitude' => 48.341, 'consent_token' => 'valid-nonce-cross-link']));
        $req3 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req3, 'wa-promo');
        $out3 = json_decode(ob_get_clean(), true);
        $stat20 = \Core\DB::stats()->where('id', 20)->first();
        self::assert(\Core\Response::lastStatusCode() === 403 && $stat20->city === null, "geoConsent blocks cross-link stat modification when stat.urlid != url.id (403)", $results);

        // Case 4: Cross-visitor IP mismatch attack (stat 10 belongs to 196.192.32.1, attacker sends from 102.16.0.55)
        $_SERVER['REMOTE_ADDR'] = '102.16.0.55';
        $_SESSION = [
            'last_stat_1' => 10,
            'geoconsent_nonce_1' => 'valid-nonce-ip-mismatch',
        ];
        \Core\Request::setRawBody(json_encode(['latitude' => -21.223, 'longitude' => 48.341, 'consent_token' => 'valid-nonce-ip-mismatch']));
        $req4 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req4, 'wa-promo');
        $out4 = json_decode(ob_get_clean(), true);
        self::assert(\Core\Response::lastStatusCode() === 403 && !empty($out4['error']), "geoConsent blocks cross-visitor modification when requester IP does not match stat IP (403)", $results);

        // Case 5: Valid consent flow via Gate::consentLocation() -> updates stat 10 with Mananjary / Vatovavy and blocks replay
        $_SERVER['REMOTE_ADDR'] = '196.192.32.1';
        $_SESSION = ['last_stat_1' => 10];
        $urlObj = \Core\DB::url()->where('id', 1)->first();
        ob_start();
        \Helpers\Gate::consentLocation($urlObj);
        ob_end_clean();
        $generatedNonce = $_SESSION['geoconsent_nonce_1'] ?? '';
        self::assert(!empty($generatedNonce) && strlen($generatedNonce) === 64, "Gate::consentLocation generates 64-char HMAC-SHA256 scan-bound nonce", $results);

        \Core\Request::setRawBody(json_encode([
            'latitude' => -21.223,
            'longitude' => 48.341,
            'accuracy' => 18.0,
            'consent_token' => $generatedNonce,
        ]));
        $req5 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req5, 'wa-promo');
        $out5 = json_decode(ob_get_clean(), true);

        $updatedStat10 = \Core\DB::stats()->where('id', 10)->first();
        self::assert(\Core\Response::lastStatusCode() === 200 && !empty($out5['success']) && $out5['redirect'] === 'https://wa.me/261340000000?text=Bonjour', "Valid geoConsent returns 200 and preserves WhatsApp target redirect URL", $results);
        self::assert($updatedStat10->country === 'Madagascar' && $updatedStat10->region === 'Vatovavy' && $updatedStat10->city === 'Mananjary' && $updatedStat10->geo_source === 'browser_consent', "Valid geoConsent updates stat 10 with Mananjary, Vatovavy, Madagascar and geo_source='browser_consent'", $results);

        // Verify no raw GPS coordinates are stored in any column of stats
        $rawRow = $pdo->query("SELECT * FROM `{$prefix}stats` WHERE id = 10")->fetch(\PDO::FETCH_ASSOC);
        $serializedRow = json_encode($rawRow);
        self::assert(strpos($serializedRow, '-21.223') === false && strpos($serializedRow, '48.341') === false, "Raw GPS coordinates (-21.223, 48.341) are NEVER stored in the stats table", $results);

        // Case 6: Replay attack with the same token must be rejected with 409 Conflict
        $req6 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req6, 'wa-promo');
        $out6 = json_decode(ob_get_clean(), true);
        self::assert(\Core\Response::lastStatusCode() === 409 && !empty($out6['error']), "Replaying geoConsent for the same scan is rejected with 409 Conflict", $results);

        \Core\Request::setRawBody(null);
    }

    public static function testCliMigrationAndRepairScriptsOnRealDatabase(&$results) {
        echo "Running GeoLocationTest::testCliMigrationAndRepairScriptsOnRealDatabase...\n";

        $phpBin = PHP_BINARY;
        $migrateScript = ROOT . '/scripts/migrate-geo-stats.php';
        $repairScript = ROOT . '/scripts/geo-repair.php';
        $cliBootstrap = ROOT . '/scripts/cli-bootstrap.php';

        // 1. Verify scripts/cli-bootstrap.php is independent of tests/bootstrap.php and never defines _STATE = 'TEST'
        $bootstrapSource = file_get_contents($cliBootstrap);
        $migrateSource = file_get_contents($migrateScript);
        $repairSource = file_get_contents($repairScript);
        self::assert(
            strpos($bootstrapSource, 'tests/bootstrap.php') === false &&
            strpos($migrateSource, 'tests/bootstrap.php') === false &&
            strpos($repairSource, 'tests/bootstrap.php') === false &&
            strpos($bootstrapSource, "define('_STATE', 'TEST')") === false,
            "CLI scripts and cli-bootstrap.php are completely independent of tests/bootstrap.php and _STATE='TEST'",
            $results
        );

        // 2. Verify migrate-geo-stats.php fails with non-zero exit code when DB is unavailable
        $cmdFail = sprintf('CLI_DB_DSN="sqlite:/nonexistent_dir_xyz/impossible.sqlite" %s %s --status 2>&1', escapeshellarg($phpBin), escapeshellarg($migrateScript));
        $outFail = [];
        $codeFail = 0;
        exec($cmdFail, $outFail, $codeFail);
        self::assert($codeFail !== 0, "migrate-geo-stats.php exits with non-zero code ({$codeFail}) when database connection fails", $results);

        // 3. Create a real temporary SQLite database with legacy stats table (without region, geo_source, accuracy_radius)
        $tmpSqlite = sys_get_temp_dir() . '/codex_geo_test_' . uniqid() . '.sqlite';
        $testPdo = new \PDO('sqlite:' . $tmpSqlite);
        $testPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $testPdo->exec("CREATE TABLE `stats` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `short` TEXT,
            `urlid` INTEGER,
            `urluserid` INTEGER DEFAULT 0,
            `date` TEXT,
            `ip` TEXT,
            `country` TEXT,
            `city` TEXT,
            `referer` TEXT,
            `domain` TEXT,
            `os` TEXT,
            `browser` TEXT,
            `language` TEXT
        )");

        // Insert historical records:
        // - Row 1: Public Madagascar fixed IP (41.188.0.1 -> Antananarivo) with missing city
        // - Row 2: Public France IP (80.12.34.56 -> Andrésy / Île-de-France) with 'Unknown' city
        // - Row 3: MD5 hashed IP (d41d8cd98f00b204e9800998ecf8427e) -> must NEVER be modified
        // - Row 4: Private IP (192.168.1.50) -> must be skipped
        $testPdo->exec("INSERT INTO `stats` (`id`, `short`, `urlid`, `ip`, `country`, `city`) VALUES
            (1, 'qr1', 1, '41.188.0.1', 'madagascar', ''),
            (2, 'qr2', 1, '80.12.34.56', 'france', 'Unknown'),
            (3, 'qr3', 1, 'd41d8cd98f00b204e9800998ecf8427e', 'madagascar', ''),
            (4, 'qr4', 1, '192.168.1.50', 'madagascar', '')
        ");
        $testPdo = null;

        $envPrefix = sprintf('CLI_DB_DSN=%s CLI_DB_PREFIX=""', escapeshellarg('sqlite:' . $tmpSqlite));

        // 4. Test migrate-geo-stats.php --status (before migration)
        $outStatus1 = [];
        $codeStatus1 = 0;
        exec("{$envPrefix} " . escapeshellarg($phpBin) . " " . escapeshellarg($migrateScript) . " --status 2>&1", $outStatus1, $codeStatus1);
        $statusText1 = implode("\n", $outStatus1);
        self::assert($codeStatus1 === 0 && strpos($statusText1, 'PENDING (3 missing)') !== false, "migrate-geo-stats.php --status detects 3 missing columns before migration", $results);

        // 5. Test migrate-geo-stats.php --apply WITHOUT --confirm (must refuse with exit code 1)
        $outNoConfirm = [];
        $codeNoConfirm = 0;
        exec("{$envPrefix} " . escapeshellarg($phpBin) . " " . escapeshellarg($migrateScript) . " --apply 2>&1", $outNoConfirm, $codeNoConfirm);
        self::assert($codeNoConfirm !== 0, "migrate-geo-stats.php --apply without --confirm is rejected with non-zero exit code", $results);

        // 6. Test migrate-geo-stats.php --apply --confirm (executes real migration)
        $outApply = [];
        $codeApply = 0;
        exec("{$envPrefix} " . escapeshellarg($phpBin) . " " . escapeshellarg($migrateScript) . " --apply --confirm 2>&1", $outApply, $codeApply);
        $applyText = implode("\n", $outApply);
        self::assert($codeApply === 0 && strpos($applyText, 'Migration completed successfully') !== false, "migrate-geo-stats.php --apply --confirm succeeds on real test database", $results);

        // 7. Verify idempotency: running --apply --confirm a second time succeeds without error
        $outApply2 = [];
        $codeApply2 = 0;
        exec("{$envPrefix} " . escapeshellarg($phpBin) . " " . escapeshellarg($migrateScript) . " --apply --confirm 2>&1", $outApply2, $codeApply2);
        self::assert($codeApply2 === 0 && strpos(implode("\n", $outApply2), 'already exist') !== false, "migrate-geo-stats.php is idempotent on second execution", $results);

        // 8. Test geo-repair.php --dry-run (does not modify DB)
        $outRepairDry = [];
        $codeRepairDry = 0;
        exec("{$envPrefix} " . escapeshellarg($phpBin) . " " . escapeshellarg($repairScript) . " --dry-run 2>&1", $outRepairDry, $codeRepairDry);
        $repairDryText = implode("\n", $outRepairDry);
        self::assert($codeRepairDry === 0 && strpos($repairDryText, 'Database rows updated       : 0') !== false, "geo-repair.php --dry-run inspects records without writing to DB", $results);

        // 9. Test geo-repair.php --apply --confirm (repairs unhashed public IPs, skips hashed & private IPs)
        $outRepairApply = [];
        $codeRepairApply = 0;
        exec("{$envPrefix} " . escapeshellarg($phpBin) . " " . escapeshellarg($repairScript) . " --apply --confirm 2>&1", $outRepairApply, $codeRepairApply);
        $repairApplyText = implode("\n", $outRepairApply);
        self::assert($codeRepairApply === 0 && strpos($repairApplyText, 'Database rows updated       : 2') !== false, "geo-repair.php --apply --confirm updates the 2 repairable public IP rows", $results);

        // Verify database contents after repair
        $verifyPdo = new \PDO('sqlite:' . $tmpSqlite);
        $rows = $verifyPdo->query("SELECT id, ip, country, region, city, geo_source FROM `stats` ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);
        self::assert($rows[0]['city'] === 'Antananarivo' && $rows[0]['geo_source'] === 'maxmind_repair', "Row 1 (41.188.0.1) repaired to city 'Antananarivo' with geo_source='maxmind_repair'", $results);
        self::assert($rows[1]['city'] === 'Andrésy' && $rows[1]['region'] === 'Île-de-France', "Row 2 (80.12.34.56) repaired to city 'Andrésy' and region 'Île-de-France'", $results);
        self::assert($rows[2]['city'] === '' && $rows[2]['region'] === null, "Row 3 (MD5 hashed IP) strictly untouched by geo-repair.php", $results);
        self::assert($rows[3]['city'] === '' && $rows[3]['region'] === null, "Row 4 (private IP) strictly untouched by geo-repair.php", $results);
        $verifyPdo = null;

        @unlink($tmpSqlite);
    }
}
