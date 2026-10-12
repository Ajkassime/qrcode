<?php
/**
 * CLI GeoIP Diagnostic Tool
 *
 * Diagnoses IP address geolocation resolution and MaxMind GeoLite2-City health
 * without logging the tested IP address.
 *
 * Usage:
 *   php scripts/geo-diagnose.php [IP] [--json]
 */

define("ROOT", dirname(__DIR__));
define("STORAGE", ROOT . "/storage");
define("APP", ROOT . "/app");
define("CORE", ROOT . "/core");

require_once ROOT . "/vendor/autoload.php";

$args = array_slice($argv, 1);
$jsonOutput = in_array('--json', $args);
$targetIp = null;

foreach ($args as $arg) {
    if ($arg !== '--json' && !empty($arg)) {
        $targetIp = trim($arg);
        break;
    }
}

// Default demonstration IP if none provided (Madagascar test IP)
$isDefaultIp = false;
if ($targetIp === null) {
    $targetIp = '196.192.32.1'; // Madagascar Telecom/Blueline allocation
    $isDefaultIp = true;
}

$diagnosis = [
    'tested_ip'           => $targetIp,
    'is_demo_default'     => $isDefaultIp,
    'source'              => $isDefaultIp ? 'default_demo' : 'cli_argument',
    'ip_status'           => 'invalid',
    'ip_version'          => null,
    'database'            => [
        'path'       => STORAGE . '/app/GeoLite2-City.mmdb',
        'exists'     => false,
        'readable'   => false,
        'size_bytes' => 0,
        'build_epoch'=> null,
        'build_date' => null,
    ],
    'resolution'          => [
        'resolved'        => false,
        'country_code'    => null,
        'country_name'    => null,
        'region_code'     => null,
        'region_name'     => null,
        'city_name'       => null,
        'latitude'        => null,
        'longitude'       => null,
        'accuracy_radius' => null,
        'precision_level' => 'unknown',
    ],
    'city_missing_reason' => null,
    'technical_error'     => null,
];

// 1. IP Validation & Classification
if (substr($targetIp, 0, 7) === '::ffff:') {
    $targetIp = substr($targetIp, 7);
    $diagnosis['tested_ip'] = $targetIp;
}

if (!filter_var($targetIp, FILTER_VALIDATE_IP)) {
    $diagnosis['ip_status'] = 'invalid';
    $diagnosis['city_missing_reason'] = 'invalid_ip';
} else {
    $diagnosis['ip_version'] = filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4';

    $isPublic = filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($isPublic === false) {
        if ($targetIp === '127.0.0.1' || $targetIp === '::1') {
            $diagnosis['ip_status'] = 'loopback';
        } else {
            $diagnosis['ip_status'] = 'private_or_reserved';
        }
        $diagnosis['city_missing_reason'] = 'private_or_reserved_ip';
    } else {
        $diagnosis['ip_status'] = 'public';
    }
}

