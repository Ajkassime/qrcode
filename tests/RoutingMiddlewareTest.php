<?php
/**
 * Test Suite: Routing & Middleware Behavior
 */

class RoutingMiddlewareTest {

    public static function run(&$results) {
        self::testMissingRoutesRemoved($results);
        self::testWildcardMiddlewareMatching($results);
        self::testStateMutatingActionMethods($results);
        self::testNonRegressionCoreRoutes($results);
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

        $routes = \Gem::getRoutes();
        $handlers = array_map(function($r) {
            return is_string($r['handler']) ? $r['handler'] : '';
        }, $routes);

        self::assert(
            !in_array("Admin\\Finance@index", $handlers),
            "Non-existent route 'Admin\\Finance@index' is absent from registered routes",
            $results
        );

        self::assert(
            !in_array("API\\Index@index", $handlers),
            "Non-existent route 'API\\Index@index' is absent from registered routes",
            $results
        );

        self::assert(
            !in_array("Discord@interaction", $handlers),
            "Non-existent route 'Discord@interaction' is absent from registered routes",
            $results
        );

        self::assert(
            !in_array("Home@packed", $handlers),
            "Dead route 'Home@packed' is absent from registered routes",
            $results
        );
    }

    public static function testWildcardMiddlewareMatching(&$results) {
        echo "Running RoutingMiddlewareTest::testWildcardMiddlewareMatching...\n";

        $middleware = new class extends \Core\Middleware {
            public function isExempt(\Core\Request $request) {
                return $this->check($request) === false;
            }
            public function setRules(array $rules) {
                $this->_exempt = $rules;
            }
        };

        $middleware->setRules(['admin/*', 'webhook/*']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/users';
        self::assert($middleware->isExempt(new \Core\Request()) === true, "Pattern 'admin/*' matches real Request /admin/users", $results);

        $_SERVER['REQUEST_URI'] = '/admin/settings/site';
        self::assert($middleware->isExempt(new \Core\Request()) === true, "Pattern 'admin/*' matches real Request /admin/settings/site", $results);

        $_SERVER['REQUEST_URI'] = '/user/links';
        self::assert($middleware->isExempt(new \Core\Request()) === false, "Pattern 'admin/*' does not match real Request /user/links", $results);
    }

    public static function testStateMutatingActionMethods(&$results) {
        echo "Running RoutingMiddlewareTest::testStateMutatingActionMethods...\n";

        $routes = \Gem::getRoutes();

        // 1. Archive selected route
        $archiveRoute = $routes['links.archive'] ?? null;
        self::assert(
            $archiveRoute !== null && in_array('POST', (array) $archiveRoute['method']),
            "Archive selected route is registered and accepts POST method",
            $results
        );
        self::assert(
            $archiveRoute !== null && in_array('CSRF', (array) ($archiveRoute['middleware'] ?? [])),
            "Archive selected route has CSRF middleware applied",
            $results
        );

        // 2. Unarchive selected route
        $unarchiveRoute = $routes['links.unarchive'] ?? null;
        self::assert(
            $unarchiveRoute !== null && in_array('POST', (array) $unarchiveRoute['method']),
            "Unarchive selected route is registered and accepts POST method",
            $results
        );
        self::assert(
            $unarchiveRoute !== null && in_array('CSRF', (array) ($unarchiveRoute['middleware'] ?? [])),
            "Unarchive selected route has CSRF middleware applied",
            $results
        );

        // 3. QR duplicate route
        $qrDupRoute = $routes['qr.duplicate'] ?? null;
        self::assert(
            $qrDupRoute !== null && in_array('POST', (array) $qrDupRoute['method']),
            "QR duplicate route is registered and accepts POST method",
            $results
        );
        self::assert(
            $qrDupRoute !== null && in_array('CSRF', (array) ($qrDupRoute['middleware'] ?? [])),
            "QR duplicate route has CSRF middleware applied",
            $results
        );

        // 4. Bio duplicate route
        $bioDupRoute = $routes['bio.duplicate'] ?? null;
        self::assert(
            $bioDupRoute !== null && in_array('POST', (array) $bioDupRoute['method']),
            "Bio duplicate route is registered and accepts POST method",
            $results
        );
        self::assert(
            $bioDupRoute !== null && in_array('CSRF', (array) ($bioDupRoute['middleware'] ?? [])),
            "Bio duplicate route has CSRF middleware applied",
            $results
        );
    }

    public static function testNonRegressionCoreRoutes(&$results) {
        echo "Running RoutingMiddlewareTest::testNonRegressionCoreRoutes...\n";

        $routes = \Gem::getRoutes();
        $csrf = new \Middleware\CSRF();

        // 1. Homepage route
        $home = $routes['home'] ?? null;
        self::assert($home !== null && in_array('GET', (array) $home['method']), "Homepage route exists and allows GET", $results);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        self::assert($csrf->handle(new \Core\Request()) === true, "GET request to homepage is not blocked by CSRF", $results);

        // 2. Short link redirection route
        $redirect = $routes['redirect'] ?? null;
        self::assert($redirect !== null && in_array('GET', (array) $redirect['method']), "Redirect route /{alias} exists and allows GET", $results);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/sample-alias';
        self::assert($csrf->handle(new \Core\Request()) === true, "GET request to /{alias} is not blocked by CSRF", $results);

        // 3. Single QR download route
        $qrDownload = $routes['qr.download'] ?? null;
        self::assert($qrDownload !== null && $qrDownload['method'] === 'GET', "Single QR download route exists and uses GET", $results);

        // 4. Batch QR download route
        $batchDownload = $routes['qr.downloadall'] ?? null;
        self::assert(
            $batchDownload !== null && $batchDownload['method'] === 'POST' && in_array('CSRF', (array) ($batchDownload['middleware'] ?? [])),
            "Batch QR download route exists, uses POST, and enforces CSRF",
            $results
        );
    }
}
