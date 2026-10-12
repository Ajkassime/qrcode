<?php
/**
 * Test Suite Bootstrap
 */

define("_VERSION", "1.1");
define("_INAPP", true);
define("_STATE", "TEST");

define("ROOT", dirname(__DIR__));
define("APP", ROOT . '/app');
define("PUB", ROOT . "/public");
define("CORE", ROOT . "/core");

define("CONTROLLER", APP . "/controllers");
define("MODELS", APP . "/models");
define("MIDDLEWARE", APP . "/middleware");
define("LIBRARY", ROOT . "/vendor");
define("UPLOADS", PUB . "/content");
define("STORAGE", ROOT . "/storage");
define("LOGS", STORAGE . "/logs");
define("LOCALE", STORAGE . "/languages");
define("PLUGIN", STORAGE . "/plugins");
if (!defined('AuthToken')) {
    define('AuthToken', 'test_auth_token_for_tests_123456');
}
if (!defined('EncryptionToken')) {
    define('EncryptionToken', 'test_encryption_token_for_tests_123456');
}
if (!defined('USECDN')) {
    define('USECDN', false);
}
if (!defined('CDNASSETS')) {
    define('CDNASSETS', null);
}
if (!defined('CDNUPLOADS')) {
    define('CDNUPLOADS', null);
}

require_once CORE . "/functions/core.php";
require_once CORE . "/GemError.class.php";
require_once LIBRARY . "/autoload.php";
require_once CORE . "/Gem.class.php";

if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Gem::$App['appConfig']['boot'] = [];

// Initialize minimal test configuration without requiring MySQL
if (!isset(Gem::$Config) || !is_object(Gem::$Config)) {
    Gem::$Config = new \stdClass();
    Gem::$Config->url = "http://localhost";
    Gem::$Config->security = "test-secret-key-32-chars-long-123456";
    Gem::$Config->publicqr = true;
    Gem::$Config->pro = true;
    Gem::$Config->api = true;
    Gem::$Config->theme = "the23";
    Gem::$Config->qrlogo = "";
}

// Load routes
if (file_exists(APP . "/routes.php")) {
    require_once APP . "/routes.php";
}
