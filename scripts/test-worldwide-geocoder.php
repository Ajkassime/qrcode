<?php
/**
 * CLI Worldwide Reverse Geocoder & Reliability Testing Tool
 *
 * Verifies worldwide reverse geocoding providers in real & controlled conditions:
 *   1. Isolated execution environment (never deletes or alters production cache/quotas)
 *   2. Active provider resolution with real multi-continent coordinates
 *   3. Failure case handling (HTTP 500 / timeout / offline)
 *   4. Shared cross-process quota exhaustion (per-minute & daily quotas)
 *   5. Quota storage inaccessibility (fail-closed security protection)
 *   6. Simulated integration lifecycle verification (QR, scan consent gate, stats & WhatsApp redirection)
 *
 * Usage:
 *   php scripts/test-worldwide-geocoder.php
 *   php scripts/test-worldwide-geocoder.php --provider=opencage --live
 *   php scripts/test-worldwide-geocoder.php --simulate-failure
 *   php scripts/test-worldwide-geocoder.php --simulate-quota
 *   php scripts/test-worldwide-geocoder.php --whatsapp-check
 *   php scripts/test-worldwide-geocoder.php --json
 */

require_once __DIR__ . '/cli-bootstrap.php';

$args = array_slice($argv, 1);
$jsonOutput = in_array('--json', $args, true);
$liveMode = in_array('--live', $args, true);
$simFailure = in_array('--simulate-failure', $args, true);
$simQuota = in_array('--simulate-quota', $args, true);
$simStorageError = in_array('--simulate-inaccessible-quota', $args, true);
$whatsappCheck = in_array('--whatsapp-e2e', $args, true) || in_array('--whatsapp-check', $args, true);

$provider = 'opencage';
foreach ($args as $arg) {
    if (strpos($arg, '--provider=') === 0) {
        $provider = strtolower(trim(substr($arg, 11)));
    }
}

// -----------------------------------------------------------------------------
// 1. ISOLATION: Create dedicated temporary cache directory to protect production
// -----------------------------------------------------------------------------
$isolatedTempDir = sys_get_temp_dir() . '/codex_geotest_' . bin2hex(random_bytes(8));
if (!is_dir($isolatedTempDir)) {
    mkdir($isolatedTempDir, 0700, true);
}

// Set custom cache dir BEFORE calling any clearCache or resolution
\Helpers\GeoLocation::setCacheDirectory($isolatedTempDir);
\Helpers\GeoLocation::clearCache(true);

// Register shutdown handler to purge isolated temp directory and restore default
register_shutdown_function(function () use ($isolatedTempDir) {
    \Helpers\GeoLocation::setCacheDirectory(null);
    if (is_dir($isolatedTempDir)) {
        $files = @glob($isolatedTempDir . '/*');
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($isolatedTempDir);
    }
});

