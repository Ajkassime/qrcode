<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel
 * ---------------------------------------------------------------------------------------
 *  Standalone CLI Bootstrap (Production & Maintenance Scripts)
 *
 *  IMPORTANT: This bootstrap is strictly independent of the test runner and never
 *  defines a test state. It loads the real application configuration and database
 *  layer for CLI maintenance, diagnostic, and migration scripts.
 * =======================================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
if (!defined('APP')) {
    define('APP', ROOT . '/app');
}
if (!defined('CORE')) {
    define('CORE', ROOT . '/core');
}
if (!defined('STORAGE')) {
    define('STORAGE', ROOT . '/storage');
}
if (!defined('PUB')) {
    define('PUB', ROOT . '/public');
}
if (!defined('CONTROLLER')) {
    define('CONTROLLER', APP . '/controllers');
}
if (!defined('MODELS')) {
    define('MODELS', APP . '/models');
}
if (!defined('MIDDLEWARE')) {
    define('MIDDLEWARE', APP . '/middleware');
}
if (!defined('LIBRARY')) {
    define('LIBRARY', ROOT . '/vendor');
}
if (!defined('UPLOADS')) {
    define('UPLOADS', PUB . '/content');
}
if (!defined('LOGS')) {
    define('LOGS', STORAGE . '/logs');
}
if (!defined('LOCALE')) {
    define('LOCALE', STORAGE . '/languages');
}
if (!defined('PLUGIN')) {
    define('PLUGIN', STORAGE . '/plugins');
}
if (!defined('PLUGINS')) {
    define('PLUGINS', STORAGE . '/plugins');
}
if (!defined('THEMES')) {
    define('THEMES', STORAGE . '/themes');
}
if (!defined('VIEW')) {
    define('VIEW', STORAGE . '/themes/default');
}

require_once ROOT . '/vendor/autoload.php';

// Load real config.php if it exists and no explicit CLI_DB_DSN override is provided
$cliDbDsn = getenv('CLI_DB_DSN');
if (empty($cliDbDsn) && file_exists(ROOT . '/config.php')) {
    include_once ROOT . '/config.php';
}

if (!defined('DEBUG')) {
    define('DEBUG', 0);
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
if (!defined('AuthToken')) {
    define('AuthToken', 'cli_auth_token_default_fallback_123456');
}
if (!defined('EncryptionToken')) {
    define('EncryptionToken', 'cli_encryption_token_default_fallback_123456');
}
if (!defined('PublicToken')) {
    define('PublicToken', 'cli_public_token_default_fallback_123456');
}
if (!defined('DBprefix')) {
    define('DBprefix', '');
}

require_once CORE . '/functions/core.php';
require_once CORE . '/GemError.class.php';
require_once CORE . '/Gem.class.php';
require_once CORE . '/Helper.class.php';
require_once CORE . '/Request.class.php';
require_once CORE . '/Response.class.php';
require_once CORE . '/DB.class.php';
require_once APP . '/helpers/App.php';
require_once APP . '/helpers/GeoLocation.php';

if (!isset(\Gem::$Config) || !is_object(\Gem::$Config)) {
    \Gem::$Config = new \stdClass();
    \Gem::$Config->url = defined('URL') ? URL : 'http://localhost';
}

if (!function_exists('e')) {
    function e($text, $plural = null, $parameters = []) {
        if (!empty($parameters) && is_array($parameters)) {
            foreach ($parameters as $k => $v) {
                $text = str_replace('{' . $k . '}', (string) $v, $text);
            }
        }
        return $text;
    }
}

/**
 * Establish a verified database connection for CLI scripts.
 * Refuses to simulate success when the database is unavailable or unconfigured.
 *
 * @return \PDO
 * @throws \RuntimeException
 */
function cliConnectDatabase(): \PDO {
    $cliDsn = getenv('CLI_DB_DSN');
    $cliUser = getenv('CLI_DB_USER') !== false ? getenv('CLI_DB_USER') : null;
    $cliPass = getenv('CLI_DB_PASS') !== false ? getenv('CLI_DB_PASS') : null;
    $cliPrefix = getenv('CLI_DB_PREFIX') !== false ? getenv('CLI_DB_PREFIX') : (defined('DBprefix') ? DBprefix : '');

    if (!empty($cliDsn)) {
        if (!defined('DBprefix')) {
            define('DBprefix', $cliPrefix);
        }
        \Core\DB::configure($cliDsn);
        if ($cliUser !== null) {
            \Core\DB::configure('username', $cliUser);
        }
        if ($cliPass !== null) {
            \Core\DB::configure('password', $cliPass);
        }
        \Core\DB::configure('return_result_sets', true);
        \Core\DB::configure('error_mode', \PDO::ERRMODE_EXCEPTION);
    } else {
        if (!defined('DBhost') || empty(constant('DBhost')) || !defined('DBname') || empty(constant('DBname'))) {
            throw new \RuntimeException(
                "Database configuration missing (DBhost / DBname not defined in config.php and CLI_DB_DSN not set)."
            );
        }
        \Core\DB::Connect();
    }

    $pdo = \Core\DB::get_db();
    if (!$pdo instanceof \PDO) {
        throw new \RuntimeException("Failed to initialize PDO database connection.");
    }

    // Verify active connection
    $pdo->query("SELECT 1");

    return $pdo;
}

/**
 * Retrieve column names for a given table on MySQL/MariaDB or SQLite
 *
 * @param \PDO $pdo
 * @param string $table
 * @return array
 */
function cliGetTableColumns(\PDO $pdo, string $table): array {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_column($rows, 'name');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    return array_column($rows, 'Field');
}
