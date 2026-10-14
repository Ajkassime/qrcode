<?php
/**
 * Test Suite: Phase 1 Modernization (In-App Browser Breakout & Anti-Bot Protection)
 *
 * Verifies Module 1A (InAppBreakout) and Module 1B (BotProtection)
 * ensuring zero regression on existing QR codes, WhatsApp targets, and geocoding.
 */

class Phase1ModernizationTest {

    public static function run(&$results) {
        self::testStandardQrAndWhatsAppPreservation($results);
        self::testInAppBrowserDetectionMatrix($results);
        self::testInAppBreakoutOptionToggleAndBypass($results);
        self::testAndroidChromeIntentGeneration($results);
        self::testBotProtectionClassification($results);
        self::testSocialPreviewOpenGraphRenderingWithoutGeocoding($results);
        self::testMaliciousBotBlockingWithoutGeocoding($results);
        self::testHumanInAppVisitorPreservedForStatsAndGeo($results);
        self::testHistoricalLinksWithoutNewConfig($results);
        self::testSecurityGatesOrderAndConfidentialityPreservation($results);
        self::testJavaScriptBreakoutSerializationAndXssSafety($results);
        self::testSearchEngineBotRespectsNoStatsAndNoGeocoding($results);
        self::testBreakoutParameterNotForwardedToDestinationUrl($results);
        self::testBotProtectionExplicitToggleOption($results);
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

    /**
     * Test 1: Verify standard QR redirect towards HTTPS URL and WhatsApp with prefilled parameters
     */
    public static function testStandardQrAndWhatsAppPreservation(&$results) {
        echo "Running Phase1ModernizationTest::testStandardQrAndWhatsAppPreservation...\n";

        // WhatsApp target with prefilled text message
        $waUrl = 'https://wa.me/261340000000?text=' . urlencode('Bonjour, commande packaging QR #405');
        self::assert(
            \Helpers\InAppBreakout::isSupportedUrl($waUrl),
            "WhatsApp HTTPS URL with prefilled text is recognized as supported URL",
            $results
        );

        self::assert(
            \Helpers\InAppBreakout::isWhatsAppTarget($waUrl),
            "wa.me target is correctly identified by isWhatsAppTarget()",
            $results
        );

        self::assert(
            \Helpers\InAppBreakout::isWhatsAppTarget('whatsapp://send?phone=261340000000'),
            "whatsapp:// native protocol is correctly identified by isWhatsAppTarget()",
            $results
        );

        // Standard HTTPS URL
        $httpsUrl = 'https://example.mg/produits/vanille-madagascar?lot=2026-B&ref=123';
        self::assert(
            \Helpers\InAppBreakout::isSupportedUrl($httpsUrl) && !\Helpers\InAppBreakout::isWhatsAppTarget($httpsUrl),
            "Standard e-commerce URL is supported and not flagged as WhatsApp target",
            $results
        );

        // Unsupported scheme (javascript:, file:)
        self::assert(
            !\Helpers\InAppBreakout::isSupportedUrl('javascript:alert(1)') && !\Helpers\InAppBreakout::isSupportedUrl(''),
            "Dangerous or empty schemes are rejected by isSupportedUrl()",
            $results
        );
    }

    /**
     * Test 2: In-App Browser Detection across all target platforms
     */
    public static function testInAppBrowserDetectionMatrix(&$results) {
        echo "Running Phase1ModernizationTest::testInAppBrowserDetectionMatrix...\n";

        // Standard Desktop Safari -> NOT in-app
        $desktopSafari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        $res = \Helpers\InAppBreakout::detectInApp($desktopSafari);
        self::assert(!$res['isInApp'], "Standard Desktop Safari is NOT detected as in-app browser", $results);

        // Standard Mobile Chrome -> NOT in-app
        $mobileChrome = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';
        $res = \Helpers\InAppBreakout::detectInApp($mobileChrome);
        self::assert(!$res['isInApp'], "Standard Android Chrome Mobile is NOT detected as in-app browser", $results);

        // Instagram iOS -> In-App
        $igIos = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21E219 Instagram 324.0.0.18.109';
        $res = \Helpers\InAppBreakout::detectInApp($igIos);
        self::assert($res['isInApp'] && $res['app'] === 'instagram' && $res['platform'] === 'ios', "Instagram on iOS correctly detected as in-app browser", $results);

        // Facebook Android -> In-App
        $fbAndroid = 'Mozilla/5.0 (Linux; Android 13; SM-A536B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/116.0.5845.163 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/431.0.0.25.114;]';
        $res = \Helpers\InAppBreakout::detectInApp($fbAndroid);
        self::assert($res['isInApp'] && $res['app'] === 'facebook' && $res['platform'] === 'android', "Facebook Android WebView correctly detected as in-app browser", $results);

        // TikTok -> In-App
        $tiktokUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_30.9.0';
        $res = \Helpers\InAppBreakout::detectInApp($tiktokUa);
        self::assert($res['isInApp'] && $res['app'] === 'tiktok', "TikTok in-app browser correctly detected", $results);

        // Messenger -> In-App
        $messengerUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21A329 [FB_IAB/MESSENGER;FBAV/429.0.0.27.114;]';
        $res = \Helpers\InAppBreakout::detectInApp($messengerUa);
        self::assert($res['isInApp'] && $res['app'] === 'messenger', "Messenger in-app browser correctly detected", $results);
    }

    /**
     * Test 3: InAppBreakout option toggle, default disabled, and user bypass
     */
    public static function testInAppBreakoutOptionToggleAndBypass(&$results) {
        echo "Running Phase1ModernizationTest::testInAppBreakoutOptionToggleAndBypass...\n";

        $mockReq = new class {
            public $ua = '';
            public $queryParam = null;
            public function userAgent() { return $this->ua; }
            public function get($k = null, $d = null) { return $k === '_breakout' ? $this->queryParam : null; }
            public function query($q = null) { return $q === '_breakout' ? $this->queryParam : null; }
            public function session($k = null, $v = null) { return false; }
        };

        $igUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21E219 Instagram 324.0.0.18.109';
        $mockReq->ua = $igUa;

        // 1. Link without option (historical link) -> MUST NOT breakout
        $urlHistorical = (object)[
            'id' => 10,
            'url' => 'https://example.mg/produit/10',
            'options' => null
        ];
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlHistorical, $mockReq),
            "Historical link without options defaults to shouldBreakout() = false",
            $results
        );

