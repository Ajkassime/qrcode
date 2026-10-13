<?php
/**
 * Comprehensive Test Suite: Worldwide & Madagascar Geolocation, Production Reverse Geocoding,
 * Shared Cross-Process Cache & Quotas, GPS Accuracy & Privacy Rules, and CLI Migration/Repair Scripts
 */

class GeoLocationTest {

    public static function run(&$results) {
        self::testIpDetectionAndSpoofingProtection($results);
        self::testWorldwideMaxmindAndIpv6Resolution($results);
        self::testRemovalOfFakeBoundariesAndUniqueMadagascarCodes($results);
        self::testProviderPriorityAndAllFiveReverseGeocoders($results);
        self::testSharedCrossProcessCacheExpirationAndQuotas($results);
        self::testMissingAndCoarseGpsAccuracyAndBorderDegradation($results);
        self::testGeographicHierarchyAndGlobalQualityMetrics($results);
        self::testGeoConsentEndpointPrivacyAndExternalFailureResilience($results);
        self::testCliMigrationAndRepairScriptsOnRealDatabase($results);
        self::testIsolatedHttpServerConsentAndWhatsAppLifecycle($results);
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
        self::assert($mgMobile['location_level'] === 'country' && $mgMobile['precision'] === 'country' && $mgMobile['missing_reason'] === 'country_level_only', "Location level is 'country' with reason 'country_level_only'", $results);
        self::assert($mgMobile['source'] === 'maxmind' && $mgMobile['provider'] === 'maxmind_geolite2' && $mgMobile['declared_accuracy_meters'] === null, "MaxMind IP resolution distinguishes source='maxmind', provider='maxmind_geolite2', declared_accuracy_meters=null", $results);

        // 2. Africa — Madagascar fixed ISP with city POP (41.188.0.1) -> normalized to Analamanga (MG-ANA)
        $mgCity = \Helpers\GeoLocation::locate('41.188.0.1');
        self::assert($mgCity['country'] === 'Madagascar' && $mgCity['city'] === 'Antananarivo' && $mgCity['location_level'] === 'city', "MaxMind resolves city 'Antananarivo' for 41.188.0.1", $results);
        self::assert($mgCity['region'] === 'Analamanga' && $mgCity['region_code'] === 'MG-ANA', "Madagascar city Antananarivo is normalized to unique regional code MG-ANA (Analamanga)", $results);

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
                    'region_code'     => 'MG-F', // Legacy province code should be normalized to MG-VAT
                    'city'            => null,
                    'accuracy_radius' => 25,
                ];
            }
            return null;
        });
        $enriched = \Helpers\GeoLocation::locate('196.192.32.1', ['bypass_cache' => true]);
        self::assert($enriched['region'] === 'Vatovavy' && $enriched['region_code'] === 'MG-VAT' && $enriched['city'] === null && $enriched['location_level'] === 'region' && $enriched['source'] === 'secondary_provider', "Secondary provider enriches region with unique code MG-VAT without inventing city", $results);
        \Helpers\GeoLocation::clearCache();

        // Test GEOSECONDARY_URL parameter interpolation
        $customUrlInterpolated = null;
        \Helpers\GeoLocation::setSecondaryProviderConfig([
            'enabled'   => true,
            'provider'  => 'custom',
            'api_key'   => 'sec_secret_key',
            'url'       => 'https://geo-custom.example.org/lookup?ip={ip}&token={key}',
            'cache_ttl' => 0,
        ]);
        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$customUrlInterpolated) {
            $customUrlInterpolated = $url;
            return json_encode([
                'city'         => 'Mananjary',
                'region'       => 'Vatovavy',
                'country_name' => 'Madagascar',
                'country'      => 'MG',
            ]);
        });
        $secUrlRes = \Helpers\GeoLocation::locate('196.192.32.1', ['bypass_cache' => true]);
        self::assert(
            $customUrlInterpolated === 'https://geo-custom.example.org/lookup?ip=196.192.32.1&token=sec_secret_key' &&
            $secUrlRes['region'] === 'Vatovavy' && $secUrlRes['city'] === 'Mananjary',
            "GEOSECONDARY_URL correctly interpolates {ip} and {key} parameters for custom secondary endpoints",
            $results
        );
        \Helpers\GeoLocation::clearCache();
    }

    public static function testRemovalOfFakeBoundariesAndUniqueMadagascarCodes(&$results) {
        echo "Running GeoLocationTest::testRemovalOfFakeBoundariesAndUniqueMadagascarCodes...\n";
        \Helpers\GeoLocation::clearCache();

        // 1. Default geo_boundaries.json must have authoritative_polygons=false and no approximate polygons
        self::assert(\Helpers\GeoLocation::hasAuthoritativeLocalBoundaries() === false, "Default geo_boundaries.json is marked authoritative_polygons=false and never used as fake polygons in production", $results);

        // 2. Verify all 23 regions of Madagascar have unique administrative codes (no duplicate MG-T, MG-F, etc.)
        $mgRegistry = \Helpers\GeoLocation::getMadagascarRegionsRegistry();
        $regionNames = array_column($mgRegistry, 'name');
        $regionCodes = array_column($mgRegistry, 'code');
        self::assert(count($mgRegistry) === 23 && count(array_unique($regionNames)) === 23, "Madagascar registry defines all 23 administrative regions", $results);
        self::assert(count(array_unique($regionCodes)) === 23, "All 23 Madagascar regions have strictly unique administrative codes (zero duplicate codes)", $results);
        self::assert(!in_array('MG-T', $regionCodes, true) && !in_array('MG-F', $regionCodes, true), "Legacy duplicate province codes MG-T and MG-F are no longer used as regional identifiers", $results);

        // 3. Verify isAuthoritativePolygon rejects 4-corner bounding-box rectangles
        $rectangle = [[47.42, -19.02], [47.62, -19.02], [47.62, -18.78], [47.42, -18.78], [47.42, -19.02]];
        $multiVertexPoly = [[47.45, -18.80], [47.55, -18.78], [47.62, -18.84], [47.60, -18.95], [47.52, -19.02], [47.43, -18.98], [47.40, -18.88], [47.45, -18.80]];
        self::assert(\Helpers\GeoLocation::isAuthoritativePolygon($rectangle) === false, "isAuthoritativePolygon rejects 4-corner rectangular bounding boxes", $results);
        self::assert(\Helpers\GeoLocation::isAuthoritativePolygon($multiVertexPoly) === true, "isAuthoritativePolygon accepts true multi-vertex non-rectangular polygons (>= 6 unique vertices)", $results);

        // 4. Without an external reverse geocoder configured, resolveCoordinates must NOT attribute any city/region from local file
        $unconfigured = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 20.0);
        self::assert(
            $unconfigured['country'] === null &&
            $unconfigured['region'] === null &&
            $unconfigured['city'] === null &&
            $unconfigured['location_level'] === 'unknown' &&
            $unconfigured['missing_reason'] === 'reverse_geocoder_disabled',
            "Without an active reverse geocoder, resolveCoordinates never invents a city or region from local approximate polygons",
            $results
        );

        // 5. Verify formal distinction between ISO 3166-2:MG provinces and internal INSTAT regional codes
        $boundariesJson = json_decode(file_get_contents(STORAGE . '/app/geo_boundaries.json'), true);
        self::assert(
            isset($boundariesJson['code_standard_classification']['official_iso_3166_2_provinces']) &&
            isset($boundariesJson['code_standard_classification']['internal_instat_regional_codes']),
            "geo_boundaries.json clearly distinguishes official ISO 3166-2:MG provinces from internal INSTAT regional codes",
            $results
        );
    }

    public static function testProviderPriorityAndAllFiveReverseGeocoders(&$results) {
        echo "Running GeoLocationTest::testProviderPriorityAndAllFiveReverseGeocoders...\n";
        \Helpers\GeoLocation::clearCache();

        $tmpCacheDir = sys_get_temp_dir() . '/codex_geocache_test_' . uniqid();
        \Helpers\GeoLocation::setCacheDirectory($tmpCacheDir);

        // 1. Test Provider Priority:
        // Even if a custom file contains a rectangular polygon claiming a wrong city ("FakeLocalCity"),
        // 4-corner rectangles are rejected AND the external reverse geocoder takes priority.
        $tmpFakeBoundaries = sys_get_temp_dir() . '/codex_fake_boundaries_' . uniqid() . '.json';
        file_put_contents($tmpFakeBoundaries, json_encode([
            'authoritative_polygons' => true,
            'countries' => [[
                'country' => 'Madagascar',
                'country_code' => 'MG',
                'continent' => 'Africa',
                'continent_code' => 'AF',
                'regions' => [[
                    'name' => 'FakeLocalRegion',
                    'code' => 'MG-FAKE',
                    'polygon' => [[47.0, -22.5], [49.0, -22.5], [49.0, -20.5], [47.0, -20.5], [47.0, -22.5]], // 4-corner rectangle
                    'cities' => [[
                        'name' => 'FakeLocalCity',
                        'polygon' => [[48.0, -21.5], [48.6, -21.5], [48.6, -21.0], [48.0, -21.0], [48.0, -21.5]],
                    ]],
                ]],
            ]],
        ]));
        \Helpers\GeoLocation::setBoundariesPath($tmpFakeBoundaries);

        $externalCalled = false;
        \Helpers\GeoLocation::setMockReverseGeocoder(function ($lat, $lon) use (&$externalCalled) {
            $externalCalled = true;
            return [
                'country'      => 'Madagascar',
                'country_code' => 'MG',
                'region'       => 'Vatovavy',
                'city'         => 'Mananjary',
                '_provider'    => 'opencage',
            ];
        });

        $priorityRes = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 18.0);
        self::assert(
            $externalCalled === true &&
            $priorityRes['city'] === 'Mananjary' &&
            $priorityRes['region'] === 'Vatovavy' &&
            $priorityRes['region_code'] === 'MG-VAT' &&
            $priorityRes['provider'] === 'opencage',
            "External reverse geocoder is queried first and overrides/rejects any local rectangular boundary",
            $results
        );
        @unlink($tmpFakeBoundaries);
        \Helpers\GeoLocation::clearCache();
        \Helpers\GeoLocation::setCacheDirectory($tmpCacheDir);

        // 2. Test all 5 implemented providers (OpenCage, BigDataCloud, Nominatim, Google, Mapbox)
        //    against independent reference coordinates across 6 continents + rural areas + countries without local polygons

        // Provider A: OpenCage (Geneva, Switzerland [EU] & Mananjary, Madagascar [AF])
        $lastRequestedUrl = null;
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'  => true,
            'provider' => 'opencage',
            'api_key'  => 'oc_live_test_key_123456',
            'timeout'  => 1.5,
            'cache_ttl' => 3600,
        ]);
        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$lastRequestedUrl) {
            $lastRequestedUrl = $url;
            if (strpos($url, '46.2044') !== false) {
                return json_encode([
                    'results' => [[
                        'components' => [
                            'ISO_3166-1_alpha-2' => 'CH',
                            'ISO_3166-2'         => ['CH-GE'],
                            'country'            => 'Switzerland',
                            'state'              => 'Geneva',
                            'city'               => 'Genève',
                        ],
                    ]],
                ]);
            }
            return json_encode([
                'results' => [[
                    'components' => [
                        'ISO_3166-1_alpha-2' => 'MG',
                        'country'            => 'Madagascar',
                        'state'              => 'Vatovavy',
                        'city'               => 'Mananjary',
                    ],
                ]],
            ]);
        });

        $ocGeneva = \Helpers\GeoLocation::resolveCoordinates(46.2044, 6.1432, 15.0);
        self::assert(
            strpos($lastRequestedUrl, 'api.opencagedata.com/geocode/v1/json') !== false &&
            $ocGeneva['country_code'] === 'CH' && $ocGeneva['region_code'] === 'CH-GE' && $ocGeneva['city'] === 'Genève' && $ocGeneva['provider'] === 'opencage',
            "OpenCage adapter builds valid URL and resolves Genève, Switzerland (EU/CH-GE)",
            $results
        );

        $ocMananjary = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 20.0);
        self::assert(
            $ocMananjary['country_code'] === 'MG' && $ocMananjary['region'] === 'Vatovavy' && $ocMananjary['region_code'] === 'MG-VAT' && $ocMananjary['city'] === 'Mananjary',
            "OpenCage adapter resolves Mananjary, Vatovavy (MG-VAT), Madagascar",
            $results
        );

        // Provider B: BigDataCloud (Sydney, Australia [OC] & Manakara, Fitovinany, Madagascar [AF])
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'   => true,
            'provider'  => 'bigdatacloud',
            'api_key'   => 'bdc_key_987654',
            'cache_ttl' => 3600,
        ]);
        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$lastRequestedUrl) {
            $lastRequestedUrl = $url;
            if (strpos($url, '-33.8688') !== false) {
                return json_encode([
                    'continent'                => 'Oceania',
                    'continentCode'            => 'OC',
                    'countryName'              => 'Australia',
                    'countryCode'              => 'AU',
                    'principalSubdivision'     => 'New South Wales',
                    'principalSubdivisionCode' => 'AU-NSW',
                    'city'                     => 'Sydney',
                ]);
            }
            return json_encode([
                'countryName'              => 'Madagascar',
                'countryCode'              => 'MG',
                'principalSubdivision'     => 'Fitovinany',
                'principalSubdivisionCode' => 'MG-F',
                'city'                     => 'Manakara',
            ]);
        });

        $bdcSydney = \Helpers\GeoLocation::resolveCoordinates(-33.8688, 151.2093, 18.0);
        self::assert(
            strpos($lastRequestedUrl, 'api.bigdatacloud.net/data/reverse-geocode') !== false &&
            $bdcSydney['continent_code'] === 'OC' && $bdcSydney['country_code'] === 'AU' && $bdcSydney['region_code'] === 'AU-NSW' && $bdcSydney['city'] === 'Sydney' && $bdcSydney['provider'] === 'bigdatacloud',
            "BigDataCloud adapter resolves Sydney, New South Wales, Australia (OC/AU-NSW)",
            $results
        );

        $bdcManakara = \Helpers\GeoLocation::resolveCoordinates(-22.148, 48.010, 22.0);
        self::assert(
            $bdcManakara['region'] === 'Fitovinany' && $bdcManakara['region_code'] === 'MG-FIT' && $bdcManakara['city'] === 'Manakara',
            "BigDataCloud adapter resolves Manakara, Fitovinany (MG-FIT), distinct from Vatovavy",
            $results
        );

        // Provider C: Nominatim / OpenStreetMap (Nairobi, Kenya [AF] & Rural Vatovavy, Madagascar [AF])
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'    => true,
            'provider'   => 'nominatim',
            'user_agent' => 'QRCodeApp-TestSuite/1.0',
            'cache_ttl'  => 3600,
        ]);
        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$lastRequestedUrl) {
            $lastRequestedUrl = $url;
            if (strpos($url, '-1.2921') !== false) {
                return json_encode([
                    'address' => [
                        'city'           => 'Nairobi',
                        'state'          => 'Nairobi County',
                        'ISO3166-2-lvl4' => 'KE-30',
                        'country'        => 'Kenya',
                        'country_code'   => 'ke',
                    ],
                ]);
            }
            // Rural area in Vatovavy (-21.30, 47.63): Nominatim returns hamlet/village without city/town/municipality
            return json_encode([
                'address' => [
                    'hamlet'       => 'Ambodiara Rural',
                    'state'        => 'Vatovavy',
                    'country'      => 'Madagascar',
                    'country_code' => 'mg',
                ],
            ]);
        });

        $nomNairobi = \Helpers\GeoLocation::resolveCoordinates(-1.2921, 36.8219, 15.0);
        self::assert(
            strpos($lastRequestedUrl, 'nominatim.openstreetmap.org/reverse') !== false &&
            $nomNairobi['continent_code'] === 'AF' && $nomNairobi['country_code'] === 'KE' && $nomNairobi['region_code'] === 'KE-30' && $nomNairobi['city'] === 'Nairobi' && $nomNairobi['provider'] === 'nominatim',
            "Nominatim adapter resolves Nairobi, Kenya (AF/KE-30) in country without local polygons",
            $results
        );

        $nomRural = \Helpers\GeoLocation::resolveCoordinates(-21.30, 47.63, 25.0);
        self::assert(
            $nomRural['country'] === 'Madagascar' &&
            $nomRural['region'] === 'Vatovavy' &&
            $nomRural['region_code'] === 'MG-VAT' &&
            $nomRural['city'] === null &&
            $nomRural['location_level'] === 'region' &&
            $nomRural['missing_reason'] === 'outside_urban_commune',
            "Rural coordinates (-21.30, 47.63) fallback to region 'Vatovavy' (MG-VAT) with city=null (never promoting rural hamlet to urban city)",
            $results
        );

        // Provider D: Google Maps Geocoding API (Tokyo, Japan [AS] & New York, USA [NA])
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'   => true,
            'provider'  => 'google',
            'api_key'   => 'AIzaSyTestGoogleKey123456',
            'cache_ttl' => 3600,
        ]);
        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$lastRequestedUrl) {
            $lastRequestedUrl = $url;
            if (strpos($url, '35.6762') !== false) {
                return json_encode([
                    'status'  => 'OK',
                    'results' => [[
                        'address_components' => [
                            ['long_name' => 'Tokyo', 'short_name' => 'Tokyo', 'types' => ['locality', 'political']],
                            ['long_name' => 'Tokyo', 'short_name' => '13', 'types' => ['administrative_area_level_1', 'political']],
                            ['long_name' => 'Japan', 'short_name' => 'JP', 'types' => ['country', 'political']],
                        ],
                    ]],
                ]);
            }
            return json_encode([
                'status'  => 'OK',
                'results' => [[
                    'address_components' => [
                        ['long_name' => 'New York', 'short_name' => 'NYC', 'types' => ['locality', 'political']],
                        ['long_name' => 'New York', 'short_name' => 'NY', 'types' => ['administrative_area_level_1', 'political']],
                        ['long_name' => 'United States', 'short_name' => 'US', 'types' => ['country', 'political']],
                    ],
                ]],
            ]);
        });

        $googTokyo = \Helpers\GeoLocation::resolveCoordinates(35.6762, 139.6503, 12.0);
        self::assert(
            strpos($lastRequestedUrl, 'maps.googleapis.com/maps/api/geocode/json') !== false &&
            $googTokyo['continent_code'] === 'AS' && $googTokyo['country_code'] === 'JP' && $googTokyo['region_code'] === 'JP-13' && $googTokyo['city'] === 'Tokyo' && $googTokyo['provider'] === 'google',
            "Google Maps Geocoding adapter builds valid URL and resolves Tokyo, Japan (AS/JP-13)",
            $results
        );

        $googNyc = \Helpers\GeoLocation::resolveCoordinates(40.7128, -74.0060, 15.0);
        self::assert(
            $googNyc['continent_code'] === 'NA' && $googNyc['country_code'] === 'US' && $googNyc['region_code'] === 'US-NY' && $googNyc['city'] === 'New York',
            "Google Maps Geocoding adapter resolves New York, NY, United States (NA/US-NY)",
            $results
        );

        // Provider E: Mapbox Geocoding API v5 (São Paulo, Brazil [SA] & Santiago, Chile [SA])
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'   => true,
            'provider'  => 'mapbox',
            'api_key'   => 'pk.eyJ1IjoidGVzdCIsImEiOiJ0ZXN0In0.test',
            'cache_ttl' => 3600,
        ]);
        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$lastRequestedUrl) {
            $lastRequestedUrl = $url;
            return json_encode([
                'type'     => 'FeatureCollection',
                'features' => [[
                    'id'         => 'place.12345',
                    'place_type' => ['place'],
                    'text'       => 'São Paulo',
                    'context'    => [
                        ['id' => 'region.678', 'text' => 'São Paulo', 'short_code' => 'BR-SP'],
                        ['id' => 'country.901', 'text' => 'Brazil', 'short_code' => 'br'],
                    ],
                ]],
            ]);
        });

        $mbSp = \Helpers\GeoLocation::resolveCoordinates(-23.5505, -46.6333, 20.0);
        // Verify Mapbox URL uses {longitude},{latitude} order (-46.6333,-23.5505)
        self::assert(
            strpos($lastRequestedUrl, 'api.mapbox.com/geocoding/v5/mapbox.places/-46.6333%2C-23.5505.json') !== false &&
            $mbSp['continent_code'] === 'SA' && $mbSp['country_code'] === 'BR' && $mbSp['region_code'] === 'BR-SP' && $mbSp['city'] === 'São Paulo' && $mbSp['provider'] === 'mapbox',
            "Mapbox Geocoding adapter uses {lon},{lat} order and resolves São Paulo, Brazil (SA/BR-SP)",
            $results
        );

        // 3. Test External Provider Unavailability (HTTP failure / timeout)
        \Helpers\GeoLocation::setMockHttpTransport(function () {
            return null; // Simulate network timeout / HTTP 503
        });
        $unavailable = \Helpers\GeoLocation::resolveCoordinates(-18.8792, 47.5079, 20.0);
        self::assert(
            $unavailable['country'] === null &&
            $unavailable['region'] === null &&
            $unavailable['city'] === null &&
            $unavailable['location_level'] === 'unknown' &&
            $unavailable['missing_reason'] === 'reverse_geocoder_unavailable',
            "External reverse geocoder failure returns location_level='unknown' and reason='reverse_geocoder_unavailable' without crashing",
            $results
        );

        \Helpers\GeoLocation::clearCache();
        @rmdir($tmpCacheDir);
    }

    public static function testSharedCrossProcessCacheExpirationAndQuotas(&$results) {
        echo "Running GeoLocationTest::testSharedCrossProcessCacheExpirationAndQuotas...\n";

        $tmpCacheDir = sys_get_temp_dir() . '/codex_shared_cache_' . uniqid();
        \Helpers\GeoLocation::clearCache();
        \Helpers\GeoLocation::setCacheDirectory($tmpCacheDir);

        $baseTime = 1760000000;
        \Helpers\GeoLocation::setTestTimestamp($baseTime);

        $httpCallsCount = 0;
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'                 => true,
            'provider'                => 'opencage',
            'api_key'                 => 'oc_test_cache_quota_key',
            'cache_ttl'               => 120, // 2 minutes TTL
            'max_requests_per_minute' => 2,
            'daily_quota'             => 3,
        ]);

        \Helpers\GeoLocation::setMockHttpTransport(function ($url) use (&$httpCallsCount) {
            $httpCallsCount++;
            return json_encode([
                'results' => [[
                    'components' => [
                        'ISO_3166-1_alpha-2' => 'FR',
                        'ISO_3166-2'         => ['FR-IDF'],
                        'country'            => 'France',
                        'state'              => 'Île-de-France',
                        'city'               => 'Paris',
                    ],
                ]],
            ]);
        });

        // 1. First call populates L1 memory and L2 shared disk cache
        $res1 = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        self::assert($httpCallsCount === 1 && $res1['city'] === 'Paris', "First coordinate lookup queries external provider and caches result on disk", $results);

        // 2. Simulate a separate PHP-FPM worker process by wiping L1 in-memory cache
        \Helpers\GeoLocation::resetWorkerMemory();
        $res2 = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        $statsAfterHit = \Helpers\GeoLocation::getReverseGeocoderStats();
        self::assert(
            $httpCallsCount === 1 && $res2['city'] === 'Paris' && $statsAfterHit['cache_hits'] === 1,
            "Second lookup from a new worker hits shared L2 disk cache without making a duplicate API call",
            $results
        );

        // 3. Advance time beyond TTL (baseTime + 180s > 120s TTL) to verify effective expiration
        \Helpers\GeoLocation::setTestTimestamp($baseTime + 180);
        \Helpers\GeoLocation::resetWorkerMemory();
        $res3 = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        self::assert($httpCallsCount === 2 && $res3['city'] === 'Paris', "Expired cache entry on disk is invalidated after TTL elapses, triggering a fresh API lookup", $results);

        // 4. Test per-minute rate limit (max_requests_per_minute = 2) within the same minute window (baseTime + 180)
        // Call #2 in this minute window with distinct coordinates:
        $res4 = \Helpers\GeoLocation::resolveCoordinates(45.7640, 4.8357, 15.0);
        self::assert($httpCallsCount === 3 && $res4['country'] === 'France', "Second request in the same minute window is allowed (2/2 rpm)", $results);

        // Call #3 in the same minute window must be blocked by rate limiter!
        $resRateLimited = \Helpers\GeoLocation::resolveCoordinates(43.2965, 5.3698, 15.0);
        $statsAfterLimit = \Helpers\GeoLocation::getReverseGeocoderStats();
        self::assert(
            $httpCallsCount === 3 &&
            $resRateLimited['location_level'] === 'unknown' &&
            $resRateLimited['missing_reason'] === 'quota_exceeded' &&
            $statsAfterLimit['quota_exceeded_count'] === 1,
            "Third uncached request in the same minute is blocked by shared per-minute rate limit (quota_exceeded)",
            $results
        );

        // 5. Advance time by 1 minute (baseTime + 240): minute_count resets, but daily_quota = 3 is now reached!
        \Helpers\GeoLocation::setTestTimestamp($baseTime + 240);
        $resDailyExceeded = \Helpers\GeoLocation::resolveCoordinates(43.2965, 5.3698, 15.0);
        $statsAfterDaily = \Helpers\GeoLocation::getReverseGeocoderStats();
        self::assert(
            $httpCallsCount === 3 &&
            $resDailyExceeded['missing_reason'] === 'quota_exceeded' &&
            $statsAfterDaily['quota_exceeded_count'] === 2,
            "Daily quota cap (3/3) blocks further requests even in a new minute window",
            $results
        );

        self::assert(\Helpers\GeoLocation::maskApiKey('abcdef1234567890xyz') === 'abc*************xyz', "maskApiKey hides sensitive API key characters", $results);

        // 6. Test fail-closed quota storage protection: if quota file/directory is inaccessible or cannot be locked,
        // it must prevent any uncounted external call!
        $tempFileAsDir = tempnam(sys_get_temp_dir(), 'geo_inaccessible_');
        \Helpers\GeoLocation::setCacheDirectory($tempFileAsDir);
        $externalCalledOnInaccessible = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$externalCalledOnInaccessible) {
            $externalCalledOnInaccessible = true;
            return json_encode(['results' => [['components' => ['country' => 'France']]]]);
        });
        $resInaccessible = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        \Helpers\GeoLocation::setCacheDirectory(null);
        @unlink($tempFileAsDir);

        self::assert(
            $resInaccessible['missing_reason'] === 'quota_storage_inaccessible' &&
            !$externalCalledOnInaccessible &&
            $resInaccessible['location_level'] === 'unknown',
            "Inaccessible quota storage or lock failure prevents uncounted external call (fail-closed security)",
            $results
        );

        // 7. Test PHP-FPM environment variable accessibility and .env parsing
        $tmpEnvFile = tempnam(sys_get_temp_dir(), 'test_env_');
        file_put_contents($tmpEnvFile, "CUSTOM_TEST_GEO_VAR=\"loaded_from_env_file\"\nREVERSE_GEOCODER_MAX_RPM=45\n");
        \Helpers\GeoLocation::loadEnvFile($tmpEnvFile);
        $readEnv = \Helpers\GeoLocation::getEnv('CUSTOM_TEST_GEO_VAR');
        $readRpm = \Helpers\GeoLocation::getEnv('REVERSE_GEOCODER_MAX_RPM');
        @unlink($tmpEnvFile);
        self::assert(
            $readEnv === 'loaded_from_env_file' && (int) $readRpm === 45,
            "GeoLocation::getEnv and loadEnvFile ensure environment variables are accessible across PHP-FPM without relying on automatic .env loading",
            $results
        );

        // 8a. Legitimate initial creation: state file does not exist yet
        $initDir = sys_get_temp_dir() . '/geo_init_' . uniqid();
        mkdir($initDir, 0700, true);
        \Helpers\GeoLocation::setCacheDirectory($initDir);
        \Helpers\GeoLocation::resetWorkerMemory();
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'  => true,
            'provider' => 'opencage',
            'api_key'  => 'oc_init_test_key',
        ]);
        $initCalled = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$initCalled) {
            $initCalled = true;
            return json_encode(['results' => [['components' => ['country' => 'Madagascar', 'state' => 'Analamanga', 'city' => 'Antananarivo']]]]);
        });
        $resInit = \Helpers\GeoLocation::resolveCoordinates(-18.913, 47.536, 10.0);
        $initFileExists = file_exists($initDir . '/ratelimit_state.json');
        $initStats = \Helpers\GeoLocation::getReverseGeocoderStats();
        self::assert(
            $initCalled && $initFileExists && ($initStats['requests_total'] ?? 0) === 1,
            "Legitimate initial quota state creation atomically creates ratelimit_state.json and records first request",
            $results
        );
        @unlink($initDir . '/ratelimit_state.json');
        @unlink($initDir . '/ratelimit.lock');
        @rmdir($initDir);

        // 8b. Existing empty state file (0 bytes from power failure / interruption): MUST NOT silently reset to zero
        $emptyDir = sys_get_temp_dir() . '/geo_empty_' . uniqid();
        mkdir($emptyDir, 0700, true);
        file_put_contents($emptyDir . '/ratelimit_state.json', '');
        \Helpers\GeoLocation::setCacheDirectory($emptyDir);
        \Helpers\GeoLocation::resetWorkerMemory();
        $externalCalledOnEmpty = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$externalCalledOnEmpty) {
            $externalCalledOnEmpty = true;
            return json_encode(['results' => [['components' => ['country' => 'France']]]]);
        });
        $resEmpty = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        \Helpers\GeoLocation::setCacheDirectory(null);
        @unlink($emptyDir . '/ratelimit_state.json');
        @unlink($emptyDir . '/ratelimit.lock');
        @rmdir($emptyDir);

        self::assert(
            $resEmpty['missing_reason'] === 'quota_storage_inaccessible' &&
            !$externalCalledOnEmpty,
            "Existing empty quota state file (0 bytes) triggers fail-closed protection without silently resetting counters to zero",
            $results
        );

        // 8c. Test corrupted JSON state file: MUST NOT silently reset counters to defaultState (fail-closed protection)
        $corruptDir = sys_get_temp_dir() . '/geo_corrupt_' . uniqid();
        mkdir($corruptDir, 0700, true);
        file_put_contents($corruptDir . '/ratelimit_state.json', '{"minute_window": INVALID-SYNTAX-NOT-JSON...');
        \Helpers\GeoLocation::setCacheDirectory($corruptDir);
        $externalCalledOnCorrupt = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$externalCalledOnCorrupt) {
            $externalCalledOnCorrupt = true;
            return json_encode(['results' => [['components' => ['country' => 'France']]]]);
        });
        $resCorrupt = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        \Helpers\GeoLocation::setCacheDirectory(null);
        @unlink($corruptDir . '/ratelimit_state.json');
        @unlink($corruptDir . '/ratelimit.lock');
        @rmdir($corruptDir);

        self::assert(
            $resCorrupt['missing_reason'] === 'quota_storage_inaccessible' &&
            !$externalCalledOnCorrupt,
            "Corrupted JSON quota state file triggers fail-closed protection without silently resetting counters to zero",
            $results
        );

        // 9. Test partial write failure during quota mutation: real truncated bytes written to disk & fail-closed protection
        $faultDir = sys_get_temp_dir() . '/geo_fault_' . uniqid();
        mkdir($faultDir, 0700, true);
        \Helpers\GeoLocation::setCacheDirectory($faultDir);
        \Helpers\GeoLocation::resetWorkerMemory();
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'  => true,
            'provider' => 'opencage',
            'api_key'  => 'oc_test_quota_key',
        ]);
        \Helpers\GeoLocation::setTestQuotaStorageFault('partial_write');
        $externalCalledOnPartial = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$externalCalledOnPartial) {
            $externalCalledOnPartial = true;
            return json_encode(['results' => [['components' => ['country' => 'France']]]]);
        });
        $resPartial = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        \Helpers\GeoLocation::setTestQuotaStorageFault(null);
        $diskContent = file_exists($faultDir . '/ratelimit_state.json') ? file_get_contents($faultDir . '/ratelimit_state.json') : '';
        $isTruncatedOnDisk = (strlen($diskContent) > 0 && strlen($diskContent) <= 15);

        // Verify that subsequent resolution against this real truncated file on disk also fails closed
        \Helpers\GeoLocation::resetWorkerMemory();
        $subsequentCalled = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$subsequentCalled) {
            $subsequentCalled = true;
            return json_encode(['results' => [['components' => ['country' => 'France']]]]);
        });
        $resSubsequent = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);

        self::assert(
            $resPartial['missing_reason'] === 'quota_storage_inaccessible' &&
            !$externalCalledOnPartial &&
            $isTruncatedOnDisk &&
            $resSubsequent['missing_reason'] === 'quota_storage_inaccessible' &&
            !$subsequentCalled,
            "Partial disk write writes real truncated bytes to disk, triggers fail-closed rejection, and persists protection against subsequent calls",
            $results
        );

        // 10. Test ftruncate / write failure during quota mutation: fail-closed protection
        \Helpers\GeoLocation::resetWorkerMemory();
        \Helpers\GeoLocation::setTestQuotaStorageFault('ftruncate_fail');
        $externalCalledOnTruncate = false;
        \Helpers\GeoLocation::setMockHttpTransport(function () use (&$externalCalledOnTruncate) {
            $externalCalledOnTruncate = true;
            return json_encode(['results' => [['components' => ['country' => 'France']]]]);
        });
        $resTruncate = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 15.0);
        \Helpers\GeoLocation::setTestQuotaStorageFault(null);
        \Helpers\GeoLocation::setCacheDirectory(null);
        @unlink($faultDir . '/ratelimit_state.json');
        @unlink($faultDir . '/ratelimit.lock');
        @rmdir($faultDir);
        self::assert(
            $resTruncate['missing_reason'] === 'quota_storage_inaccessible' &&
            !$externalCalledOnTruncate,
            "ftruncate disk failure in mutateSharedQuotaState triggers fail-closed rejection",
            $results
        );

        // 11. Test isolation and CLI exit codes of test-worldwide-geocoder.php
        $mockProdDir = sys_get_temp_dir() . '/geo_prod_mock_' . uniqid();
        mkdir($mockProdDir, 0700, true);
        $prodStatePayload = json_encode(['requests_total' => 888, 'minute_count' => 15, 'day_count' => 500]);
        file_put_contents($mockProdDir . '/ratelimit_state.json', $prodStatePayload);
        \Helpers\GeoLocation::setCacheDirectory($mockProdDir);

        // Execute test-worldwide-geocoder.php CLI (must exit with code 0 on success)
        $cmdOutput = [];
        $cmdExitCode = -1;
        exec('php ' . escapeshellarg(ROOT . '/scripts/test-worldwide-geocoder.php') . ' 2>&1', $cmdOutput, $cmdExitCode);
        \Helpers\GeoLocation::setCacheDirectory(null);

        $prodFileStillExists = file_exists($mockProdDir . '/ratelimit_state.json');
        $prodFileContentAfterTest = $prodFileStillExists ? file_get_contents($mockProdDir . '/ratelimit_state.json') : '';
        @unlink($mockProdDir . '/ratelimit_state.json');
        @unlink($mockProdDir . '/ratelimit.lock');
        @rmdir($mockProdDir);

        self::assert(
            $cmdExitCode === 0 && $prodFileStillExists && $prodFileContentAfterTest === $prodStatePayload,
            "test-worldwide-geocoder.php exits with status code 0, operates in isolated temp directory and never deletes or alters production quotas",
            $results
        );

        // Execute test-worldwide-geocoder.php with missing required API key (must exit with non-zero code)
        $failOutput = [];
        $failExitCode = 0;
        exec('php ' . escapeshellarg(ROOT . '/scripts/test-worldwide-geocoder.php') . ' --live 2>&1', $failOutput, $failExitCode);
        self::assert(
            $failExitCode === 1,
            "test-worldwide-geocoder.php exits with non-zero exit code (1) when required live configuration is missing",
            $results
        );

        \Helpers\GeoLocation::clearCache();
        @rmdir($tmpCacheDir);
    }

    public static function testMissingAndCoarseGpsAccuracyAndBorderDegradation(&$results) {
        echo "Running GeoLocationTest::testMissingAndCoarseGpsAccuracyAndBorderDegradation...\n";
        \Helpers\GeoLocation::clearCache();

        \Helpers\GeoLocation::setMockReverseGeocoder(function ($lat, $lon) {
            if ($lat < -50 || $lon > 60) {
                return null;
            }
            // Simulate a point 0.55 km from Vatovavy/Fitovinany regional border when lat is near -21.595
            $borderDist = (abs($lat - (-21.595)) < 0.01) ? 0.55 : 25.0;
            return [
                'country'               => 'Madagascar',
                'country_code'          => 'MG',
                'region'                => 'Vatovavy',
                'region_code'           => 'MG-VAT',
                'city'                  => 'Mananjary',
                'region_border_dist_km' => $borderDist,
                '_provider'             => 'opencage',
            ];
        });

        // 1. Missing GPS accuracy (null) -> MUST NEVER default to 50m!
        $missingAcc = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, null);
        self::assert(
            $missingAcc['declared_accuracy_meters'] === null &&
            $missingAcc['accuracy_radius'] === null,
            "Missing GPS accuracy (null) keeps declared_accuracy_meters=null and accuracy_radius=null (never defaults to 50m)",
            $results
        );
        self::assert(
            $missingAcc['country'] === 'Madagascar' &&
            $missingAcc['region'] === 'Vatovavy' &&
            $missingAcc['region_code'] === 'MG-VAT' &&
            $missingAcc['city'] === null &&
            $missingAcc['location_level'] === 'region' &&
            $missingAcc['missing_reason'] === 'missing_gps_accuracy',
            "Missing GPS accuracy caps localization level at 'region' with city=null and missing_reason='missing_gps_accuracy'",
            $results
        );

        // 2. Very low GPS accuracy (> 50km, e.g. 75,000m) -> degrades to country only
        $coarseCountry = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 75000.0);
        self::assert(
            $coarseCountry['country'] === 'Madagascar' &&
            $coarseCountry['region'] === null &&
            $coarseCountry['city'] === null &&
            $coarseCountry['location_level'] === 'country' &&
            $coarseCountry['declared_accuracy_meters'] === 75000.0 &&
            $coarseCountry['accuracy_radius'] === 75.0 &&
            $coarseCountry['missing_reason'] === 'low_gps_accuracy',
            "GPS accuracy > 50km degrades to country only (never attributes uncertain region or city)",
            $results
        );

        // 3. Moderate GPS accuracy (e.g. 8,000m = 8km) -> degrades city to null, keeps region Vatovavy
        $coarseCity = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 8000.0);
        self::assert(
            $coarseCity['country'] === 'Madagascar' &&
            $coarseCity['region'] === 'Vatovavy' &&
            $coarseCity['city'] === null &&
            $coarseCity['location_level'] === 'region' &&
            $coarseCity['missing_reason'] === 'low_gps_accuracy_for_city',
            "GPS accuracy > 5km degrades city to null while preserving region Vatovavy",
            $results
        );

        // 4. Proximity to regional border between Vatovavy and Fitovinany (0.55 km from border, uncertainty 2,500m = 2.5km)
        $borderZone = \Helpers\GeoLocation::resolveCoordinates(-21.595, 48.00, 2500.0);
        self::assert(
            $borderZone['country'] === 'Madagascar' &&
            $borderZone['region'] === null &&
            $borderZone['city'] === null &&
            $borderZone['location_level'] === 'country' &&
            $borderZone['missing_reason'] === 'region_border_uncertainty',
            "Point near regional border with overlapping GPS uncertainty degrades to country level",
            $results
        );

        // 5. Invalid coordinates
        $oob = \Helpers\GeoLocation::resolveCoordinates(999.0, -999.0, 20.0);
        self::assert($oob['location_level'] === 'unknown' && $oob['missing_reason'] === 'invalid_coordinates', "Out-of-bounds coordinates return 'invalid_coordinates'", $results);

        \Helpers\GeoLocation::clearCache();
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
            ['city' => 'Mananjary', 'region' => 'Vatovavy',      'country' => 'Madagascar', 'country_code' => 'MG', 'count' => 15],
            ['city' => 'Paris',     'region' => 'Île-de-France', 'country' => 'France',     'country_code' => 'FR', 'count' => 10],
            ['city' => '',          'region' => 'Fitovinany',    'country' => 'Madagascar', 'country_code' => 'MG', 'count' => 15],
            ['city' => 'Unknown',   'region' => '',              'country' => 'Madagascar', 'country_code' => 'MG', 'count' => 10],
        ];
        $metrics = \Helpers\GeoLocation::calculateQualityMetrics($sampleAggregations);
        self::assert($metrics['identified_city_count'] === 25 && $metrics['city_percent'] === 50.0, "Quality metrics computes 25 city clicks (50.0%)", $results);
        self::assert($metrics['region_only_count'] === 15 && $metrics['region_percent'] === 30.0, "Quality metrics computes 15 region-only clicks (30.0%)", $results);
        self::assert($metrics['country_only_count'] === 10 && $metrics['country_only_percent'] === 20.0, "Quality metrics computes 10 country-only clicks (20.0%)", $results);
        self::assert($metrics['continents']['Africa'] === 40 && $metrics['continents']['Europe'] === 10, "Quality metrics aggregates scans by continent (Africa=40, Europe=10)", $results);
    }

    public static function testGeoConsentEndpointPrivacyAndExternalFailureResilience(&$results) {
        echo "Running GeoLocationTest::testGeoConsentEndpointPrivacyAndExternalFailureResilience...\n";
        \Helpers\GeoLocation::clearCache();

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

        // Case 5: Verify Gate::consentLocation() privacy disclosure and valid consent flow
        \Helpers\GeoLocation::setReverseGeocoderConfig([
            'enabled'  => true,
            'provider' => 'opencage',
            'api_key'  => 'oc_live_test_key',
        ]);
        \Helpers\GeoLocation::setMockReverseGeocoder(function ($lat, $lon) {
            return [
                'country'      => 'Madagascar',
                'country_code' => 'MG',
                'region'       => 'Vatovavy',
                'region_code'  => 'MG-VAT',
                'city'         => 'Mananjary',
                '_provider'    => 'opencage',
            ];
        });

        $_SERVER['REMOTE_ADDR'] = '196.192.32.1';
        $_SESSION = ['last_stat_1' => 10];
        $urlObj = \Core\DB::url()->where('id', 1)->first();
        ob_start();
        \Helpers\Gate::consentLocation($urlObj);
        $consentHtml = ob_get_clean();
        $generatedNonce = $_SESSION['geoconsent_nonce_1'] ?? '';

        self::assert(!empty($generatedNonce) && strlen($generatedNonce) === 64, "Gate::consentLocation generates 64-char HMAC-SHA256 scan-bound nonce", $results);
        self::assert(
            strpos($consentHtml, 'fournisseur tiers de géocodage inverse') !== false &&
            strpos($consentHtml, 'OpenCage Geocoding API') !== false &&
            strpos($consentHtml, 'Continuer sans partager') !== false &&
            strpos($consentHtml, 'ne sont pas anonymisées') !== false,
            "Consent page transparently discloses external reverse geocoding transmission and provider name without false non-disclosure claims",
            $results
        );

        \Core\Request::setRawBody(json_encode([
            'latitude'      => -21.223,
            'longitude'     => 48.341,
            'accuracy'      => 18.0,
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

        // Case 7: Resilience when external reverse geocoder is unavailable during geoConsent:
        // Existing MaxMind IP country ('Madagascar') must be preserved and WhatsApp redirect must succeed!
        $pdo->exec("INSERT INTO `{$prefix}stats` (`id`, `short`, `urlid`, `ip`, `country`, `region`, `city`, `geo_source`) VALUES
            (30, 'wa-promo', 1, '196.192.32.1', 'Madagascar', NULL, NULL, 'maxmind')
        ");
        $_SESSION = ['last_stat_1' => 30];
        ob_start();
        \Helpers\Gate::consentLocation($urlObj);
        ob_end_clean();
        $nonce30 = $_SESSION['geoconsent_nonce_1'] ?? '';

        \Helpers\GeoLocation::setMockReverseGeocoder(function () {
            return null; // Simulate external provider outage
        });

        \Core\Request::setRawBody(json_encode([
            'latitude'      => -21.223,
            'longitude'     => 48.341,
            'accuracy'      => 20.0,
            'consent_token' => $nonce30,
        ]));
        $req7 = new \Core\Request();
        ob_start();
        $controller->geoConsent($req7, 'wa-promo');
        $out7 = json_decode(ob_get_clean(), true);
        $stat30 = \Core\DB::stats()->where('id', 30)->first();

        self::assert(
            \Core\Response::lastStatusCode() === 200 &&
            !empty($out7['success']) &&
            $out7['geo_updated'] === false &&
            $out7['redirect'] === 'https://wa.me/261340000000?text=Bonjour' &&
            $stat30->country === 'Madagascar' &&
            $stat30->geo_source === 'maxmind',
            "When external reverse geocoder is unavailable, geoConsent preserves MaxMind country='Madagascar' and redirects cleanly to WhatsApp",
            $results
        );

        // Case 8: Visitor opt-out / "Continuer sans partager" flow
        // Simulates visitor declining geolocation: they bypass the POST endpoint and follow the target link directly
        $pdo->exec("INSERT INTO `{$prefix}stats` (`id`, `short`, `urlid`, `ip`, `country`, `region`, `city`, `geo_source`) VALUES
            (40, 'wa-promo', 1, '196.192.32.1', 'Madagascar', NULL, NULL, 'maxmind')
        ");
        $_SESSION = ['last_stat_1' => 40];
        ob_start();
        \Helpers\Gate::consentLocation($urlObj);
        $optOutHtml = ob_get_clean();

        $escapedWaUrl = htmlspecialchars('https://wa.me/261340000000?text=Bonjour', ENT_QUOTES, 'UTF-8');
        self::assert(
            strpos($optOutHtml, 'id="btn-skip-location"') !== false &&
            strpos($optOutHtml, 'href="' . $escapedWaUrl . '"') !== false,
            "Consent gate provides a direct opt-out link pointing to WhatsApp URL without triggering geocoding",
            $results
        );

        $stat40 = \Core\DB::stats()->where('id', 40)->first();
        self::assert(
            $stat40->country === 'Madagascar' &&
            $stat40->region === null &&
            $stat40->city === null &&
            $stat40->geo_source === 'maxmind',
            "Opting out preserves initial IP country='Madagascar' without collecting GPS or reverse-geocoding city",
            $results
        );

        \Core\Request::setRawBody(null);
        \Helpers\GeoLocation::clearCache();
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
        self::assert($rows[0]['city'] === 'Antananarivo' && $rows[0]['region'] === 'Analamanga' && $rows[0]['geo_source'] === 'maxmind_repair', "Row 1 (41.188.0.1) repaired to city 'Antananarivo', region 'Analamanga' with geo_source='maxmind_repair'", $results);
        self::assert($rows[1]['city'] === 'Andrésy' && $rows[1]['region'] === 'Île-de-France', "Row 2 (80.12.34.56) repaired to city 'Andrésy' and region 'Île-de-France'", $results);
        self::assert($rows[2]['city'] === '' && $rows[2]['region'] === null, "Row 3 (MD5 hashed IP) strictly untouched by geo-repair.php", $results);
        self::assert($rows[3]['city'] === '' && $rows[3]['region'] === null, "Row 4 (private IP) strictly untouched by geo-repair.php", $results);
        $verifyPdo = null;

        @unlink($tmpSqlite);
    }

    public static function testIsolatedHttpServerConsentAndWhatsAppLifecycle(&$results) {
        echo "Running GeoLocationTest::testIsolatedHttpServerConsentAndWhatsAppLifecycle...\n";

        // 1. Pick an available port and create isolated paths
        $sock = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        $port = parse_url("tcp://" . $name, PHP_URL_PORT);
        fclose($sock);

        $tmpSqlite = tempnam(sys_get_temp_dir(), 'test_sqlite_') . '.sqlite';
        $tmpCacheDir = sys_get_temp_dir() . '/test_cache_http_' . uniqid();
        mkdir($tmpCacheDir, 0700, true);

        // 2. Setup isolated ephemeral SQLite database
        $pdo = new \PDO('sqlite:' . $tmpSqlite);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `url` (
          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
          `userid` INTEGER DEFAULT 0,
          `alias` TEXT,
          `custom` TEXT,
          `url` TEXT,
          `location` TEXT DEFAULT NULL,
          `devices` TEXT DEFAULT NULL,
          `options` TEXT DEFAULT NULL,
          `domain` TEXT DEFAULT NULL,
          `description` TEXT DEFAULT NULL,
          `date` TEXT DEFAULT NULL,
          `pass` TEXT DEFAULT NULL,
          `click` INTEGER DEFAULT 0,
          `uniqueclick` INTEGER DEFAULT 0,
          `meta_title` TEXT DEFAULT NULL,
          `meta_description` TEXT DEFAULT NULL,
          `meta_image` TEXT DEFAULT NULL,
          `bundle` INTEGER DEFAULT NULL,
          `public` INTEGER DEFAULT 0,
          `archived` INTEGER DEFAULT 0,
          `type` TEXT DEFAULT NULL,
          `pixels` TEXT DEFAULT NULL,
          `expiry` TEXT DEFAULT NULL,
          `parameters` TEXT DEFAULT NULL,
          `status` INTEGER DEFAULT 1,
          `qrid` INTEGER DEFAULT NULL,
          `profileid` INTEGER DEFAULT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `stats` (
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
            `accuracy_radius` INTEGER,
            `browser` TEXT DEFAULT '',
            `os` TEXT DEFAULT '',
            `device` TEXT DEFAULT '',
            `domain` TEXT DEFAULT '',
            `referer` TEXT DEFAULT '',
            `language` TEXT DEFAULT ''
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `bannedlink` TEXT DEFAULT ''
        )");

        $waTarget = 'https://wa.me/261340000000?text=' . urlencode('Bonjour, commande QR #102');
        $stmt = $pdo->prepare("INSERT INTO `url` (`id`, `alias`, `url`, `options`, `status`) VALUES (1, 'wa-qr-http', :url, :opts, 1)");
        $stmt->execute([':url' => $waTarget, ':opts' => json_encode(['geoconsent' => true])]);
        $pdo = null;

        // 3. Generate router script for PHP built-in web server
        $routerFile = tempnam(sys_get_temp_dir(), 'router_') . '.php';
        $bootstrapPath = var_export(ROOT . '/tests/bootstrap.php', true);
        $routerCode = <<<"ROUTER"
<?php
define('_STATE', 'TEST');
require_once {$bootstrapPath};

\$sqliteDb = getenv('CODEX_TEST_SQLITE_PATH');
\Core\DB::configure('sqlite:' . \$sqliteDb);
\Core\DB::configure('return_result_sets', true);

\$cacheDir = getenv('CODEX_TEST_CACHE_PATH');
\Helpers\GeoLocation::setCacheDirectory(\$cacheDir);

\Helpers\GeoLocation::setReverseGeocoderConfig([
    'enabled'  => true,
    'provider' => 'opencage',
    'api_key'  => 'http_test_key',
]);
\Helpers\GeoLocation::setMockReverseGeocoder(function(\$lat, \$lon) {
    if (abs(\$lat - (-21.223)) < 0.1 && abs(\$lon - 48.341) < 0.1) {
        return [
            'country'      => 'Madagascar',
            'country_code' => 'MG',
            'region'       => 'Vatovavy',
            'region_code'  => 'MG-VAT',
            'city'         => 'Mananjary',
            '_provider'    => 'opencage',
        ];
    }
    return null;
});

\Helpers\GeoLocation::setMockReader(new class {
    public function city(\$ip) {
        return new class {
            public \$continent;
            public \$country;
            public \$subdivisions;
            public \$city;
            public \$location;
            public function __construct() {
                \$this->continent = (object)['code' => 'AF', 'names' => ['en' => 'Africa']];
                \$this->country = (object)['isoCode' => 'MG', 'names' => ['en' => 'Madagascar']];
                \$this->subdivisions = [];
                \$this->city = (object)['names' => []];
                \$this->location = (object)['latitude' => null, 'longitude' => null, 'accuracyRadius' => null];
            }
        };
    }
});

\Gem::\$Config->url = 'http://' . (\$_SERVER['HTTP_HOST'] ?? '127.0.0.1');
\Gem::\$Config->security = 'test-secret-key-32-chars-long-123456';
\Gem::\$Config->tracking = '1';
\Gem::\$Config->cookieconsent = (object)['enabled' => false];
\Gem::\$Config->theme = 'default';
\Core\View::set('path', STORAGE . '/themes/default');

register_shutdown_function(function() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
});

\$rClass = new \ReflectionClass('\Gem');
\$dispProp = \$rClass->getProperty('dispatcher');
\$dispProp->setAccessible(true);
\$dispProp->setValue(null, \FastRoute\simpleDispatcher(function(\FastRoute\RouteCollector \$r) {
    foreach (\Gem::Controllers() as \$name => \$data) {
        \$handler = ['controller' => \$data["handler"], 'name' => \$data["name"]];
        if (isset(\$data["middleware"]) && !is_callable(\$handler)) {
            \$handler['middleware'] = \$data["middleware"];
        }
        try {
            \$r->addRoute(\$data["method"], \$data["path"], \$handler);
        } catch (\Exception \$e) {
            continue;
        }
    }
}));

try {
    \Gem::Dispatch();
} catch (\Throwable \$e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "EXCEPTION: " . \$e->getMessage() . " in " . \$e->getFile() . ":" . \$e->getLine();
}
ROUTER;
        file_put_contents($routerFile, $routerCode);

        // 4. Start isolated ephemeral php -S server
        $cmd = escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:{$port} " . escapeshellarg($routerFile);
        $env = [
            'CODEX_TEST_SQLITE_PATH' => $tmpSqlite,
            'CODEX_TEST_CACHE_PATH'  => $tmpCacheDir,
            'PATH'                   => getenv('PATH'),
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        usleep(400000); // 400ms

        $cookieJar = tempnam(sys_get_temp_dir(), 'cookie_');
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';

        try {
            // 5. HTTP Request 1: Initial scan of QR code (GET /wa-qr-http)
            $ch = curl_init("http://127.0.0.1:{$port}/wa-qr-http");
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            $body1 = curl_exec($ch);
            $httpCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $hasConsentModal = (strpos($body1, 'Localisation') !== false);
            $hasSkipBtn = (strpos($body1, 'Continuer sans partager') !== false);
            $hasWaTarget = (strpos($body1, htmlspecialchars($waTarget, ENT_QUOTES, 'UTF-8')) !== false);

            preg_match('/var consentNonce = "(.*?)";/', $body1, $mNonce);
            $nonce = $mNonce[1] ?? '';
            preg_match('/var csrfToken = (.*?);/', $body1, $mCsrf);
            $csrf = !empty($mCsrf[1]) ? json_decode($mCsrf[1]) : '';
            preg_match('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $body1, $mCookie);
            $sessionCookie = $mCookie[1] ?? '';

            self::assert(
                $httpCode1 === 200 && $hasConsentModal && $hasSkipBtn && $hasWaTarget && strlen($nonce) === 64 && !empty($csrf),
                "HTTP Integration 1: Initial QR scan displays privacy consent modal with 64-char HMAC nonce, skip button, and WhatsApp target",
                $results
            );

            // 6. HTTP Request 2: Consented POST (/geo/consent/wa-qr-http)
            $payload = json_encode([
                'latitude'      => -21.223,
                'longitude'     => 48.341,
                'accuracy'      => 15.0,
                'consent_token' => $nonce,
                '_token'        => $csrf,
            ]);

            $ch2 = curl_init("http://127.0.0.1:{$port}/geo/consent/wa-qr-http");
            curl_setopt($ch2, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload);
            if ($sessionCookie !== '') {
                curl_setopt($ch2, CURLOPT_COOKIE, $sessionCookie);
            }
            curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-CSRF-TOKEN: ' . $csrf,
                'X-Requested-With: XMLHttpRequest',
            ]);
            $body2 = curl_exec($ch2);
            $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            $json2 = json_decode($body2, true);
            $redirectUrl = $json2['redirect'] ?? '';
            $redirectValid = (strpos($redirectUrl, 'https://wa.me/261340000000') === 0);
            $isSuccess = is_array($json2) && (!empty($json2['success']) || ($json2['error'] ?? null) === 0);

            self::assert(
                $httpCode2 === 200 && $isSuccess && $redirectValid,
                "HTTP Integration 2: Consented geolocation POST updates stats and returns WhatsApp redirection URL",
                $results
            );

            // 7. Verify SQLite database: verify stats record without raw GPS coordinates
            $verifyPdo = new \PDO('sqlite:' . $tmpSqlite);
            $stat = $verifyPdo->query("SELECT * FROM `stats` WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
            $verifyPdo = null;

            $correctAdmin = ($stat && $stat['country'] === 'Madagascar' && $stat['region'] === 'Vatovavy' && $stat['city'] === 'Mananjary' && $stat['geo_source'] === 'browser_consent');
            $noRawGps = !isset($stat['latitude']) && !isset($stat['longitude']);

            self::assert(
                $correctAdmin && $noRawGps,
                "HTTP Integration 3: Database recorded administrative location (Mananjary, Vatovavy, Madagascar) with strictly zero raw GPS coordinates",
                $results
            );
        } finally {
            proc_terminate($process);
            @unlink($tmpSqlite);
            @unlink($routerFile);
            @unlink($cookieJar);
            if (is_dir($tmpCacheDir)) {
                $files = @glob($tmpCacheDir . '/*');
                if (is_array($files)) {
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                }
                @rmdir($tmpCacheDir);
            }
        }
    }
}