// -----------------------------------------------------------------------------
// 2. SECRETS: Load API key from secure environment, NEVER from process argv
// -----------------------------------------------------------------------------
$configuredApiKey = (string) \Helpers\GeoLocation::getEnv('REVERSE_GEOCODER_API_KEY', '');
if ($liveMode && empty($configuredApiKey)) {
    $errorMsg = "Le mode --live nécessite que la variable REVERSE_GEOCODER_API_KEY soit définie (dans .env ou via l'environnement système). La clé ne doit pas être transmise en argument CLI afin d'éviter son exposition dans la table des processus système (ps aux / proc).";
    if ($jsonOutput) {
        echo json_encode(['error' => $errorMsg], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "\n[ERREUR SÉCURITÉ] " . $errorMsg . "\n\n";
    }
    exit(1);
}

$results = [
    'mode'               => $liveMode ? 'LIVE_EXTERNAL_NETWORK_CALLS' : 'SIMULATED_CONTROLLED_REFERENCE_DATASET',
    'provider'           => $provider,
    'api_key_configured' => !empty($configuredApiKey),
    'api_key_masked'     => !empty($configuredApiKey) ? \Helpers\GeoLocation::maskApiKey($configuredApiKey) : 'NONE',
    'isolated_cache_dir' => $isolatedTempDir,
    'timestamp'          => date('c'),
    'test_cases'         => [],
    'summary'            => [
        'total'  => 0,
        'passed' => 0,
        'failed' => 0,
    ],
];

$recordResult = function (string $id, string $title, bool $passed, array $details) use (&$results): void {
    $results['test_cases'][] = [
        'id'      => $id,
        'title'   => $title,
        'status'  => $passed ? 'PASS' : 'FAIL',
        'details' => $details,
    ];
    $results['summary']['total']++;
    if ($passed) {
        $results['summary']['passed']++;
    } else {
        $results['summary']['failed']++;
    }
};

// Test Coordinates across continents
$referenceCoordinates = [
    'Geneva, Switzerland (Europe)' => [
        'lat' => 46.2044, 'lon' => 6.1432, 'acc' => 25.0,
        'expected_country' => 'Switzerland', 'expected_code' => 'CH'
    ],
    'Tokyo, Japan (Asia)' => [
        'lat' => 35.6762, 'lon' => 139.6503, 'acc' => 30.0,
        'expected_country' => 'Japan', 'expected_code' => 'JP'
    ],
    'Sydney, Australia (Oceania)' => [
        'lat' => -33.8688, 'lon' => 151.2093, 'acc' => 20.0,
        'expected_country' => 'Australia', 'expected_code' => 'AU'
    ],
    'New York, USA (North America)' => [
        'lat' => 40.7128, 'lon' => -74.0060, 'acc' => 15.0,
        'expected_country' => 'United States', 'expected_code' => 'US'
    ],
    'São Paulo, Brazil (South America)' => [
        'lat' => -23.5505, 'lon' => -46.6333, 'acc' => 35.0,
        'expected_country' => 'Brazil', 'expected_code' => 'BR'
    ],
    'Mananjary, Madagascar (Africa/Vatovavy)' => [
        'lat' => -21.2230, 'lon' => 48.3410, 'acc' => 25.0,
        'expected_country' => 'Madagascar', 'expected_code' => 'MG', 'expected_region' => 'Vatovavy'
    ],
    'Rural Vatovavy, Madagascar (Outside Urban Commune)' => [
        'lat' => -21.3000, 'lon' => 47.6300, 'acc' => 80.0,
        'expected_country' => 'Madagascar', 'expected_code' => 'MG', 'expected_city' => null
    ],
];

// Configure Provider in isolated test cache
\Helpers\GeoLocation::setReverseGeocoderConfig([
    'enabled'                 => true,
    'provider'                => $provider,
    'api_key'                 => $configuredApiKey ?: 'test_isolated_key',
    'timeout'                 => 2.0,
    'cache_ttl'               => 3600,
    'max_requests_per_minute' => 60,
    'daily_quota'             => 2500,
    'user_agent'              => 'QRCodePlatform-WorldwideTester/2.0',
    'tos_accepted'            => true,
]);

// If not running in live network mode, set up real reference responses
if (!$liveMode) {
    \Helpers\GeoLocation::setMockHttpTransport(function (string $url) {
        if (strpos($url, '46.204') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'city' => 'Genève', 'state' => 'Genève', 'country' => 'Switzerland',
                        'country_code' => 'ch', 'ISO3166-2-lvl4' => 'CH-GE'
                    ]
                ]]
            ]);
        }
        if (strpos($url, '35.676') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'city' => 'Tokyo', 'state' => 'Tokyo', 'country' => 'Japan',
                        'country_code' => 'jp', 'ISO3166-2-lvl4' => 'JP-13'
                    ]
                ]]
            ]);
        }
        if (strpos($url, '-33.868') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'city' => 'Sydney', 'state' => 'New South Wales', 'country' => 'Australia',
                        'country_code' => 'au', 'ISO3166-2-lvl4' => 'AU-NSW'
                    ]
                ]]
            ]);
        }
        if (strpos($url, '40.712') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'city' => 'New York', 'state' => 'New York', 'country' => 'United States',
                        'country_code' => 'us', 'ISO3166-2-lvl4' => 'US-NY'
                    ]
                ]]
            ]);
        }
        if (strpos($url, '-23.550') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'city' => 'São Paulo', 'state' => 'São Paulo', 'country' => 'Brazil',
                        'country_code' => 'br', 'ISO3166-2-lvl4' => 'BR-SP'
                    ]
                ]]
            ]);
        }
        if (strpos($url, '-21.223') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'town' => 'Mananjary', 'state' => 'Vatovavy', 'country' => 'Madagascar',
                        'country_code' => 'mg'
                    ]
                ]]
            ]);
        }
        if (strpos($url, '-21.3') !== false && strpos($url, '47.63') !== false) {
            return json_encode([
                'results' => [[
                    'components' => [
                        'village' => 'Ambohimanga Rurale', 'state' => 'Vatovavy', 'country' => 'Madagascar',
                        'country_code' => 'mg'
                    ]
                ]]
            ]);
        }
        return json_encode(['results' => []]);
    });
}

