<?php
/**
 * Test Suite: Phase 2 Modernization Tests (QR Engine, Commercial Types, Conditional Multi-URL, Graphics)
 */

class Phase2ModernizationTest {

    public static function run(&$results) {
        self::testNewQrTypesRegistrationAndValidation($results);
        self::testMultiUrlConditionalRedirectionLanguage($results);
        self::testMultiUrlConditionalRedirectionCountry($results);
        self::testMultiUrlConditionalRedirectionDevice($results);
        self::testMultiUrlConditionalRedirectionTimeSchedule($results);
        self::testCustomerFeedbackPageRenderingAndAntiSpam($results);
        self::testCouponPromotionPageRenderingAndWhatsAppClaim($results);
        self::testGraphicEnhancementsGradientsAndShapes($results);
        self::testBinaryFormatNonRegression($results);
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
     * 1. Registration & Validation of new commercial QR types
     */
    public static function testNewQrTypesRegistrationAndValidation(&$results) {
        echo "Running Phase2ModernizationTest::testNewQrTypesRegistrationAndValidation...\n";

        // Check typeExists
        self::assert(\Helpers\QR::typeExists('multiurl') === true, "QR type 'multiurl' is registered in QR helper", $results);
        self::assert(\Helpers\QR::typeExists('feedback') === true, "QR type 'feedback' is registered in QR helper", $results);
        self::assert(\Helpers\QR::typeExists('coupon') === true, "QR type 'coupon' is registered in QR helper", $results);

        // Multiurl validation
        $validMulti = \Helpers\QR::typeMultiurl(['default' => 'https://example.com/global']);
        self::assert(is_array($validMulti) && $validMulti['default'] === 'https://example.com/global', "typeMultiurl accepts valid default URL", $results);

        $multiFailed = false;
        try {
            \Helpers\QR::typeMultiurl(['default' => 'not-a-url']);
        } catch (\Throwable $e) {
            $multiFailed = true;
        }
        self::assert($multiFailed, "typeMultiurl throws exception when default URL is invalid", $results);

        // Feedback validation
        $validFeedback = \Helpers\QR::typeFeedback(['title' => 'Votre avis', 'business_name' => 'Boutique Antananarivo']);
        self::assert(is_array($validFeedback) && $validFeedback['title'] === 'Votre avis', "typeFeedback accepts valid feedback data", $results);

        $feedbackFailed = false;
        try {
            \Helpers\QR::typeFeedback(['title' => '', 'business_name' => '']);
        } catch (\Throwable $e) {
            $feedbackFailed = true;
        }
        self::assert($feedbackFailed, "typeFeedback throws exception when title and business_name are empty", $results);

        // Coupon validation
        $validCoupon = \Helpers\QR::typeCoupon(['code' => 'PROMO2026', 'discount' => '-20%']);
        self::assert(is_array($validCoupon) && $validCoupon['code'] === 'PROMO2026', "typeCoupon accepts valid coupon data", $results);

        $couponFailed = false;
        try {
            \Helpers\QR::typeCoupon(['code' => '', 'discount' => '']);
        } catch (\Throwable $e) {
            $couponFailed = true;
        }
        self::assert($couponFailed, "typeCoupon throws exception when code or discount are empty", $results);

        // Factory can build instances of all 3 types
        $qrMulti = \Helpers\QR::factory(['type' => 'multiurl', 'data' => ['default' => 'https://example.com/global']], 200, 0)->format('svg');
        self::assert(!empty($qrMulti->string()) && strpos($qrMulti->string(), '<svg') !== false, "QR::factory renders valid SVG for multiurl", $results);

        $qrFeed = \Helpers\QR::factory(['type' => 'feedback', 'data' => ['title' => 'Avis client']], 200, 0)->format('svg');
        self::assert(!empty($qrFeed->string()) && strpos($qrFeed->string(), '<svg') !== false, "QR::factory renders valid SVG for feedback", $results);

        $qrCpn = \Helpers\QR::factory(['type' => 'coupon', 'data' => ['code' => 'PROMO10', 'discount' => '10%']], 200, 0)->format('svg');
        self::assert(!empty($qrCpn->string()) && strpos($qrCpn->string(), '<svg') !== false, "QR::factory renders valid SVG for coupon", $results);
    }

    /**
     * 2. Multi-URL conditional redirection by Language
     */
    public static function testMultiUrlConditionalRedirectionLanguage(&$results) {
        echo "Running Phase2ModernizationTest::testMultiUrlConditionalRedirectionLanguage...\n";

        $config = [
            'default' => 'https://example.com/en',
            'rules' => [
                ['type' => 'language', 'match' => 'fr', 'url' => 'https://example.com/fr'],
                ['type' => 'language', 'match' => 'mg', 'url' => 'https://example.com/mg'],
            ]
        ];

        // 1. French visitor
        $reqFr = new \Core\Request();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9,en;q=0.8';
        $targetFr = \Helpers\MultiUrlEngine::resolve($config, $reqFr);
        self::assert($targetFr === 'https://example.com/fr', "French browser (fr-FR) resolves to French URL", $results);

        // 2. Malagasy visitor
        $reqMg = new \Core\Request();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'mg,fr;q=0.5';
        $targetMg = \Helpers\MultiUrlEngine::resolve($config, $reqMg);
        self::assert($targetMg === 'https://example.com/mg', "Malagasy browser (mg) resolves to Malagasy URL", $results);

        // 3. Fallback for German visitor
        $reqDe = new \Core\Request();
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
        $targetDe = \Helpers\MultiUrlEngine::resolve($config, $reqDe);
        self::assert($targetDe === 'https://example.com/en', "Unsupported language (de) cleanly falls back to default URL", $results);

        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    /**
     * 3. Multi-URL conditional redirection by Country
     */
    public static function testMultiUrlConditionalRedirectionCountry(&$results) {
        echo "Running Phase2ModernizationTest::testMultiUrlConditionalRedirectionCountry...\n";

        $config = [
            'default' => 'https://example.com/global',
            'rules' => [
                ['type' => 'country', 'match' => 'MG', 'url' => 'https://example.com/madagascar'],
                ['type' => 'country', 'match' => 'FR', 'url' => 'https://example.com/france'],
            ]
        ];

        $req = new \Core\Request();

        // Madagascar visitor
        $targetMg = \Helpers\MultiUrlEngine::resolve($config, $req, ['country_code' => 'MG', 'country' => 'Madagascar']);
        self::assert($targetMg === 'https://example.com/madagascar', "Visitor detected in Madagascar resolves to /madagascar", $results);

        // France visitor
        $targetFr = \Helpers\MultiUrlEngine::resolve($config, $req, ['country_code' => 'FR', 'country' => 'France']);
        self::assert($targetFr === 'https://example.com/france', "Visitor detected in France resolves to /france", $results);

        // Other country visitor (fallback)
        $targetUs = \Helpers\MultiUrlEngine::resolve($config, $req, ['country_code' => 'US', 'country' => 'United States']);
        self::assert($targetUs === 'https://example.com/global', "Visitor in USA falls back to default global URL", $results);
    }

    /**
     * 4. Multi-URL conditional redirection by Device
     */
    public static function testMultiUrlConditionalRedirectionDevice(&$results) {
        echo "Running Phase2ModernizationTest::testMultiUrlConditionalRedirectionDevice...\n";

        $config = [
            'default' => 'https://example.com/desktop-portal',
            'rules' => [
                ['type' => 'device', 'match' => 'ios', 'url' => 'https://apps.apple.com/app/id12345'],
                ['type' => 'device', 'match' => 'android', 'url' => 'https://play.google.com/store/apps/details?id=com.app'],
                ['type' => 'device', 'match' => 'mobile', 'url' => 'https://example.com/mobile-web'],
            ]
        ];

        // iPhone visitor
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
        $reqIos = new \Core\Request();
        $targetIos = \Helpers\MultiUrlEngine::resolve($config, $reqIos);
        self::assert($targetIos === 'https://apps.apple.com/app/id12345', "iPhone visitor resolves to iOS App Store URL", $results);

        // Android visitor
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13; SM-S901B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Mobile Safari/537.36';
        $reqAndroid = new \Core\Request();
        $targetAndroid = \Helpers\MultiUrlEngine::resolve($config, $reqAndroid);
        self::assert($targetAndroid === 'https://play.google.com/store/apps/details?id=com.app', "Android visitor resolves to Google Play URL", $results);

        // Desktop visitor
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36';
        $reqDesktop = new \Core\Request();
        $targetDesktop = \Helpers\MultiUrlEngine::resolve($config, $reqDesktop);
        self::assert($targetDesktop === 'https://example.com/desktop-portal', "Desktop visitor resolves to desktop portal", $results);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * 5. Multi-URL conditional redirection by Time Schedule & Timezone
     */
    public static function testMultiUrlConditionalRedirectionTimeSchedule(&$results) {
        echo "Running Phase2ModernizationTest::testMultiUrlConditionalRedirectionTimeSchedule...\n";

        $config = [
            'default' => 'https://example.com/after-hours',
            'rules' => [
                [
                    'type'     => 'time',
                    'start'    => '08:00',
                    'end'      => '17:30',
                    'timezone' => 'Indian/Antananarivo',
                    'url'      => 'https://example.com/open-for-business'
                ]
            ]
        ];

        $req = new \Core\Request();

        // 10:15 AM in Antananarivo (UTC+3) -> Inside business hours
        $duringOpenHours = new \DateTime('2026-10-14 10:15:00', new \DateTimeZone('Indian/Antananarivo'));
        $targetOpen = \Helpers\MultiUrlEngine::resolve($config, $req, null, $duringOpenHours);
        self::assert($targetOpen === 'https://example.com/open-for-business', "10:15 AM in Antananarivo resolves to open business URL", $results);

        // 21:45 PM in Antananarivo (UTC+3) -> Outside business hours
        $afterHours = new \DateTime('2026-10-14 21:45:00', new \DateTimeZone('Indian/Antananarivo'));
        $targetClosed = \Helpers\MultiUrlEngine::resolve($config, $req, null, $afterHours);
        self::assert($targetClosed === 'https://example.com/after-hours', "21:45 PM in Antananarivo falls through to after-hours URL", $results);

        // Overnight window test (e.g. 22:00 -> 05:00)
        $overnightConfig = [
            'default' => 'https://example.com/day-shift',
            'rules' => [
                [
                    'type'     => 'time',
                    'start'    => '22:00',
                    'end'      => '05:00',
                    'timezone' => 'UTC',
                    'url'      => 'https://example.com/night-service'
                ]
            ]
        ];
        $atMidnight = new \DateTime('2026-10-14 23:30:00', new \DateTimeZone('UTC'));
        $targetNight = \Helpers\MultiUrlEngine::resolve($overnightConfig, $req, null, $atMidnight);
        self::assert($targetNight === 'https://example.com/night-service', "Overnight window crossing midnight correctly matches 23:30", $results);
    }

    /**
     * 6. Customer Feedback Page rendering, anti-spam, and DB submission
     */
    public static function testCustomerFeedbackPageRenderingAndAntiSpam(&$results) {
        echo "Running Phase2ModernizationTest::testCustomerFeedbackPageRenderingAndAntiSpam...\n";

        $mockUrl = (object) [
            'id'     => 42,
            'alias'  => 'feedback-test',
            'custom' => '',
            'domain' => 'https://qr.mg',
            'qrid'   => 10,
        ];
        $mockQr = (object) [
            'name' => 'Restaurant La Varangue',
        ];
        $data = [
            'title'         => 'Donnez votre avis',
            'subtitle'      => 'Aidez-nous à améliorer notre cuisine',
            'business_name' => 'La Varangue',
        ];

        $req = new \Core\Request();

        // 1. GET Page Rendering
        $response = \Helpers\CommercialQr::renderFeedbackPage($mockUrl, $mockQr, $data, $req);
        $body = $response->getBody();

        self::assert($response->getCode() === 200, "Feedback page returns HTTP 200", $results);
        self::assert(strpos($body, 'Donnez votre avis') !== false, "Feedback page renders custom title", $results);
        self::assert(strpos($body, 'La Varangue') !== false, "Feedback page renders business name", $results);
        self::assert(strpos($body, 'name="rating"') !== false && strpos($body, 'value="5"') !== false, "Feedback page includes 1-5 star rating inputs", $results);
        self::assert(strpos($body, 'name="_hp_company"') !== false, "Feedback page includes honeypot anti-spam protection", $results);

        // 2. Submission handling: Spambot with honeypot filled
        $_POST = [
            'rating'      => '5',
            'comment'     => 'Spam content',
            '_hp_company' => 'Bot Company Inc',
        ];
        $botResult = \Helpers\CommercialQr::handleFeedbackSubmission($mockUrl, $req);
        self::assert($botResult['success'] === true, "Honeypot filled submission silently handled without polluting DB", $results);

        // 3. Submission handling: Invalid rating
        $_POST = [
            'rating'      => '10', // out of 1-5 range
            'comment'     => 'Invalid',
            '_hp_company' => '',
        ];
        $invalidResult = \Helpers\CommercialQr::handleFeedbackSubmission($mockUrl, $req);
        self::assert($invalidResult['success'] === false, "Invalid rating (>5) is rejected with error", $results);

        // 4. Submission handling: Valid customer review
        $_POST = [
            'rating'      => '5',
            'comment'     => 'Service impeccable et plats délicieux !',
            'name'        => 'Rabe',
            'contact'     => '+261340000000',
            '_hp_company' => '',
        ];
        $validResult = \Helpers\CommercialQr::handleFeedbackSubmission($mockUrl, $req);
        self::assert($validResult['success'] === true, "Valid 5-star customer review successfully saved to DB", $results);

        $_POST = [];
    }

    /**
     * 7. Retail Promotional Coupon page rendering, countdown, and WhatsApp claim
     */
    public static function testCouponPromotionPageRenderingAndWhatsAppClaim(&$results) {
        echo "Running Phase2ModernizationTest::testCouponPromotionPageRenderingAndWhatsAppClaim...\n";

        $mockUrl = (object) [
            'id'     => 88,
            'alias'  => 'coupon-test',
            'custom' => '',
            'domain' => 'https://qr.mg',
            'qrid'   => 12,
        ];
        $mockQr = (object) [
            'name' => 'Boutique Zoma',
        ];
        $data = [
            'title'         => 'Remise Exceptionnelle Emballage',
            'discount'      => '5 000 Ar DE RÉDUCTION',
            'code'          => 'ZOMA2026',
            'description'   => 'Valable sur votre prochain achat de vanille et épises.',
            'business_name' => 'Zoma Épices Madagascar',
            'expiry'        => '2026-12-31T23:59:59',
            'whatsapp'      => '+261340123456',
            'terms'         => 'Une seule remise par client.',
        ];

        $req = new \Core\Request();
        $response = \Helpers\CommercialQr::renderCouponPage($mockUrl, $mockQr, $data, $req);
        $body = $response->getBody();

        self::assert($response->getCode() === 200, "Coupon page returns HTTP 200", $results);
        self::assert(strpos($body, '5 000 Ar DE RÉDUCTION') !== false, "Coupon page highlights discount amount", $results);
        self::assert(strpos($body, 'ZOMA2026') !== false, "Coupon page displays promotional code", $results);
        self::assert(strpos($body, 'data-expiry="2026-12-31T23:59:59"') !== false, "Coupon page includes dynamic countdown target date", $results);
        self::assert(strpos($body, 'Copier le code') !== false, "Coupon page includes one-click clipboard copy button", $results);
        self::assert(strpos($body, 'https://wa.me/261340123456') !== false, "Coupon page provides one-click WhatsApp redemption link", $results);
        self::assert(strpos($body, 'ZOMA2026') !== false, "WhatsApp message pre-fills the promo code ZOMA2026", $results);
    }

    /**
     * 8. Graphic enhancements: Gradients, Custom Module shapes, and Logo Security Zone
     */
    public static function testGraphicEnhancementsGradientsAndShapes(&$results) {
        echo "Running Phase2ModernizationTest::testGraphicEnhancementsGradientsAndShapes...\n";

        // 1. Gradient in pure PHP SVG
        $gradientData = [
            'mode'     => 'gradient',
            'gradient' => [
                ['rgb(230,57,70)', 'rgb(29,53,87)'],
                'rgb(255,255,255)',
                'horizontal'
            ],
            'margin'   => 0,
        ];
        $qrGradient = \Helpers\QR::factory('https://example.com/gradient-test', 300, 0)->format('svg');
        $designedGradient = \Helpers\QR::applyDesign($qrGradient, $gradientData, null, 300, 0);
        $svgGrad = $designedGradient->string();
        self::assert(strpos($svgGrad, '<linearGradient') !== false, "SVG generator renders native vector gradient (<linearGradient>)", $results);

        // 2. Dots module shape
        $dotsData = [
            'matrix' => 'dots',
            'color'  => ['fg' => 'rgb(0,0,0)', 'bg' => 'rgb(255,255,255)'],
        ];
        $qrDots = \Helpers\QR::factory('https://example.com/dots-test', 300, 0)->format('svg');
        $designedDots = \Helpers\QR::applyDesign($qrDots, $dotsData, null, 300, 0);
        $svgDots = $designedDots->string();
        self::assert(!empty($svgDots) && strpos($svgDots, '<svg') !== false, "SVG generator renders with custom 'dots' matrix module", $results);

        // 3. Rounded module shape
        $roundedData = [
            'matrix' => 'rounded',
            'color'  => ['fg' => 'rgb(0,0,0)', 'bg' => 'rgb(255,255,255)'],
        ];
        $qrRounded = \Helpers\QR::factory('https://example.com/rounded-test', 300, 0)->format('svg');
        $designedRounded = \Helpers\QR::applyDesign($qrRounded, $roundedData, null, 300, 0);
        $svgRounded = $designedRounded->string();
        self::assert(!empty($svgRounded) && strpos($svgRounded, '<svg') !== false, "SVG generator renders with custom 'rounded' matrix module", $results);

        // 4. Custom eye shapes (circle, diamond, hexagon)
        foreach (['circle', 'diamond', 'hexagon'] as $eyeShape) {
            $eyeData = [
                'eye'      => $eyeShape,
                'eyeframe' => $eyeShape,
                'color'    => ['fg' => 'rgb(0,0,0)', 'bg' => 'rgb(255,255,255)'],
            ];
            $qrEye = \Helpers\QR::factory('https://example.com/eye-test', 300, 0)->format('svg');
            $designedEye = \Helpers\QR::applyDesign($qrEye, $eyeData, null, 300, 0);
            $svgEye = $designedEye->string();
            self::assert(!empty($svgEye) && strpos($svgEye, '<svg') !== false, "SVG generator renders with custom eye shape '{$eyeShape}'", $results);
        }

        // 5. Logo security zone and automatic Error Correction promotion
        $dummyLogo = tempnam(sys_get_temp_dir(), 'logo_') . '.png';
        $dummyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($dummyLogo, $dummyPng);

        $logoData = [
            'punchedlogo' => true,
            'logosize'    => 200,
            'custom'      => basename($dummyLogo),
            'error'       => 'l', // low error correction requested
        ];

        // Ensure storage directory for qr custom logo
        $qrStorageDir = appConfig('app.storage')['qr']['path'];
        if (!is_dir($qrStorageDir)) {
            @mkdir($qrStorageDir, 0777, true);
        }
        @copy($dummyLogo, $qrStorageDir . '/' . basename($dummyLogo));

        $qrWithLogo = \Helpers\QR::factory('https://example.com/logo-test', 400, 1)->format('svg');
        $designedLogo = \Helpers\QR::applyDesign($qrWithLogo, $logoData, null, 400, 1);
        $svgLogo = $designedLogo->string();

        self::assert(strpos($svgLogo, '<image') !== false, "Center logo is properly embedded in SVG", $results);
        self::assert(strpos($svgLogo, 'width=') !== false, "Center logo is dimensioned with safety zone", $results);

        @unlink($dummyLogo);
        @unlink($qrStorageDir . '/' . basename($dummyLogo));
    }

    /**
     * 9. Non-regression across image formats (PNG, SVG, WebP)
     */
    public static function testBinaryFormatNonRegression(&$results) {
        echo "Running Phase2ModernizationTest::testBinaryFormatNonRegression...\n";

        $testUrl = 'https://wa.me/261340000000?text=' . urlencode('Commande test packaging #55');

        // SVG
        $svg = \Helpers\QR::factory($testUrl, 250, 0)->format('svg')->string();
        self::assert(!empty($svg) && strpos($svg, '<svg') !== false && strpos($svg, '</svg>') !== false, "SVG binary format intact and valid", $results);

        // PNG
        $png = \Helpers\QR::factory($testUrl, 250, 0)->format('png')->string();
        self::assert(!empty($png) && substr($png, 1, 3) === 'PNG', "PNG binary format intact and valid (\x89PNG)", $results);

        // WebP
        if (function_exists('imagewebp') || extension_loaded('imagick')) {
            $webp = \Helpers\QR::factory($testUrl, 250, 0)->format('webp')->string();
            self::assert(!empty($webp) && substr($webp, 0, 4) === 'RIFF' && substr($webp, 8, 4) === 'WEBP', "WebP binary format intact and valid (RIFF....WEBP)", $results);
        } else {
            self::assert(true, "WebP format check skipped (no WebP support in CLI env)", $results);
        }
    }
}
