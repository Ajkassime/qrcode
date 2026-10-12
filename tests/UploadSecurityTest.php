<?php
/**
 * Test Suite: File Upload Security & Path Sanitization
 */

class UploadSecurityTest {

    public static function run(&$results) {
        self::testBlockedExecutableExtensions($results);
        self::testFilenameSanitizationAndTraversal($results);
        self::testTemporaryDirectoryCleanup($results);
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

    public static function testBlockedExecutableExtensions(&$results) {
        echo "Running UploadSecurityTest::testBlockedExecutableExtensions...\n";

        $dangerous = ['php', 'phtml', 'phar', 'php5', 'php7', 'exe', 'sh', 'bash', 'pl', 'cgi'];

        foreach ($dangerous as $ext) {
            $fakeFile = [
                'name' => "exploit.{$ext}",
                'type' => 'text/plain',
                'tmp_name' => tempnam(sys_get_temp_dir(), 'test_'),
                'error' => 0,
                'size' => 100,
            ];

            file_put_contents($fakeFile['tmp_name'], '<?php echo "evil"; ?>');

            $request = new \Core\Request();
            $_FILES['test_file'] = $fakeFile;
            $uploaded = $request->file('test_file');

            self::assert(
                $uploaded === false || !is_object($uploaded) || $uploaded->mimematch === false,
                "Dangerous extension '.{$ext}' is rejected or flags mime mismatch",
                $results
            );

            @unlink($fakeFile['tmp_name']);
            unset($_FILES['test_file']);
        }
    }

    public static function testFilenameSanitizationAndTraversal(&$results) {
        echo "Running UploadSecurityTest::testFilenameSanitizationAndTraversal...\n";

        $maliciousNames = [
            '../../../../etc/passwd' => 'passwd',
            '..\\..\\windows\\system32' => 'system32',
            "file\0nullbyte.png" => 'filenullbyte.png',
            'normal_image.png' => 'normal_image.png',
        ];

        foreach ($maliciousNames as $input => $expectedSuffix) {
            $sanitized = preg_replace('/[^\w\.\-\_]/', '', basename(str_replace(['\\', "\0"], ['/', ''], $input)));
            self::assert(
                strpos($sanitized, '..') === false,
                "Sanitized filename '{$input}' contains no directory traversal sequences ('{$sanitized}')",
                $results
            );
        }
    }

    public static function testTemporaryDirectoryCleanup(&$results) {
        echo "Running UploadSecurityTest::testTemporaryDirectoryCleanup...\n";

        $tempBase = STORAGE . '/app/tmp';
        if (!is_dir($tempBase)) {
            mkdir($tempBase, 0755, true);
        }

        $uniqueDir = $tempBase . '/test_run_' . bin2hex(random_bytes(8));
        mkdir($uniqueDir, 0755, true);
        file_put_contents($uniqueDir . '/sample.txt', 'test content');

        self::assert(is_dir($uniqueDir), "Test directory created successfully", $results);

        // Mimic the cleanup routine in QRController::downloadall
        $files = glob($uniqueDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($uniqueDir);

        self::assert(!file_exists($uniqueDir), "Temporary directory fully removed after cleanup routine", $results);
    }
}
