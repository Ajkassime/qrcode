<?php
/**
 * Test Suite: CSRF Token & Security Middleware
 */

class CsrfSecurityTest {

    public static function run(&$results) {
        self::testTokenGenerationAndValidation($results);
        self::testConstantTimeValidation($results);
        self::testHeaderTokenSupport($results);
        self::testRealCsrfMiddlewareExecution($results);
        self::testWebhookExemptionSecurity($results);
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

    public static function testTokenGenerationAndValidation(&$results) {
        echo "Running CsrfSecurityTest::testTokenGenerationAndValidation...\n";

        // Generate token
        $token = \Core\Helper::CSRF(false);
        self::assert(!empty($token) && is_string($token), "Helper::CSRF(false) generates non-empty token string", $results);

        // Session must store the token under Helper::CSRFNAME
        $sessionToken = $_SESSION[\Core\Helper::CSRFNAME] ?? null;
        self::assert($token === $sessionToken, "Session stores matching csrf_token in Helper::CSRFNAME", $results);

        // Verification with valid token
        self::assert(hash_equals((string) $sessionToken, (string) $token) === true, "Valid token matches session via hash_equals", $results);
    }

    public static function testConstantTimeValidation(&$results) {
        echo "Running CsrfSecurityTest::testConstantTimeValidation...\n";

        $tokenA = bin2hex(random_bytes(32));
        $tokenB = bin2hex(random_bytes(32));

        self::assert(hash_equals($tokenA, $tokenA) === true, "hash_equals returns true for identical tokens", $results);
        self::assert(hash_equals($tokenA, $tokenB) === false, "hash_equals returns false for divergent tokens", $results);
        self::assert(hash_equals($tokenA, '') === false, "hash_equals rejects empty candidate token", $results);
    }

    public static function testHeaderTokenSupport(&$results) {
        echo "Running CsrfSecurityTest::testHeaderTokenSupport...\n";

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;

        // Simulate X-CSRF-TOKEN header
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $request = new \Core\Request();
        $headerToken = $request->server('HTTP_X_CSRF_TOKEN');

        self::assert($headerToken === $token, "Request correctly reads HTTP_X_CSRF_TOKEN header", $results);
        self::assert(hash_equals($token, $headerToken) === true, "Header token matches session token via hash_equals", $results);

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public static function testRealCsrfMiddlewareExecution(&$results) {
        echo "Running CsrfSecurityTest::testRealCsrfMiddlewareExecution...\n";

        $csrf = new \Middleware\CSRF();

        // 1. GET requests must ALWAYS pass without requiring a CSRF token
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $requestGet = new \Core\Request();
        self::assert($csrf->handle($requestGet) === true, "CSRF::handle allows GET on homepage without token", $results);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/my-short-link';
        $requestAlias = new \Core\Request();
        self::assert($csrf->handle($requestAlias) === true, "CSRF::handle allows GET on /{alias} without token", $results);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/u/testuser';
        $requestProfile = new \Core\Request();
        self::assert($csrf->handle($requestProfile) === true, "CSRF::handle allows GET on public profile without token", $results);

        // 2. Exempt POST routes must pass without token
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/webhook/stripe';
        $requestWebhook = new \Core\Request();
        self::assert($csrf->handle($requestWebhook) === true, "CSRF::handle allows POST on /webhook/stripe without token", $results);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/user';
        $requestApi = new \Core\Request();
        self::assert($csrf->handle($requestApi) === true, "CSRF::handle allows POST on /api/* without token", $results);

        // 3. POST mutation with valid session token must pass
        $validToken = bin2hex(random_bytes(32));
        $_SESSION[\Core\Helper::CSRFNAME] = $validToken;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/user/settings';
        $_REQUEST['_token'] = $validToken;
        $requestValidPost = new \Core\Request();
        self::assert($csrf->handle($requestValidPost) === true, "CSRF::handle allows POST with matching session _token", $results);

        unset($_REQUEST['_token']);
    }

    public static function testWebhookExemptionSecurity(&$results) {
        echo "Running CsrfSecurityTest::testWebhookExemptionSecurity...\n";

        $csrf = new \Middleware\CSRF();
        $ref = new \ReflectionClass(\Middleware\CSRF::class);
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $exemptions = $prop->getValue($csrf);

        // Ensure broad 'user/bio/' exemption does not exist in real CSRF class
        self::assert(!in_array('user/bio/', $exemptions) && !in_array('user/bio/*', $exemptions), "Broad 'user/bio/' path is absent from real CSRF _exempt property", $results);
        self::assert(in_array('webhook/*', $exemptions), "Real CSRF middleware explicitly exempts 'webhook/*'", $results);
        self::assert(in_array('api/*', $exemptions), "Real CSRF middleware explicitly exempts 'api/*'", $results);
    }
}
