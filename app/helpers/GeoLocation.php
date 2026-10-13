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
     * In-memory L1 cache for IP resolutions within the same worker request
     * @var array
     */
    protected static $memoryCache = [];

    /**
     * In-memory L1 cache for reverse geocoding coordinate cells
     * @var array
     */
    protected static $reverseGeoCache = [];

    /**
     * Loaded administrative boundary dataset / registry
     * @var array|null
     */
    protected static $boundariesData = null;

    /**
     * Custom path override for boundaries dataset
     * @var string|null
     */
    protected static $customBoundariesPath = null;

    /**
     * Custom shared cache directory override
     * @var string|null
     */
    protected static $customCacheDir = null;

    /**
     * Custom reverse geocoder configuration override
     * @var array|null
     */
    protected static $customReverseGeocoderConfig = null;

    /**
     * Custom secondary IP provider configuration override
     * @var array|null
     */
    protected static $customSecondaryConfig = null;

    /**
     * Custom timestamp override for deterministic TTL / quota window testing
     * @var int|null
     */
    protected static $testTimestamp = null;

    /**
     * Test fault injection for quota storage testing ('ftruncate_fail', 'partial_write', 'fflush_fail')
     * @var string|null
     */
    protected static $testQuotaStorageFault = null;

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
     * Mock HTTP transport for testing real provider URL & payload parsing
     * @var callable|null
     */
    protected static $mockHttpTransport = null;

    /**
     * Cached stats table columns
     * @var array|null
     */
    protected static $statsColumnsCache = null;

    /**
     * Supported reverse geocoding providers and their requirements
     * @var array
     */
    protected static $supportedReverseProviders = [
        'opencage'     => ['name' => 'OpenCage Geocoding API', 'requires_key' => true],
        'bigdatacloud' => ['name' => 'BigDataCloud Reverse Geocoding', 'requires_key' => false],
        'nominatim'    => ['name' => 'OpenStreetMap Nominatim', 'requires_key' => false],
        'google'       => ['name' => 'Google Maps Geocoding API', 'requires_key' => true],
        'mapbox'       => ['name' => 'Mapbox Geocoding API', 'requires_key' => true],
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
        'DZ' => ['code' => 'AF', 'name' => 'Africa'],
        'TN' => ['code' => 'AF', 'name' => 'Africa'],
        'GH' => ['code' => 'AF', 'name' => 'Africa'],
        'ET' => ['code' => 'AF', 'name' => 'Africa'],
        'UG' => ['code' => 'AF', 'name' => 'Africa'],
        'RW' => ['code' => 'AF', 'name' => 'Africa'],
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
        'AT' => ['code' => 'EU', 'name' => 'Europe'],
        'IE' => ['code' => 'EU', 'name' => 'Europe'],
        'DK' => ['code' => 'EU', 'name' => 'Europe'],
        'FI' => ['code' => 'EU', 'name' => 'Europe'],
        'GR' => ['code' => 'EU', 'name' => 'Europe'],
        'CZ' => ['code' => 'EU', 'name' => 'Europe'],
        'RO' => ['code' => 'EU', 'name' => 'Europe'],
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
        'QA' => ['code' => 'AS', 'name' => 'Asia'],
        'IL' => ['code' => 'AS', 'name' => 'Asia'],
        'PK' => ['code' => 'AS', 'name' => 'Asia'],
        'BD' => ['code' => 'AS', 'name' => 'Asia'],
        'TW' => ['code' => 'AS', 'name' => 'Asia'],
        'HK' => ['code' => 'AS', 'name' => 'Asia'],
        'US' => ['code' => 'NA', 'name' => 'North America'],
        'CA' => ['code' => 'NA', 'name' => 'North America'],
        'MX' => ['code' => 'NA', 'name' => 'North America'],
        'CR' => ['code' => 'NA', 'name' => 'North America'],
        'PA' => ['code' => 'NA', 'name' => 'North America'],
        'BR' => ['code' => 'SA', 'name' => 'South America'],
        'AR' => ['code' => 'SA', 'name' => 'South America'],
        'CL' => ['code' => 'SA', 'name' => 'South America'],
        'CO' => ['code' => 'SA', 'name' => 'South America'],
        'PE' => ['code' => 'SA', 'name' => 'South America'],
        'UY' => ['code' => 'SA', 'name' => 'South America'],
        'EC' => ['code' => 'SA', 'name' => 'South America'],
        'AU' => ['code' => 'OC', 'name' => 'Oceania'],
        'NZ' => ['code' => 'OC', 'name' => 'Oceania'],
        'FJ' => ['code' => 'OC', 'name' => 'Oceania'],
    ];

    /**
     * Current unix timestamp (supports deterministic test override)
     */
    protected static function currentTime(): int {
        return self::$testTimestamp !== null ? self::$testTimestamp : time();
    }

    /**
     * Set custom timestamp for testing cache TTL expiration and rate-limit windows
     */
    public static function setTestTimestamp(?int $timestamp): void {
        self::$testTimestamp = $timestamp;
    }

    /**
     * Set custom shared cache directory
     */
    public static function setCacheDirectory(?string $dir): void {
        self::$customCacheDir = $dir;
        self::$memoryCache = [];
        self::$reverseGeoCache = [];
    }

    /**
     * Get the shared cross-process cache directory
     */
    public static function getCacheDirectory(): string {
        if (self::$customCacheDir !== null && self::$customCacheDir !== '') {
            $dir = self::$customCacheDir;
        } else {
            $base = function_exists('appConfig') ? appConfig('app.cachepath') : null;
            if (empty($base)) {
                $base = defined('STORAGE') ? STORAGE . '/cache' : sys_get_temp_dir();
            }
            $dir = rtrim($base, '/\\') . '/geolocation';
        }

        if (!file_exists($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Set custom reverse geocoder config override
     */
    public static function setReverseGeocoderConfig(?array $config): void {
        self::$customReverseGeocoderConfig = $config;
        self::$reverseGeoCache = [];
    }

    /**
     * Get effective reverse geocoder configuration
     */
    public static function getReverseGeocoderConfig(): array {
        if (self::$customReverseGeocoderConfig !== null) {
            return self::$customReverseGeocoderConfig;
        }
        $cfg = function_exists('appConfig') ? appConfig('app.reverse_geocoder') : null;
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * Set custom secondary IP provider config override
     */
    public static function setSecondaryProviderConfig(?array $config): void {
        self::$customSecondaryConfig = $config;
        self::$memoryCache = [];
    }

    /**
     * Get effective secondary IP provider configuration
     */
    public static function getSecondaryProviderConfig(): array {
        if (self::$customSecondaryConfig !== null) {
            return self::$customSecondaryConfig;
        }
        $cfg = function_exists('appConfig') ? appConfig('app.geosecondary') : null;
        return is_array($cfg) ? $cfg : [];
    }

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
        self::$memoryCache = [];
    }

    /**
     * Set a mock external reverse geocoder for testing
     */
    public static function setMockReverseGeocoder($callableOrArray): void {
        self::$mockReverseGeocoder = $callableOrArray;
        self::$reverseGeoCache = [];
    }

    /**
     * Set a mock HTTP transport callable(string $url, float $timeout, array $headers): ?string
     */
    public static function setMockHttpTransport(?callable $transport): void {
        self::$mockHttpTransport = $transport;
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
     * Track whether .env file has been loaded into environment
     * @var bool
     */
    protected static $envLoaded = false;

    /**
     * Parse and load key-value pairs from a .env file into $_ENV, $_SERVER and putenv.
     * Prevents issues on PHP-FPM where OS environment variables might not be populated or clear_env=yes is active.
     */
    public static function loadEnvFile(?string $path = null): bool {
        if ($path === null) {
            $candidates = [
                defined('ROOT') ? ROOT . '/.env' : null,
                dirname(__DIR__, 2) . '/.env',
                getcwd() . '/.env',
            ];
            foreach ($candidates as $cand) {
                if ($cand !== null && is_file($cand) && is_readable($cand)) {
                    $path = $cand;
                    break;
                }
            }
        }

        if ($path === null || !is_file($path) || !is_readable($path)) {
            self::$envLoaded = true;
            return false;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            self::$envLoaded = true;
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);

            // Strip surrounding quotes
            if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }

            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
            }
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $val;
            }
            if (getenv($key) === false) {
                @putenv("{$key}={$val}");
            }
        }

        self::$envLoaded = true;
        return true;
    }

    /**
     * Retrieve an environment variable value reliably across PHP-FPM, CLI, and web server environments.
     * Inspects $_ENV, $_SERVER, getenv(), and auto-loads .env if available.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getEnv(string $key, $default = null) {
        if (!self::$envLoaded) {
            self::loadEnvFile();
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }

        return $default;
    }

    /**
     * Clear resolution caches, shared cache files, and mocks
     */
    public static function clearCache(bool $clearSharedFiles = true): void {
        if ($clearSharedFiles) {
            self::purgeSharedCacheFiles();
        }
        self::$memoryCache = [];
        self::$reverseGeoCache = [];
        self::$mockReader = null;
        self::$mockSecondary = null;
        self::$mockReverseGeocoder = null;
        self::$mockHttpTransport = null;
        self::$customReverseGeocoderConfig = null;
        self::$customSecondaryConfig = null;
        self::$testTimestamp = null;
        self::$boundariesData = null;
        self::$customBoundariesPath = null;
        self::$statsColumnsCache = null;
        self::$envLoaded = false;
        self::$testQuotaStorageFault = null;
    }

    /**
     * Set test fault injection for quota storage testing ('ftruncate_fail', 'partial_write', 'fflush_fail')
     */
    public static function setTestQuotaStorageFault(?string $fault): void {
        self::$testQuotaStorageFault = $fault;
    }

    /**
     * Purge only L1 in-memory caches (simulating a new PHP-FPM worker process while keeping shared disk cache)
     */
    public static function resetWorkerMemory(): void {
        self::$memoryCache = [];
        self::$reverseGeoCache = [];
    }

    /**
     * Purge shared cache files and rate-limit state from disk
     */
    public static function purgeSharedCacheFiles(): void {
        $dir = self::getCacheDirectory();
        if (!is_dir($dir)) {
            return;
        }
        $files = @glob($dir . '/*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Read an item from the cross-process shared cache with strict TTL expiration enforcement
     */
    protected static function sharedCacheGet(string $namespace, string $key): ?array {
        $dir = self::getCacheDirectory();
        $file = $dir . '/' . $namespace . '_' . sha1($key) . '.json';
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return null;
        }

        $payload = null;
        if (@flock($fp, LOCK_SH)) {
            $raw = stream_get_contents($fp);
            @flock($fp, LOCK_UN);
            if (is_string($raw) && $raw !== '') {
                $payload = json_decode($raw, true);
            }
        }
        @fclose($fp);

        if (!is_array($payload) || !isset($payload['expires_at']) || !isset($payload['value']) || !is_array($payload['value'])) {
            @unlink($file);
            return null;
        }

        if (self::currentTime() > (int) $payload['expires_at']) {
            @unlink($file);
            return null;
        }

        return $payload['value'];
    }

    /**
     * Write an item to the cross-process shared cache with TTL expiration
     */
    protected static function sharedCacheSet(string $namespace, string $key, array $value, int $ttl): void {
        if ($ttl <= 0) {
            return;
        }

        $dir = self::getCacheDirectory();
        if (!is_dir($dir)) {
            return;
        }

        $file = $dir . '/' . $namespace . '_' . sha1($key) . '.json';
        $now = self::currentTime();
        $data = json_encode([
            'created_at' => $now,
            'expires_at' => $now + $ttl,
            'value'      => $value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($data === false) {
            return;
        }

        $fp = @fopen($file, 'cb');
        if (!$fp) {
            return;
        }

        if (@flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $data);
            fflush($fp);
            @flock($fp, LOCK_UN);
        }
        @fclose($fp);
    }

    /**
     * Update or read shared cross-process rate-limit & quota state atomically using flock(LOCK_EX).
     * Returns null if file is inaccessible or locking fails, ensuring fail-closed protection
     * against uncounted or unconstrained external API calls.
     */
    protected static function mutateSharedQuotaState(callable $mutator): ?array {
        $defaultState = [
            'requests_total'       => 0,
            'cache_hits'           => 0,
            'quota_exceeded_count' => 0,
            'errors_count'         => 0,
            'minute_window'        => 0,
            'minute_count'         => 0,
            'day_window'           => '',
            'day_count'            => 0,
            'last_request_time'    => 0,
        ];

        $dir = self::getCacheDirectory();
        $file = $dir . '/ratelimit_state.json';
        $fp = @fopen($file, 'c+b');
        if (!$fp) {
            // Fail-closed: prevent uncounted external calls when state file is inaccessible
            return null;
        }

        $state = null;
        if (@flock($fp, LOCK_EX)) {
            rewind($fp);
            $raw = stream_get_contents($fp);
            $currentState = $defaultState;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    // Corrupted state file: MUST NOT silently reset counters to defaultState!
                    // Fail-closed to prevent uncounted API calls
                    @flock($fp, LOCK_UN);
                    @fclose($fp);
                    return null;
                }
                $currentState = array_merge($defaultState, $decoded);
            }

            $state = $mutator($currentState);
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                return null;
            }

            if (self::$testQuotaStorageFault === 'ftruncate_fail' || @ftruncate($fp, 0) === false) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                return null;
            }
            @rewind($fp);

            $bytesWritten = @fwrite($fp, $encoded);
            if (self::$testQuotaStorageFault === 'partial_write') {
                $bytesWritten = strlen($encoded) - 10;
            }
            if ($bytesWritten === false || $bytesWritten !== strlen($encoded)) {
                // Incomplete or failed write! Fail-closed!
                @flock($fp, LOCK_UN);
                @fclose($fp);
                return null;
            }

            if (self::$testQuotaStorageFault === 'fflush_fail' || @fflush($fp) === false) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                return null;
            }

            @flock($fp, LOCK_UN);
        }
        @fclose($fp);

        // If flock or writing failed, $state remains null (fail-closed)
        return $state;
    }

    /**
     * Get reverse geocoder usage and cost/quota telemetry across PHP processes
     */
    public static function getReverseGeocoderStats(): array {
        $defaultState = [
            'requests_total'       => 0,
            'cache_hits'           => 0,
            'quota_exceeded_count' => 0,
            'errors_count'         => 0,
            'minute_window'        => 0,
            'minute_count'         => 0,
            'day_window'           => '',
            'day_count'            => 0,
            'last_request_time'    => 0,
        ];
        $res = self::mutateSharedQuotaState(function (array $state) {
            return $state;
        });
        return is_array($res) ? $res : $defaultState;
    }

    /**
     * Get list of supported reverse geocoding providers
     */
    public static function getSupportedReverseGeocoders(): array {
        return self::$supportedReverseProviders;
    }

    /**
     * Get active reverse geocoder status and privacy disclosure metadata
     */
    public static function getReverseGeocoderProviderInfo(): array {
        $config = self::getReverseGeocoderConfig();
        $provider = strtolower(trim((string) ($config['provider'] ?? 'opencage')));
        $enabled = self::isReverseGeocoderEnabled();
        $providerMeta = self::$supportedReverseProviders[$provider] ?? null;

        return [
            'enabled'        => $enabled,
            'provider'       => $provider,
            'provider_name'  => $providerMeta['name'] ?? ($enabled && self::$mockReverseGeocoder !== null ? 'Configured Reverse Geocoder' : null),
            'supported'      => $providerMeta !== null,
            'requires_key'   => $providerMeta['requires_key'] ?? true,
            'has_api_key'    => !empty($config['api_key']),
            'tos_accepted'   => !isset($config['tos_accepted']) || !empty($config['tos_accepted']),
            'sends_external' => $enabled,
        ];
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
            'continent'                => null,
            'continent_code'           => null,
            'country'                  => null,
            'country_code'             => null,
            'region'                   => null,
            'region_code'              => null,
            'city'                     => null,
            'postal_code'              => null,
            'latitude'                 => null,
            'longitude'                => null,
            'declared_accuracy_meters' => null,
            'accuracy_radius'          => null,
            'source'                   => 'unknown',
            'provider'                 => 'none',
            'verified'                 => false,
            'location_level'           => 'unknown',
            'precision'                => 'unknown',
            'missing_reason'           => null,
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

        // 4. Normalize Madagascar region codes if applicable
        if ($result['country_code'] === 'MG' && (!empty($result['region']) || !empty($result['city']))) {
            $mgAdmin = self::normalizeMadagascarAdmin($result['region'], $result['region_code'], $result['city']);
            $result['region'] = $mgAdmin['region'];
            $result['region_code'] = $mgAdmin['region_code'];
        }

        // 5. If city is undetermined and a secondary provider is configured & enabled
        if (empty($result['city']) && self::isSecondaryProviderEnabled()) {
            $secondaryResult = self::resolveSecondary($ip, $bypassCache);
            if ($secondaryResult !== null) {
                $result['country'] = $secondaryResult['country'] ?: $result['country'];
                $result['country_code'] = $secondaryResult['country_code'] ?: $result['country_code'];
                $result['region'] = $secondaryResult['region'] ?: $result['region'];
                $result['region_code'] = $secondaryResult['region_code'] ?: $result['region_code'];
                $result['city'] = $secondaryResult['city'] ?: $result['city'];
                $result['accuracy_radius'] = $secondaryResult['accuracy_radius'] ?? $result['accuracy_radius'];
                $result['source'] = 'secondary_provider';
                $result['provider'] = $secondaryResult['provider'] ?? 'secondary';
                $result['verified'] = true;

                if ($result['country_code'] === 'MG') {
                    $mgAdmin = self::normalizeMadagascarAdmin($result['region'], $result['region_code'], $result['city']);
                    $result['region'] = $mgAdmin['region'];
                    $result['region_code'] = $mgAdmin['region_code'];
                }

                if (empty($result['continent_code']) && !empty($result['country_code'])) {
                    $cont = self::getContinentForCountry($result['country_code']);
                    $result['continent'] = $cont['name'];
                    $result['continent_code'] = $cont['code'];
                }

                if (!empty($result['city'])) {
                    $result['location_level'] = 'city';
                    $result['precision'] = 'city';
                    $result['missing_reason'] = null;
                } elseif (!empty($result['region'])) {
                    $result['location_level'] = 'region';
                    $result['precision'] = 'region';
                    $result['missing_reason'] = 'region_level_only';
                } elseif (!empty($result['country'])) {
                    $result['location_level'] = 'country';
                    $result['precision'] = 'country';
                    $result['missing_reason'] = 'country_level_only';
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
            'continent'                => null,
            'continent_code'           => null,
            'country'                  => null,
            'country_code'             => null,
            'region'                   => null,
            'region_code'              => null,
            'city'                     => null,
            'postal_code'              => null,
            'latitude'                 => null,
            'longitude'                => null,
            'declared_accuracy_meters' => null,
            'accuracy_radius'          => null,
            'source'                   => 'maxmind',
            'provider'                 => 'maxmind_geolite2',
            'verified'                 => false,
            'location_level'           => 'unknown',
            'precision'                => 'unknown',
            'missing_reason'           => null,
        ];

        $geopath = appConfig('app.geopath') ?: (defined('STORAGE') ? STORAGE . '/app/GeoLite2-City.mmdb' : null);

        if (self::$mockReader !== null) {
            $reader = self::$mockReader;
        } else {
            if (empty($geopath) || !file_exists($geopath) || !is_readable($geopath)) {
                $data['source'] = 'unknown';
                $data['provider'] = 'none';
                $data['missing_reason'] = 'database_missing';
                return $data;
            }
            try {
                $reader = new \MaxMind\Db\Reader($geopath);
            } catch (\Exception $e) {
                $data['source'] = 'unknown';
                $data['provider'] = 'none';
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
                $data['verified'] = true;
                $data['location_level'] = 'city';
                $data['precision'] = 'city';
                $data['missing_reason'] = null;
            } elseif (!empty($data['region'])) {
                $data['verified'] = true;
                $data['location_level'] = 'region';
                $data['precision'] = 'region';
                $data['missing_reason'] = 'region_level_only';
            } elseif (!empty($data['country'])) {
                $data['verified'] = true;
                $data['location_level'] = 'country';
                $data['precision'] = 'country';
                $data['missing_reason'] = 'country_level_only';
            } else {
                $data['missing_reason'] = 'insufficient_data';
            }

            return $data;
        } catch (\Exception $e) {
            $data['source'] = 'unknown';
            $data['provider'] = 'none';
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

        $config = self::getSecondaryProviderConfig();
        return !empty($config['enabled']) && (!empty($config['api_key']) || !empty($config['url']));
    }

    /**
     * Resolve via secondary IP provider (with cross-process cache, strict timeouts & failure isolation)
     */
    protected static function resolveSecondary(string $ip, bool $bypassCache = false): ?array {
        $config = self::getSecondaryProviderConfig();
        $ttl = isset($config['cache_ttl']) ? (int) $config['cache_ttl'] : 86400;

        if (!$bypassCache && $ttl > 0) {
            $cached = self::sharedCacheGet('ip_sec', $ip);
            if ($cached !== null) {
                return $cached;
            }
        }

        if (self::$mockSecondary !== null) {
            $res = is_callable(self::$mockSecondary)
                ? call_user_func(self::$mockSecondary, $ip)
                : (is_array(self::$mockSecondary) ? self::$mockSecondary : null);
            if (is_array($res)) {
                $res['provider'] = $res['provider'] ?? 'mock_secondary';
                if ($ttl > 0) {
                    self::sharedCacheSet('ip_sec', $ip, $res, $ttl);
                }
                return $res;
            }
            return null;
        }

        if (empty($config['enabled'])) {
            return null;
        }

        $provider = strtolower((string) ($config['provider'] ?? 'ipinfo'));
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 1.5;
        $urlTemplate = !empty($config['url']) ? trim((string) $config['url']) : '';
        $apiKey = !empty($config['api_key']) ? trim((string) $config['api_key']) : '';

        try {
            if ($urlTemplate !== '') {
                $url = str_replace(['{ip}', '{key}'], [urlencode($ip), urlencode($apiKey)], $urlTemplate);
            } elseif ($provider === 'ipinfo') {
                if (empty($apiKey)) return null;
                $url = "https://ipinfo.io/{$ip}/json?token=" . urlencode($apiKey);
            } elseif ($provider === 'ipapi') {
                if (empty($apiKey)) return null;
                $url = "https://ipapi.co/{$ip}/json/?key=" . urlencode($apiKey);
            } else {
                return null;
            }

            $raw = self::executeHttpGet($url, $timeout, ["User-Agent: Codex-GeoClient/1.0"]);
            if ($raw === null) {
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

            $resolved = [
                'country'         => $country,
                'country_code'    => $countryCode,
                'region'          => $region,
                'region_code'     => !empty($json['region_code']) && $countryCode ? "{$countryCode}-" . strtoupper($json['region_code']) : null,
                'city'            => $city,
                'accuracy_radius' => null,
                'provider'        => $provider,
            ];

            if ($ttl > 0) {
                self::sharedCacheSet('ip_sec', $ip, $resolved, $ttl);
            }

            return $resolved;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Format a display name according to the 3-level geographic hierarchy:
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
     * Load the administrative boundary registry / dataset from storage/app/geo_boundaries.json
     */
    protected static function getBoundariesData(): array {
        if (self::$boundariesData !== null) {
            return self::$boundariesData;
        }

        $path = self::$customBoundariesPath;
        if (!$path) {
            $cfg = self::getReverseGeocoderConfig();
            $path = !empty($cfg['boundaries_path'])
                ? $cfg['boundaries_path']
                : (defined('STORAGE') ? STORAGE . '/app/geo_boundaries.json' : dirname(__DIR__, 2) . '/storage/app/geo_boundaries.json');
        }

        if (!$path || !file_exists($path) || !is_readable($path)) {
            return self::$boundariesData = ['authoritative_polygons' => false, 'madagascar_regions' => [], 'countries' => []];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::$boundariesData = ['authoritative_polygons' => false, 'madagascar_regions' => [], 'countries' => []];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::$boundariesData = ['authoritative_polygons' => false, 'madagascar_regions' => [], 'countries' => []];
        }

        return self::$boundariesData = $decoded;
    }

    /**
     * Get the registry of Madagascar's 23 administrative regions with unique codes
     */
    public static function getMadagascarRegionsRegistry(): array {
        $data = self::getBoundariesData();
        if (!empty($data['madagascar_regions']) && is_array($data['madagascar_regions'])) {
            return $data['madagascar_regions'];
        }

        $defaultPath = defined('STORAGE') ? STORAGE . '/app/geo_boundaries.json' : dirname(__DIR__, 2) . '/storage/app/geo_boundaries.json';
        if (file_exists($defaultPath) && is_readable($defaultPath)) {
            $defaultData = json_decode(@file_get_contents($defaultPath), true);
            if (!empty($defaultData['madagascar_regions']) && is_array($defaultData['madagascar_regions'])) {
                return $defaultData['madagascar_regions'];
            }
        }

        return [];
    }

    /**
     * Normalize Madagascar administrative region and code to ensure unique identifiers
     * across all 23 regions (avoiding duplicate province codes MG-T, MG-F, etc.)
     */
    public static function normalizeMadagascarAdmin(?string $region, ?string $regionCode, ?string $city): array {
        $region = $region !== null ? trim($region) : null;
        $regionCode = $regionCode !== null ? strtoupper(trim($regionCode)) : null;
        $city = $city !== null ? trim($city) : null;

        $registry = self::getMadagascarRegionsRegistry();
        if (empty($registry)) {
            return ['region' => $region, 'region_code' => $regionCode];
        }

        $normRegion = $region !== null ? mb_strtolower( str_replace(['region', 'région', 'province de ', 'province d\'', 'faritanin\'i '], '', $region), 'UTF-8') : '';
        $normRegion = trim($normRegion);
        $normCity = $city !== null ? mb_strtolower($city, 'UTF-8') : '';

        // 1. Direct match by unique region code or region name
        foreach ($registry as $entry) {
            $entryNameLower = mb_strtolower($entry['name'], 'UTF-8');
            $entryCodeUpper = strtoupper($entry['code']);

            if ($regionCode === $entryCodeUpper || ($normRegion !== '' && $normRegion === $entryNameLower)) {
                return [
                    'region'      => $entry['name'],
                    'region_code' => $entryCodeUpper,
                ];
            }
        }

        // 2. Legacy Vatovavy-Fitovinany split (2021) or province-level response disambiguated by known commune
        if ($normCity !== '') {
            foreach ($registry as $entry) {
                $communes = array_map(function ($c) {
                    return mb_strtolower($c, 'UTF-8');
                }, $entry['communes'] ?? []);

                if (in_array($normCity, $communes, true)) {
                    // Only map from commune if region was missing, was a legacy combined region, or was a province name
                    $isProvinceOrLegacy = ($normRegion === '' ||
                        strpos($normRegion, 'vatovavy') !== false ||
                        strpos($normRegion, 'fitovinany') !== false ||
                        in_array($regionCode, ['MG-T', 'MG-F', 'MG-A', 'MG-M', 'MG-D', 'MG-U'], true) ||
                        mb_strtolower($entry['province_name'] ?? '', 'UTF-8') === $normRegion);

                    if ($isProvinceOrLegacy) {
                        return [
                            'region'      => $entry['name'],
                            'region_code' => strtoupper($entry['code']),
                        ];
                    }
                }
            }
        }

        return ['region' => $region, 'region_code' => $regionCode];
    }

    /**
     * Verify whether a polygon represents a true multi-vertex administrative boundary
     * rather than a synthetic 4-corner bounding box rectangle.
     *
     * @param array $polygon Array of [lon, lat] vertices
     * @return bool
     */
    public static function isAuthoritativePolygon(array $polygon): bool {
        $n = count($polygon);
        if ($n < 6) {
            return false;
        }

        // Collect unique vertices (excluding closing vertex if identical to first)
        $uniqueVertices = [];
        $uniqueLons = [];
        $uniqueLats = [];

        foreach ($polygon as $pt) {
            if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0]) || !is_numeric($pt[1])) {
                return false;
            }
            $lonKey = number_format((float) $pt[0], 5, '.', '');
            $latKey = number_format((float) $pt[1], 5, '.', '');
            $uniqueVertices["{$lonKey},{$latKey}"] = true;
            $uniqueLons[$lonKey] = true;
            $uniqueLats[$latKey] = true;
        }

        // A true administrative boundary polygon must have at least 6 unique vertices
        // and cannot be an axis-aligned rectangle (which only has 2 distinct longitudes and 2 distinct latitudes)
        if (count($uniqueVertices) < 6 || (count($uniqueLons) <= 2 && count($uniqueLats) <= 2)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether the loaded boundary dataset contains verified authoritative polygons
     */
    public static function hasAuthoritativeLocalBoundaries(): bool {
        $data = self::getBoundariesData();
        if (empty($data['authoritative_polygons']) || $data['authoritative_polygons'] !== true) {
            return false;
        }
        if (empty($data['countries']) || !is_array($data['countries'])) {
            return false;
        }
        return true;
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
     * 1. Never uses approximate or rectangular polygons to assign a city or region.
     * 2. Queries the configured worldwide reverse geocoding service as primary source,
     *    only using local boundaries when they come from a verified authoritative multi-vertex GeoJSON dataset.
     * 3. Never defaults a missing GPS accuracy (`null`) to 50m; missing accuracy caps resolution at region/country.
     * 4. Distinguishes `declared_accuracy_meters`, `accuracy_radius` (km), `source`, `provider`, and `location_level`.
     * 5. Never claims browser coordinates are server-verified (`verified => false`, `source => 'browser_consent'`).
     *
     * @param float $lat
     * @param float $lon
     * @param float|null $accuracyMeters
     * @return array
     */
    public static function resolveCoordinates(float $lat, float $lon, ?float $accuracyMeters = null): array {
        $baseResult = [
            'continent'                => null,
            'continent_code'           => null,
            'country'                  => null,
            'country_code'             => null,
            'region'                   => null,
            'region_code'              => null,
            'admin_type'               => null,
            'city'                     => null,
            'declared_accuracy_meters' => null,
            'accuracy_radius'          => null,
            'source'                   => 'browser_consent',
            'provider'                 => 'none',
            'verified'                 => false,
            'location_level'           => 'unknown',
            'precision'                => 'unknown',
            'missing_reason'           => null,
        ];

        // 1. Validate coordinate bounds
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            $baseResult['missing_reason'] = 'invalid_coordinates';
            return $baseResult;
        }

        // 2. Validate GPS accuracy (NEVER assume 50m when accuracyMeters is null!)
        $hasDeclaredAccuracy = ($accuracyMeters !== null);
        $declaredAccuracyMeters = null;
        $accuracyKm = null;

        if ($hasDeclaredAccuracy) {
            if (!is_finite($accuracyMeters) || $accuracyMeters <= 0.0 || $accuracyMeters > 1000000.0) {
                $baseResult['missing_reason'] = 'invalid_gps_accuracy';
                return $baseResult;
            }
            $declaredAccuracyMeters = round((float) $accuracyMeters, 2);
            $accuracyKm = round($declaredAccuracyMeters / 1000.0, 3);
            $baseResult['declared_accuracy_meters'] = $declaredAccuracyMeters;
            $baseResult['accuracy_radius'] = $accuracyKm;
        }

        // 3. Provider resolution priority:
        //    - External worldwide reverse geocoder is queried first whenever enabled.
        //    - Local polygons are ONLY consulted if they are verified authoritative multi-vertex boundaries.
        $externalEnabled = self::isReverseGeocoderEnabled();
        $externalMatch = null;
        $externalErrorReason = null;

        if ($externalEnabled) {
            $externalMatch = self::resolveExternalReverseGeocoder($lat, $lon, $externalErrorReason);
        }

        $localMatch = null;
        if (self::hasAuthoritativeLocalBoundaries()) {
            $localMatch = self::resolveViaPolygonBoundaries($lat, $lon);
        }

        // Merge with priority to external reverse geocoder for city/region/country,
        // falling back to verified authoritative local polygons if external is disabled or incomplete
        $continent = $externalMatch['continent'] ?? ($localMatch['continent'] ?? null);
        $continentCode = $externalMatch['continent_code'] ?? ($localMatch['continent_code'] ?? null);
        $country = $externalMatch['country'] ?? ($localMatch['country'] ?? null);
        $countryCode = $externalMatch['country_code'] ?? ($localMatch['country_code'] ?? null);
        $region = $externalMatch['region'] ?? ($localMatch['region'] ?? null);
        $regionCode = $externalMatch['region_code'] ?? ($localMatch['region_code'] ?? null);
        $adminType = $externalMatch['admin_type'] ?? ($localMatch['admin_type'] ?? null);
        $city = $externalMatch['city'] ?? ($localMatch['city'] ?? null);

        $regionBorderDistKm = $externalMatch['region_border_dist_km'] ?? ($localMatch['region_border_dist_km'] ?? INF);
        $cityBorderDistKm = $externalMatch['city_border_dist_km'] ?? ($localMatch['city_border_dist_km'] ?? INF);

        if ($externalMatch !== null && !empty($externalMatch['country'])) {
            $baseResult['provider'] = $externalMatch['provider'] ?? 'external_reverse_geocoder';
        } elseif ($localMatch !== null && !empty($localMatch['country'])) {
            $baseResult['provider'] = 'local_authoritative';
        }

        if (empty($continentCode) && !empty($countryCode)) {
            $cont = self::getContinentForCountry($countryCode);
            $continent = $cont['name'];
            $continentCode = $cont['code'];
        }

        // Normalize Madagascar administrative identifiers to eliminate duplicate province codes (MG-T, MG-F, etc.)
        if ($countryCode === 'MG') {
            $mgAdmin = self::normalizeMadagascarAdmin($region, $regionCode, $city);
            $region = $mgAdmin['region'];
            $regionCode = $mgAdmin['region_code'];
        }

        // If no country could be identified
        if (empty($country)) {
            if ($externalErrorReason !== null) {
                $baseResult['missing_reason'] = $externalErrorReason;
            } elseif (!$externalEnabled && !self::hasAuthoritativeLocalBoundaries()) {
                $baseResult['missing_reason'] = 'reverse_geocoder_disabled';
            } else {
                $baseResult['missing_reason'] = 'out_of_coverage';
            }
            return $baseResult;
        }

        $baseResult['continent'] = $continent;
        $baseResult['continent_code'] = $continentCode;
        $baseResult['country'] = $country;
        $baseResult['country_code'] = $countryCode;
        $baseResult['admin_type'] = $adminType;

        // 4. Enforce GPS accuracy & administrative border proximity rules:

        // Rule A: Missing GPS accuracy (`$declaredAccuracyMeters === null`)
        // Never assume 50m! Without a declared accuracy radius, we cannot confirm urban commune precision.
        if (!$hasDeclaredAccuracy) {
            $baseResult['city'] = null;
            if (!empty($region)) {
                $baseResult['region'] = $region;
                $baseResult['region_code'] = $regionCode;
                $baseResult['location_level'] = 'region';
                $baseResult['precision'] = 'region';
            } else {
                $baseResult['region'] = null;
                $baseResult['region_code'] = null;
                $baseResult['location_level'] = 'country';
                $baseResult['precision'] = 'country';
            }
            $baseResult['missing_reason'] = 'missing_gps_accuracy';
            return $baseResult;
        }

        // Rule B: Accuracy worse than 50 km -> cannot reliably attribute region or city
        if ($declaredAccuracyMeters > 50000.0) {
            $baseResult['region'] = null;
            $baseResult['region_code'] = null;
            $baseResult['city'] = null;
            $baseResult['location_level'] = 'country';
            $baseResult['precision'] = 'country';
            $baseResult['missing_reason'] = 'low_gps_accuracy';
            return $baseResult;
        }

        // Rule C: Region border uncertainty check
        // If the GPS uncertainty circle crosses the regional border, do not attribute a potentially wrong region
        if (!empty($region) && $regionBorderDistKm < INF) {
            if ($declaredAccuracyMeters >= 500.0 && $accuracyKm > $regionBorderDistKm) {
                $baseResult['region'] = null;
                $baseResult['region_code'] = null;
                $baseResult['city'] = null;
                $baseResult['location_level'] = 'country';
                $baseResult['precision'] = 'country';
                $baseResult['missing_reason'] = 'region_border_uncertainty';
                return $baseResult;
            }
        }

        $baseResult['region'] = $region;
        $baseResult['region_code'] = $regionCode;

        // Rule D: Accuracy worse than 5 km -> too coarse to confirm a specific urban commune/city
        if ($declaredAccuracyMeters > 5000.0) {
            $baseResult['city'] = null;
            if (!empty($region)) {
                $baseResult['location_level'] = 'region';
                $baseResult['precision'] = 'region';
                $baseResult['missing_reason'] = 'low_gps_accuracy_for_city';
            } else {
                $baseResult['location_level'] = 'country';
                $baseResult['precision'] = 'country';
                $baseResult['missing_reason'] = 'low_gps_accuracy';
            }
            return $baseResult;
        }

        // Rule E: City border uncertainty check
        if (!empty($city) && $cityBorderDistKm < INF) {
            if ($declaredAccuracyMeters >= 250.0 && $accuracyKm > $cityBorderDistKm) {
                $baseResult['city'] = null;
                if (!empty($region)) {
                    $baseResult['location_level'] = 'region';
                    $baseResult['precision'] = 'region';
                    $baseResult['missing_reason'] = 'city_border_uncertainty';
                } else {
                    $baseResult['location_level'] = 'country';
                    $baseResult['precision'] = 'country';
                    $baseResult['missing_reason'] = 'city_border_uncertainty';
                }
                return $baseResult;
            }
        }

        $baseResult['city'] = $city;

        if (!empty($city)) {
            $baseResult['location_level'] = 'city';
            $baseResult['precision'] = 'city';
            $baseResult['missing_reason'] = null;
        } elseif (!empty($region)) {
            $baseResult['location_level'] = 'region';
            $baseResult['precision'] = 'region';
            $baseResult['missing_reason'] = 'outside_urban_commune';
        } else {
            $baseResult['location_level'] = 'country';
            $baseResult['precision'] = 'country';
            $baseResult['missing_reason'] = 'country_level_only';
        }

        return $baseResult;
    }

    /**
     * Match (lat, lon) against verified authoritative multi-vertex polygons ONLY.
     * Any dataset with authoritative_polygons !== true or any rectangular polygon is ignored.
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

        if (!self::hasAuthoritativeLocalBoundaries()) {
            return $result;
        }

        $dataset = self::getBoundariesData();
        $fallbackCountryMatch = null;

        foreach ($dataset['countries'] as $countryEntry) {
            if (!empty($countryEntry['bbox'])) {
                [$minLat, $minLon, $maxLat, $maxLon] = $countryEntry['bbox'];
                if ($lat < $minLat || $lat > $maxLat || $lon < $minLon || $lon > $maxLon) {
                    continue;
                }
            }

            $matchedRegion = null;
            $matchedRegionDist = INF;

            if (!empty($countryEntry['regions']) && is_array($countryEntry['regions'])) {
                foreach ($countryEntry['regions'] as $regionEntry) {
                    if (empty($regionEntry['polygon']) || !self::isAuthoritativePolygon($regionEntry['polygon'])) {
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
                        if (!empty($cityEntry['polygon']) && self::isAuthoritativePolygon($cityEntry['polygon']) && self::isPointInPolygon($lat, $lon, $cityEntry['polygon'])) {
                            $result['city'] = $cityEntry['name'] ?? null;
                            $result['city_border_dist_km'] = self::distanceToPolygonBorderKm($lat, $lon, $cityEntry['polygon']);
                            break;
                        }
                    }
                }

                return $result;
            }

            if ($fallbackCountryMatch === null) {
                $countryPoly = $countryEntry['polygon'] ?? ($countryEntry['country_polygon'] ?? null);
                if (!empty($countryPoly) && self::isAuthoritativePolygon($countryPoly) && self::isPointInPolygon($lat, $lon, $countryPoly)) {
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
     * Check if external reverse geocoding is enabled and properly configured
     */
    public static function isReverseGeocoderEnabled(): bool {
        if (self::$mockReverseGeocoder !== null) {
            return true;
        }

        $config = self::getReverseGeocoderConfig();
        if (empty($config['enabled'])) {
            return false;
        }

        // Verify Terms of Service acceptance if specified
        if (isset($config['tos_accepted']) && !$config['tos_accepted']) {
            return false;
        }

        $provider = strtolower(trim((string) ($config['provider'] ?? 'opencage')));
        if (!isset(self::$supportedReverseProviders[$provider])) {
            return false;
        }

        if (!empty(self::$supportedReverseProviders[$provider]['requires_key']) && empty($config['api_key'])) {
            return false;
        }

        return true;
    }

    /**
     * Execute an HTTP GET request (supports mock transport for testing all provider adapters)
     */
    protected static function executeHttpGet(string $url, float $timeout, array $headers = []): ?string {
        if (self::$mockHttpTransport !== null) {
            $res = call_user_func(self::$mockHttpTransport, $url, $timeout, $headers);
            return is_string($res) ? $res : null;
        }

        $headerStr = implode("\r\n", $headers) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'timeout'       => $timeout,
                'ignore_errors' => false,
                'header'        => $headerStr,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        return $raw;
    }

    /**
     * Resolve coordinates via configurable external reverse geocoder with shared cross-process caching,
     * rate limiting, daily quota enforcement, timeout, and international normalization.
     *
     * Supported providers: 'opencage', 'bigdatacloud', 'nominatim', 'google', 'mapbox'
     */
    protected static function resolveExternalReverseGeocoder(float $lat, float $lon, ?string &$errorReason = null): ?array {
        $config = self::getReverseGeocoderConfig();
        $provider = strtolower(trim((string) ($config['provider'] ?? 'opencage')));
        $ttl = isset($config['cache_ttl']) ? (int) $config['cache_ttl'] : 86400;

        // Round to ~110m grid cell for privacy and high cache hit ratio
        $cacheKey = $provider . ':' . number_format($lat, 3, '.', '') . ',' . number_format($lon, 3, '.', '');

        // 1. Check L1 in-memory cache (if TTL > 0)
        if ($ttl > 0 && isset(self::$reverseGeoCache[$cacheKey])) {
            $entry = self::$reverseGeoCache[$cacheKey];
            if (self::currentTime() <= $entry['expires_at']) {
                self::mutateSharedQuotaState(function (array $state) {
                    $state['cache_hits']++;
                    return $state;
                });
                return $entry['value'];
            }
            unset(self::$reverseGeoCache[$cacheKey]);
        }

        // 2. Check L2 cross-process shared disk cache (with effective TTL expiration)
        if ($ttl > 0) {
            $sharedHit = self::sharedCacheGet('revgeo', $cacheKey);
            if ($sharedHit !== null) {
                self::$reverseGeoCache[$cacheKey] = [
                    'expires_at' => self::currentTime() + $ttl,
                    'value'      => $sharedHit,
                ];
                self::mutateSharedQuotaState(function (array $state) {
                    $state['cache_hits']++;
                    return $state;
                });
                return $sharedHit;
            }
        }

        // 3. Enforce cross-process per-minute rate limit and daily quota atomically
        $maxPerMinute = isset($config['max_requests_per_minute']) ? (int) $config['max_requests_per_minute'] : 60;
        $dailyQuota = isset($config['daily_quota']) ? (int) $config['daily_quota'] : 2500;
        $now = self::currentTime();

        $allowed = false;
        $stateResult = self::mutateSharedQuotaState(function (array $state) use ($maxPerMinute, $dailyQuota, $now, &$allowed) {
            $currentMinute = (int) floor($now / 60);
            if ($state['minute_window'] !== $currentMinute) {
                $state['minute_window'] = $currentMinute;
                $state['minute_count'] = 0;
            }

            $currentDay = gmdate('Y-m-d', $now);
            if ($state['day_window'] !== $currentDay) {
                $state['day_window'] = $currentDay;
                $state['day_count'] = 0;
            }

            if ($state['minute_count'] >= $maxPerMinute || $state['day_count'] >= $dailyQuota) {
                $state['quota_exceeded_count']++;
                $allowed = false;
                return $state;
            }

            $state['minute_count']++;
            $state['day_count']++;
            $state['requests_total']++;
            $state['last_request_time'] = $now;
            $allowed = true;
            return $state;
        });

        if ($stateResult === null) {
            $errorReason = 'quota_storage_inaccessible';
            return null;
        }

        if (!$allowed) {
            $errorReason = 'quota_exceeded';
            return null;
        }

        // 4. Handle mock reverse geocoder if set
        if (self::$mockReverseGeocoder !== null) {
            try {
                $res = is_callable(self::$mockReverseGeocoder)
                    ? call_user_func(self::$mockReverseGeocoder, $lat, $lon)
                    : (is_array(self::$mockReverseGeocoder) ? self::$mockReverseGeocoder : null);
            } catch (\Throwable $e) {
                $res = null;
            }

            if (is_array($res)) {
                $normalized = self::normalizeReverseGeocoderResponse($res, $res['_provider'] ?? $provider);
                if ($normalized !== null && !empty($normalized['country'])) {
                    $normalized['provider'] = $normalized['provider'] ?? ($res['_provider'] ?? $provider);
                    if ($ttl > 0) {
                        self::$reverseGeoCache[$cacheKey] = ['expires_at' => $now + $ttl, 'value' => $normalized];
                        self::sharedCacheSet('revgeo', $cacheKey, $normalized, $ttl);
                    }
                    return $normalized;
                }
            }

            self::mutateSharedQuotaState(function (array $state) {
                $state['errors_count']++;
                return $state;
            });
            $errorReason = 'reverse_geocoder_unavailable';
            return null;
        }

        if (empty($config['enabled'])) {
            $errorReason = 'reverse_geocoder_disabled';
            return null;
        }

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 1.5;
        $userAgent = !empty($config['user_agent']) ? $config['user_agent'] : 'QRCodePlatform-GeoResolver/1.0';

        try {
            $url = self::buildReverseGeocoderUrl($provider, $lat, $lon, $apiKey, $endpoint);
            if ($url === null) {
                $errorReason = 'reverse_geocoder_misconfigured';
                return null;
            }

            $raw = self::executeHttpGet($url, $timeout, [
                "User-Agent: {$userAgent}",
                "Accept: application/json",
            ]);

            if ($raw === null) {
                self::mutateSharedQuotaState(function (array $state) {
                    $state['errors_count']++;
                    return $state;
                });
                $errorReason = 'reverse_geocoder_unavailable';
                return null;
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                self::mutateSharedQuotaState(function (array $state) {
                    $state['errors_count']++;
                    return $state;
                });
                $errorReason = 'reverse_geocoder_unavailable';
                return null;
            }

            $normalized = self::normalizeReverseGeocoderResponse($json, $provider);
            if ($normalized !== null && !empty($normalized['country'])) {
                $normalized['provider'] = $provider;
                if ($ttl > 0) {
                    self::$reverseGeoCache[$cacheKey] = ['expires_at' => $now + $ttl, 'value' => $normalized];
                    self::sharedCacheSet('revgeo', $cacheKey, $normalized, $ttl);
                }
                return $normalized;
            }

            $errorReason = 'out_of_coverage';
            return null;
        } catch (\Throwable $e) {
            self::mutateSharedQuotaState(function (array $state) {
                $state['errors_count']++;
                return $state;
            });
            $errorReason = 'reverse_geocoder_unavailable';
            return null;
        }
    }

    /**
     * Build the request URL for each supported worldwide reverse geocoding provider
     */
    public static function buildReverseGeocoderUrl(string $provider, float $lat, float $lon, string $apiKey = '', string $customEndpoint = ''): ?string {
        $provider = strtolower(trim($provider));

        switch ($provider) {
            case 'opencage':
                if ($apiKey === '') {
                    return null;
                }
                $base = $customEndpoint !== '' ? rtrim($customEndpoint, '?') : 'https://api.opencagedata.com/geocode/v1/json';
                return $base . '?q=' . urlencode("{$lat}+{$lon}") . '&key=' . urlencode($apiKey) . '&no_annotations=1&limit=1';

            case 'bigdatacloud':
                $base = $customEndpoint !== ''
                    ? rtrim($customEndpoint, '?')
                    : ($apiKey !== '' ? 'https://api.bigdatacloud.net/data/reverse-geocode' : 'https://api.bigdatacloud.net/data/reverse-geocode-client');
                $url = $base . '?latitude=' . urlencode((string) $lat) . '&longitude=' . urlencode((string) $lon) . '&localityLanguage=en';
                if ($apiKey !== '') {
                    $url .= '&key=' . urlencode($apiKey);
                }
                return $url;

            case 'nominatim':
                $base = $customEndpoint !== '' ? rtrim($customEndpoint, '?') : 'https://nominatim.openstreetmap.org/reverse';
                return $base . '?format=jsonv2&lat=' . urlencode((string) $lat) . '&lon=' . urlencode((string) $lon) . '&zoom=14&addressdetails=1';

            case 'google':
                if ($apiKey === '') {
                    return null;
                }
                $base = $customEndpoint !== '' ? rtrim($customEndpoint, '?') : 'https://maps.googleapis.com/maps/api/geocode/json';
                return $base . '?latlng=' . urlencode("{$lat},{$lon}") . '&key=' . urlencode($apiKey);

            case 'mapbox':
                if ($apiKey === '') {
                    return null;
                }
                // Mapbox uses {longitude},{latitude} order
                $base = $customEndpoint !== '' ? rtrim($customEndpoint, '/') : 'https://api.mapbox.com/geocoding/v5/mapbox.places';
                return $base . '/' . urlencode("{$lon},{$lat}") . '.json?access_token=' . urlencode($apiKey) . '&types=country,region,district,place,locality';

            default:
                return null;
        }
    }

    /**
     * Normalize international administrative hierarchies (State, Province, Region, Department, Commune, City)
     * across all 5 reverse geocoding providers ('opencage', 'bigdatacloud', 'nominatim', 'google', 'mapbox')
     * and direct normalized payloads.
     */
    public static function normalizeReverseGeocoderResponse(array $payload, string $provider = 'custom'): ?array {
        $provider = strtolower(trim($provider));

        // 1. Direct normalized format (used by custom adapters / fixtures)
        if (isset($payload['country']) || isset($payload['country_code'])) {
            $countryCode = !empty($payload['country_code']) ? strtoupper(trim((string) $payload['country_code'])) : null;
            $cont = self::getContinentForCountry($countryCode);
            $region = $payload['region'] ?? ($payload['state'] ?? ($payload['province'] ?? ($payload['department'] ?? null)));
            $regionCode = $payload['region_code'] ?? null;
            $city = $payload['city'] ?? ($payload['commune'] ?? ($payload['municipality'] ?? ($payload['town'] ?? null)));

            if ($countryCode === 'MG') {
                $mg = self::normalizeMadagascarAdmin($region, $regionCode, $city);
                $region = $mg['region'];
                $regionCode = $mg['region_code'];
            }

            return [
                'continent'             => $payload['continent'] ?? $cont['name'],
                'continent_code'        => $payload['continent_code'] ?? $cont['code'],
                'country'               => $payload['country'] ?? null,
                'country_code'          => $countryCode,
                'region'                => $region,
                'region_code'           => $regionCode,
                'admin_type'            => $payload['admin_type'] ?? 'region',
                'city'                  => $city,
                'provider'              => $payload['provider'] ?? $provider,
                'region_border_dist_km' => isset($payload['region_border_dist_km']) ? (float) $payload['region_border_dist_km'] : INF,
                'city_border_dist_km'   => isset($payload['city_border_dist_km']) ? (float) $payload['city_border_dist_km'] : INF,
            ];
        }

        // 2. Google Maps Geocoding API format
        if (isset($payload['status']) && isset($payload['results']) && is_array($payload['results'])) {
            if ($payload['status'] !== 'OK' || empty($payload['results'][0]['address_components'])) {
                return null;
            }
            $country = null;
            $countryCode = null;
            $region = null;
            $regionCode = null;
            $regionLevel2 = null;
            $city = null;

            foreach ($payload['results'] as $resItem) {
                if (empty($resItem['address_components']) || !is_array($resItem['address_components'])) {
                    continue;
                }
                foreach ($resItem['address_components'] as $comp) {
                    $types = $comp['types'] ?? [];
                    if (in_array('country', $types, true) && $country === null) {
                        $country = $comp['long_name'] ?? null;
                        $countryCode = !empty($comp['short_name']) ? strtoupper($comp['short_name']) : null;
                    }
                    if (in_array('administrative_area_level_1', $types, true) && $region === null) {
                        $region = $comp['long_name'] ?? null;
                        $short = $comp['short_name'] ?? null;
                        if ($short && preg_match('/^[A-Z0-9]{1,3}$/i', $short)) {
                            $regionCode = strtoupper($short);
                        }
                    }
                    if (in_array('administrative_area_level_2', $types, true) && $regionLevel2 === null) {
                        $regionLevel2 = $comp['long_name'] ?? null;
                    }
                    if ((in_array('locality', $types, true) || in_array('postal_town', $types, true)) && $city === null) {
                        $city = $comp['long_name'] ?? null;
                    }
                }
            }

            if ($region === null && $regionLevel2 !== null) {
                $region = $regionLevel2;
            }
            if ($regionCode !== null && $countryCode !== null && strpos($regionCode, '-') === false) {
                $regionCode = "{$countryCode}-{$regionCode}";
            }
            if ($countryCode === 'MG') {
                $mg = self::normalizeMadagascarAdmin($region, $regionCode, $city);
                $region = $mg['region'];
                $regionCode = $mg['region_code'];
            }
            $cont = self::getContinentForCountry($countryCode);

            return [
                'continent'      => $cont['name'],
                'continent_code' => $cont['code'],
                'country'        => $country,
                'country_code'   => $countryCode,
                'region'         => $region,
                'region_code'    => $regionCode,
                'admin_type'     => 'administrative_area_level_1',
                'city'           => $city,
                'provider'       => 'google',
            ];
        }

        // 3. Mapbox Geocoding API v5 format
        if (isset($payload['type']) && $payload['type'] === 'FeatureCollection' && isset($payload['features']) && is_array($payload['features'])) {
            if (empty($payload['features'])) {
                return null;
            }
            $country = null;
            $countryCode = null;
            $region = null;
            $regionCode = null;
            $district = null;
            $city = null;

            $extractMapboxItem = function (array $item) use (&$country, &$countryCode, &$region, &$regionCode, &$district, &$city) {
                $id = (string) ($item['id'] ?? '');
                $placeTypes = $item['place_type'] ?? [];
                $text = $item['text'] ?? ($item['text_en'] ?? null);
                $shortCode = $item['properties']['short_code'] ?? ($item['short_code'] ?? null);

                if ((strpos($id, 'country.') === 0 || in_array('country', $placeTypes, true)) && $country === null) {
                    $country = $text;
                    if ($shortCode) {
                        $countryCode = strtoupper($shortCode);
                    }
                } elseif ((strpos($id, 'region.') === 0 || in_array('region', $placeTypes, true)) && $region === null) {
                    $region = $text;
                    if ($shortCode) {
                        $regionCode = strtoupper($shortCode);
                    }
                } elseif ((strpos($id, 'district.') === 0 || in_array('district', $placeTypes, true)) && $district === null) {
                    $district = $text;
                } elseif ((strpos($id, 'place.') === 0 || in_array('place', $placeTypes, true)) && $city === null) {
                    $city = $text;
                }
            };

            foreach ($payload['features'] as $feature) {
                $extractMapboxItem($feature);
                if (!empty($feature['context']) && is_array($feature['context'])) {
                    foreach ($feature['context'] as $ctx) {
                        if (is_array($ctx)) {
                            $extractMapboxItem($ctx);
                        }
                    }
                }
            }

            if ($region === null && $district !== null) {
                $region = $district;
            }
            if ($countryCode === 'MG') {
                $mg = self::normalizeMadagascarAdmin($region, $regionCode, $city);
                $region = $mg['region'];
                $regionCode = $mg['region_code'];
            }
            $cont = self::getContinentForCountry($countryCode);

            return [
                'continent'      => $cont['name'],
                'continent_code' => $cont['code'],
                'country'        => $country,
                'country_code'   => $countryCode,
                'region'         => $region,
                'region_code'    => $regionCode,
                'admin_type'     => 'region',
                'city'           => $city,
                'provider'       => 'mapbox',
            ];
        }

        // 4. OpenCage format
        if (!empty($payload['results'][0]['components'])) {
            $comp = $payload['results'][0]['components'];
            $countryCode = !empty($comp['ISO_3166-1_alpha-2']) ? strtoupper($comp['ISO_3166-1_alpha-2']) : (!empty($comp['country_code']) ? strtoupper($comp['country_code']) : null);
            $cont = self::getContinentForCountry($countryCode);
            $regionCode = null;
            if (!empty($comp['ISO_3166-2'][0])) {
                $regionCode = strtoupper($comp['ISO_3166-2'][0]);
            }
            $region = $comp['state'] ?? ($comp['province'] ?? ($comp['region'] ?? ($comp['county'] ?? ($comp['state_district'] ?? null))));
            // Do not promote rural hamlets/villages to urban city unless classified as city/town/municipality/commune
            $city = $comp['city'] ?? ($comp['town'] ?? ($comp['municipality'] ?? ($comp['commune'] ?? null)));

            if ($countryCode === 'MG') {
                $mg = self::normalizeMadagascarAdmin($region, $regionCode, $city);
                $region = $mg['region'];
                $regionCode = $mg['region_code'];
            }

            return [
                'continent'      => $comp['continent'] ?? $cont['name'],
                'continent_code' => $cont['code'],
                'country'        => $comp['country'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $region,
                'region_code'    => $regionCode,
                'admin_type'     => isset($comp['state']) ? 'state' : (isset($comp['province']) ? 'province' : 'region'),
                'city'           => $city,
                'provider'       => 'opencage',
            ];
        }

        // 5. BigDataCloud format
        if (isset($payload['countryName']) || isset($payload['countryCode'])) {
            $countryCode = !empty($payload['countryCode']) ? strtoupper($payload['countryCode']) : null;
            $cont = self::getContinentForCountry($countryCode);
            $region = !empty($payload['principalSubdivision']) ? $payload['principalSubdivision'] : null;
            $regionCode = !empty($payload['principalSubdivisionCode']) ? strtoupper($payload['principalSubdivisionCode']) : null;
            $city = !empty($payload['city']) ? $payload['city'] : null;

            if ($countryCode === 'MG') {
                $mg = self::normalizeMadagascarAdmin($region, $regionCode, $city);
                $region = $mg['region'];
                $regionCode = $mg['region_code'];
            }

            return [
                'continent'      => $payload['continent'] ?? $cont['name'],
                'continent_code' => $payload['continentCode'] ?? $cont['code'],
                'country'        => $payload['countryName'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $region,
                'region_code'    => $regionCode,
                'admin_type'     => 'subdivision',
                'city'           => $city,
                'provider'       => 'bigdatacloud',
            ];
        }

        // 6. Nominatim / OSM addressdetails format
        if (!empty($payload['address']) && is_array($payload['address'])) {
            $addr = $payload['address'];
            $countryCode = !empty($addr['country_code']) ? strtoupper($addr['country_code']) : null;
            $cont = self::getContinentForCountry($countryCode);
            $region = $addr['state'] ?? ($addr['province'] ?? ($addr['region'] ?? ($addr['county'] ?? null)));
            $regionCode = $addr['ISO3166-2-lvl4'] ?? ($addr['ISO3166-2-lvl6'] ?? null);
            $city = $addr['city'] ?? ($addr['town'] ?? ($addr['municipality'] ?? null));

            if ($countryCode === 'MG') {
                $mg = self::normalizeMadagascarAdmin($region, $regionCode, $city);
                $region = $mg['region'];
                $regionCode = $mg['region_code'];
            }

            return [
                'continent'      => $cont['name'],
                'continent_code' => $cont['code'],
                'country'        => $addr['country'] ?? null,
                'country_code'   => $countryCode,
                'region'         => $region,
                'region_code'    => $regionCode ? strtoupper($regionCode) : null,
                'admin_type'     => 'region',
                'city'           => $city,
                'provider'       => 'nominatim',
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
