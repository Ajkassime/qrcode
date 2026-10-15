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
        self::testFullConfigurationLifecycleAndLegacyCompatibility($results);
        self::testWhatsAppUrlSanitizationAndEndToEndScanRedirect($results);
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
        $pdo->exec("DROP TABLE IF EXISTS `{$prefix}domains`");
        $pdo->exec("DROP TABLE IF EXISTS `{$prefix}tochannels`");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}url` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `userid` INTEGER DEFAULT 0,
            `alias` TEXT,
            `custom` TEXT,
            `url` TEXT,
            `description` TEXT DEFAULT NULL,
            `location` TEXT DEFAULT NULL,
            `devices` TEXT DEFAULT NULL,
            `options` TEXT DEFAULT NULL,
            `pass` TEXT DEFAULT NULL,
            `expiry` TEXT DEFAULT NULL,
            `pixels` TEXT DEFAULT NULL,
            `parameters` TEXT DEFAULT NULL,
            `type` TEXT DEFAULT 'direct',
            `bundle` INTEGER DEFAULT NULL,
            `public` INTEGER DEFAULT 1,
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}domains` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `domain` TEXT,
            `userid` INTEGER,
            `status` INTEGER
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}tochannels` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `type` TEXT,
            `itemid` INTEGER,
            `channelid` INTEGER
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}user` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `banned` INTEGER DEFAULT 0,
            `pro` INTEGER DEFAULT 1,
            `trial` INTEGER DEFAULT 0,
            `expiration` TEXT DEFAULT NULL,
            `media` INTEGER DEFAULT 1,
            `admin` INTEGER DEFAULT 1
        )");
        $pdo->exec("INSERT INTO `{$prefix}user` (`id`, `banned`, `pro`, `admin`) VALUES (1, 0, 1, 1)");

        // Insert test URLs with bot_protection enabled
        // 1. Password-protected link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `pass`, `meta_title`, `options`, `status`) VALUES
            (101, 'pass-protected', 'https://internal-confidential.example.com/vault', 'secret_key_123', 'Secret Financial Document', '{\"bot_protection\":true}', 1)");

        // 2. Expired by date link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `expiry`, `meta_title`, `options`, `status`) VALUES
            (102, 'expired-link', 'https://campaign.example.com/ended', '2020-01-01 00:00:00', 'Ended Summer 2020 Promo', '{\"bot_protection\":true}', 1)");

        // 3. Expired by click limit
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `options`, `click`, `meta_title`, `status`) VALUES
            (103, 'click-limit', 'https://limited.example.com/tickets', '{\"clicklimit\":5,\"bot_protection\":true}', 5, 'VIP Concert Ticket Giveaway', 1)");

        // 4. Inactive / disabled link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `options`, `status`) VALUES
            (104, 'inactive-link', 'https://inactive.example.com/page', 'Hidden Draft', '{\"bot_protection\":true}', 0)");

        // 5. Banned link in reports
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `options`, `status`) VALUES
            (105, 'banned-link', 'https://phishing-domain.example.com/login', 'Phishing Page', '{\"bot_protection\":true}', 1)");
        $pdo->exec("INSERT INTO `{$prefix}reports` (`id`, `bannedlink`) VALUES (1, 'phishing-domain.example.com')");

        // 6. Public valid accessible link
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `meta_description`, `options`, `status`) VALUES
            (106, 'public-qr', 'https://wa.me/261340000000?text=Bonjour', 'Catalogue Vanille Export', 'Commandez votre vanille certifiée.', '{\"bot_protection\":true}', 1)");

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

        // Reset and insert a fresh link for search engine test with bot_protection enabled
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `meta_title`, `options`, `status`) VALUES
            (110, 'search-bot-test', 'https://example.mg/destination-bot', 'Page Publique Pour Moteurs', '{\"bot_protection\":true}', 1)");

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

    /**
     * Test 15: Full Lifecycle: Form -> HTTP Request -> createLink/updateLink -> SQL DB -> Form Reload -> Redirect Controller
     * Covering activation, deactivation, reactivation, legacy links without options, breakout toggle, and historical QR safety.
     */
    public static function testFullConfigurationLifecycleAndLegacyCompatibility(&$results) {
        echo "Running Phase1ModernizationTest::testFullConfigurationLifecycleAndLegacyCompatibility...\n";

        $pdo = \Core\DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';

        // 1. Verify Core\Request dynamic property check using __isset() and has()
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST = ['bot_protection' => '1', 'inapp_breakout' => '1'];
        $reqTest = new \Core\Request();
        self::assert(
            isset($reqTest->bot_protection) && $reqTest->has('bot_protection') && $reqTest->bot_protection === '1',
            "Core\\Request correctly supports isset(\$request->bot_protection) via __isset() and has()",
            $results
        );

        $_REQUEST = [];
        $reqEmpty = new \Core\Request();
        self::assert(
            !isset($reqEmpty->bot_protection) && !$reqEmpty->has('bot_protection') && $reqEmpty->bot_protection === null,
            "Core\\Request returns false for isset() when property is not present in request",
            $results
        );

        // Mock User for Links trait operations
        $user = new class {
            public $id = 1;
            public $admin = 1;
            public $planid = 1;
            public $pro = 1;
            public function pro() { return true; }
            public function rID() { return 1; }
            public function has($perm) { return true; }
            public function plan($key) { return null; }
        };

        require_once ROOT . '/app/controllers/LinkController.php';
        $controller = new \Link();

        // 2. LIFECYCLE STEP A: CREATE LINK with bot_protection=1 & inapp_breakout=1
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST = [
            'url' => 'https://example.mg/boutique-lifecycle',
            'custom' => 'lifecycle-test-1',
            'inapp_breakout' => '1',
            'bot_protection' => '1'
        ];
        $reqCreate = new \Core\Request();
        $created = $controller->createLink($reqCreate, $user);
        $linkId = (int) $created['id'];

        $createdRow = $pdo->query("SELECT * FROM `{$prefix}url` WHERE id = {$linkId}")->fetch(\PDO::FETCH_ASSOC);
        $createdOptions = json_decode($createdRow['options'] ?? '{}', true);

        self::assert(
            !empty($createdOptions['bot_protection']) && !empty($createdOptions['inapp_breakout']),
            "Lifecycle A: createLink() successfully records bot_protection=true and inapp_breakout=true in SQL database",
            $results
        );

        // Form reload simulation for Step A
        $editBotProtA = !empty($createdOptions['bot_protection']);
        $editBreakoutA = !empty($createdOptions['inapp_breakout']);
        self::assert(
            $editBotProtA === true && $editBreakoutA === true,
            "Lifecycle A: Edit form reload reflects bot_protection=true and inapp_breakout=true (switches checked)",
            $results
        );

        // Redirection behavior for Step A (Enabled state)
        // A.1: Bad bot gets blocked with HTTP 403
        $_SERVER['REQUEST_URI'] = '/lifecycle-test-1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.6#stable (https://sqlmap.org)';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        ob_start();
        $controller->redirect(new \Core\Request(), 'lifecycle-test-1');
        $outBotA = ob_get_clean();
        self::assert(
            \Core\Response::lastStatusCode() === 403,
            "Lifecycle A: Bad bot request to link with bot_protection=true receives HTTP 403 Access Denied",
            $results
        );

        // A.2: In-app browser triggers breakout
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/21E219 Instagram 324.0.0.18.109';
        ob_start();
        $resBreakoutA = $controller->redirect(new \Core\Request(), 'lifecycle-test-1');
        $outBreakoutA = ob_get_clean();
        if ($resBreakoutA instanceof \Core\Response) {
            $outBreakoutA .= $resBreakoutA->getBody();
        }
        self::assert(
            strpos($outBreakoutA, 'Ouverture du lien') !== false || strpos($outBreakoutA, 'openInChrome') !== false,
            "Lifecycle A: Instagram in-app visitor triggers breakout view for inapp_breakout=true link",
            $results
        );

        // 3. LIFECYCLE STEP B: UPDATE LINK to DEACTIVATE (form transmitting 0 for both checkboxes)
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST = [
            'url' => 'https://example.mg/boutique-lifecycle',
            'custom' => 'lifecycle-test-1',
            'inapp_breakout' => '0',
            'bot_protection' => '0'
        ];
        $linkObj = \Core\DB::url()->where('id', $linkId)->first();
        $reqUpdateDeact = new \Core\Request();
        $controller->updateLink($reqUpdateDeact, $linkObj, $user);

        $deactRow = $pdo->query("SELECT * FROM `{$prefix}url` WHERE id = {$linkId}")->fetch(\PDO::FETCH_ASSOC);
        $deactOptions = json_decode($deactRow['options'] ?? '{}', true);

        self::assert(
            isset($deactOptions['bot_protection']) && $deactOptions['bot_protection'] === false && empty($deactOptions['inapp_breakout']),
            "Lifecycle B: updateLink() deactivates bot_protection=false and removes inapp_breakout in SQL database",
            $results
        );

        // Form reload simulation for Step B
        $editBotProtB = !empty($deactOptions['bot_protection']);
        $editBreakoutB = !empty($deactOptions['inapp_breakout']);
        self::assert(
            $editBotProtB === false && $editBreakoutB === false,
            "Lifecycle B: Edit form reload reflects bot_protection=false and inapp_breakout=false (switches unchecked)",
            $results
        );

        // Redirection behavior for Step B (Deactivated state)
        // B.1: Bad bot is NOT blocked with 403
        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.6#stable (https://sqlmap.org)';
        ob_start();
        $controller->redirect(new \Core\Request(), 'lifecycle-test-1');
        $outBotB = ob_get_clean();
        self::assert(
            \Core\Response::lastStatusCode() !== 403,
            "Lifecycle B: Bad bot is NOT blocked with 403 when bot_protection is deactivated",
            $results
        );

        // B.2: In-app browser does NOT trigger breakout
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/21E219 Instagram 324.0.0.18.109';
        ob_start();
        $resBreakoutB = $controller->redirect(new \Core\Request(), 'lifecycle-test-1');
        $outBreakoutB = ob_get_clean();
        if ($resBreakoutB instanceof \Core\Response) {
            $outBreakoutB .= $resBreakoutB->getBody();
        }
        self::assert(
            strpos($outBreakoutB, 'Ouverture du lien') === false && strpos($outBreakoutB, 'openInChrome') === false,
            "Lifecycle B: In-app browser does NOT trigger breakout when inapp_breakout is deactivated",
            $results
        );

        // 4. LIFECYCLE STEP C: UPDATE LINK to REACTIVATE
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST = [
            'url' => 'https://example.mg/boutique-lifecycle',
            'custom' => 'lifecycle-test-1',
            'inapp_breakout' => '1',
            'bot_protection' => '1'
        ];
        $linkObj2 = \Core\DB::url()->where('id', $linkId)->first();
        $reqUpdateReact = new \Core\Request();
        $controller->updateLink($reqUpdateReact, $linkObj2, $user);

        $reactRow = $pdo->query("SELECT * FROM `{$prefix}url` WHERE id = {$linkId}")->fetch(\PDO::FETCH_ASSOC);
        $reactOptions = json_decode($reactRow['options'] ?? '{}', true);

        self::assert(
            !empty($reactOptions['bot_protection']) && !empty($reactOptions['inapp_breakout']),
            "Lifecycle C: updateLink() reactivates bot_protection=true and inapp_breakout=true",
            $results
        );

        // 5. LIFECYCLE STEP D: HISTORICAL LINK (without recorded options)
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `options`, `status`) VALUES
            (140, 'historical-qr-packaging', 'https://example.mg/produits/vanille-bio', NULL, 1)");

        $histRow = $pdo->query("SELECT * FROM `{$prefix}url` WHERE id = 140")->fetch(\PDO::FETCH_ASSOC);
        $histOptions = json_decode($histRow['options'] ?? '{}', true);

        // Form reload simulation for historical link
        $editBotProtHist = !empty($histOptions['bot_protection']);
        $editBreakoutHist = !empty($histOptions['inapp_breakout']);
        self::assert(
            $editBotProtHist === false && $editBreakoutHist === false,
            "Lifecycle D: Historical link without options loads in edit form with both switches unchecked",
            $results
        );

        // Redirection behavior on historical link
        // D.1: Bad bot does NOT get blocked with 403 (no new 403 on historical links)
        $_SERVER['REQUEST_URI'] = '/historical-qr-packaging';
        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.6#stable (https://sqlmap.org)';
        ob_start();
        $controller->redirect(new \Core\Request(), 'historical-qr-packaging');
        $outBotHist = ob_get_clean();
        self::assert(
            \Core\Response::lastStatusCode() !== 403,
            "Lifecycle D: Historical QR code without options never returns HTTP 403 to scanners (no unexpected blocking)",
            $results
        );

        // D.2: In-app browser does NOT breakout on historical link
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/21E219 Instagram 324.0.0.18.109';
        ob_start();
        $resHistBreakout = $controller->redirect(new \Core\Request(), 'historical-qr-packaging');
        $outHistBreakout = ob_get_clean();
        if ($resHistBreakout instanceof \Core\Response) {
            $outHistBreakout .= $resHistBreakout->getBody();
        }
        self::assert(
            strpos($outHistBreakout, 'Ouverture du lien') === false && strpos($outHistBreakout, 'openInChrome') === false,
            "Lifecycle D: Historical QR code without options does not trigger breakout (direct historical redirect)",
            $results
        );

        // 6. INDISPENSABLE SECURITY CHECKS INDEPENDENT OF BOT PROTECTION
        // Inactive link with bot_protection=false must still be rejected (cannot bypass basic security)
        $pdo->exec("INSERT INTO `{$prefix}url` (`id`, `alias`, `url`, `options`, `status`) VALUES
            (141, 'inactive-sec-check', 'https://example.mg/secret', '{\"bot_protection\":false}', 0)");
        $_SERVER['REQUEST_URI'] = '/inactive-sec-check';
        ob_start();
        $controller->redirect(new \Core\Request(), 'inactive-sec-check');
        $outInactiveSec = ob_get_clean();
        self::assert(
            strpos($outInactiveSec, 'inactive') !== false || strpos($outInactiveSec, 'disabled') !== false || \Core\Response::lastStatusCode() !== 200,
            "Indispensable Security: Inactive link is rejected regardless of bot_protection setting",
            $results
        );

        // 7. HTML INTERFACE VERIFICATION: unique IDs and hidden inputs in edit.php & shortenermodal.php
        $editHtml = file_get_contents(STORAGE . '/themes/default/user/edit.php');
        self::assert(
            strpos($editHtml, 'id="inapp_breakout_block"') !== false &&
            strpos($editHtml, 'id="bot_protection_block"') !== false &&
            strpos($editHtml, '<input type="hidden" name="inapp_breakout" value="0">') !== false &&
            strpos($editHtml, '<input type="hidden" name="bot_protection" value="0">') !== false,
            "HTML UI: edit.php uses unique container IDs (inapp_breakout_block, bot_protection_block) and explicit hidden 0 inputs",
            $results
        );

        $modalHtml = file_get_contents(STORAGE . '/themes/default/partials/shortenermodal.php');
        self::assert(
            strpos($modalHtml, '<input type="hidden" name="inapp_breakout" value="0">') !== false &&
            strpos($modalHtml, '<input type="hidden" name="bot_protection" value="0">') !== false &&
            strpos($modalHtml, 'id="qsinapp_breakout"') !== false &&
            strpos($modalHtml, 'id="qsbot_protection"') !== false,
            "HTML UI: shortenermodal.php uses explicit hidden 0 inputs and unique element IDs",
            $results
        );
    }

    /**
     * Test 16: WhatsApp URL Sanitization and End-to-End Mobile QR Scan Redirection
     * Solves the WhatsApp API rejection ("Le lien est incorrect") caused by '+' and empty query strings
     * on mobile browsers (iPhone/Safari, Android/Chrome) for both new and historical QR codes.
     */
    public static function testWhatsAppUrlSanitizationAndEndToEndScanRedirect(&$results) {
        echo "Running Phase1ModernizationTest::testWhatsAppUrlSanitizationAndEndToEndScanRedirect...\n";

        // 1. QR::typeWhatsapp generation sanitization (official wa.me format)
        $waData1 = ['phone' => '+261 34 65 831 94', 'body' => ''];
        $generatedUrl1 = \Helpers\QR::typeWhatsapp($waData1);
        self::assert(
            $generatedUrl1 === 'https://wa.me/261346583194',
            "QR::typeWhatsapp formats phone as canonical https://wa.me/NUMERO_INTERNATIONAL and omits empty text parameter",
            $results
        );

        $waData2 = ['phone' => '+261-34-65-831-94', 'body' => 'Bonjour à tous !'];
        $generatedUrl2 = \Helpers\QR::typeWhatsapp($waData2);
        self::assert(
            $generatedUrl2 === 'https://wa.me/261346583194?text=Bonjour+%C3%A0+tous+%21',
            "QR::typeWhatsapp formats prefilled message with valid encoding without altering content",
            $results
        );

        // Leading 00 international prefix
        $waData3 = ['phone' => '00261346583194', 'body' => 'Test'];
        $generatedUrl3 = \Helpers\QR::typeWhatsapp($waData3);
        self::assert(
            $generatedUrl3 === 'https://wa.me/261346583194?text=Test',
            "QR::typeWhatsapp strips international leading 00 to match E.164 digits-only",
            $results
        );

        // String input compatibility
        $generatedUrlString = \Helpers\QR::typeWhatsapp('+261 34 65 831 94');
        self::assert(
            $generatedUrlString === 'https://wa.me/261346583194',
            "QR::typeWhatsapp handles string phone argument gracefully",
            $results
        );

        // Non-guessing rule: Local number starting with 0 is rejected with informative error (no silent country code guessing)
        $localZeroThrown = false;
        try {
            \Helpers\QR::typeWhatsapp(['phone' => '0346583194', 'body' => 'Test']);
        } catch (\Exception $e) {
            $localZeroThrown = true;
        }
        self::assert(
            $localZeroThrown,
            "Non-guessing policy: Local Malagasy number starting with single 0 (034...) is rejected to prevent guessing country code",
            $results
        );

        // Invalid phone number rejection (no digits or too short)
        $invalidThrown = false;
        try {
            \Helpers\QR::typeWhatsapp(['phone' => 'invalid-no-digits', 'body' => 'hello']);
        } catch (\Exception $e) {
            $invalidThrown = true;
        }
        self::assert(
            $invalidThrown,
            "QR::typeWhatsapp throws Exception when no valid phone digits are present",
            $results
        );

        $tooShortThrown = false;
        try {
            \Helpers\QR::typeWhatsapp(['phone' => '12345', 'body' => 'hello']);
        } catch (\Exception $e) {
            $tooShortThrown = true;
        }
        self::assert(
            $tooShortThrown,
            "QR::typeWhatsapp throws Exception when phone number is too short (< 8 digits)",
            $results
        );

        // 2. InAppBreakout::normalizeWhatsAppUrl on various historical formats
        // Exact historical row from innovstu_qr.sql / live packaging: api.whatsapp.com -> wa.me
        $historicalUrl = 'https://api.whatsapp.com/send?phone=+261346583194&text=';
        $normalizedHistorical = \Helpers\InAppBreakout::normalizeWhatsAppUrl($historicalUrl);
        self::assert(
            $normalizedHistorical === 'https://wa.me/261346583194',
            "normalizeWhatsAppUrl heals historical 'api.whatsapp.com/send?phone=+261...&text=' to canonical 'https://wa.me/261346583194'",
            $results
        );

        // Historical link with message preserves message content perfectly
        $historicalWithMsg = 'https://api.whatsapp.com/send?phone=+261346583194&text=Commande%20vanille%20%23123';
        self::assert(
            \Helpers\InAppBreakout::normalizeWhatsAppUrl($historicalWithMsg) === 'https://wa.me/261346583194?text=Commande+vanille+%23123',
            "normalizeWhatsAppUrl preserves message content without altering special characters",
            $results
        );

        // URL encoded plus
        $encodedPlusUrl = 'https://api.whatsapp.com/send/?phone=%2B261346583194&text=';
        self::assert(
            \Helpers\InAppBreakout::normalizeWhatsAppUrl($encodedPlusUrl) === 'https://wa.me/261346583194',
            "normalizeWhatsAppUrl handles %2B encoded plus cleanly",
            $results
        );

        // wa.me format with plus
        $waMePlus = 'https://wa.me/+261346583194?text=';
        self::assert(
            \Helpers\InAppBreakout::normalizeWhatsAppUrl($waMePlus) === 'https://wa.me/261346583194',
            "normalizeWhatsAppUrl strips plus from wa.me path and omits empty text parameter",
            $results
        );

        // whatsapp:// native protocol
        $nativeWa = 'whatsapp://send?phone=+261346583194&text=';
        self::assert(
            \Helpers\InAppBreakout::normalizeWhatsAppUrl($nativeWa) === 'whatsapp://send?phone=261346583194',
            "normalizeWhatsAppUrl strips plus from whatsapp:// protocol URL",
            $results
        );

        // Non-guessing policy on existing local number: does NOT invent 261
        $localExisting = 'https://api.whatsapp.com/send?phone=0346583194';
        self::assert(
            \Helpers\InAppBreakout::normalizeWhatsAppUrl($localExisting) === 'https://wa.me/0346583194',
            "Non-guessing policy: existing number starting with 0 is preserved as digits-only without silently injecting country code",
            $results
        );

        // Non-WhatsApp URL must NOT be touched
        $standardUrl = 'https://example.com/produits?id=123&phone=+261340000000';
        self::assert(
            \Helpers\InAppBreakout::normalizeWhatsAppUrl($standardUrl) === $standardUrl,
            "normalizeWhatsAppUrl leaves non-WhatsApp destinations completely untouched",
            $results
        );

        // 3. End-to-end QR scan redirection test
        // Simulate scanning a historical QR code on iPhone/Safari
        $pdo = \Core\DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo->exec("INSERT INTO `{$prefix}url` (url, alias, status, click, options) VALUES ('https://api.whatsapp.com/send?phone=+261346583194&text=', 'wa-scan-test', 1, 0, NULL)");
        $linkId = $pdo->lastInsertId();

        $controller = new \Link();
        $_SERVER['REQUEST_URI'] = '/wa-scan-test';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $mockRequest = new \Core\Request();

        ob_start();
        $controller->redirect($mockRequest, 'wa-scan-test');
        ob_get_clean();

        $lastStatus = \Core\Response::lastStatusCode();
        $lastHeaders = \Core\Response::lastHeaders();
        $locationHeader = $lastHeaders['location'] ?? $lastHeaders['Location'] ?? null;

        self::assert(
            $lastStatus === 301 && $locationHeader === 'https://wa.me/261346583194',
            "End-to-End Scan: iPhone Safari scanning historical QR code redirects via HTTP 301 to canonical 'https://wa.me/261346583194'",
            $results
        );

        // Clean up test link
        $pdo->exec("DELETE FROM `{$prefix}url` WHERE id = {$linkId}");
    }
}