// 2. Database Health
$dbPath = $diagnosis['database']['path'];
if (file_exists($dbPath)) {
    $diagnosis['database']['exists'] = true;
    $diagnosis['database']['readable'] = is_readable($dbPath);
    $diagnosis['database']['size_bytes'] = filesize($dbPath);

    try {
        $reader = new \MaxMind\Db\Reader($dbPath);
        $meta = $reader->metadata();
        $diagnosis['database']['build_epoch'] = $meta->buildEpoch;
        $diagnosis['database']['build_date'] = date('Y-m-d H:i:s T', $meta->buildEpoch);

        // 3. Resolution if IP is valid and public
        if ($diagnosis['ip_status'] === 'public') {
            $record = $reader->get($targetIp);

            if ($record !== null) {
                $diagnosis['resolution']['resolved'] = true;
                $diagnosis['resolution']['country_code'] = $record['country']['iso_code'] ?? ($record['registered_country']['iso_code'] ?? null);
                $countryName = $record['country']['names']['en'] ?? ($record['registered_country']['names']['en'] ?? null);
                if ($countryName === 'Türkiye') {
                    $countryName = 'Turkey';
                }
                $diagnosis['resolution']['country_name'] = $countryName;

                if (!empty($record['subdivisions'])) {
                    $diagnosis['resolution']['region_code'] = $record['subdivisions'][0]['iso_code'] ?? null;
                    $diagnosis['resolution']['region_name'] = $record['subdivisions'][0]['names']['en'] ?? null;
                }

                $diagnosis['resolution']['city_name'] = $record['city']['names']['en'] ?? null;
                $diagnosis['resolution']['latitude'] = $record['location']['latitude'] ?? null;
                $diagnosis['resolution']['longitude'] = $record['location']['longitude'] ?? null;
                $diagnosis['resolution']['accuracy_radius'] = $record['location']['accuracy_radius'] ?? null;

                // Determine precision & missing reason
                if (!empty($diagnosis['resolution']['city_name'])) {
                    $diagnosis['resolution']['precision_level'] = 'city';
                    $diagnosis['city_missing_reason'] = null;
                } elseif (!empty($diagnosis['resolution']['region_name'])) {
                    $diagnosis['resolution']['precision_level'] = 'region';
                    $diagnosis['city_missing_reason'] = 'region_level_only';
                } elseif (!empty($diagnosis['resolution']['country_name'])) {
                    $diagnosis['resolution']['precision_level'] = 'country';
                    $diagnosis['city_missing_reason'] = 'country_level_only';
                } else {
                    $diagnosis['resolution']['precision_level'] = 'unknown';
                    $diagnosis['city_missing_reason'] = 'insufficient_geographic_data';
                }
            } else {
                $diagnosis['city_missing_reason'] = 'insufficient_geographic_data';
            }
        }
        $reader->close();
    } catch (\Exception $e) {
        $diagnosis['technical_error'] = $e->getMessage();
        $diagnosis['city_missing_reason'] = 'technical_error';
    }
} else {
    $diagnosis['city_missing_reason'] = 'database_missing';
}

if ($jsonOutput) {
    echo json_encode($diagnosis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Human-readable CLI formatting
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
echo "   - Country           : " . ($diagnosis['resolution']['country_name'] ? "{$diagnosis['resolution']['country_name']} [{$diagnosis['resolution']['country_code']}]" : 'None') . "\n";
echo "   - Region            : " . ($diagnosis['resolution']['region_name'] ? "{$diagnosis['resolution']['region_name']} [{$diagnosis['resolution']['region_code']}]" : 'None') . "\n";
echo "   - City              : " . ($diagnosis['resolution']['city_name'] ?: 'None (Undetermined)') . "\n";
echo "   - Accuracy Radius   : " . ($diagnosis['resolution']['accuracy_radius'] !== null ? "{$diagnosis['resolution']['accuracy_radius']} km" : 'N/A') . "\n";
echo "   - Approx. Coords    : " . ($diagnosis['resolution']['latitude'] !== null ? "lat={$diagnosis['resolution']['latitude']}, lon={$diagnosis['resolution']['longitude']}" : 'N/A') . "\n";
echo "   - Precision Level   : " . strtoupper($diagnosis['resolution']['precision_level']) . "\n\n";

echo "4. DIAGNOSTIC REASON:\n";
if ($diagnosis['city_missing_reason'] === null) {
    echo "   [OK] City identified with precision: '{$diagnosis['resolution']['city_name']}'\n";
} else {
    echo "   [INFO] City undetermined. Reason: " . strtoupper($diagnosis['city_missing_reason']) . "\n";
    switch ($diagnosis['city_missing_reason']) {
        case 'country_level_only':
            echo "   Explanation: The ISP/carrier IP block is registered for {$diagnosis['resolution']['country_name']},\n";
            echo "   but MaxMind does not have specific city/regional POP mappings for this subnet (typical for mobile networks).\n";
            break;
        case 'region_level_only':
            echo "   Explanation: The region is known ({$diagnosis['resolution']['region_name']}), but city-level resolution is absent.\n";
            break;
        case 'private_or_reserved_ip':
            echo "   Explanation: Private or loopback IP addresses cannot be geolocated on the public internet.\n";
            break;
        case 'database_missing':
            echo "   Explanation: GeoLite2-City.mmdb is missing from storage/app/.\n";
            break;
        case 'technical_error':
            echo "   Explanation: MaxMind reader encountered an error: {$diagnosis['technical_error']}\n";
            break;
        default:
            echo "   Explanation: No city-level data could be found for this IP in the current database.\n";
            break;
    }
}
echo "======================================================================\n";
exit(0);
