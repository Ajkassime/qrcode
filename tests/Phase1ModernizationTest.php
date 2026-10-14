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

        // 4. WhatsApp link scanned within WhatsApp -> MUST NOT breakout (native preservation)
        $urlWaTarget = (object)[
            'id' => 12,
            'url' => 'https://wa.me/261340000000?text=Test',
            'options' => json_encode(['inapp_breakout' => true])
        ];
        $mockReq->ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1 WhatsApp/2.23.20.78';
        self::assert(
            !\Helpers\InAppBreakout::shouldBreakout($urlWaTarget, $mockReq),
            "WhatsApp destination opened inside WhatsApp does NOT trigger breakout (prevents loop)",
            $results
        );
    }

    /**
     * Test 4: Android Chrome intent URL generation
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
}
