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
            $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
            file_put_contents($tmpFile, '<?php echo "evil"; ?>');

            $_FILES['test_file'] = [
                'name' => "exploit.{$ext}",
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => 100,
            ];

            // Instantiate Request AFTER $_FILES is populated so catchFile() processes it
            $request = new \Core\Request();
            $uploaded = $request->file('test_file');

            self::assert(
                is_object($uploaded) && $uploaded->allowed === false && $uploaded->isvalid === false,
                "Dangerous extension '.{$ext}' is strictly rejected by Request (allowed=false, isvalid=false)",
                $results
            );

            @unlink($tmpFile);
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
            $tmpFile = tempnam(sys_get_temp_dir(), 'test_name_');
            file_put_contents($tmpFile, 'PNG_DUMMY_DATA');

            $_FILES['test_file'] = [
                'name' => $input,
                'type' => 'image/png',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => 14,
            ];

            $request = new \Core\Request();
            $uploaded = $request->file('test_file');

            self::assert(
                is_object($uploaded) && strpos($uploaded->name, '..') === false && strpos($uploaded->name, "\0") === false,
                "Request sanitizes filename '{$input}' preventing directory traversal ('{$uploaded->name}')",
                $results
            );

            @unlink($tmpFile);
            unset($_FILES['test_file']);
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
        file_put_contents($uniqueDir . '/sample1.txt', 'test content 1');
        file_put_contents($uniqueDir . '/sample2.txt', 'test content 2');
        mkdir($uniqueDir . '/nested', 0755, true);
        file_put_contents($uniqueDir . '/nested/sample3.txt', 'test content 3');

        self::assert(is_dir($uniqueDir), "Test directory created successfully with nested contents", $results);

        // Run the real cleanup routine used in App::deleteFolder
        \Helpers\App::deleteFolder($uniqueDir);

        self::assert(!file_exists($uniqueDir), "Temporary directory and all nested files fully removed after cleanup routine", $results);
    }
}
