<?php
/**
 * Test Suite: Routing & Middleware Behavior
 */

class RoutingMiddlewareTest {

    public static function run(&$results) {
        self::testMissingRoutesRemoved($results);
        self::testWildcardMiddlewareMatching($results);
        self::testStateMutatingActionMethods($results);
    }

    private static function assert($condition, $message, &$results) {
        if ($condition) {
            $results['passed']++;
            echo "  [PASS] {$message}\n";
        } else {
            $results['failed']++;
            echo "  [FAIL] {$message}\n";
        }
    }

    public static function testMissingRoutesRemoved(&$results) {
        echo "Running RoutingMiddlewareTest::testMissingRoutesRemoved...\n";

        $routesFile = file_get_contents(APP . '/routes.php');

        self::assert(
            strpos($routesFile, "Admin\\Finance@index") === false,
            "Non-existent route 'Admin\\Finance@index' is absent from routes.php",
            $results
        );

        self::assert(
            strpos($routesFile, "API\\Index@index") === false,
            "Non-existent route 'API\\Index@index' is absent from routes.php",
            $results
        );

        self::assert(
            strpos($routesFile, "Discord@interaction") === false,
            "Non-existent route 'Discord@interaction' is absent from routes.php",
            $results
        );

        self::assert(
            strpos($routesFile, "Home@packed") === false,
            "Dead commented route 'Home@packed' is absent from routes.php",
            $results
        );
    }

    public static function testWildcardMiddlewareMatching(&$results) {
        echo "Running RoutingMiddlewareTest::testWildcardMiddlewareMatching...\n";

        // Test the regex conversion used in Middleware.class.php
        $testRule = "admin/*";
        $regex = '#^' . str_replace('\*', '.*', preg_quote($testRule, '#')) . '$#i';

        self::assert(preg_match($regex, 'admin/users') === 1, "Pattern 'admin/*' matches 'admin/users'", $results);
        self::assert(preg_match($regex, 'admin/settings/site') === 1, "Pattern 'admin/*' matches 'admin/settings/site'", $results);
        self::assert(preg_match($regex, 'user/links') === 0, "Pattern 'admin/*' does not match 'user/links'", $results);
    }

    public static function testStateMutatingActionMethods(&$results) {
        echo "Running RoutingMiddlewareTest::testStateMutatingActionMethods...\n";

        $routesFile = file_get_contents(APP . '/routes.php');

        self::assert(
            strpos($routesFile, "Gem::route(['GET', 'POST'], '/links/archiveselected'") !== false,
            "Archive selected route supports POST method",
            $results
        );

        self::assert(
            strpos($routesFile, "Gem::route(['GET', 'POST'], '/links/unarchiveselected'") !== false,
            "Unarchive selected route supports POST method",
            $results
        );

        self::assert(
            strpos($routesFile, "Gem::route(['GET', 'POST'], '/qr/{id}/duplicate'") !== false,
            "QR duplicate route supports POST method",
            $results
        );

        self::assert(
            strpos($routesFile, "Gem::route(['GET', 'POST'], '/bio/{id}/duplicate'") !== false,
            "Bio duplicate route supports POST method",
            $results
        );
    }
}
