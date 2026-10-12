<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel
 * ---------------------------------------------------------------------------------------
 *  Centralized Geolocation Service
 * =======================================================================================
 *
 * @package Helpers
 * @author GemPixel
 * @license https://gempixel.com/licenses
 */

namespace Helpers;

use Core\Helper;
use Core\Http;

class GeoLocation {

    /**
     * In-memory cache for IP resolutions
     * @var array
     */
    protected static $memoryCache = [];

    /**
     * Mock reader for testing purposes
     * @var mixed
     */
    protected static $mockReader = null;

    /**
     * Mock secondary provider response for testing
     * @var mixed
     */
    protected static $mockSecondary = null;

    /**
     * Known Cloudflare IP Ranges (IPv4 and IPv6)
     * @var array
     */
    protected static $cloudflareRanges = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Set a mock reader for unit testing
     */
    public static function setMockReader($reader): void {
        self::$mockReader = $reader;
        self::$memoryCache = [];
    }

    /**
     * Set a mock secondary provider response for testing
     */
    public static function setMockSecondary($callableOrArray): void {
        self::$mockSecondary = $callableOrArray;
    }

    /**
     * Clear resolution caches
     */
    public static function clearCache(): void {
        self::$memoryCache = [];
        self::$mockReader = null;
        self::$mockSecondary = null;
    }

    /**
     * Locate an IP address
     *
     * @param string|null $ip
     * @param array $options
     * @return array
     */
    public static function locate(?string $ip = null, array $options = []): array {
        if ($ip === null) {
            $ip = (new \Core\Request())->ip();
        }

        $ip = trim((string) $ip);
        if (substr($ip, 0, 7) === '::ffff:') {
            $ip = substr($ip, 7);
        }

        $bypassCache = !empty($options['bypass_cache']);
        if (!$bypassCache && isset(self::$memoryCache[$ip])) {
            return self::$memoryCache[$ip];
        }

        $defaultResult = [
            'country'         => null,
            'country_code'    => null,
            'region'          => null,
            'region_code'     => null,
            'city'            => null,
            'postal_code'     => null,
            'latitude'        => null,
            'longitude'       => null,
            'accuracy_radius' => null,
            'source'          => 'unknown',
            'precision'       => 'unknown',
            'missing_reason'  => null,
        ];

        // 1. IP Validation
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $defaultResult['missing_reason'] = 'invalid_ip';
            return self::$memoryCache[$ip] = $defaultResult;
        }

        // 2. Reject private or reserved ranges
        if (!self::isPublicIp($ip)) {
            $defaultResult['missing_reason'] = 'private_or_reserved_ip';
            return self::$memoryCache[$ip] = $defaultResult;
        }

        // 3. Try primary provider (MaxMind MMDB)
        $result = self::resolveMaxMind($ip);

        // 4. If city is undetermined and a secondary provider is configured & enabled
        if (empty($result['city']) && self::isSecondaryProviderEnabled()) {
            $secondaryResult = self::resolveSecondary($ip);
            if ($secondaryResult !== null) {
                // Merge while preserving verified primary values if secondary is incomplete
                $result['country'] = $secondaryResult['country'] ?: $result['country'];
                $result['country_code'] = $secondaryResult['country_code'] ?: $result['country_code'];
                $result['region'] = $secondaryResult['region'] ?: $result['region'];
                $result['region_code'] = $secondaryResult['region_code'] ?: $result['region_code'];
                $result['city'] = $secondaryResult['city'] ?: $result['city'];
                $result['accuracy_radius'] = $secondaryResult['accuracy_radius'] ?? $result['accuracy_radius'];
                $result['source'] = 'secondary_provider';
                
                if (!empty($result['city'])) {
                    $result['precision'] = 'city';
                    $result['missing_reason'] = null;
                } elseif (!empty($result['region'])) {
                    $result['precision'] = 'region';
                    $result['missing_reason'] = 'region_level_only';
                }
            }
        }

