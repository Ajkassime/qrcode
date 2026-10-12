<?php
/**
 * Test Suite: QR Code Generation & Capability Engine
 */

class QrGenerationTest {

    public static function run(&$results) {
        self::testCapabilityDetection($results);
        self::testAllBinaryFormats($results);
        self::testDimensionsAndMargins($results);
        self::testErrorCorrectionLevels($results);
        self::testQrDesignGradientsAndLogos($results);
        self::testQrGdCapabilities($results);
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

    public static function testCapabilityDetection(&$results) {
        echo "Running QrGenerationTest::testCapabilityDetection...\n";
        
        $hasImagick = \Helpers\QR::hasImagick();
        $hasGd = \Helpers\QR::hasGd();

        // SVG is supported on pure PHP without Imagick
        self::assert(\Helpers\QR::hasImagick('svg') === true, "SVG format reports true capability regardless of imagick extension", $results);

        // Raster checks actual extension
        $expectedImagick = extension_loaded('imagick');
        self::assert(\Helpers\QR::hasImagick('png') === $expectedImagick, "Raster PNG checks ext-imagick correctly (detected: " . ($expectedImagick ? 'yes' : 'no') . ")", $results);

        $expectedGd = extension_loaded('gd');
        self::assert($hasGd === $expectedGd, "hasGd() accurately reflects GD extension availability", $results);
    }

    public static function testAllBinaryFormats(&$results) {
        echo "Running QrGenerationTest::testAllBinaryFormats...\n";

        // 1. SVG Format
        $svgQr = \Helpers\QR::factory('https://example.com', 250, 1)->format('svg');
        $svgData = $svgQr->create('raw');
        self::assert(!empty($svgData) && strpos($svgData, '<svg') !== false && strpos($svgData, '</svg>') !== false, "SVG generator produces valid <svg> XML document", $results);
        self::assert($svgQr->extension() === 'svg', "SVG QR reports 'svg' extension", $results);
        self::assert($svgQr->getMimeType() === 'image/svg+xml', "SVG QR reports 'image/svg+xml' MIME type", $results);

        // 2. PNG Format
        $pngQr = \Helpers\QR::factory('https://example.com', 250, 1)->format('png');
        $pngData = $pngQr->create('raw');
        self::assert(!empty($pngData) && substr($pngData, 1, 3) === 'PNG', "PNG generator produces valid PNG binary header (\x89PNG)", $results);
        self::assert($pngQr->extension() === 'png', "PNG QR reports 'png' extension", $results);
        self::assert($pngQr->getMimeType() === 'image/png', "PNG QR reports 'image/png' MIME type", $results);

        // 3. PDF Format (Pure PHP via PdfWriter + FPDF)
        $pdfQr = \Helpers\QR::factory('https://example.com', 250, 1)->format('pdf');
        $pdfData = $pdfQr->create('raw');
        self::assert(!empty($pdfData) && substr($pdfData, 0, 5) === '%PDF-', "PDF generator produces genuine PDF document with '%PDF-' header", $results);
        self::assert($pdfQr->extension() === 'pdf', "PDF QR reports 'pdf' extension", $results);
        self::assert($pdfQr->getMimeType() === 'application/pdf', "PDF QR reports 'application/pdf' MIME type", $results);

        // 4. WebP Format
        if (function_exists('imagewebp') || extension_loaded('imagick')) {
            $webpQr = \Helpers\QR::factory('https://example.com', 250, 1)->format('webp');
            $webpData = $webpQr->create('raw');
            self::assert(!empty($webpData) && substr($webpData, 0, 4) === 'RIFF' && substr($webpData, 8, 4) === 'WEBP', "WebP generator produces genuine WebP document (RIFF....WEBP)", $results);
            self::assert($webpQr->extension() === 'webp', "WebP QR reports 'webp' extension", $results);
            self::assert($webpQr->getMimeType() === 'image/webp', "WebP QR reports 'image/webp' MIME type", $results);
        } else {
            self::assert(true, "Skipped WebP test (imagewebp not available in environment)", $results);
        }
    }

    public static function testDimensionsAndMargins(&$results) {
        echo "Running QrGenerationTest::testDimensionsAndMargins...\n";

        $mockQrData = (object) [
            'mode' => 'color',
            'color' => (object) ['fg' => 'rgb(0,0,0)', 'bg' => 'rgb(255,255,255)'],
            'margin' => 4,
            'error' => 'm',
        ];

        $targetSize = 450;
        $targetMargin = 4;

        $qr = \Helpers\QR::factory('https://example.com', $targetSize, $targetMargin)->format('svg');
        $designed = \Helpers\QR::applyDesign($qr, $mockQrData, null, $targetSize, $targetMargin);
        $svg = $designed->create('raw');

        // Extract width and height from SVG root element
        preg_match('/width="(\d+)"/', $svg, $widthMatches);
        preg_match('/height="(\d+)"/', $svg, $heightMatches);

        self::assert(isset($widthMatches[1]) && (int)$widthMatches[1] === $targetSize, "SVG width attribute matches requested size ({$targetSize})", $results);
        self::assert(isset($heightMatches[1]) && (int)$heightMatches[1] === $targetSize, "SVG height attribute matches requested size ({$targetSize})", $results);
    }

    public static function testErrorCorrectionLevels(&$results) {
        echo "Running QrGenerationTest::testErrorCorrectionLevels...\n";

        foreach (['l', 'm', 'q', 'h'] as $level) {
            $qr = \Helpers\QR::factory('https://example.com', 300, 0)->format('svg');
            $qr->errorCorrection($level);
            $output = $qr->create('raw');

            self::assert(!empty($output) && strpos($output, '<svg') !== false, "Error correction level '{$level}' produces valid SVG", $results);
        }
    }

    public static function testQrDesignGradientsAndLogos(&$results) {
        echo "Running QrGenerationTest::testQrDesignGradientsAndLogos...\n";

        // 1. Vector gradient support in pure PHP
        $mockGradientData = (object) [
            'mode' => 'gradient',
            'gradient' => [['rgb(255,0,0)', 'rgb(0,0,255)'], 'rgb(255,255,255)', 'vertical'],
            'margin' => 0,
            'error' => 'm',
        ];

        $qrGradient = \Helpers\QR::factory('https://example.com', 300, 0)->format('svg');
        $designedGradient = \Helpers\QR::applyDesign($qrGradient, $mockGradientData, null, 300, 0);
        $gradientSvg = $designedGradient->create('raw');

        self::assert(strpos($gradientSvg, '<linearGradient') !== false, "Pure PHP SVG rendering preserves gradient definitions (<linearGradient>)", $results);

        // 2. Logo embedding in SVG
        $dummyLogo = tempnam(sys_get_temp_dir(), 'logo_') . '.png';
        // Create 1x1 dummy PNG
        $dummyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($dummyLogo, $dummyPng);

        $qrLogo = \Helpers\QR::factory('https://example.com', 400, 2)->format('svg');
        $qrLogo->withLogo($dummyLogo, 60);
        $logoSvg = $qrLogo->create('raw');

        self::assert(strpos($logoSvg, '<image') !== false, "SVG generator properly embeds logo via <image> element", $results);
        self::assert(strpos($logoSvg, 'width="60"') !== false, "Logo sizing in SVG accurately reflects requested width (60)", $results);

        @unlink($dummyLogo);
    }

    public static function testQrGdCapabilities(&$results) {
        echo "Running QrGenerationTest::testQrGdCapabilities...\n";

        $gd = new \Helpers\QrGd('https://example.com', 200, 1);
        self::assert($gd->supportsCustomization('color') === true, "QrGd explicitly supports 'color' customization", $results);
        self::assert($gd->supportsCustomization('logo') === true, "QrGd explicitly supports 'logo' customization", $results);
        self::assert($gd->supportsCustomization('matrix') === false, "QrGd explicitly does not natively support vector 'matrix' styling", $results);

        // Track unsupported features when invoked
        $gd->module('heart');
        $unsupported = $gd->getUnsupportedFeatures();
        self::assert(isset($unsupported['module']) && $unsupported['module'] === 'heart', "QrGd accurately tracks requested unsupported customization ('heart')", $results);
    }
}
