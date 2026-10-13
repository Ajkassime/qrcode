<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel
 * ---------------------------------------------------------------------------------------
 *  Centralized Geolocation & Reverse Geocoding Service (Worldwide & Administrative)
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
     * In-memory cache for reverse geocoding coordinate cells
     * @var array
     */
    protected static $reverseGeoCache = [];

    /**
     * Loaded administrative boundary polygons dataset
     * @var array|null
     */
    protected static $boundariesData = null;

    /**
     * Custom path override for boundaries dataset
     * @var string|null
     */
    protected static $customBoundariesPath = null;

    /**
     * Mock reader for testing purposes
     * @var mixed
     */
    protected static $mockReader = null;

    /**
     * Mock secondary IP provider response for testing
     * @var mixed
     */
    protected static $mockSecondary = null;

    /**
     * Mock external reverse geocoder callback/array for testing
     * @var mixed
     */
    protected static $mockReverseGeocoder = null;

    /**
     * Cached stats table columns
     * @var array|null
     */
    protected static $statsColumnsCache = null;

    /**
     * Rate-limiting and quota tracking for external reverse geocoder
     * @var array
     */
    protected static $geocoderMetrics = [
        'requests_total'       => 0,
        'cache_hits'           => 0,
        'quota_exceeded_count' => 0,
        'errors_count'         => 0,
        'minute_window'        => 0,
        'minute_count'         => 0,
        'day_window'           => '',
        'day_count'            => 0,
    ];

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
     * ISO 3166-1 alpha-2 country code to Continent mapping
     * @var array
     */
    protected static $countryContinentMap = [
        'MG' => ['code' => 'AF', 'name' => 'Africa'],
        'ZA' => ['code' => 'AF', 'name' => 'Africa'],
        'EG' => ['code' => 'AF', 'name' => 'Africa'],
        'MA' => ['code' => 'AF', 'name' => 'Africa'],
        'NG' => ['code' => 'AF', 'name' => 'Africa'],
        'KE' => ['code' => 'AF', 'name' => 'Africa'],
        'SN' => ['code' => 'AF', 'name' => 'Africa'],
        'CI' => ['code' => 'AF', 'name' => 'Africa'],
        'CM' => ['code' => 'AF', 'name' => 'Africa'],
        'MU' => ['code' => 'AF', 'name' => 'Africa'],
        'RE' => ['code' => 'AF', 'name' => 'Africa'],
        'KM' => ['code' => 'AF', 'name' => 'Africa'],
        'SC' => ['code' => 'AF', 'name' => 'Africa'],
        'TZ' => ['code' => 'AF', 'name' => 'Africa'],
        'MZ' => ['code' => 'AF', 'name' => 'Africa'],
        'FR' => ['code' => 'EU', 'name' => 'Europe'],
        'GB' => ['code' => 'EU', 'name' => 'Europe'],
        'DE' => ['code' => 'EU', 'name' => 'Europe'],
        'IT' => ['code' => 'EU', 'name' => 'Europe'],
        'ES' => ['code' => 'EU', 'name' => 'Europe'],
        'CH' => ['code' => 'EU', 'name' => 'Europe'],
        'BE' => ['code' => 'EU', 'name' => 'Europe'],
        'NL' => ['code' => 'EU', 'name' => 'Europe'],
        'PT' => ['code' => 'EU', 'name' => 'Europe'],
        'SE' => ['code' => 'EU', 'name' => 'Europe'],
        'NO' => ['code' => 'EU', 'name' => 'Europe'],
        'PL' => ['code' => 'EU', 'name' => 'Europe'],
        'TR' => ['code' => 'AS', 'name' => 'Asia'],
        'JP' => ['code' => 'AS', 'name' => 'Asia'],
        'CN' => ['code' => 'AS', 'name' => 'Asia'],
        'IN' => ['code' => 'AS', 'name' => 'Asia'],
        'SG' => ['code' => 'AS', 'name' => 'Asia'],
        'AE' => ['code' => 'AS', 'name' => 'Asia'],
        'KR' => ['code' => 'AS', 'name' => 'Asia'],
        'TH' => ['code' => 'AS', 'name' => 'Asia'],
        'VN' => ['code' => 'AS', 'name' => 'Asia'],
        'ID' => ['code' => 'AS', 'name' => 'Asia'],
        'MY' => ['code' => 'AS', 'name' => 'Asia'],
        'PH' => ['code' => 'AS', 'name' => 'Asia'],
        'SA' => ['code' => 'AS', 'name' => 'Asia'],
        'US' => ['code' => 'NA', 'name' => 'North America'],
        'CA' => ['code' => 'NA', 'name' => 'North America'],
        'MX' => ['code' => 'NA', 'name' => 'North America'],
        'BR' => ['code' => 'SA', 'name' => 'South America'],
        'AR' => ['code' => 'SA', 'name' => 'South America'],
        'CL' => ['code' => 'SA', 'name' => 'South America'],
        'CO' => ['code' => 'SA', 'name' => 'South America'],
        'PE' => ['code' => 'SA', 'name' => 'South America'],
        'AU' => ['code' => 'OC', 'name' => 'Oceania'],
        'NZ' => ['code' => 'OC', 'name' => 'Oceania'],
    ];

    /**
     * Set a mock reader for unit testing
     */
    public static function setMockReader($reader): void {
        self::$mockReader = $reader;
        self::$memoryCache = [];
    }

    /**
     * Set a mock secondary IP provider response for testing
     */
    public static function setMockSecondary($callableOrArray): void {
        self::$mockSecondary = $callableOrArray;
    }

    /**
     * Set a mock external reverse geocoder for testing
     */
    public static function setMockReverseGeocoder($callableOrArray): void {
        self::$mockReverseGeocoder = $callableOrArray;
        self::$reverseGeoCache = [];
    }

    /**
     * Set a custom boundaries JSON file path
     */
    public static function setBoundariesPath(?string $path): void {
        self::$customBoundariesPath = $path;
        self::$boundariesData = null;
    }

    /**
     * Reset cached stats table columns
     */
    public static function resetColumnCache(): void {
        self::$statsColumnsCache = null;
    }

    /**
     * Clear resolution caches and mocks
     */
    public static function clearCache(): void {
        self::$memoryCache = [];
        self::$reverseGeoCache = [];
        self::$mockReader = null;
        self::$mockSecondary = null;
        self::$mockReverseGeocoder = null;
        self::$boundariesData = null;
        self::$customBoundariesPath = null;
        self::$statsColumnsCache = null;
        self::$geocoderMetrics = [
            'requests_total'       => 0,
            'cache_hits'           => 0,
            'quota_exceeded_count' => 0,
            'errors_count'         => 0,
            'minute_window'        => 0,
            'minute_count'         => 0,
            'day_window'           => '',
            'day_count'            => 0,
        ];
    }

    /**
     * Get reverse geocoder usage and cost/quota telemetry
     */
    public static function getReverseGeocoderStats(): array {
        return self::$geocoderMetrics;
    }

    /**
     * Mask an API key for safe logging/diagnostics
     */
    public static function maskApiKey(?string $key): string {
        $key = trim((string) $key);
        if ($key === '') {
            return '(none)';
        }
        if (strlen($key) <= 6) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 3) . str_repeat('*', strlen($key) - 6) . substr($key, -3);
    }

    /**
     * Resolve continent info from ISO 3166-1 alpha-2 country code
     */
    public static function getContinentForCountry(?string $countryCode): array {
        $code = strtoupper(trim((string) $countryCode));
        if ($code !== '' && isset(self::$countryContinentMap[$code])) {
            return self::$countryContinentMap[$code];
        }
        return ['code' => null, 'name' => null];
    }

    /**
     * Locate an IP address (IPv4 or IPv6) worldwide
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
            'continent'       => null,
            'continent_code'  => null,
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
                $result['country'] = $secondaryResult['country'] ?: $result['country'];
                $result['country_code'] = $secondaryResult['country_code'] ?: $result['country_code'];
                $result['region'] = $secondaryResult['region'] ?: $result['region'];
                $result['region_code'] = $secondaryResult['region_code'] ?: $result['region_code'];
                $result['city'] = $secondaryResult['city'] ?: $result['city'];
                $result['accuracy_radius'] = $secondaryResult['accuracy_radius'] ?? $result['accuracy_radius'];
                $result['source'] = 'secondary_provider';

                if (empty($result['continent_code']) && !empty($result['country_code'])) {
                    $cont = self::getContinentForCountry($result['country_code']);
                    $result['continent'] = $cont['name'];
                    $result['continent_code'] = $cont['code'];
                }

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
            'continent'       => null,
            'continent_code'  => null,
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

            $data['continent'] = $record['continent']['names']['en'] ?? null;
            $data['continent_code'] = $record['continent']['code'] ?? null;
            if (empty($data['continent_code']) && !empty($data['country_code'])) {
                $cont = self::getContinentForCountry($data['country_code']);
                $data['continent'] = $cont['name'];
                $data['continent_code'] = $cont['code'];
            }

            if (!empty($record['subdivisions'])) {
                $data['region'] = $record['subdivisions'][0]['names']['en'] ?? null;
                $isoCode = $record['subdivisions'][0]['iso_code'] ?? null;
                if ($isoCode && !empty($data['country_code']) && strpos($isoCode, '-') === false) {
                    $data['region_code'] = $data['country_code'] . '-' . $isoCode;
                } else {
                    $data['region_code'] = $isoCode;
                }
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
     * Check if a secondary IP provider is configured and enabled
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
     * Resolve via secondary IP provider (with strict timeouts & circuit breaker)
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
     * Format a display name according to the mandated 3-level geographic hierarchy:
     * - Level 1 (Known city): "City, Country"
     * - Level 2 (Unknown city, known region): "Région : Region — ville non déterminée, Country"
     * - Level 3 (Country only): "Country — ville non déterminée"
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

        // Level 1: City known
        if ($city !== '') {
            $cityFormatted = mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
            return $country !== '' ? "{$cityFormatted}, {$country}" : $cityFormatted;
        }

        // Level 2: City unknown, Region known
        if ($region !== '') {
            $label = function_exists('e') ? e("Région : {r} — ville non déterminée", null, ['r' => $region]) : "Région : {$region} — ville non déterminée";
            return $country !== '' ? "{$label}, {$country}" : $label;
        }

        // Level 3: Country only
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
     * Load the administrative boundary polygons dataset from storage/app/geo_boundaries.json
     */
    protected static function getBoundariesData(): array {
        if (self::$boundariesData !== null) {
            return self::$boundariesData;
        }

        $path = self::$customBoundariesPath;
        if (!$path) {
            $cfg = appConfig('app.reverse_geocoder');
            $path = is_array($cfg) && !empty($cfg['boundaries_path'])
                ? $cfg['boundaries_path']
                : (defined('STORAGE') ? STORAGE . '/app/geo_boundaries.json' : dirname(__DIR__, 2) . '/storage/app/geo_boundaries.json');
        }

        if (!$path || !file_exists($path) || !is_readable($path)) {
            return self::$boundariesData = ['countries' => []];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::$boundariesData = ['countries' => []];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['countries'])) {
            return self::$boundariesData = ['countries' => []];
        }

        return self::$boundariesData = $decoded;
    }

    /**
     * Ray-casting algorithm to determine whether a (lat, lon) coordinate lies inside a polygon.
     * Polygon vertices follow GeoJSON standard [lon, lat] pairs.
     *
     * @param float $lat
     * @param float $lon
     * @param array $polygon Array of [lon, lat] points
     * @return bool
     */
    public static function isPointInPolygon(float $lat, float $lon, array $polygon): bool {
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $polygon[$i][0]; // lon
            $yi = (float) $polygon[$i][1]; // lat
            $xj = (float) $polygon[$j][0]; // lon
            $yj = (float) $polygon[$j][1]; // lat

            // Check if point is exactly on a vertex
            if (abs($lat - $yi) < 1e-9 && abs($lon - $xi) < 1e-9) {
                return true;
            }

            $intersect = (($yi > $lat) !== ($yj > $lat)) &&
                ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Compute the minimum distance in kilometers from (lat, lon) to any edge of a polygon.
     * Used to detect when a visitor's GPS uncertainty circle overlaps an administrative border.
     * Polygon vertices follow GeoJSON standard [lon, lat] pairs.
     *
     * @param float $lat
     * @param float $lon
     * @param array $polygon Array of [lon, lat] vertices
     * @return float Distance in km
     */
    public static function distanceToPolygonBorderKm(float $lat, float $lon, array $polygon): float {
        $n = count($polygon);
        if ($n < 2) {
            return INF;
        }

        $minDistKm = INF;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $lon1 = (float) $polygon[$j][0];
            $lat1 = (float) $polygon[$j][1];
            $lon2 = (float) $polygon[$i][0];
            $lat2 = (float) $polygon[$i][1];

            $dist = self::pointToSegmentDistanceKm($lat, $lon, $lat1, $lon1, $lat2, $lon2);
            if ($dist < $minDistKm) {
                $minDistKm = $dist;
            }
        }

        return $minDistKm;
    }

    /**
     * Distance in kilometers from point P(lat, lon) to line segment A(lat1, lon1) - B(lat2, lon2)
     */
    protected static function pointToSegmentDistanceKm(float $lat, float $lon, float $lat1, float $lon1, float $lat2, float $lon2): float {
        $cosLat = cos(deg2rad($lat));
        $px = $lon * $cosLat;
        $py = $lat;
        $ax = $lon1 * $cosLat;
        $ay = $lat1;
        $bx = $lon2 * $cosLat;
        $by = $lat2;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lenSq = $dx * $dx + $dy * $dy;

        if ($lenSq <= 1e-12) {
            return self::haversineDistance($lat, $lon, $lat1, $lon1);
        }

        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
        $t = max(0.0, min(1.0, $t));

        $projLat = $lat1 + $t * ($lat2 - $lat1);
        $projLon = $lon1 + $t * ($lon2 - $lon1);

        return self::haversineDistance($lat, $lon, $projLat, $projLon);
    }

    /**
     * Resolve administrative location from browser coordinates (HTTPS browser consent).
     *
     * Rules enforced:
     * 1. No hardcoded 12-city center distance approximations.
     * 2. Uses true administrative polygon boundaries (`storage/app/geo_boundaries.json`) +
     *    configurable worldwide reverse geocoding service.
     * 3. Degrades precision when GPS accuracy (`accuracyMeters`) is too coarse or when the
     *    uncertainty circle crosses an administrative boundary (`border_uncertainty`).
     * 4. Never claims browser coordinates are server-verified (`verified => false`, `source => 'browser_consent'`).
     *
     * @param float $lat
     * @param float $lon
     * @param float|null $accuracyMeters
     * @return array
     */
    public static function resolveCoordinates(float $lat, float $lon, ?float $accuracyMeters = null): array {
        $baseResult = [
            'continent'       => null,
            'continent_code'  => null,
            'country'         => null,
            'country_code'    => null,
            'region'          => null,
            'region_code'     => null,
            'admin_type'      => null,
            'city'            => null,
            'accuracy_radius' => null,
            'source'          => 'browser_consent',
            'verified'        => false,
            'precision'       => 'unknown',
            'missing_reason'  => null,
        ];

        if (!is_finite($lat) || !is_finite($lon) || $lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            $baseResult['missing_reason'] = 'invalid_coordinates';
            return $baseResult;
        }

        // Convert accuracy in meters to kilometers (default 0.05 km = 50m when unspecified)
        $effectiveAccuracyMeters = ($accuracyMeters !== null && $accuracyMeters >= 0) ? (float) $accuracyMeters : 50.0;
        $accuracyKm = round(max(0.01, $effectiveAccuracyMeters / 1000.0), 2);
        $baseResult['accuracy_radius'] = $accuracyKm;

        // 1. Evaluate against local administrative boundary polygons
        $localMatch = self::resolveViaPolygonBoundaries($lat, $lon);

        // 2. If not resolved to a city or region and external reverse geocoder is enabled, query it
        $externalMatch = null;
        if ((empty($localMatch['country']) || empty($localMatch['city'])) && self::isReverseGeocoderEnabled()) {
            $externalMatch = self::resolveExternalReverseGeocoder($lat, $lon);
        }

        // Merge local polygon match with external reverse geocoder match
        $continent = $localMatch['continent'] ?? ($externalMatch['continent'] ?? null);
        $continentCode = $localMatch['continent_code'] ?? ($externalMatch['continent_code'] ?? null);
        $country = $localMatch['country'] ?? ($externalMatch['country'] ?? null);
        $countryCode = $localMatch['country_code'] ?? ($externalMatch['country_code'] ?? null);
        $region = $localMatch['region'] ?? ($externalMatch['region'] ?? null);
        $regionCode = $localMatch['region_code'] ?? ($externalMatch['region_code'] ?? null);
        $adminType = $localMatch['admin_type'] ?? ($externalMatch['admin_type'] ?? null);
        $city = $localMatch['city'] ?? ($externalMatch['city'] ?? null);
        $regionBorderDistKm = $localMatch['region_border_dist_km'] ?? INF;
        $cityBorderDistKm = $localMatch['city_border_dist_km'] ?? INF;

        if (empty($continentCode) && !empty($countryCode)) {
            $cont = self::getContinentForCountry($countryCode);
            $continent = $cont['name'];
            $continentCode = $cont['code'];
        }

        // If no country could be identified (e.g., ocean / international waters / unmapped territory)
        if (empty($country)) {
            $baseResult['missing_reason'] = 'out_of_coverage';
            return $baseResult;
        }

        $baseResult['continent'] = $continent;
        $baseResult['continent_code'] = $continentCode;
        $baseResult['country'] = $country;
        $baseResult['country_code'] = $countryCode;
        $baseResult['admin_type'] = $adminType;

        // 3. Enforce GPS accuracy & administrative border proximity rules:
        // Rule A: Accuracy worse than 50 km -> cannot reliably attribute region or city
        if ($effectiveAccuracyMeters > 50000.0) {
            $baseResult['region'] = null;
            $baseResult['region_code'] = null;
            $baseResult['city'] = null;
            $baseResult['precision'] = 'country';
            $baseResult['missing_reason'] = 'low_gps_accuracy';
            return $baseResult;
        }

        // Rule B: Region border uncertainty check
        // If the GPS uncertainty circle crosses the regional border, do not attribute a potentially wrong region
        if (!empty($region) && $regionBorderDistKm < INF) {
            if ($effectiveAccuracyMeters >= 500.0 && $accuracyKm > $regionBorderDistKm) {
                $baseResult['region'] = null;
                $baseResult['region_code'] = null;
                $baseResult['city'] = null;
                $baseResult['precision'] = 'country';
                $baseResult['missing_reason'] = 'region_border_uncertainty';
                return $baseResult;
            }
        }

        $baseResult['region'] = $region;
        $baseResult['region_code'] = $regionCode;

        // Rule C: Accuracy worse than 5 km -> too coarse to confirm a specific urban commune/city
        if ($effectiveAccuracyMeters > 5000.0) {
            $baseResult['city'] = null;
            if (!empty($region)) {
                $baseResult['precision'] = 'region';
                $baseResult['missing_reason'] = 'low_gps_accuracy_for_city';
            } else {
                $baseResult['precision'] = 'country';
                $baseResult['missing_reason'] = 'low_gps_accuracy';
            }
            return $baseResult;
        }

        // Rule D: City border uncertainty check
        if (!empty($city) && $cityBorderDistKm < INF) {
            if ($effectiveAccuracyMeters >= 250.0 && $accuracyKm > $cityBorderDistKm) {
                $baseResult['city'] = null;
                if (!empty($region)) {
                    $baseResult['precision'] = 'region';
                    $baseResult['missing_reason'] = 'city_border_uncertainty';
                } else {
                    $baseResult['precision'] = 'country';
                    $baseResult['missing_reason'] = 'city_border_uncertainty';
                }
                return $baseResult;
            }
        }

        $baseResult['city'] = $city;

        if (!empty($city)) {
            $baseResult['precision'] = 'city';
            $baseResult['missing_reason'] = null;
        } elseif (!empty($region)) {
            $baseResult['precision'] = 'region';
            $baseResult['missing_reason'] = 'outside_urban_commune';
        } else {
            $baseResult['precision'] = 'country';
            $baseResult['missing_reason'] = 'country_level_only';
        }

        return $baseResult;
    }

    /**
     * Match (lat, lon) against administrative boundary polygons in geo_boundaries.json
     */
    protected static function resolveViaPolygonBoundaries(float $lat, float $lon): array {
        $result = [
            'continent'             => null,
            'continent_code'        => null,
            'country'               => null,
            'country_code'          => null,
            'region'                => null,
            'region_code'           => null,
            'admin_type'            => null,
            'city'                  => null,
            'region_border_dist_km' => INF,
            'city_border_dist_km'   => INF,
        ];

        $dataset = self::getBoundariesData();
        if (empty($dataset['countries']) || !is_array($dataset['countries'])) {
            return $result;
        }

        $fallbackCountryMatch = null;

        foreach ($dataset['countries'] as $countryEntry) {
            if (!empty($countryEntry['bbox'])) {
                // bbox format: [minLat, minLon, maxLat, maxLon]
                [$minLat, $minLon, $maxLat, $maxLon] = $countryEntry['bbox'];
                if ($lat < $minLat || $lat > $maxLat || $lon < $minLon || $lon > $maxLon) {
                    continue;
                }
            }

            $matchedRegion = null;
            $matchedRegionDist = INF;

            if (!empty($countryEntry['regions']) && is_array($countryEntry['regions'])) {
                foreach ($countryEntry['regions'] as $regionEntry) {
                    if (empty($regionEntry['polygon'])) {
                        continue;
                    }
                    if (self::isPointInPolygon($lat, $lon, $regionEntry['polygon'])) {
                        $matchedRegion = $regionEntry;
                        $matchedRegionDist = self::distanceToPolygonBorderKm($lat, $lon, $regionEntry['polygon']);
                        break;
                    }
                }
            }

            if ($matchedRegion !== null) {
                $result['continent'] = $countryEntry['continent'] ?? null;
                $result['continent_code'] = $countryEntry['continent_code'] ?? null;
                $result['country'] = $countryEntry['country'] ?? null;
                $result['country_code'] = $countryEntry['country_code'] ?? null;
                $result['region'] = $matchedRegion['name'] ?? null;
                $result['region_code'] = $matchedRegion['code'] ?? ($matchedRegion['iso_code'] ?? null);
                $result['admin_type'] = $matchedRegion['type'] ?? 'region';
                $result['region_border_dist_km'] = $matchedRegionDist;

                if (!empty($matchedRegion['cities']) && is_array($matchedRegion['cities'])) {
                    foreach ($matchedRegion['cities'] as $cityEntry) {
                        if (!empty($cityEntry['polygon']) && self::isPointInPolygon($lat, $lon, $cityEntry['polygon'])) {
                            $result['city'] = $cityEntry['name'] ?? null;
                            $result['city_border_dist_km'] = self::distanceToPolygonBorderKm($lat, $lon, $cityEntry['polygon']);
                            break;
                        }
                    }
                }

                return $result;
            }

            // Only use country-level polygon fallback if the country entry has no regions defined
            if (empty($countryEntry['regions']) && $fallbackCountryMatch === null) {
                $countryPoly = $countryEntry['polygon'] ?? ($countryEntry['country_polygon'] ?? null);
                if (!empty($countryPoly) && self::isPointInPolygon($lat, $lon, $countryPoly)) {
                    $fallbackCountryMatch = $countryEntry;
                }
            }
        }

        if ($fallbackCountryMatch !== null) {
            $result['continent'] = $fallbackCountryMatch['continent'] ?? null;
            $result['continent_code'] = $fallbackCountryMatch['continent_code'] ?? null;
            $result['country'] = $fallbackCountryMatch['country'] ?? null;
            $result['country_code'] = $fallbackCountryMatch['country_code'] ?? null;
        }

        return $result;
    }

    /**
     * Check if external reverse geocoding is enabled
     */
    public static function isReverseGeocoderEnabled(): bool {
        if (self::$mockReverseGeocoder !== null) {
            return true;
        }

        $config = appConfig('app.reverse_geocoder');
        if (!is_array($config) || empty($config['enabled'])) {
            return false;
        }

        $provider = strtolower((string) ($config['provider'] ?? 'opencage'));
        if (in_array($provider, ['local_polygons', 'none', ''])) {
            return false;
        }

        // Providers requiring an API key
        if (in_array($provider, ['opencage', 'mapbox', 'google']) && empty($config['api_key'])) {
            return false;
        }

        return true;
    }

    /**
     * Resolve coordinates via configurable external reverse geocoder with caching,
     * rate limiting, daily quota enforcement, timeout, and international normalization.
     */
    protected static function resolveExternalReverseGeocoder(float $lat, float $lon): ?array {
        // Round to ~110m grid cell for privacy and high cache hit ratio
        $cacheKey = number_format($lat, 3, '.', '') . ',' . number_format($lon, 3, '.', '');

        if (isset(self::$reverseGeoCache[$cacheKey])) {
            self::$geocoderMetrics['cache_hits']++;
            return self::$reverseGeoCache[$cacheKey];
        }

        $config = appConfig('app.reverse_geocoder');
        $maxPerMinute = is_array($config) && isset($config['max_requests_per_minute']) ? (int) $config['max_requests_per_minute'] : 60;
        $dailyQuota = is_array($config) && isset($config['daily_quota']) ? (int) $config['daily_quota'] : 2500;

        // Enforce rate-limiting & daily cost/quota cap
        $currentMinute = (int) floor(time() / 60);
        if (self::$geocoderMetrics['minute_window'] !== $currentMinute) {
            self::$geocoderMetrics['minute_window'] = $currentMinute;
            self::$geocoderMetrics['minute_count'] = 0;
        }
        $currentDay = gmdate('Y-m-d');
        if (self::$geocoderMetrics['day_window'] !== $currentDay) {
            self::$geocoderMetrics['day_window'] = $currentDay;
            self::$geocoderMetrics['day_count'] = 0;
        }

        if (self::$geocoderMetrics['minute_count'] >= $maxPerMinute || self::$geocoderMetrics['day_count'] >= $dailyQuota) {
            self::$geocoderMetrics['quota_exceeded_count']++;
            return null;
        }

        self::$geocoderMetrics['minute_count']++;
        self::$geocoderMetrics['day_count']++;
        self::$geocoderMetrics['requests_total']++;

        if (self::$mockReverseGeocoder !== null) {
            if (is_callable(self::$mockReverseGeocoder)) {
                $res = call_user_func(self::$mockReverseGeocoder, $lat, $lon);
            } else {
                $res = is_array(self::$mockReverseGeocoder) ? self::$mockReverseGeocoder : null;
            }
            if (is_array($res)) {
                $normalized = self::normalizeReverseGeocoderResponse($res);
                return self::$reverseGeoCache[$cacheKey] = $normalized;
            }
            return null;
        }

        if (!is_array($config) || empty($config['enabled'])) {
            return null;
        }

        $provider = strtolower((string) ($config['provider'] ?? 'opencage'));
        $apiKey = (string) ($config['api_key'] ?? '');
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 1.5;
        $userAgent = !empty($config['user_agent']) ? $config['user_agent'] : 'QRCodePlatform-GeoResolver/1.0';

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'header' => "User-Agent: {$userAgent}\r\nAccept: application/json\r\n",
                ],
            ]);

            $url = null;
            if ($provider === 'opencage' && $apiKey !== '') {
                $url = 'https://api.opencagedata.com/geocode/v1/json?q=' . urlencode("{$lat}+{$lon}") . '&key=' . urlencode($apiKey) . '&no_annotations=1&limit=1';
            } elseif ($provider === 'bigdatacloud') {
                $url = 'https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=' . urlencode((string) $lat) . '&longitude=' . urlencode((string) $lon) . '&localityLanguage=en';
                if ($apiKey !== '') {
                    $url .= '&key=' . urlencode($apiKey);
                }
            } elseif ($provider === 'nominatim') {
                $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . urlencode((string) $lat) . '&lon=' . urlencode((string) $lon) . '&zoom=14&addressdetails=1';
            } else {
                return null;
            }

            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                self::$geocoderMetrics['errors_count']++;
                return null;
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                self::$geocoderMetrics['errors_count']++;
                return null;
            }

            $normalized = self::normalizeReverseGeocoderResponse($json, $provider);
            if ($normalized !== null) {
                self::$reverseGeoCache[$cacheKey] = $normalized;
            }
            return $normalized;
        } catch (\Throwable $e) {
            self::$geocoderMetrics['errors_count']++;
            return null;
        }
    }

    /**
     * Normalize international administrative hierarchies (State, Province, Region, Department, Commune, City)
     * across reverse geocoding providers.
     */
    public static function normalizeReverseGeocoderResponse(array $payload, string $provider = 'custom'): ?array {
        // Direct normalized format (used by mocks or custom adapters)
        if (isset($payload['country']) || isset($payload['country_code'])) {
            $countryCode = !empty($payload['country_code']) ? strtoupper(trim((string) $payload['country_code'])) : null;
            $cont = self::getContinentForCountry($countryCode);
            return [
                'continent'      => $payload['continent'] ?? $cont['name'],
                'continent_code' => $payload['continent_code'] ?? $cont['code'],
                'country'        => $payload['country'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $payload['region'] ?? ($payload['state'] ?? ($payload['province'] ?? ($payload['department'] ?? null))),
                'region_code'    => $payload['region_code'] ?? null,
                'admin_type'     => $payload['admin_type'] ?? 'region',
                'city'           => $payload['city'] ?? ($payload['commune'] ?? ($payload['municipality'] ?? ($payload['town'] ?? null))),
            ];
        }

        // OpenCage format
        if (!empty($payload['results'][0]['components'])) {
            $comp = $payload['results'][0]['components'];
            $countryCode = !empty($comp['ISO_3166-1_alpha-2']) ? strtoupper($comp['ISO_3166-1_alpha-2']) : (!empty($comp['country_code']) ? strtoupper($comp['country_code']) : null);
            $cont = self::getContinentForCountry($countryCode);
            $regionCode = null;
            if (!empty($comp['ISO_3166-2'][0])) {
                $regionCode = $comp['ISO_3166-2'][0];
            }
            return [
                'continent'      => $comp['continent'] ?? $cont['name'],
                'continent_code' => $cont['code'],
                'country'        => $comp['country'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $comp['state'] ?? ($comp['province'] ?? ($comp['region'] ?? ($comp['county'] ?? ($comp['state_district'] ?? null)))),
                'region_code'    => $regionCode,
                'admin_type'     => isset($comp['state']) ? 'state' : (isset($comp['province']) ? 'province' : 'region'),
                'city'           => $comp['city'] ?? ($comp['town'] ?? ($comp['municipality'] ?? ($comp['commune'] ?? ($comp['village'] ?? null)))),
            ];
        }

        // BigDataCloud format
        if (isset($payload['countryName']) || isset($payload['countryCode'])) {
            $countryCode = !empty($payload['countryCode']) ? strtoupper($payload['countryCode']) : null;
            $cont = self::getContinentForCountry($countryCode);
            return [
                'continent'      => $payload['continent'] ?? $cont['name'],
                'continent_code' => $payload['continentCode'] ?? $cont['code'],
                'country'        => $payload['countryName'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $payload['principalSubdivision'] ?? null,
                'region_code'    => $payload['principalSubdivisionCode'] ?? null,
                'admin_type'     => 'subdivision',
                'city'           => $payload['city'] ?: ($payload['locality'] ?: null),
            ];
        }

        // Nominatim / OSM addressdetails format
        if (!empty($payload['address']) && is_array($payload['address'])) {
            $addr = $payload['address'];
            $countryCode = !empty($addr['country_code']) ? strtoupper($addr['country_code']) : null;
            $cont = self::getContinentForCountry($countryCode);
            return [
                'continent'      => $cont['name'],
                'continent_code' => $cont['code'],
                'country'        => $addr['country'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $addr['state'] ?? ($addr['province'] ?? ($addr['region'] ?? ($addr['county'] ?? null))),
                'region_code'    => $addr['ISO3166-2-lvl4'] ?? ($addr['ISO3166-2-lvl6'] ?? null),
                'admin_type'     => 'region',
                'city'           => $addr['city'] ?? ($addr['town'] ?? ($addr['municipality'] ?? ($addr['village'] ?? null))),
            ];
        }

        return null;
    }

    /**
     * Haversine distance in kilometers between two lat/lon points
     */
    public static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371.0;
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
     * Check if a specific column exists in the stats table (supports MySQL & SQLite)
     */
    public static function hasStatsColumn(string $column): bool {
        if (self::$statsColumnsCache === null) {
            self::$statsColumnsCache = [];
            try {
                if (class_exists('\Core\DB')) {
                    $pdo = \Core\DB::get_db();
                    if ($pdo instanceof \PDO) {
                        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
                        $table = (defined('DBprefix') ? DBprefix : '') . 'stats';
                        if ($driver === 'sqlite') {
                            $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
                            $cols = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                            self::$statsColumnsCache = array_column($cols, 'name');
                        } else {
                            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                            $cols = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                            self::$statsColumnsCache = array_column($cols, 'Field');
                        }
                    }
                }
            } catch (\Throwable $e) {
                self::$statsColumnsCache = [];
            }
        }
        return in_array($column, self::$statsColumnsCache, true);
    }

    /**
     * Calculate global & regional geolocation data quality metrics from stats records or aggregations
     */
    public static function calculateQualityMetrics(array $cities): array {
        $identifiedCityCount = 0;
        $regionOnlyCount = 0;
        $countryOnlyCount = 0;
        $continents = [];

        foreach ($cities as $item) {
            $count = (int) ($item['count'] ?? 0);
            $city = trim((string) ($item['city'] ?? ''));
            $region = trim((string) ($item['region'] ?? ''));
            $country = trim((string) ($item['country'] ?? ''));
            $countryCode = trim((string) ($item['country_code'] ?? ''));

            $isCityKnown = ($city !== '' && !in_array(strtolower($city), ['unknown', 'inconnu', 'n/a', 'none']));
            $isRegionKnown = ($region !== '' && !in_array(strtolower($region), ['unknown', 'inconnu', 'n/a', 'none']));

            if ($isCityKnown) {
                $identifiedCityCount += $count;
            } elseif ($isRegionKnown) {
                $regionOnlyCount += $count;
            } else {
                $countryOnlyCount += $count;
            }

            if ($countryCode !== '' || $country !== '') {
                $cont = $countryCode !== '' ? self::getContinentForCountry($countryCode) : ['code' => null, 'name' => null];
                $contName = $item['continent'] ?? ($cont['name'] ?: 'Other');
                $continents[$contName] = ($continents[$contName] ?? 0) + $count;
            }
        }

        $total = $identifiedCityCount + $regionOnlyCount + $countryOnlyCount;
        $cityPercent = $total > 0 ? round(($identifiedCityCount / $total) * 100, 1) : 0.0;
        $regionPercent = $total > 0 ? round(($regionOnlyCount / $total) * 100, 1) : 0.0;
        $countryOnlyPercent = $total > 0 ? round(($countryOnlyCount / $total) * 100, 1) : 0.0;
        $completeness = $total > 0 ? round((($identifiedCityCount + $regionOnlyCount) / $total) * 100, 1) : 0.0;

        return [
            'identified_city_count' => $identifiedCityCount,
            'city_percent'          => $cityPercent,
            'region_only_count'     => $regionOnlyCount,
            'region_percent'        => $regionPercent,
            'country_only_count'    => $countryOnlyCount,
            'country_only_percent'  => $countryOnlyPercent,
            'undetermined_count'    => $countryOnlyCount,
            'total'                 => $total,
            'completeness_percent'  => $completeness,
            'continents'            => $continents,
        ];
    }
}