        return self::$memoryCache[$ip] = $result;
    }

    /**
     * Resolve via MaxMind GeoLite2-City
     */
    protected static function resolveMaxMind(string $ip): array {
        $data = [
            'country'         => null,
            'country_code'    => null,
            'region'          => null,
            'region_code'     => null,
            'city'            => null,
            'postal_code'     => null,
            'latitude'        => null,
            'longitude'       => null,
            'accuracy_radius' => null,
            'source'          => 'maxmind',
            'precision'       => 'unknown',
            'missing_reason'  => null,
        ];

        $geopath = appConfig('app.geopath') ?: (defined('STORAGE') ? STORAGE . '/app/GeoLite2-City.mmdb' : null);

        if (self::$mockReader !== null) {
            $reader = self::$mockReader;
        } else {
            if (empty($geopath) || !file_exists($geopath) || !is_readable($geopath)) {
                $data['source'] = 'unknown';
                $data['missing_reason'] = 'database_missing';
                return $data;
            }
            try {
                $reader = new \MaxMind\Db\Reader($geopath);
            } catch (\Exception $e) {
                $data['source'] = 'unknown';
                $data['missing_reason'] = 'technical_error';
                return $data;
            }
        }

        try {
            $record = $reader->get($ip);
            if (self::$mockReader === null && is_object($reader) && method_exists($reader, 'close')) {
                $reader->close();
            }

            if (!$record) {
                $data['missing_reason'] = 'insufficient_data';
                return $data;
            }

            $countryName = $record['country']['names']['en'] ?? ($record['registered_country']['names']['en'] ?? null);
            if ($countryName === 'Türkiye') {
                $countryName = 'Turkey';
            }
            $data['country'] = $countryName;
            $data['country_code'] = $record['country']['iso_code'] ?? ($record['registered_country']['iso_code'] ?? null);

            if (!empty($record['subdivisions'])) {
                $data['region'] = $record['subdivisions'][0]['names']['en'] ?? null;
                $data['region_code'] = $record['subdivisions'][0]['iso_code'] ?? null;
            }

            $data['city'] = $record['city']['names']['en'] ?? null;
            $data['postal_code'] = $record['postal']['code'] ?? null;
            $data['latitude'] = $record['location']['latitude'] ?? null;
            $data['longitude'] = $record['location']['longitude'] ?? null;
            $data['accuracy_radius'] = $record['location']['accuracy_radius'] ?? null;

            if (!empty($data['city'])) {
                $data['precision'] = 'city';
                $data['missing_reason'] = null;
            } elseif (!empty($data['region'])) {
                $data['precision'] = 'region';
                $data['missing_reason'] = 'region_level_only';
            } elseif (!empty($data['country'])) {
                $data['precision'] = 'country';
                $data['missing_reason'] = 'country_level_only';
            } else {
                $data['missing_reason'] = 'insufficient_data';
            }

            return $data;
        } catch (\Exception $e) {
            $data['source'] = 'unknown';
            $data['missing_reason'] = 'technical_error';
            return $data;
        }
    }

    /**
     * Check if a secondary provider is configured and enabled
     */
    public static function isSecondaryProviderEnabled(): bool {
        if (self::$mockSecondary !== null) {
            return true;
        }

        $config = appConfig('app.geosecondary');
        if (!is_array($config)) {
            return false;
        }

        return !empty($config['enabled']) && !empty($config['api_key']);
    }

    /**
     * Resolve via secondary provider (with strict timeouts & circuit breaker)
     */
    protected static function resolveSecondary(string $ip): ?array {
        if (self::$mockSecondary !== null) {
            if (is_callable(self::$mockSecondary)) {
                return call_user_func(self::$mockSecondary, $ip);
            }
            return is_array(self::$mockSecondary) ? self::$mockSecondary : null;
        }

        $config = appConfig('app.geosecondary');
        if (!is_array($config) || empty($config['enabled']) || empty($config['api_key'])) {
            return null;
        }

        $provider = $config['provider'] ?? 'ipinfo';
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 1.5;

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'header' => "User-Agent: Codex-GeoClient/1.0\r\n",
                ],
            ]);

            if ($provider === 'ipinfo') {
                $url = "https://ipinfo.io/{$ip}/json?token=" . urlencode($config['api_key']);
            } elseif ($provider === 'ipapi') {
                $url = "https://ipapi.co/{$ip}/json/?key=" . urlencode($config['api_key']);
            } else {
                return null;
            }

            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                return null;
            }

            $json = json_decode($raw, true);
            if (!is_array($json) || !empty($json['error'])) {
                return null;
            }

            $city = !empty($json['city']) ? trim($json['city']) : null;
            $region = !empty($json['region']) ? trim($json['region']) : null;
            $country = !empty($json['country_name']) ? trim($json['country_name']) : (!empty($json['country']) ? trim($json['country']) : null);
            $countryCode = !empty($json['country']) && strlen($json['country']) === 2 ? strtoupper($json['country']) : null;

            return [
                'country'         => $country,
                'country_code'    => $countryCode,
                'region'          => $region,
                'region_code'     => null,
                'city'            => $city,
                'accuracy_radius' => null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Format a display name according to the mandated geographic hierarchy:
     * - Case A: Known city -> "City, Country"
     * - Case B: Unknown city, known region -> "Région : Region — ville non déterminée, Country"
     * - Case C: Country only -> "Country — ville non déterminée"
     *
     * @param string|null $city
     * @param string|null $region
     * @param string|null $country
     * @return string
     */
    public static function formatLocationName(?string $city, ?string $region, ?string $country): string {
        $city = trim((string) $city);
        $region = trim((string) $region);
        $country = trim((string) $country);

        // Normalize placeholders
        if (in_array(strtolower($city), ['unknown', 'inconnu', 'n/a', 'none', ''])) {
            $city = '';
        }
        if (in_array(strtolower($region), ['unknown', 'inconnu', 'n/a', 'none', ''])) {
            $region = '';
        }
        if (in_array(strtolower($country), ['unknown', 'inconnu', 'n/a', 'none', ''])) {
            $country = '';
        }

        // Case A: City known
        if ($city !== '') {
            $cityFormatted = ucwords(strtolower($city));
            return $country !== '' ? "{$cityFormatted}, {$country}" : $cityFormatted;
        }

        // Case B: City unknown, Region known
        if ($region !== '') {
            $label = function_exists('e') ? e("Région : {r} — ville non déterminée", null, ['r' => $region]) : "Région : {$region} — ville non déterminée";
            return $country !== '' ? "{$label}, {$country}" : $label;
        }

        // Case C: Country only
        if ($country !== '') {
            $undetermined = function_exists('e') ? e("ville non déterminée") : "ville non déterminée";
            return "{$country} — {$undetermined}";
        }

        return function_exists('e') ? e("Localisation non déterminée") : "Localisation non déterminée";
    }

    /**
     * Check if an IP address is a public, non-reserved IP
     */
    public static function isPublicIp(?string $ip): bool {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Check if an IP matches a CIDR block or single IP (supports IPv4 and IPv6)
     */
    public static function isIpInCidr(string $ip, string $cidr): bool {
        $ip = trim($ip);
        $cidr = trim($cidr);

        if (substr($ip, 0, 7) === '::ffff:') {
            $ip = substr($ip, 7);
        }

        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $isIpV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isSubnetV4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($isIpV4 && $isSubnetV4) {
            if ($bits < 0 || $bits > 32) return false;
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        $isIpV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $isSubnetV6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if ($isIpV6 && $isSubnetV6) {
            if ($bits < 0 || $bits > 128) return false;
            $ipBytes = inet_pton($ip);
            $subnetBytes = inet_pton($subnet);
            if ($ipBytes === false || $subnetBytes === false) return false;

            $bytesToCheck = (int) floor($bits / 8);
            $remainderBits = $bits % 8;

            for ($i = 0; $i < $bytesToCheck; $i++) {
                if ($ipBytes[$i] !== $subnetBytes[$i]) {
                    return false;
                }
            }

            if ($remainderBits > 0) {
                $mask = 0xFF << (8 - $remainderBits);
                if ((ord($ipBytes[$bytesToCheck]) & $mask) !== (ord($subnetBytes[$bytesToCheck]) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Check if a remote address is a trusted Cloudflare proxy
     */
    public static function isCloudflareProxy(string $remoteAddr): bool {
        foreach (self::$cloudflareRanges as $range) {
            if (self::isIpInCidr($remoteAddr, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve administrative location from browser coordinates (HTTPS browser consent)
     *
     * @param float $lat
     * @param float $lon
     * @param float|null $accuracyMeters
     * @return array
     */
    public static function resolveCoordinates(float $lat, float $lon, ?float $accuracyMeters = null): array {
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return [
                'country'         => null,
                'country_code'    => null,
                'region'          => null,
                'city'            => null,
                'accuracy_radius' => null,
                'source'          => 'browser_consent',
                'precision'       => 'unknown'
            ];
        }

        $accuracyKm = $accuracyMeters !== null && $accuracyMeters > 0 ? round($accuracyMeters / 1000, 2) : 1.0;

        // Known administrative centers in Madagascar
        $mgCenters = [
            ['city' => 'Antananarivo', 'region' => 'Analamanga',      'lat' => -18.8792, 'lon' => 47.5079, 'radius' => 25],
            ['city' => 'Toamasina',    'region' => 'Atsinanana',      'lat' => -18.1492, 'lon' => 49.4023, 'radius' => 20],
            ['city' => 'Antsirabe',    'region' => 'Vakinankaratra',  'lat' => -19.8659, 'lon' => 47.0333, 'radius' => 20],
            ['city' => 'Mahajanga',    'region' => 'Boeny',           'lat' => -15.7167, 'lon' => 46.3167, 'radius' => 20],
            ['city' => 'Fianarantsoa', 'region' => 'Haute Matsiatra', 'lat' => -21.4536, 'lon' => 47.0858, 'radius' => 20],
            ['city' => 'Toliara',      'region' => 'Atsimo-Andrefana','lat' => -23.3516, 'lon' => 43.6672, 'radius' => 20],
            ['city' => 'Antsiranana',  'region' => 'Diana',           'lat' => -12.3056, 'lon' => 49.2917, 'radius' => 20],
            ['city' => 'Nosy Be',      'region' => 'Diana',           'lat' => -13.3167, 'lon' => 48.2667, 'radius' => 18],
            ['city' => 'Mananjary',    'region' => 'Vatovavy',        'lat' => -21.2167, 'lon' => 48.3333, 'radius' => 20],
            ['city' => 'Morondava',    'region' => 'Menabe',          'lat' => -20.2833, 'lon' => 44.2833, 'radius' => 20],
            ['city' => 'Taolagnaro',   'region' => 'Anosy',           'lat' => -25.0381, 'lon' => 46.9987, 'radius' => 20],
            ['city' => 'Sambava',      'region' => 'Sava',            'lat' => -14.2667, 'lon' => 50.1667, 'radius' => 20],
        ];

        $isMadagascar = ($lat >= -25.7 && $lat <= -11.8 && $lon >= 43.1 && $lon <= 50.6);

        if ($isMadagascar) {
            $closest = null;
            $minDist = INF;

            foreach ($mgCenters as $center) {
                $dist = self::haversineDistance($lat, $lon, $center['lat'], $center['lon']);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $closest = $center;
                }
            }

            if ($closest && $minDist <= $closest['radius']) {
                return [
                    'country'         => 'Madagascar',
                    'country_code'    => 'MG',
                    'region'          => $closest['region'],
                    'city'            => $closest['city'],
                    'accuracy_radius' => $accuracyKm,
                    'source'          => 'browser_consent',
                    'precision'       => 'city'
                ];
            } elseif ($closest) {
                return [
                    'country'         => 'Madagascar',
                    'country_code'    => 'MG',
                    'region'          => $closest['region'],
                    'city'            => null,
                    'accuracy_radius' => $accuracyKm,
                    'source'          => 'browser_consent',
                    'precision'       => 'region'
                ];
            }

            return [
                'country'         => 'Madagascar',
                'country_code'    => 'MG',
                'region'          => null,
                'city'            => null,
                'accuracy_radius' => $accuracyKm,
                'source'          => 'browser_consent',
                'precision'       => 'country'
            ];
        }

        return [
            'country'         => null,
            'country_code'    => null,
            'region'          => null,
            'city'            => null,
            'accuracy_radius' => $accuracyKm,
            'source'          => 'browser_consent',
            'precision'       => 'coordinates'
        ];
    }

    /**
     * Haversine distance in kilometers between two lat/lon points
     */
    public static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Get Database Information and Status
     */
    public static function getDatabaseInfo(?string $path = null): array {
        $path = $path ?: (appConfig('app.geopath') ?: (defined('STORAGE') ? STORAGE . '/app/GeoLite2-City.mmdb' : ''));
        $info = [
            'path'        => $path,
            'exists'      => file_exists($path),
            'readable'    => is_readable($path),
            'size_bytes'  => file_exists($path) ? filesize($path) : 0,
            'build_epoch' => null,
            'build_date'  => null,
            'age_days'    => null,
        ];

        if ($info['exists'] && $info['readable']) {
            try {
                $reader = new \MaxMind\Db\Reader($path);
                $meta = $reader->metadata();
                $info['build_epoch'] = $meta->buildEpoch;
                $info['build_date'] = date('Y-m-d H:i:s T', $meta->buildEpoch);
                $info['age_days'] = round((time() - $meta->buildEpoch) / 86400, 1);
                $reader->close();
            } catch (\Exception $e) {}
        }

        return $info;
    }

    /**
     * Check if a specific column exists in the stats table
     */
    public static function hasStatsColumn(string $column): bool {
        static $columns = null;
        if ($columns === null) {
            $columns = [];
            try {
                if (class_exists('\Core\DB') && defined('DBhost') && DBhost) {
                    $table = (defined('DBprefix') ? DBprefix : '') . 'stats';
                    $cols = \Core\DB::forTable('stats')->rawQuery("SHOW COLUMNS FROM `{$table}`")->findArray();
                    $columns = array_column($cols, 'Field');
                }
            } catch (\Throwable $e) {
                $columns = [];
            }
        }
        return in_array($column, $columns);
    }

    /**
     * Calculate geolocation data quality metrics from stats records or aggregations
     */
    public static function calculateQualityMetrics(array $cities): array {
        $identifiedCityCount = 0;
        $regionOnlyCount = 0;
        $undeterminedCount = 0;

        foreach ($cities as $item) {
            $count = (int) ($item['count'] ?? 0);
            $city = trim((string) ($item['city'] ?? ''));
            $region = trim((string) ($item['region'] ?? ''));

            $isCityKnown = ($city !== '' && !in_array(strtolower($city), ['unknown', 'inconnu', 'n/a', 'none']));
            $isRegionKnown = ($region !== '' && !in_array(strtolower($region), ['unknown', 'inconnu', 'n/a', 'none']));

            if ($isCityKnown) {
                $identifiedCityCount += $count;
            } elseif ($isRegionKnown) {
                $regionOnlyCount += $count;
            } else {
                $undeterminedCount += $count;
            }
        }

        $total = $identifiedCityCount + $regionOnlyCount + $undeterminedCount;
        $completeness = $total > 0 ? round((($identifiedCityCount + $regionOnlyCount) / $total) * 100, 1) : 0;

        return [
            'identified_city_count' => $identifiedCityCount,
            'region_only_count'     => $regionOnlyCount,
            'undetermined_count'    => $undeterminedCount,
            'total'                 => $total,
            'completeness_percent'  => $completeness,
        ];
    }
}
