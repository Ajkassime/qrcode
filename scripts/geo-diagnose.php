<?php
/**
 * CLI GeoIP & Reverse Geocoding Diagnostic Tool
 *
 * Diagnoses IPv4/IPv6 geolocation resolution, MaxMind GeoLite2-City health,
 * and administrative polygon / reverse geocoding resolution without logging PII.
 *
 * Usage:
 *   php scripts/geo-diagnose.php [IP] [--json]
 *   php scripts/geo-diagnose.php --coords=-21.223,48.341,25 [--json]
 */

require_once __DIR__ . '/cli-bootstrap.php';

$args = array_slice($argv, 1);
$jsonOutput = in_array('--json', $args, true);
$targetIp = null;
$coordsArg = null;

foreach ($args as $arg) {
    if ($arg === '--json') {
        continue;
    }
    if (strpos($arg, '--coords=') === 0) {
        $coordsArg = substr($arg, 9);
        continue;
    }
    if (!empty($arg) && $targetIp === null) {
        $targetIp = trim($arg);
    }
}

if ($coordsArg !== null) {
    $parts = array_map('trim', explode(',', $coordsArg));
    $lat = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : NAN;
    $lon = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : NAN;
    $acc = (isset($parts[2]) && $parts[2] !== '' && is_numeric($parts[2])) ? (float) $parts[2] : null;

    $resolved = \Helpers\GeoLocation::resolveCoordinates($lat, $lon, $acc);
    $formatted = \Helpers\GeoLocation::formatLocationName($resolved['city'], $resolved['region'], $resolved['country']);

    $payload = [
        'mode'            => 'coordinates',
        'input'           => ['latitude' => $lat, 'longitude' => $lon, 'declared_accuracy_meters' => $acc],
        'resolution'      => $resolved,
        'formatted_label' => $formatted,
    ];

    if ($jsonOutput) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    echo "======================================================================\n";
    echo "       MISSION CODEX — COORDINATE GEOCODING DIAGNOSTIC                \n";
    echo "======================================================================\n";
    echo "Coordinates     : lat={$lat}, lon={$lon}\n";
    echo "Declared Acc.   : " . ($resolved['declared_accuracy_meters'] !== null ? "{$resolved['declared_accuracy_meters']} m ({$resolved['accuracy_radius']} km)" : 'None (Unspecified)') . "\n";
    echo "Data Source     : {$resolved['source']} (provider: {$resolved['provider']})\n";
    echo "Continent       : " . ($resolved['continent'] ? "{$resolved['continent']} [{$resolved['continent_code']}]" : 'None') . "\n";
    echo "Country         : " . ($resolved['country'] ? "{$resolved['country']} [{$resolved['country_code']}]" : 'None') . "\n";
    echo "Region          : " . ($resolved['region'] ? "{$resolved['region']} [" . ($resolved['region_code'] ?: 'N/A') . "]" : 'None') . "\n";
    echo "City            : " . ($resolved['city'] ?: 'None (Undetermined)') . "\n";
    echo "Location Level  : " . strtoupper($resolved['location_level'] ?? $resolved['precision']) . "\n";
    echo "Verified        : " . ($resolved['verified'] ? 'YES' : 'NO (Browser-declared)') . "\n";
    echo "Missing Reason  : " . ($resolved['missing_reason'] ?: 'None') . "\n";
    echo "Display Label   : {$formatted}\n";
    echo "======================================================================\n";
    exit(0);
}

$isDefaultIp = false;
if ($targetIp === null) {
    $targetIp = '196.192.32.1';
    $isDefaultIp = true;
}

if (substr($targetIp, 0, 7) === '::ffff:') {
    $targetIp = substr($targetIp, 7);
}

$dbInfo = \Helpers\GeoLocation::getDatabaseInfo();
$loc = \Helpers\GeoLocation::locate($targetIp, ['bypass_cache' => true]);

$ipStatus = 'invalid';
$ipVersion = null;
if (filter_var($targetIp, FILTER_VALIDATE_IP)) {
    $ipVersion = filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4';
    if (\Helpers\GeoLocation::isPublicIp($targetIp)) {
        $ipStatus = 'public';
    } elseif ($targetIp === '127.0.0.1' || $targetIp === '::1') {
        $ipStatus = 'loopback';
    } else {
        $ipStatus = 'private_or_reserved';
    }
}