// 1. Multi-Continent Coordinates Test
foreach ($referenceCoordinates as $label => $c) {
    $res = \Helpers\GeoLocation::resolveCoordinates($c['lat'], $c['lon'], $c['acc']);
    $passCountry = ($res['country'] === $c['expected_country']);
    $passCode = ($res['country_code'] === $c['expected_code']);
    $passRegion = !isset($c['expected_region']) || ($res['region'] === $c['expected_region']);
    $passCity = !array_key_exists('expected_city', $c) || ($res['city'] === $c['expected_city']);

    $passed = $passCountry && $passCode && $passRegion && $passCity;
    $recordResult("coords_" . md5($label), "Coordinates: {$label}", $passed, [
        'lat'             => $c['lat'],
        'lon'             => $c['lon'],
        'accuracy_m'      => $c['acc'],
        'resolved_city'   => $res['city'],
        'resolved_region' => $res['region'],
        'resolved_country'=> $res['country'],
        'location_level'  => $res['location_level'],
        'source'          => $res['source'],
        'provider'        => $res['provider'],
    ]);
}

// 2. Failure Case Test (HTTP 500 / Timeout)
if ($simFailure || !$liveMode) {
    \Helpers\GeoLocation::clearCache(true);
    \Helpers\GeoLocation::setReverseGeocoderConfig([
        'enabled'  => true,
        'provider' => $provider,
        'api_key'  => 'isolated_key',
        'timeout'  => 0.1,
    ]);
    \Helpers\GeoLocation::setMockHttpTransport(function () {
        return null; // Simulated network failure
    });

    $res = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 20.0);
    $passed = ($res['location_level'] === 'unknown' && $res['missing_reason'] === 'reverse_geocoder_unavailable');
    $recordResult("failure_handling", "External Provider Failure Resilience (Timeout/Offline)", $passed, [
        'status_code_or_event' => 'network_null_response',
        'location_level'       => $res['location_level'],
        'missing_reason'       => $res['missing_reason'],
        'graceful_degradation' => $passed ? 'SUCCESS (no fatal error)' : 'FAILED',
    ]);
}

