<?php
/**
 * Standalone Test Runner
 *
 * Runs all unit and integration test suites without requiring external dependencies.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/QrGenerationTest.php';
require_once __DIR__ . '/CsrfSecurityTest.php';
require_once __DIR__ . '/UploadSecurityTest.php';
require_once __DIR__ . '/RoutingMiddlewareTest.php';

$results = [
    'passed' => 0,
    'failed' => 0,
];

echo "========================================================\n";
echo "   MISSION CODEX - AUTOMATED TEST SUITE RUNNER         \n";
echo "========================================================\n\n";

QrGenerationTest::run($results);
echo "\n";
CsrfSecurityTest::run($results);
echo "\n";
UploadSecurityTest::run($results);
echo "\n";
RoutingMiddlewareTest::run($results);
echo "\n";

$total = $results['passed'] + $results['failed'];
echo "========================================================\n";
echo "TEST RESULTS SUMMARY:\n";
echo "  Total assertions : {$total}\n";
echo "  Passed           : {$results['passed']}\n";
echo "  Failed           : {$results['failed']}\n";
echo "========================================================\n";

if ($results['failed'] > 0) {
    echo "FAILED: Some tests did not pass.\n";
    exit(1);
} else {
    echo "SUCCESS: All tests passed cleanly!\n";
    exit(0);
}
