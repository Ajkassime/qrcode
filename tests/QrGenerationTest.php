<?php
/**
 * Test Suite: QR Code Generation & Capability Engine
 */

class QrGenerationTest {

    public static function run(&$results) {
        self::testCapabilityDetection($results);
        self::testSvgGeneration($results);
        self::testPngGenerationFallback($results);
        self::testErrorCorrectionLevels($results);
        self::testQrDesignApplication($results);
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

    public static function testSvgGeneration(&$results) {
        echo "Running QrGenerationTest::testSvgGeneration...\n";

        $qr = \Helpers\QR::factory('https://example.com', 300, 2)->format('svg');
        $output = $qr->create('raw');

        self::assert(!empty($output), "QR create('raw') produces non-empty output", $results);
        self::assert(strpos($output, '<svg') !== false, "Output contains valid <svg root element", $results);
        self::assert(strpos($output, '</svg>') !== false, "Output contains closing </svg> tag", $results);
    }

    public static function testPngGenerationFallback(&$results) {
        echo "Running QrGenerationTest::testPngGenerationFallback...\n";

        if (extension_loaded('gd')) {
            $gdQr = new \Helpers\QrGd('https://example.com', 200, 1);
            $gdQr->format('png');
            $data = $gdQr->string();

            self::assert(!empty($data), "QrGd returns non-empty PNG string without terminating execution", $results);
            self::assert(substr($data, 1, 3) === 'PNG', "QrGd output has valid PNG signature", $results);
        } else {
            self::assert(true, "Skipped QrGd test (ext-gd not loaded in current environment)", $results);
        }
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

    public static function testQrDesignApplication(&$results) {
        echo "Running QrGenerationTest::testQrDesignApplication...\n";

        $mockQrData = (object) [
            'mode' => 'color',
            'color' => (object) ['fg' => 'rgb(30,30,30)', 'bg' => 'rgb(255,255,255)'],
            'margin' => 1,
            'error' => 'm',
        ];

        $mockUser = new class {
            public function has($feature) { return true; }
        };

        $qr = \Helpers\QR::factory('https://example.com', 300, 1)->format('svg');
        $designed = \Helpers\QR::applyDesign($qr, $mockQrData, $mockUser, 300, 1);
        $output = $designed->create('raw');

        self::assert(!empty($output), "QR::applyDesign generates valid rendered output", $results);
        self::assert(strpos($output, '<svg') !== false, "Designed QR contains SVG root element", $results);
    }
}