        // 2. Link with inapp_breakout explicitly enabled -> MUST breakout
        $urlWithBreakout = (object)[
            'id' => 11,
            'url' => 'https://example.mg/produit/11',
            'options' => json_encode(['inapp_breakout' => true])
        ];
        self::assert(
            \Helpers\InAppBreakout::shouldBreakout($urlWithBreakout, $mockReq),
            "Link with inapp_breakout=true triggers shouldBreakout() in Instagram WebView",
            $results
        );

        // 3. User clicks 'Continuer dans cette application' (?_breakout=skip) -> MUST NOT breakout
        $mockReq->queryParam = 'skip';
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlWithBreakout, $mockReq),
            "User choosing 'Continuer ici' (?_breakout=skip) bypasses breakout cleanly",
            $results
        );
        $mockReq->queryParam = null;

        // 4. WhatsApp link opened from ANY in-app browser (Instagram, Facebook, TikTok, WhatsApp) -> MUST NOT breakout
        $urlWaTarget = (object)[
            'id' => 12,
            'url' => 'https://wa.me/261340000000?text=' . urlencode('Bonjour, commande QR #102 & vanille + livraison!'),
            'options' => json_encode(['inapp_breakout' => true])
        ];
        // 4a. From Instagram
        $mockReq->ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/21E219 Instagram 324.0.0.18.109';
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlWaTarget, $mockReq),
            "WhatsApp destination opened inside Instagram does NOT trigger breakout (direct handoff to WhatsApp app)",
            $results
        );
        // 4b. From Facebook
        $mockReq->ua = 'Mozilla/5.0 (Linux; Android 14; SM-S901B) AppleWebKit/537.36 Chrome/122.0.6261.64 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/450.0.0.33.109;]';
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlWaTarget, $mockReq),
            "WhatsApp destination opened inside Facebook does NOT trigger breakout",
            $results
        );
        // 4c. From TikTok
        $mockReq->ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 musical_ly_32.5.0';
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlWaTarget, $mockReq),
            "WhatsApp destination opened inside TikTok does NOT trigger breakout",
            $results
        );
        // 4d. From WhatsApp itself
        $mockReq->ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1 WhatsApp/2.23.20.78';
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlWaTarget, $mockReq),
            "WhatsApp destination opened inside WhatsApp does NOT trigger breakout (prevents loop)",
            $results
        );
    }

    /**
     * Test 4: Android Chrome intent URL generation and Injection Neutralization
     */
    public static function testAndroidChromeIntentGeneration(&$results) {
        echo "Running Phase1ModernizationTest::testAndroidChromeIntentGeneration...\n";

        $target = 'https://example.mg/boutique/promo-madagascar?ref=qr2026#catalogue';
        $intent = \Helpers\InAppBreakout::getAndroidIntentUrl($target);

        self::assert(
            strpos($intent, 'intent://example.mg/boutique/promo-madagascar?ref=qr2026#catalogue') === 0,
            "Android intent properly formats host, path, query and hash anchor",
            $results
        );

        self::assert(
            strpos($intent, '#Intent;scheme=https;package=com.android.chrome;end') !== false,
            "Android intent specifies package=com.android.chrome and scheme=https",
            $results
        );

        // Test malicious intent injection neutralization
        $maliciousTarget = 'https://example.mg/exploit#Intent;action=android.intent.action.VIEW;component=com.evil/.Attack;end';
        $safeIntent = \Helpers\InAppBreakout::getAndroidIntentUrl($maliciousTarget);

        self::assert(
            strpos($safeIntent, '%23Intent%3B') !== false &&
            strpos($safeIntent, '%3Bend') !== false &&
            substr($safeIntent, -strlen('#Intent;scheme=https;package=com.android.chrome;end')) === '#Intent;scheme=https;package=com.android.chrome;end',
            "Malicious #Intent; injection within URL is safely neutralized without altering terminal package structure",
            $results
        );

        // Test stripping of ASCII control characters
        $crlfTarget = "https://example.mg/path\r\n#catalogue";
        $sanitizedIntent = \Helpers\InAppBreakout::getAndroidIntentUrl($crlfTarget);
        self::assert(
            strpos($sanitizedIntent, "\r") === false && strpos($sanitizedIntent, "\n") === false,
            "Control characters (CR/LF) are strictly stripped from intent URL",
            $results
        );
    }

    /**
     * Test 5: BotProtection Classification
     */
    public static function testBotProtectionClassification(&$results) {
        echo "Running Phase1ModernizationTest::testBotProtectionClassification...\n";

        $mockReq = new class {
            public $ua = '';
            public $accept = 'text/html';
            public $acceptLang = 'fr-FR';
            public function userAgent() { return $this->ua; }
            public function ip() { return '127.0.0.1'; }
            public function server($k = null) {
                if ($k === 'HTTP_ACCEPT') return $this->accept;
                if ($k === 'HTTP_ACCEPT_LANGUAGE') return $this->acceptLang;
                return null;
            }
        };

        // 1. Meta / Facebook OpenGraph preview crawler
        $mockReq->ua = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
        $res = \Helpers\BotProtection::analyze($mockReq);
        self::assert(
            $res['type'] === 'social_preview' && $res['is_preview'] && !$res['count_stat'] && !$res['allow_geocoding'],
            "facebookexternalhit is classified as social_preview (no click count, no paid geocoding)",
            $results
        );

        // 2. WhatsApp metadata preview crawler
        $mockReq->ua = 'WhatsApp/2.23.10.77 i';
        $res = \Helpers\BotProtection::analyze($mockReq);
        self::assert(
            $res['type'] === 'social_preview' && $res['is_preview'] && !$res['count_stat'] && !$res['allow_geocoding'],
            "WhatsApp preview fetcher is classified as social_preview (no fake click, no paid geocoding)",
            $results
        );

        // 3. Twitterbot
        $mockReq->ua = 'Twitterbot/1.0';
        $res = \Helpers\BotProtection::analyze($mockReq);
        self::assert(
            $res['type'] === 'social_preview' && $res['allow_opengraph'] && !$res['count_stat'],
            "Twitterbot is allowed OpenGraph tags without counting commercial scans",
            $results
        );

        // 4. Malicious scanner (sqlmap)
        $mockReq->ua = 'sqlmap/1.6#stable (https://sqlmap.org)';
        $res = \Helpers\BotProtection::analyze($mockReq);
        self::assert(
            $res['type'] === 'bad_bot' && $res['is_bot'] && !$res['allow_opengraph'] && !$res['allow_geocoding'],
            "sqlmap scanner is classified as bad_bot and blocked without geocoding",
            $results
        );

        // 5. Malicious scanner (nikto)
        $mockReq->ua = 'Mozilla/5.00 (Nikto/2.1.6) (Evasions:None) (Test:Port Check)';
        $res = \Helpers\BotProtection::analyze($mockReq);
        self::assert(
            $res['type'] === 'bad_bot' && !$res['allow_geocoding'],
            "Nikto scanner is blocked without geocoding",
            $results
        );

        // 6. Empty UA without standard headers
        $mockReq->ua = '';
        $mockReq->accept = '';
        $mockReq->acceptLang = '';
        $res = \Helpers\BotProtection::analyze($mockReq);
        self::assert(
            $res['type'] === 'bad_bot' && $res['reason'] === 'missing_mandatory_headers',
            "Headless client with empty User-Agent and missing headers is classified as bad_bot",
            $results
        );
    }

    /**
     * Test 6: Social Preview OpenGraph rendering without geocoding or stats increment
     */
    public static function testSocialPreviewOpenGraphRenderingWithoutGeocoding(&$results) {
        echo "Running Phase1ModernizationTest::testSocialPreviewOpenGraphRenderingWithoutGeocoding...\n";

        $url = (object)[
            'id' => 20,
            'alias' => 'vanille-mg',
            'custom' => null,
            'domain' => 'https://qr.innov.mg',
            'url' => 'https://example.mg/vanille',
            'meta_title' => 'Vanille Gourmet de Madagascar',
            'meta_description' => 'QR packaging certifié pour vanille Bourbon.',
            'meta_image' => 'vanille_pack.jpg',
        ];

        $response = \Helpers\BotProtection::handleSocialPreview($url);
        $body = (string) $response->getBody();

        self::assert(
            $response->getCode() === 200,
            "handleSocialPreview returns HTTP 200 response",
            $results
        );

        self::assert(
            strpos($body, 'og:title') !== false && strpos($body, 'Vanille Gourmet de Madagascar') !== false,
            "OpenGraph og:title correctly rendered with link title",
            $results
        );

        self::assert(
            strpos($body, 'og:description') !== false && strpos($body, 'QR packaging certifié') !== false,
            "OpenGraph og:description correctly rendered",
            $results
        );

        self::assert(
            strpos($body, 'twitter:card') !== false && strpos($body, 'summary_large_image') !== false,
            "Twitter summary_large_image card correctly rendered",
            $results
        );
    }

    /**
     * Test 7: Malicious Bot Blocking Response
     */
    public static function testMaliciousBotBlockingWithoutGeocoding(&$results) {
        echo "Running Phase1ModernizationTest::testMaliciousBotBlockingWithoutGeocoding...\n";

        $response = \Helpers\BotProtection::handleBadBot();

        self::assert(
            $response->getCode() === 403,
            "handleBadBot returns HTTP 403 Forbidden",
            $results
        );

        self::assert(
            strpos((string) $response->getBody(), 'Access Denied') !== false,
            "handleBadBot provides clear access denied explanation",
            $results
        );
    }

    /**
     * Test 8: Real human visitor using WhatsApp / Instagram WebView is preserved for stats & geo
     */
    public static function testHumanInAppVisitorPreservedForStatsAndGeo(&$results) {
        echo "Running Phase1ModernizationTest::testHumanInAppVisitorPreservedForStatsAndGeo...\n";

        $mockReq = new class {
            public $ua = '';
            public function userAgent() { return $this->ua; }
            public function ip() { return '127.0.0.1'; }
            public function server($k = null) {
                if ($k === 'HTTP_ACCEPT') return 'text/html,application/xhtml+xml,application/xml;q=0.9';
                if ($k === 'HTTP_ACCEPT_LANGUAGE') return 'fr,fr-FR;q=0.9,en;q=0.8';
                return null;
            }
        };

        // Real human user clicking link in Instagram
        $mockReq->ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21E219 Instagram 324.0.0.18.109';
        $res = \Helpers\BotProtection::analyze($mockReq);

        self::assert(
            $res['type'] === 'human' && $res['count_stat'] && $res['allow_geocoding'],
            "Human Instagram WebView visitor is classified as human (count_stat=true, allow_geocoding=true)",
            $results
        );

        // Real human user clicking link in WhatsApp on Android
        $mockReq->ua = 'Mozilla/5.0 (Linux; Android 14; SM-S901B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/122.0.6261.64 Mobile Safari/537.36 WhatsApp/2.24.4.76';
        $res = \Helpers\BotProtection::analyze($mockReq);

        self::assert(
            $res['type'] === 'human' && $res['count_stat'] && $res['allow_geocoding'],
            "Human WhatsApp Android WebView visitor is classified as human (count_stat=true, allow_geocoding=true)",
            $results
        );
    }

    /**
     * Test 9: Historical links without new configuration operate strictly identical
     */
    public static function testHistoricalLinksWithoutNewConfig(&$results) {
        echo "Running Phase1ModernizationTest::testHistoricalLinksWithoutNewConfig...\n";

        // Historical link without options
        $historical = (object)[
            'id' => 99,
            'alias' => 'pack-2025',
            'url' => 'https://innov.mg/catalogue-2025',
            'options' => ''
        ];

        $req = new class {
            public function userAgent() {
                return 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';
            }
            public function ip() { return '127.0.0.1'; }
            public function server($k = null) {
                if ($k === 'HTTP_ACCEPT') return 'text/html';
                return null;
            }
        };

        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($historical, $req),
            "Historical link never triggers breakout on standard mobile Safari",
            $results
        );

        $analysis = \Helpers\BotProtection::analyze($req);
        self::assert(
            $analysis['type'] === 'human' && $analysis['count_stat'] && $analysis['allow_geocoding'],
            "Standard mobile visitor on historical link has normal human scan processing",
            $results
        );
    }

    /**
     * Test 10: Confidentiality of Private, Expired and Banned Links Against Social Preview Crawlers
     */
    public static function testSecurityGatesOrderAndConfidentialityPreservation(&$results) {
        echo "Running Phase1ModernizationTest::testSecurityGatesOrderAndConfidentialityPreservation...\n";

        \Core\DB::configure('sqlite::memory:');
        \Core\DB::configure('return_result_sets', true);
        $pdo = \Core\DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';

        $pdo->exec("DROP TABLE IF EXISTS `{$prefix}url`");
        $pdo->exec("DROP TABLE IF EXISTS `{$prefix}stats`");
        $pdo->exec("DROP TABLE IF EXISTS `{$prefix}reports`");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}url` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `userid` INTEGER DEFAULT 0,
            `alias` TEXT,
            `custom` TEXT,
            `url` TEXT,
            `options` TEXT,
            `pass` TEXT DEFAULT NULL,
            `expiry` TEXT DEFAULT NULL,
            `click` INTEGER DEFAULT 0,
            `uniqueclick` INTEGER DEFAULT 0,
            `status` INTEGER DEFAULT 1,
            `domain` TEXT DEFAULT NULL,
            `qrid` INTEGER DEFAULT NULL,
            `profileid` INTEGER DEFAULT NULL,
            `meta_title` TEXT DEFAULT NULL,
            `meta_description` TEXT DEFAULT NULL,
            `meta_image` TEXT DEFAULT NULL,
            `date` TEXT DEFAULT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}stats` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `short` TEXT,
            `urlid` INTEGER,
            `urluserid` INTEGER DEFAULT 0,
            `date` TEXT,
            `ip` TEXT,
            `country` TEXT,
            `region` TEXT,
            `city` TEXT,
            `geo_source` TEXT,
            `accuracy_radius` INTEGER,
            `browser` TEXT DEFAULT '',
            `os` TEXT DEFAULT '',
            `device` TEXT DEFAULT '',
            `domain` TEXT DEFAULT '',
            `referer` TEXT DEFAULT '',
            `language` TEXT DEFAULT ''
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}reports` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `bannedlink` TEXT DEFAULT ''
        )");

        // Insert test URLs
        // 1. Password-protected link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `pass`, `meta_title`, `status`) VALUES
            (101, 'pass-protected', 'https://internal-confidential.example.com/vault', 'secret_key_123', 'Secret Financial Document', 1)");

        // 2. Expired by date link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `expiry`, `meta_title`, `status`) VALUES
            (102, 'expired-link', 'https://campaign.example.com/ended', '2020-01-01 00:00:00', 'Ended Summer 2020 Promo', 1)");

        // 3. Expired by click limit
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `options`, `click`, `meta_title`, `status`) VALUES
            (103, 'click-limit', 'https://limited.example.com/tickets', '{\"clicklimit\":5}', 5, 'VIP Concert Ticket Giveaway', 1)");

        // 4. Inactive / disabled link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `status`) VALUES
            (104, 'inactive-link', 'https://inactive.example.com/page', 'Hidden Draft', 0)");

        // 5. Banned link in reports
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `status`) VALUES
            (105, 'banned-link', 'https://phishing-domain.example.com/login', 'Phishing Page', 1)");
        $pdo->exec("INSERT INTO `{$prefix}reports` (`id`, `bannedlink`) VALUES (1, 'phishing-domain.example.com')");

        // 6. Public valid accessible link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `meta_description`, `status`) VALUES
            (106, 'public-qr', 'https://wa.me/261340000000?text=Bonjour', 'Catalogue Vanille Export', 'Commandez votre vanille certifiée.', 1)");

        require_once ROOT . '/app/controllers/LinkController.php';
        $controller = new \Link();

        \Core\View::set('path', STORAGE . '/themes/default');
        \Gem::$Config->theme = 'default';
        \Gem::$Config->url = 'http://localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
        $_SERVER['REMOTE_ADDR'] = '66.220.144.1';
        $_SESSION = [];

        // Check 1: Password-protected link requested by preview bot -> MUST NOT LEAK OPENGRAPH META!
        $_SERVER['REQUEST_URI'] = '/pass-protected';
        ob_start();
        $controller->redirect(new \Core\Request(), 'pass-protected');
        $outPass = ob_get_clean();
        self::assert(
            strpos($outPass, 'Secret Financial Document') === false &&
            strpos($outPass, 'internal-confidential.example.com') === false,
            "Confidentiality 1: Password-protected link strictly refuses OpenGraph leaks to social crawlers",
            $results
        );

        // Check 2: Expired date link requested by preview bot -> MUST NOT LEAK OPENGRAPH META!
        $_SERVER['REQUEST_URI'] = '/expired-link';
        ob_start();
        $controller->redirect(new \Core\Request(), 'expired-link');
        $outExp = ob_get_clean();
        self::assert(
            strpos($outExp, 'Ended Summer 2020 Promo') === false &&
            strpos($outExp, 'campaign.example.com') === false,
            "Confidentiality 2: Expired date link strictly refuses OpenGraph preview to social crawlers",
            $results
        );

        // Check 3: Click-limited link requested by preview bot -> MUST NOT LEAK OPENGRAPH META!
        $_SERVER['REQUEST_URI'] = '/click-limit';
        ob_start();
        $controller->redirect(new \Core\Request(), 'click-limit');
        $outClickLimit = ob_get_clean();
        self::assert(
            strpos($outClickLimit, 'VIP Concert Ticket Giveaway') === false,
            "Confidentiality 3: Click-exhausted link strictly refuses OpenGraph preview to social crawlers",
            $results
        );

        // Check 4: Inactive link requested by preview bot -> MUST NOT LEAK OPENGRAPH META!
        $_SERVER['REQUEST_URI'] = '/inactive-link';
        ob_start();
        $controller->redirect(new \Core\Request(), 'inactive-link');
        $outInactive = ob_get_clean();
        self::assert(
            strpos($outInactive, 'Hidden Draft') === false,
            "Confidentiality 4: Inactive link strictly refuses OpenGraph preview to social crawlers",
            $results
        );

        // Check 5: Banned link requested by preview bot -> MUST NOT LEAK OPENGRAPH META!
        $_SERVER['REQUEST_URI'] = '/banned-link';
        ob_start();
        $controller->redirect(new \Core\Request(), 'banned-link');
        $outBanned = ob_get_clean();
        self::assert(
            strpos($outBanned, 'Phishing Page') === false,
            "Confidentiality 5: Blacklisted/reported link strictly refuses OpenGraph preview to social crawlers",
            $results
        );

        // Check 6: Valid public link requested by preview bot -> serves clean OpenGraph WITHOUT incrementing stats or geocoding
        $_SERVER['REQUEST_URI'] = '/public-qr';
        $statsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}stats`")->fetchColumn();
        ob_start();
        $resPublic = $controller->redirect(new \Core\Request(), 'public-qr');
        $outPublic = ob_get_clean();
        if ($resPublic instanceof \Core\Response) {
            $outPublic .= $resPublic->getBody();
        }
        $statsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}stats`")->fetchColumn();

        self::assert(
            strpos($outPublic, 'Catalogue Vanille Export') !== false &&
            strpos($outPublic, 'Commandez votre vanille') !== false,
            "Confidentiality 6: Valid public link serves OpenGraph tags to legitimate preview bot",
            $results
        );
        self::assert(
            $statsCountBefore === $statsCountAfter,
            "Confidentiality 7: Legitimate social crawler did NOT increment stats table (0 fake clicks)",
            $results
        );
    }

    /**
     * Test 11: Secure JavaScript serialization in InAppBreakout (json_encode with HTML flags, XSS prevention)
     */
    public static function testJavaScriptBreakoutSerializationAndXssSafety(&$results) {
        echo "Running Phase1ModernizationTest::testJavaScriptBreakoutSerializationAndXssSafety...\n";

        $mockReq = new class {
            public function userAgent() {
                return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/21E219 Instagram 324.0.0.18.109';
            }
            public function server($k = null) {
                if ($k === 'REQUEST_URI') return '/test-xss';
                return null;
            }
        };

        // Attack URL with </script>, quotes, Unicode, parameters & and fragments #
        $xssTarget = "https://example.mg/produit/épice-mégapromo?id=42&desc=l'artisanat\"<script>alert('XSS')</script>#ancre-vanille";
        $urlObj = (object)[
            'id' => 88,
            'url' => $xssTarget,
            'options' => json_encode(['inapp_breakout' => true])
        ];

        $response = \Helpers\InAppBreakout::renderBreakoutPage($urlObj, $mockReq);
        $body = (string) $response->getBody();

        // 1. Literal script tag breakout MUST NOT exist
        self::assert(
            strpos($body, '</script><script>alert') === false,
            "XSS Safety: </script> sequence inside URL is safely neutralized and cannot close script context",
            $results
        );

        // 2. Encoded hex representation must be present
        self::assert(
            strpos($body, '\u003Cscript\u003E') !== false,
            "XSS Safety: HTML tags are hex-encoded as \\u003C and \\u003E in JSON literal",
            $results
        );

        // 3. Quotes must be safely encoded
        self::assert(
            strpos($body, '\u0027') !== false || strpos($body, '\u0022') !== false,
            "XSS Safety: Single and double quotes are hex-escaped (\\u0027 / \\u0022)",
            $results
        );

        // 4. Unicode characters preserved
        self::assert(
            strpos($body, 'épice-mégapromo') !== false,
            "Unicode characters (French / Malagasy diacritics) are properly preserved in breakout page",
            $results
        );

        // 5. Test long URL (600+ chars)
        $longUrl = 'https://example.mg/catalogue/' . str_repeat('abc123_', 80) . '?param=' . str_repeat('xyz_', 20) . '#anchor';
        $longUrlObj = (object)['id' => 89, 'url' => $longUrl, 'options' => json_encode(['inapp_breakout' => true])];
        $longResponse = \Helpers\InAppBreakout::renderBreakoutPage($longUrlObj, $mockReq);
        self::assert(
            $longResponse->getCode() === 200 && strlen((string)$longResponse->getBody()) > 1000,
            "Long URLs (600+ characters) serialize cleanly without truncation in JS",
            $results
        );
    }

    /**
     * Test 12: Search Engine Crawlers (Googlebot, Bingbot) respect count_stat=false and allow_geocoding=false
     */
    public static function testSearchEngineBotRespectsNoStatsAndNoGeocoding(&$results) {
        echo "Running Phase1ModernizationTest::testSearchEngineBotRespectsNoStatsAndNoGeocoding...\n";

        $pdo = \Core\DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';

        // Reset and insert a fresh link for search engine test
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `status`) VALUES
            (110, 'search-bot-test', 'https://example.mg/destination-bot', 'Page Publique Pour Moteurs', 1)");

        require_once ROOT . '/app/controllers/LinkController.php';
        $controller = new \Link();

        $_SERVER['REQUEST_URI'] = '/search-bot-test';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $_SERVER['REMOTE_ADDR'] = '66.249.66.1';
        $_SESSION = [];

        $statsBefore = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}stats` WHERE urlid = 110")->fetchColumn();
        $clicksBefore = (int)$pdo->query("SELECT click FROM `{$prefix}url` WHERE id = 110")->fetchColumn();

        ob_start();
        $controller->redirect(new \Core\Request(), 'search-bot-test');
        ob_end_clean();

        $statsAfter = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}stats` WHERE urlid = 110")->fetchColumn();
        $clicksAfter = (int)$pdo->query("SELECT click FROM `{$prefix}url` WHERE id = 110")->fetchColumn();

        self::assert(
            $statsBefore === $statsAfter && $clicksBefore === $clicksAfter,
            "Search Engine Bot (Googlebot): Strictly 0 rows inserted in stats and 0 click increment (count_stat=false)",
            $results
        );
    }

    /**
     * Test 13: Internal _breakout=skip parameter is stripped and never forwarded to destination URL
     */
    public static function testBreakoutParameterNotForwardedToDestinationUrl(&$results) {
        echo "Running Phase1ModernizationTest::testBreakoutParameterNotForwardedToDestinationUrl...\n";

        $pdo = \Core\DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';

        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `status`) VALUES
            (120, 'skip-forward-test', 'https://example.mg/shop?source=qr', 1)");

        require_once ROOT . '/app/controllers/LinkController.php';
        $controller = new \Link();

        $_SERVER['REQUEST_URI'] = '/skip-forward-test?campaign=promo2026&_breakout=skip';
        $_GET = ['campaign' => 'promo2026', '_breakout' => 'skip'];
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1';
        $_SERVER['REMOTE_ADDR'] = '197.218.0.1';

        $req = new \Core\Request();
        $queries = $req->query();
        if (is_array($queries)) {
            unset($queries['_breakout']);
        }
        $targetUrl = 'https://example.mg/shop?source=qr';
        $finalUrl = $targetUrl . '&' . http_build_query($queries);

        self::assert(
            strpos($finalUrl, '_breakout') === false,
            "Forwarded query string strictly excludes internal _breakout parameter",
            $results
        );
        self::assert(
            strpos($finalUrl, 'campaign=promo2026') !== false,
            "Legitimate client query parameters (campaign=promo2026) are cleanly preserved",
            $results
        );
        $_GET = [];
    }

    /**
     * Test 14: Explicit bot_protection toggle option on links
     */
    public static function testBotProtectionExplicitToggleOption(&$results) {
        echo "Running Phase1ModernizationTest::testBotProtectionExplicitToggleOption...\n";

        $pdo = \Core\DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';

        // Link with bot_protection explicitly disabled
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `options`, `status`) VALUES
            (130, 'bot-off-link', 'https://example.mg/api-scrapers', '{\"bot_protection\":false}', 1)");

        require_once ROOT . '/app/controllers/LinkController.php';
        $controller = new \Link();

        $_SERVER['REQUEST_URI'] = '/bot-off-link';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.6#stable (https://sqlmap.org)';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        ob_start();
        $controller->redirect(new \Core\Request(), 'bot-off-link');
        $out = ob_get_clean();

        // When bot_protection is explicitly false on the link, handleBadBot (403 Access Denied) is bypassed!
        self::assert(
            \Core\Response::lastStatusCode() !== 403,
            "Link with bot_protection=false in options bypasses 403 scanner blocking (option respected)",
            $results
        );
    }
}