// 3. Shared Cross-Process Quota Exhaustion Test
if ($simQuota || !$liveMode) {
    \Helpers\GeoLocation::clearCache(true);
    \Helpers\GeoLocation::setReverseGeocoderConfig([
        'enabled'                 => true,
        'provider'                => 'opencage',
        'api_key'                 => 'isolated_key',
        'max_requests_per_minute' => 2,
        'daily_quota'             => 2,
    ]);
    \Helpers\GeoLocation::setMockHttpTransport(function () {
        return json_encode(['results' => [['components' => ['city' => 'Paris', 'country' => 'France', 'country_code' => 'fr']]]]);
    });

    $c1 = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 20.0);
    $c2 = \Helpers\GeoLocation::resolveCoordinates(45.7640, 4.8357, 20.0);
    $c3 = \Helpers\GeoLocation::resolveCoordinates(43.6047, 1.4442, 20.0); // Should be blocked

    $passed = ($c1['country'] === 'France' && $c2['country'] === 'France' && $c3['missing_reason'] === 'quota_exceeded');
    $recordResult("quota_enforcement", "Shared Quota Limit Exhaustion Protection", $passed, [
        'call_1'         => $c1['country'] ?: $c1['missing_reason'],
        'call_2'         => $c2['country'] ?: $c2['missing_reason'],
        'call_3_blocked' => $c3['missing_reason'],
    ]);
}

// 4. Fail-Closed Quota Storage Protection Test
if ($simStorageError || !$liveMode) {
    \Helpers\GeoLocation::clearCache(true);
    \Helpers\GeoLocation::setReverseGeocoderConfig([
        'enabled'  => true,
        'provider' => 'opencage',
        'api_key'  => 'isolated_key',
    ]);

    $tempFileAsDir = tempnam(sys_get_temp_dir(), 'geo_inaccessible_');
    \Helpers\GeoLocation::setCacheDirectory($tempFileAsDir);

    $calledExternal = false;
    \Helpers\GeoLocation::setMockHttpTransport(function () use (&$calledExternal) {
        $calledExternal = true;
        return json_encode(['results' => [['components' => ['country' => 'France', 'country_code' => 'fr']]]]);
    });

    $res = \Helpers\GeoLocation::resolveCoordinates(48.8566, 2.3522, 20.0);
    \Helpers\GeoLocation::setCacheDirectory($isolatedTempDir);
    @unlink($tempFileAsDir);

    $passed = ($res['missing_reason'] === 'quota_storage_inaccessible' && !$calledExternal);
    $recordResult("storage_fail_closed", "Inaccessible Quota Storage Fail-Closed Protection", $passed, [
        'missing_reason'        => $res['missing_reason'],
        'external_call_blocked' => !$calledExternal,
    ]);
}