$diagnosis = [
    'tested_ip'           => $targetIp,
    'is_demo_default'     => $isDefaultIp,
    'source'              => $isDefaultIp ? 'default_demo' : 'cli_argument',
    'ip_status'           => $ipStatus,
    'ip_version'          => $ipVersion,
    'database'            => $dbInfo,
    'resolution'          => [
        'resolved'        => !empty($loc['country']),
        'continent_code'  => $loc['continent_code'],
        'continent_name'  => $loc['continent'],
        'country_code'    => $loc['country_code'],
        'country_name'    => $loc['country'],
        'region_code'     => $loc['region_code'],
        'region_name'     => $loc['region'],
        'city_name'       => $loc['city'],
        'latitude'        => $loc['latitude'],
        'longitude'       => $loc['longitude'],
        'accuracy_radius' => $loc['accuracy_radius'],
        'precision_level' => $loc['precision'],
    ],
    'formatted_label'     => \Helpers\GeoLocation::formatLocationName($loc['city'], $loc['region'], $loc['country']),
    'city_missing_reason' => $loc['missing_reason'],
];

if ($jsonOutput) {
    echo json_encode($diagnosis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "======================================================================\n";
echo "           MISSION CODEX — GEOIP DIAGNOSTIC REPORT                    \n";
echo "======================================================================\n\n";

echo "1. IP INSPECTION:\n";
echo "   - Tested IP         : {$diagnosis['tested_ip']}" . ($isDefaultIp ? " (default test sample)" : "") . "\n";
echo "   - IP Status         : " . strtoupper($diagnosis['ip_status']) . "\n";
echo "   - IP Version        : " . ($diagnosis['ip_version'] ?: 'N/A') . "\n";
echo "   - Source            : {$diagnosis['source']}\n\n";

echo "2. MAXMIND DATABASE STATUS:\n";
echo "   - File Path         : {$diagnosis['database']['path']}\n";
echo "   - Exists / Readable : " . ($diagnosis['database']['exists'] ? 'YES' : 'NO') . " / " . ($diagnosis['database']['readable'] ? 'YES' : 'NO') . "\n";
echo "   - File Size         : " . number_format($diagnosis['database']['size_bytes'] / (1024 * 1024), 2) . " MB\n";
echo "   - Build Date        : " . ($diagnosis['database']['build_date'] ?: 'N/A') . "\n\n";

echo "3. GEOGRAPHIC RESOLUTION:\n";
echo "   - Resolved          : " . ($diagnosis['resolution']['resolved'] ? 'YES' : 'NO') . "\n";
echo "   - Continent         : " . ($diagnosis['resolution']['continent_name'] ? "{$diagnosis['resolution']['continent_name']} [{$diagnosis['resolution']['continent_code']}]" : 'None') . "\n";
echo "   - Country           : " . ($diagnosis['resolution']['country_name'] ? "{$diagnosis['resolution']['country_name']} [{$diagnosis['resolution']['country_code']}]" : 'None') . "\n";
echo "   - Region            : " . ($diagnosis['resolution']['region_name'] ? "{$diagnosis['resolution']['region_name']} [" . ($diagnosis['resolution']['region_code'] ?: 'N/A') . "]" : 'None') . "\n";
echo "   - City              : " . ($diagnosis['resolution']['city_name'] ?: 'None (Undetermined)') . "\n";
echo "   - Accuracy Radius   : " . ($diagnosis['resolution']['accuracy_radius'] !== null ? "{$diagnosis['resolution']['accuracy_radius']} km" : 'N/A') . "\n";
echo "   - Approx. Coords    : " . ($diagnosis['resolution']['latitude'] !== null ? "lat={$diagnosis['resolution']['latitude']}, lon={$diagnosis['resolution']['longitude']}" : 'N/A') . "\n";
echo "   - Precision Level   : " . strtoupper($diagnosis['resolution']['precision_level']) . "\n";
echo "   - Display Label     : {$diagnosis['formatted_label']}\n\n";

echo "4. DIAGNOSTIC REASON:\n";
if ($diagnosis['city_missing_reason'] === null) {
    echo "   [OK] City identified with precision: '{$diagnosis['resolution']['city_name']}'\n";
} else {
    echo "   [INFO] City undetermined. Reason: " . strtoupper($diagnosis['city_missing_reason']) . "\n";
}
echo "======================================================================\n";
exit(0);
