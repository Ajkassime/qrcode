<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel
 * ---------------------------------------------------------------------------------------
 *  This software is packaged with an exclusive framework as such distribution
 *  or modification of this framework is not allowed before prior consent from
 *  GemPixel. If you find that this framework is packaged in a software not distributed
 *  by GemPixel or authorized parties, you must not use this software and contact GemPixel
 *  at https://gempixel.com/contact to inform them of this misuse.
 * =======================================================================================
 *
 * @package GemPixel\Premium-URL-Shortener
 * @author GemPixel (https://gempixel.com)
 * @license https://gempixel.com/licenses
 * @link https://gempixel.com
 */

namespace Helpers;

use Core\Request;
use Core\Helper;

/**
 * Class MultiUrlEngine
 *
 * Resolves conditional dynamic Multi-URL redirection rules based on:
 * - Visitor browser language (Accept-Language)
 * - Visitor country (GeoLocation or IP header)
 * - Visitor device type (mobile, desktop, tablet, iOS, Android)
 * - Time window and schedule with timezone support
 */
class MultiUrlEngine {

    /**
     * Resolve the target URL from a Multi-URL configuration
     *
     * @param array|object $config
     * @param Request|null $request
     * @param array|null $geoData
     * @param \DateTimeInterface|null $now
     * @return string
     */
    public static function resolve($config, ?Request $request = null, ?array $geoData = null, ?\DateTimeInterface $now = null): string {
        $config = (array) $config;

        $defaultUrl = (string) ($config['default'] ?? '');
        $rules = $config['rules'] ?? [];

        if (!is_array($rules) || empty($rules)) {
            return self::sanitizeUrl($defaultUrl);
        }

        $request = $request ?? request();

        $bestScore = 0.0;
        $bestUrl = null;
        $totalRules = count($rules);

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                $rule = (array) $rule;
            }

            if (empty($rule['url']) || !is_string($rule['url'])) {
                continue;
            }

            $score = self::getRuleScore($rule, $request, $geoData, $now);
            if ($score > 0) {
                // Add tiny order bonus so identical score rules respect declaration order
                $orderBonus = ($totalRules - $index) * 0.0001;
                $finalScore = $score + $orderBonus;

                if ($finalScore > $bestScore) {
                    $bestScore = $finalScore;
                    $bestUrl = (string) $rule['url'];
                }
            }
        }

        if ($bestUrl !== null) {
            return self::sanitizeUrl($bestUrl);
        }

        return self::sanitizeUrl($defaultUrl);
    }

    /**
     * Calculate score for a rule (0 = no match, > 0 = matched with priority score)
     *
     * @param array $rule
     * @param Request $request
     * @param array|null $geoData
     * @param \DateTimeInterface|null $now
     * @return float
     */
    public static function getRuleScore(array $rule, Request $request, ?array $geoData = null, ?\DateTimeInterface $now = null): float {
        $type = strtolower((string) ($rule['type'] ?? ''));

        switch ($type) {
            case 'language':
            case 'lang':
                return self::getLanguageScore($rule, $request);

            case 'country':
            case 'geo':
                return self::matchesCountry($rule, $request, $geoData) ? 90.0 : 0.0;

            case 'device':
            case 'platform':
                return self::matchesDevice($rule, $request) ? 80.0 : 0.0;

            case 'time':
            case 'schedule':
                return self::matchesTime($rule, $now) ? 70.0 : 0.0;

            default:
                return 0.0;
        }
    }

    /**
     * Check if a specific rule matches current visitor context
     *
     * @param array $rule
     * @param Request $request
     * @param array|null $geoData
     * @param \DateTimeInterface|null $now
     * @return bool
     */
    public static function matchesRule(array $rule, Request $request, ?array $geoData = null, ?\DateTimeInterface $now = null): bool {
        return self::getRuleScore($rule, $request, $geoData, $now) > 0.0;
    }

    /**
     * Calculate score for language rule based on visitor Accept-Language quality weights
     *
     * @param array $rule
     * @param Request $request
     * @return float
     */
    protected static function getLanguageScore(array $rule, Request $request): float {
        $match = $rule['match'] ?? $rule['value'] ?? '';
        if (empty($match)) {
            return 0.0;
        }

        $expected = is_array($match) ? array_map('strtolower', $match) : [strtolower((string) $match)];

        $acceptLang = (string) ($request->server('http_accept_language') ?? '');
        if ($acceptLang === '') {
            return 0.0;
        }

        $visitorLanguages = self::parseAcceptLanguage($acceptLang);
        $highestQ = 0.0;

        foreach ($expected as $exp) {
            $exp = trim($exp);
            if (isset($visitorLanguages[$exp])) {
                $highestQ = max($highestQ, $visitorLanguages[$exp]);
            }
            $expPrefix = explode('-', $exp)[0];
            if ($expPrefix !== '' && isset($visitorLanguages[$expPrefix])) {
                $highestQ = max($highestQ, $visitorLanguages[$expPrefix]);
            }
        }

        if ($highestQ > 0.0) {
            // Returns base score 50.0 + scaled quality factor (e.g. 50 + 50*1.0 = 100 for top language)
            return 50.0 + ($highestQ * 50.0);
        }

        return 0.0;
    }

    /**
     * Parse Accept-Language header into quality-weighted map
     *
     * @param string $header
     * @return array<string, float>
     */
    public static function parseAcceptLanguage(string $header): array {
        if ($header === '') {
            return [];
        }

        $languages = [];
        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $tokens = explode(';', trim($part));
            $tag = strtolower(trim($tokens[0]));
            if ($tag === '') continue;

            $q = 1.0;
            if (isset($tokens[1])) {
                $qToken = trim($tokens[1]);
                if (strpos($qToken, 'q=') === 0) {
                    $q = (float) substr($qToken, 2);
                }
            }

            $languages[$tag] = $q;
            $prefix = explode('-', $tag)[0];
            if ($prefix !== '' && !isset($languages[$prefix])) {
                $languages[$prefix] = max(0.01, $q - 0.001);
            }
        }

        return $languages;
    }

    /**
     * Check visitor browser language
     *
     * @param array $rule
     * @param Request $request
     * @return bool
     */
    protected static function matchesLanguage(array $rule, Request $request): bool {
        return self::getLanguageScore($rule, $request) > 0.0;
    }


    /**
     * Check visitor country
     *
     * @param array $rule
     * @param Request $request
     * @param array|null $geoData
     * @return bool
     */
    protected static function matchesCountry(array $rule, Request $request, ?array $geoData = null): bool {
        $match = $rule['match'] ?? $rule['value'] ?? '';
        if (empty($match)) {
            return false;
        }

        $expected = is_array($match) ? array_map('strtoupper', $match) : [strtoupper((string) $match)];

        $visitorCountryCode = '';
        $visitorCountryName = '';

        if (!empty($geoData['country_code'])) {
            $visitorCountryCode = strtoupper((string) $geoData['country_code']);
        }
        if (!empty($geoData['country'])) {
            $visitorCountryName = strtoupper((string) $geoData['country']);
        }

        if ($visitorCountryCode === '' && method_exists($request, 'country')) {
            $reqGeo = $request->country();
            if (is_array($reqGeo)) {
                $visitorCountryCode = strtoupper((string) ($reqGeo['country_code'] ?? ''));
                $visitorCountryName = strtoupper((string) ($reqGeo['country'] ?? ''));
            }
        }

        foreach ($expected as $exp) {
            $exp = trim($exp);
            if ($visitorCountryCode !== '' && $exp === $visitorCountryCode) {
                return true;
            }
            if ($visitorCountryName !== '' && $exp === $visitorCountryName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check visitor device
     *
     * @param array $rule
     * @param Request $request
     * @return bool
     */
    protected static function matchesDevice(array $rule, Request $request): bool {
        $match = strtolower((string) ($rule['match'] ?? $rule['value'] ?? ''));
        if ($match === '') {
            return false;
        }

        $ua = (string) ($request->server('http_user_agent') ?? '');
        $device = method_exists($request, 'device') ? strtolower((string) $request->device()) : '';

        $isIos = (bool) preg_match('/(iPhone|iPad|iPod)/i', $ua);
        $isAndroid = (bool) preg_match('/Android/i', $ua);
        $isMobile = $isIos || $isAndroid || (bool) preg_match('/(Mobile|Tablet|Silk|Kindle|Opera Mini)/i', $ua) || in_array($device, ['iphone', 'ipad', 'android', 'mobile'], true);

        switch ($match) {
            case 'mobile':
                return $isMobile;

            case 'desktop':
                return !$isMobile;

            case 'tablet':
                return (bool) preg_match('/(iPad|Tablet|Silk|Kindle)/i', $ua) || strpos($device, 'tablet') !== false || strpos($device, 'ipad') !== false;

            case 'ios':
            case 'apple':
            case 'iphone':
                return $isIos;

            case 'android':
                return $isAndroid;

            default:
                if ($device !== '' && strpos($device, $match) !== false) {
                    return true;
                }
                return (bool) preg_match('/' . preg_quote($match, '/') . '/i', $ua);
        }
    }

    /**
     * Check current time against a scheduled window
     *
     * @param array $rule
     * @param \DateTimeInterface|null $now
     * @return bool
     */
    protected static function matchesTime(array $rule, ?\DateTimeInterface $now = null): bool {
        $startTimeStr = (string) ($rule['start'] ?? '00:00');
        $endTimeStr = (string) ($rule['end'] ?? '23:59');
        $tzName = (string) ($rule['timezone'] ?? 'Indian/Antananarivo');

        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        if ($now instanceof \DateTimeInterface) {
            $dt = new \DateTime($now->format('Y-m-d H:i:s'), $now->getTimezone());
            $dt->setTimezone($tz);
        } else {
            $dt = new \DateTime('now', $tz);
        }

        // Optional days of week check (1 = Monday, 7 = Sunday)
        if (isset($rule['days']) && is_array($rule['days']) && !empty($rule['days'])) {
            $currentDay = (int) $dt->format('N');
            if (!in_array($currentDay, array_map('intval', $rule['days']), true)) {
                return false;
            }
        }

        $currentMinutes = ((int) $dt->format('H') * 60) + (int) $dt->format('i');

        $startParts = explode(':', $startTimeStr);
        $startMinutes = ((int) ($startParts[0] ?? 0) * 60) + (int) ($startParts[1] ?? 0);

        $endParts = explode(':', $endTimeStr);
        $endMinutes = ((int) ($endParts[0] ?? 23) * 60) + (int) ($endParts[1] ?? 59);

        // Standard window (e.g. 08:00 -> 18:00)
        if ($startMinutes <= $endMinutes) {
            return ($currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes);
        }

        // Overnight window crossing midnight (e.g. 22:00 -> 06:00)
        return ($currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes);
    }

    /**
     * Sanitize and validate target URL
     *
     * @param string $url
     * @return string
     */
    public static function sanitizeUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null) {
            $scheme = strtolower($scheme);
            if (!in_array($scheme, ['http', 'https', 'whatsapp', 'tel', 'mailto'], true)) {
                return '';
            }
        }

        return $url;
    }
}
