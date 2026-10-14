<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  Advanced Combined Targeting Helper (Module 4A)
 *  Evaluates multi-criteria rules: Country, Region, Device, OS, Language, Schedule.
 *  Preserves security gates, passwords, expiration, and prevents unknown region leaks.
 * ====================================================================================
 */

namespace Helpers;

use Core\Helper;

class AdvancedTargeting {

    /**
     * Resolve target URL by evaluating combined rules against visitor context
     */
    public static function resolve(object $url, array $visitorContext): string {
        $defaultUrl = $url->url ?? '';

        // Extract options
        $options = [];
        if (!empty($url->options)) {
            $options = is_array($url->options) ? $url->options : (json_decode($url->options, true) ?: []);
        }

        $rules = $options['advanced_targeting_rules'] ?? null;
        if (empty($rules) || !is_array($rules)) {
            return $defaultUrl;
        }

        // Normalize visitor attributes
        $visitorCountry  = strtolower(trim($visitorContext['country'] ?? ''));
        $visitorRegion   = strtolower(trim($visitorContext['region'] ?? ''));
        $visitorDevice   = strtolower(trim($visitorContext['device'] ?? ''));
        $visitorOs       = strtolower(trim($visitorContext['os'] ?? ''));
        $visitorLanguage = strtolower(substr(trim($visitorContext['language'] ?? ''), 0, 2));

        // Evaluate rules in defined priority order
        foreach ($rules as $rule) {
            if (empty($rule['destination'])) {
                continue;
            }

            if (self::matchesRule($rule, $visitorCountry, $visitorRegion, $visitorDevice, $visitorOs, $visitorLanguage, $visitorContext)) {
                return $rule['destination'];
            }
        }

        return $defaultUrl;
    }

    /**
     * Evaluate an individual multi-criteria rule
     */
    private static function matchesRule(
        array $rule,
        string $vCountry,
        string $vRegion,
        string $vDevice,
        string $vOs,
        string $vLang,
        array $context
    ): bool {
        // 1. Country Match (optional)
        if (!empty($rule['country'])) {
            $targetCountry = strtolower(trim($rule['country']));
            if ($vCountry !== $targetCountry) {
                return false;
            }
        }

        // 2. Region Match (optional)
        // CRITICAL: An unknown or empty region must NEVER match a specific region rule
        if (!empty($rule['region'])) {
            $targetRegion = strtolower(trim($rule['region']));
            if (empty($vRegion) || $vRegion === 'unknown' || $vRegion !== $targetRegion) {
                return false;
            }
        }

        // 3. Device Match (optional: 'mobile', 'desktop', 'tablet', 'ios', 'android')
        if (!empty($rule['device'])) {
            $targetDevice = strtolower(trim($rule['device']));
            if ($targetDevice === 'ios') {
                if ($vDevice !== 'ios' && $vDevice !== 'iphone' && $vDevice !== 'ipad' && $vOs !== 'ios') {
                    return false;
                }
            } elseif ($targetDevice === 'android') {
                if ($vDevice !== 'android' && $vOs !== 'android') {
                    return false;
                }
            } elseif ($targetDevice === 'mobile') {
                $mobiles = ['mobile', 'ios', 'iphone', 'ipad', 'android', 'phone'];
                if (!in_array($vDevice, $mobiles, true) && !in_array($vOs, ['ios', 'android'], true)) {
                    return false;
                }
            } elseif ($targetDevice === 'desktop') {
                $desktops = ['desktop', 'windows', 'mac', 'macintosh', 'linux'];
                if (in_array($vDevice, ['mobile', 'iphone', 'android', 'tablet'], true)) {
                    return false;
                }
            } elseif ($vDevice !== $targetDevice) {
                return false;
            }
        }

        // 4. Operating System Match (optional)
        if (!empty($rule['os'])) {
            $targetOs = strtolower(trim($rule['os']));
            if (strpos($vOs, $targetOs) === false) {
                return false;
            }
        }

        // 5. Browser Language Match (optional)
        if (!empty($rule['language'])) {
            $targetLang = strtolower(substr(trim($rule['language']), 0, 2));
            if ($vLang !== $targetLang) {
                return false;
            }
        }

        // 6. Time Window Match (optional)
        if (!empty($rule['schedule']) && is_array($rule['schedule'])) {
            $sched = $rule['schedule'];
            $tzName = $sched['timezone'] ?? 'UTC';
            try {
                $tz = new \DateTimeZone($tzName);
            } catch (\Throwable $e) {
                $tz = new \DateTimeZone('UTC');
            }

            $now = new \DateTime('now', $tz);
            $currentMinutes = ((int)$now->format('H') * 60) + (int)$now->format('i');

            if (!empty($sched['start']) && !empty($sched['end'])) {
                list($sh, $sm) = explode(':', $sched['start']);
                list($eh, $em) = explode(':', $sched['end']);
                $startMin = ((int)$sh * 60) + (int)$sm;
                $endMin   = ((int)$eh * 60) + (int)$em;

                if ($startMin <= $endMin) {
                    if ($currentMinutes < $startMin || $currentMinutes > $endMin) {
                        return false;
                    }
                } else {
                    // Overnight window
                    if ($currentMinutes < $startMin && $currentMinutes > $endMin) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
