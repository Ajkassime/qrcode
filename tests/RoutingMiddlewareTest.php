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

        // Build FastRoute dispatcher to test real routing resolution and method enforcement
        $dispatcher = \FastRoute\simpleDispatcher(function(\FastRoute\RouteCollector $r) use ($routes) {
            foreach ($routes as $name => $data) {
                $r->addRoute($data["method"], $data["path"], $name);
            }
        });

        $mutatingActionNames = [
            'links.archive',
            'links.unarchive',
            'links.public',
            'links.private',
            'qr.duplicate',
            'bio.duplicate',
        ];

        foreach ($mutatingActionNames as $name) {
            $route = $routes[$name] ?? null;

            // 1. Must be registered and accept POST
            self::assert(
                $route !== null && in_array('POST', (array) $route['method']),
                "Route '{$name}' is registered and accepts POST method",
                $results
            );

            // 2. Must strictly NOT accept GET
            self::assert(
                $route !== null && !in_array('GET', (array) $route['method']),
                "Route '{$name}' strictly refuses GET method",
                $results
            );

            // 3. Must enforce CSRF middleware
            self::assert(
                $route !== null && in_array('CSRF', (array) ($route['middleware'] ?? [])),
                "Route '{$name}' has CSRF middleware applied",
                $results
            );

            $testPath = str_replace('{id}', '123', $route['path'] ?? '');

            // 4. Dispatching POST must succeed (FOUND)
            $postDispatch = $dispatcher->dispatch('POST', $testPath);
            self::assert(
                $postDispatch[0] === \FastRoute\Dispatcher::FOUND && $postDispatch[1] === $name,
                "Router successfully matches POST {$testPath} to '{$name}'",
                $results
            );

            // 5. Dispatching GET must be rejected with METHOD_NOT_ALLOWED (405)
            $getDispatch = $dispatcher->dispatch('GET', $testPath);
            self::assert(
                $getDispatch[0] === \FastRoute\Dispatcher::METHOD_NOT_ALLOWED,
                "Router rejects GET {$testPath} with METHOD_NOT_ALLOWED (405)",
                $results
            );
        }
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