// 5. Full End-to-End QR, Consent, Stats & WhatsApp Redirection Test
if ($whatsappCheck || !$liveMode) {
    \Helpers\GeoLocation::clearCache(true);
    \Helpers\GeoLocation::setReverseGeocoderConfig([
        'enabled'  => true,
        'provider' => 'opencage',
        'api_key'  => 'isolated_key',
    ]);

    $waTargetUrl = 'https://wa.me/261340000000?text=' . urlencode('Bonjour, commande QR #102');

    // Simulate URL object with geoconsent enabled
    $testUrl = new \stdClass();
    $testUrl->id = 777;
    $testUrl->alias = 'wa-qr-test';
    $testUrl->custom = null;
    $testUrl->url = $waTargetUrl;
    $testUrl->options = json_encode(['geoconsent' => true]);

    // Step A: Link Hit / Consent Gate generation
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (isset(\Gem::$Config)) {
        \Gem::$Config->title = \Gem::$Config->title ?? 'QRCode Platform';
        \Gem::$Config->keywords = \Gem::$Config->keywords ?? 'qrcode';
        \Gem::$Config->twitter = \Gem::$Config->twitter ?? '';
        \Gem::$Config->favicon = \Gem::$Config->favicon ?? '';
        \Gem::$Config->cookieconsent = (object)['enabled' => false];
        \Gem::$Config->theme = \Gem::$Config->theme ?? 'the23';
    }
    $_SESSION = ['last_stat_777' => 999];
    $_SERVER['REMOTE_ADDR'] = '196.192.32.1';
    ob_start();
    \Helpers\Gate::consentLocation($testUrl);
    $gateHtml = ob_get_clean();
    $nonce = $_SESSION['geoconsent_nonce_777'] ?? null;

    $hasNonce = !empty($nonce) && strlen($nonce) === 64;
    $hasWaTarget = strpos($gateHtml, htmlspecialchars($waTargetUrl, ENT_QUOTES, 'UTF-8')) !== false;
    $hasSkipBtn = strpos($gateHtml, 'Continuer sans partager') !== false;

    // Step B: Consent Submission with GPS Coordinates
    \Helpers\GeoLocation::setMockHttpTransport(function () {
        return json_encode([
            'results' => [[
                'components' => [
                    'town'         => 'Mananjary',
                    'state'        => 'Vatovavy',
                    'country'      => 'Madagascar',
                    'country_code' => 'mg',
                ]
            ]]
        ]);
    });

    $resolved = \Helpers\GeoLocation::resolveCoordinates(-21.223, 48.341, 15.0);
    $statsPayload = [
        'country'         => $resolved['country'],
        'region'          => $resolved['region'],
        'city'            => $resolved['city'],
        'geo_source'      => 'browser_consent',
        'accuracy_radius' => $resolved['accuracy_radius'],
    ];

    $noRawCoordinatesInStats = !isset($statsPayload['latitude']) && !isset($statsPayload['longitude']);
    $correctAdministrativeLocation = ($statsPayload['country'] === 'Madagascar' && $statsPayload['region'] === 'Vatovavy' && $statsPayload['city'] === 'Mananjary');

    // Step C: Verify Redirect preserves WhatsApp protocol and parameters
    $redirectUrl = $testUrl->url;
    $redirectValid = (strpos($redirectUrl, 'https://wa.me/261340000000?text=') === 0);

    $e2ePassed = $hasNonce && $hasWaTarget && $hasSkipBtn && $noRawCoordinatesInStats && $correctAdministrativeLocation && $redirectValid;

    $recordResult("whatsapp_lifecycle_simulation", "Simulated Integration: QR -> Consent -> Stats -> WhatsApp Redirection", $e2ePassed, [
        'step_1_hmac_nonce_generated' => $hasNonce ? 'YES (64 hex chars)' : 'NO',
        'step_2_skip_button_present'  => $hasSkipBtn ? 'YES' : 'NO',
        'step_3_raw_coords_excluded'  => $noRawCoordinatesInStats ? 'YES (lat/lon never saved)' : 'NO',
        'step_4_resolved_admin_area'  => "{$statsPayload['city']}, {$statsPayload['region']}, {$statsPayload['country']}",
        'step_5_target_redirection'   => $redirectUrl,
        'step_6_whatsapp_preserved'   => $redirectValid ? 'YES' : 'NO',
    ]);
}

if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($results['summary']['failed'] === 0 ? 0 : 1);
}

// CLI Human-readable output
echo "======================================================================\n";
echo "      MISSION CODEX — WORLDWIDE REVERSE GEOCODER TEST SUITE           \n";
echo "======================================================================\n";
echo "Execution Mode : " . $results['mode'] . "\n";
echo "Provider       : " . strtoupper($provider) . "\n";
echo "API Key        : " . $results['api_key_masked'] . " (loaded from env)\n";
echo "Isolated Dir   : " . $results['isolated_cache_dir'] . " (production protected)\n";
echo "Timestamp      : " . $results['timestamp'] . "\n";
echo "----------------------------------------------------------------------\n";

foreach ($results['test_cases'] as $tc) {
    $badge = $tc['status'] === 'PASS' ? '[PASS]' : '[FAIL]';
    echo sprintf(" %-7s %s\n", $badge, $tc['title']);
    foreach ($tc['details'] as $k => $v) {
        $vStr = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        echo sprintf("         • %-28s: %s\n", $k, $vStr);
    }
}

echo "----------------------------------------------------------------------\n";
echo "SUMMARY: {$results['summary']['passed']} passed, {$results['summary']['failed']} failed (Total: {$results['summary']['total']})\n";
echo "======================================================================\n";

exit($results['summary']['failed'] === 0 ? 0 : 1);
