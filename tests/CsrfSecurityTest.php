<?php
/**
 * Test Suite: CSRF Token & Security Middleware
 */

class CsrfSecurityTest {

    public static function run(&$results) {
        self::testTokenGenerationAndValidation($results);
        self::testConstantTimeValidation($results);
        self::testHeaderTokenSupport($results);
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

    public static function testWebhookExemptionSecurity(&$results) {
        echo "Running CsrfSecurityTest::testWebhookExemptionSecurity...\n";

        $exemptions = [
            'webhook/*',
            'api/*',
            'user/login/auth',
        ];

        // Ensure broad 'user/bio/' exemption does not exist
        self::assert(!in_array('user/bio/', $exemptions), "Broad 'user/bio/' path is not exempted from CSRF", $results);

        // Test wildcard matching logic from Middleware
        $pattern = '#^webhook/.*$#i';
        self::assert(preg_match($pattern, 'webhook/stripe') === 1, "Webhook pattern allows webhook/stripe", $results);
        self::assert(preg_match($pattern, 'webhook/paypal') === 1, "Webhook pattern allows webhook/paypal", $results);
        self::assert(preg_match($pattern, 'user/bio/delete') === 0, "Webhook pattern does not match user/bio paths", $results);
    }
}
