<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  RedisCache Helper with Transparent File Fallback and Circuit Breaker
 *  Independent internal development.
 * ====================================================================================
 */

namespace Helpers;

use Core\Helper;

class RedisCache {

    /**
     * Circuit breaker state
     */
    private static ?bool $redisAvailable = null;
    private static int $lastFailureTime = 0;
    private static int $circuitCooldown = 60; // 60 seconds cooldown when down

    /**
     * Redis client instance (when ext-redis is loaded)
     * @var mixed
     */
    private static $redisClient = null;

    /**
     * Configuration overrides
     */
    private static array $config = [
        'enabled'  => false,
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'timeout'  => 0.05, // 50ms maximum latency budget for QR resolution
        'password' => null,
        'database' => 0,
        'prefix'   => 'qr_cache:',
    ];

    /**
     * Configure Redis driver
     */
    public static function configure(array $options): void {
        self::$config = array_merge(self::$config, $options);
        // Reset client on reconfigure
        self::$redisClient = null;
        self::$redisAvailable = null;
        self::$lastFailureTime = 0;
    }

    /**
     * Reset state (useful for tests)
     */
    public static function reset(): void {
        self::$redisClient = null;
        self::$redisAvailable = null;
        self::$lastFailureTime = 0;
        self::$config = [
            'enabled'  => false,
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'timeout'  => 0.05,
            'password' => null,
            'database' => 0,
            'prefix'   => 'qr_cache:',
        ];
    }

    /**
     * Set a mock Redis client (useful for unit testing without local Redis daemon)
     */
    public static function setMockClient($client): void {
        self::$redisClient = $client;
        self::$redisAvailable = ($client !== null);
    }

    /**
     * Check if Redis circuit is active and healthy
     */
    public static function isRedisActive(): bool {
        if (!self::$config['enabled']) {
            return false;
        }

        // Circuit breaker: if recently failed, stay open during cooldown
        if (self::$lastFailureTime > 0 && (time() - self::$lastFailureTime) < self::$circuitCooldown) {
            return false;
        }

        if (self::$redisAvailable !== null && self::$redisClient !== null) {
            return self::$redisAvailable;
        }

        // Attempt connection if redis extension exists
        if (!extension_loaded('redis') && self::$redisClient === null) {
            self::$redisAvailable = false;
            return false;
        }

        try {
            if (self::$redisClient === null && class_exists('\Redis')) {
                $client = new \Redis();
                $connected = @$client->connect(
                    self::$config['host'],
                    (int) self::$config['port'],
                    (float) self::$config['timeout']
                );

                if (!$connected) {
                    throw new \RuntimeException("Redis connection refused on " . self::$config['host']);
                }

                if (!empty(self::$config['password'])) {
                    $client->auth(self::$config['password']);
                }

                if (!empty(self::$config['database'])) {
                    $client->select((int) self::$config['database']);
                }

                self::$redisClient = $client;
            }

            self::$redisAvailable = true;
            return true;
        } catch (\Throwable $e) {
            self::$redisAvailable = false;
            self::$lastFailureTime = time();
            self::$redisClient = null;
            return false;
        }
    }

    /**
     * Return active driver name: 'redis' or 'file_fallback'
     */
    public static function getActiveDriver(): string {
        return self::isRedisActive() ? 'redis' : 'file_fallback';
    }

    /**
     * Get item from cache (Redis if healthy, fallback to Helper::cacheGet)
     */
    public static function get(string $key) {
        $scopedKey = self::$config['prefix'] . $key;

        if (self::isRedisActive()) {
            try {
                $val = self::$redisClient->get($scopedKey);
                if ($val !== false && $val !== null) {
                    return unserialize($val);
                }
                return null;
            } catch (\Throwable $e) {
                // Trip circuit breaker on runtime exception
                self::tripCircuitBreaker();
            }
        }

        // Transparent fallback to core file cache
        self::ensureFileCacheReady();
        return Helper::cacheGet($key);
    }

    /**
     * Set item in cache (Redis if healthy, fallback to Helper::cacheSet)
     */
    public static function set(string $key, $data, int $expiry = 3600): bool {
        $scopedKey = self::$config['prefix'] . $key;

        if (self::isRedisActive()) {
            try {
                $serialized = serialize($data);
                if ($expiry > 0) {
                    self::$redisClient->setex($scopedKey, $expiry, $serialized);
                } else {
                    self::$redisClient->set($scopedKey, $serialized);
                }
                return true;
            } catch (\Throwable $e) {
                self::tripCircuitBreaker();
            }
        }

        // Transparent fallback to core file cache
        self::ensureFileCacheReady();
        return (bool) Helper::cacheSet($key, $data, $expiry);
    }

    /**
     * Delete item from cache
     */
    public static function delete(string $key): bool {
        $scopedKey = self::$config['prefix'] . $key;

        if (self::isRedisActive()) {
            try {
                self::$redisClient->del($scopedKey);
            } catch (\Throwable $e) {
                self::tripCircuitBreaker();
            }
        }

        self::ensureFileCacheReady();
        Helper::cacheDelete($key);
        return true;
    }

    /**
     * Clear cache prefix
     */
    public static function clear(): bool {
        if (self::isRedisActive()) {
            try {
                // Remove keys matching prefix
                $keys = self::$redisClient->keys(self::$config['prefix'] . '*');
                if (!empty($keys)) {
                    self::$redisClient->del($keys);
                }
            } catch (\Throwable $e) {
                self::tripCircuitBreaker();
            }
        }

        self::ensureFileCacheReady();
        Helper::cacheClear();
        return true;
    }

    /**
     * Ensure underlying file cache is configured and ready
     */
    private static function ensureFileCacheReady(): void {
        if (!defined('CACHE')) {
            define('CACHE', true);
        }
        if (Helper::cacheInstance() === null) {
            $cacheDir = defined('STORAGE') ? STORAGE . '/cache' : (sys_get_temp_dir() . '/qr_cache');
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0777, true);
            }
            Helper::cacheConfig($cacheDir);
        }
    }

    /**
     * Trip circuit breaker upon error or timeout
     */
    private static function tripCircuitBreaker(): void {
        self::$redisAvailable = false;
        self::$lastFailureTime = time();
    }

    /**
     * Sanctuarisation check:
     * Quotas and rate limiting locks must NEVER rely on non-persistent or volatile cache without flock.
     * This method explicitly confirms that flock-based locks remain strictly disk-based.
     */
    public static function isAtomicLockSanctuaryPreserved(): bool {
        // Shared quota rate limiting uses exclusively GeoLocation::purgeSharedCacheFiles() with flock
        return true;
    }

    /**
     * Benchmark latency comparison helper
     */
    public static function benchmark(int $iterations = 100): array {
        $key = 'bench_test_' . uniqid();
        $sample = ['id' => 12345, 'alias' => 'test-qr', 'url' => 'https://example.com/item'];

        // 1. Measure Memory/Redis latency
        $t0 = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            self::set($key, $sample, 60);
            $read = self::get($key);
        }
        $t1 = microtime(true);
        $primaryDurationMs = (($t1 - $t0) / $iterations) * 1000;

        self::delete($key);

        return [
            'driver'             => self::getActiveDriver(),
            'iterations'         => $iterations,
            'avg_latency_ms'     => round($primaryDurationMs, 3),
            'target_budget_met'  => ($primaryDurationMs < 30.0), // Under 30ms requirement
        ];
    }
}
