<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  Phase 3 Automated Test Suite
 *  - RedisCache with Circuit Breaker and File Fallback
 *  - QR Code Templates (qrtemplates), Contrast Ratios & Packaging Decodability
 *  - RBAC Granular Server-side Roles & Permissions (roles)
 *  - Email Two-Factor Authentication (email2fa)
 *  - UTM & URL Parameter Templates (paramtemplates)
 * ====================================================================================
 */

namespace Tests;

use Core\DB;
use Helpers\RedisCache;
use Helpers\QrTemplate;
use Helpers\Rbac;
use Helpers\Email2FA;
use Helpers\UtmManager;

class Phase3ModernizationTest {

    public static function run(&$results) {
        self::testRedisCacheAndTransparentFallback($results);
        self::testQrTemplatesAndPackagingDecodability($results);
        self::testRbacRolesAndServerSidePermissions($results);
        self::testEmail2FATemporaryCodesAndAntiAbuse($results);
        self::testUtmManagerAndWhatsAppTargetSafety($results);
    }

    private static function assert(bool $condition, string $message, array &$results): void {
        if ($condition) {
            echo "  [PASS] {$message}\n";
            $results['passed'] = ($results['passed'] ?? 0) + 1;
        } else {
            echo "  [FAIL] {$message}\n";
            $results['failed'] = ($results['failed'] ?? 0) + 1;
        }
    }

    public static function testRedisCacheAndTransparentFallback(&$results): void {
        echo "Running Phase3ModernizationTest::testRedisCacheAndTransparentFallback...\n";

        RedisCache::reset();

        // 1. By default with Redis disabled, active driver is file_fallback
        self::assert(
            RedisCache::getActiveDriver() === 'file_fallback',
            "Default cache driver cleanly resolves to 'file_fallback' when Redis is disabled",
            $results
        );

        // 2. Read and write through file fallback works transparently without throwing exceptions
        $key = 'test_fallback_' . uniqid();
        $payload = ['item' => 'vanille_bourbon', 'qty' => 50];
        $stored = RedisCache::set($key, $payload, 300);
        self::assert($stored === true, "RedisCache::set succeeds via transparent file fallback", $results);

        $retrieved = RedisCache::get($key);
        self::assert(
            is_array($retrieved) && $retrieved['item'] === 'vanille_bourbon',
            "RedisCache::get accurately retrieves data via transparent fallback",
            $results
        );

        RedisCache::delete($key);
        self::assert(RedisCache::get($key) === null, "RedisCache::delete successfully purges cached entry", $results);

        // 3. Circuit breaker when Redis host is unreachable or times out
        RedisCache::configure([
            'enabled' => true,
            'host'    => '192.0.2.1', // Non-routable TEST-NET IP
            'port'    => 6379,
            'timeout' => 0.01, // 10ms timeout
        ]);

        $active = RedisCache::isRedisActive();
        self::assert($active === false, "Circuit breaker detects unreachable Redis host and returns false without fatal error", $results);
        self::assert(RedisCache::getActiveDriver() === 'file_fallback', "Active driver immediately falls back to 'file_fallback' under circuit breaker", $results);

        // 4. Mock Redis client simulation for memory operations
        $mockStorage = [];
        $mockClient = new class($mockStorage) {
            private $store;
            public function __construct(&$store) { $this->store = &$store; }
            public function get($k) { return $this->store[$k] ?? null; }
            public function setex($k, $exp, $v) { $this->store[$k] = $v; return true; }
            public function set($k, $v) { $this->store[$k] = $v; return true; }
            public function del($k) { unset($this->store[$k]); return 1; }
            public function keys($pattern) { return array_keys($this->store); }
        };

        RedisCache::reset();
        RedisCache::configure(['enabled' => true]);
        RedisCache::setMockClient($mockClient);

        self::assert(RedisCache::isRedisActive() === true, "Mock Redis client activates Redis driver mode", $results);
        self::assert(RedisCache::getActiveDriver() === 'redis', "Active driver reports 'redis' when client is healthy", $results);

        RedisCache::set('mock_key', ['status' => 'fast_cached'], 60);
        $fastRead = RedisCache::get('mock_key');
        self::assert(
            is_array($fastRead) && $fastRead['status'] === 'fast_cached',
            "In-memory Redis mock writes and reads serialized objects correctly",
            $results
        );

        // 5. Quotas and rate limiting locks sanctuarisation
        self::assert(
            RedisCache::isAtomicLockSanctuaryPreserved() === true,
            "Shared quota rate-limit lock flock() is preserved on disk and never bypassed by Redis",
            $results
        );

        // 6. Latency benchmark within 30ms budget
        $bench = RedisCache::benchmark(10);
        self::assert(
            $bench['target_budget_met'] === true && $bench['avg_latency_ms'] < 30.0,
            "Cache latency benchmark ({$bench['avg_latency_ms']} ms) easily meets target budget (< 30ms)",
            $results
        );

        RedisCache::reset();
    }

    public static function testQrTemplatesAndPackagingDecodability(&$results): void {
        echo "Running Phase3ModernizationTest::testQrTemplatesAndPackagingDecodability...\n";

        // Setup test DB
        $pdo = DB::get_db();
        QrTemplate::createTable();

        // 1. Contrast ratio mathematical calculation
        $crBlackWhite = QrTemplate::contrastRatio('#000000', '#ffffff');
        self::assert($crBlackWhite >= 20.0, "Black on white contrast ratio is 21:1 ({$crBlackWhite}:1)", $results);

        $crIdentical = QrTemplate::contrastRatio('#ffffff', '#ffffff');
        self::assert($crIdentical === 1.0, "Identical colors have 1:1 contrast ratio", $results);

        $crLow = QrTemplate::contrastRatio('#777777', '#888888');
        self::assert($crLow < 2.0, "Low contrast colors yield ratio < 2.0:1 ({$crLow}:1)", $results);

        // 2. Packaging decodability validation
        $validDesign = [
            'color'            => '#000000',
            'bg'               => '#ffffff',
            'matrix'           => 'dots',
            'eye_frame'        => 'rounded',
            'error_correction' => 'M',
        ];
        $val1 = QrTemplate::validatePackagingDecodability($validDesign);
        self::assert($val1['valid'] === true && $val1['contrast_compliant'] === true, "Valid high-contrast design passes decodability check", $results);

        $lowContrastDesign = [
            'color'            => '#d0d0d0',
            'bg'               => '#ffffff',
            'error_correction' => 'L',
        ];
        $val2 = QrTemplate::validatePackagingDecodability($lowContrastDesign);
        self::assert(
            $val2['valid'] === false && !empty($val2['issues']),
            "Low-contrast design is flagged with compliance warning for retail packaging",
            $results
        );

        // 3. Automatic error correction level elevation when logo is attached
        $logoDesign = [
            'color'            => '#112233',
            'bg'               => '#ffffff',
            'logo'             => 'brand_logo.png',
            'error_correction' => 'M',
        ];
        $val3 = QrTemplate::validatePackagingDecodability($logoDesign);
        self::assert(
            $val3['adjusted_design']['error_correction'] === 'H',
            "Adding a logo automatically elevates error correction level to 'H' (30% redundancy) for packaging durability",
            $results
        );

        // 4. Save and retrieve global public template (userid = 0)
        $tmplId1 = QrTemplate::saveTemplate(
            'Modèle Packaging Export Vanille',
            $validDesign,
            0,
            'Gabarit haute résistance pour carton d\'emballage'
        );
        self::assert($tmplId1 > 0, "Global template successfully saved in qrtemplates table (ID {$tmplId1})", $results);

        $loaded1 = QrTemplate::getTemplate($tmplId1, 999);
        self::assert(
            $loaded1 !== null && $loaded1->name === 'Modèle Packaging Export Vanille',
            "Global public template is accessible to any user (ID 999)",
            $results
        );

        // 5. Save private template for User 42 and verify isolation
        $tmplId2 = QrTemplate::saveTemplate('Modèle Privé Société XYZ', $validDesign, 42, 'Usage interne');
        self::assert($tmplId2 > 0, "Private template for user 42 created", $results);

        $loadedByOwner = QrTemplate::getTemplate($tmplId2, 42);
        self::assert($loadedByOwner !== null, "Owner (user 42) can access their private template", $results);

        $loadedByOther = QrTemplate::getTemplate($tmplId2, 88);
        self::assert($loadedByOther === null, "Another user (user 88) is blocked from accessing user 42 private template", $results);

        // 6. Delete template
        $deleted = QrTemplate::deleteTemplate($tmplId2, 42);
        self::assert($deleted === true, "Template successfully deleted", $results);
    }

    public static function testRbacRolesAndServerSidePermissions(&$results): void {
        echo "Running Phase3ModernizationTest::testRbacRolesAndServerSidePermissions...\n";

        Rbac::createTable();

        // 1. System default roles seeded
        $roles = Rbac::listRoles();
        self::assert(count($roles) >= 4, "Default system roles (superadmin, admin, campaign_manager, viewer) are seeded", $results);

        $superadmin = Rbac::getRole('superadmin');
        self::assert(
            $superadmin !== null && in_array('*', $superadmin->permissionList, true),
            "Superadmin role contains global wildcard '*' permission",
            $results
        );

        $campaignMgr = Rbac::getRole('campaign_manager');
        self::assert(
            $campaignMgr !== null && in_array('qr.create', $campaignMgr->permissionList, true),
            "Campaign manager role contains 'qr.create' permission",
            $results
        );

        // 2. Legacy admin preservation: user with admin = 1 has full access
        $legacyAdmin = (object)['id' => 1, 'admin' => 1, 'role_id' => null];
        self::assert(Rbac::can($legacyAdmin, 'anything.possible') === true, "Existing legacy admin (admin=1) maintains full access (no regression)", $results);

        // 3. User with Campaign Manager role
        $campaignUser = (object)['id' => 10, 'admin' => 0, 'role_id' => $campaignMgr->id];
        self::assert(Rbac::can($campaignUser, 'qr.create') === true, "Campaign manager can create QR codes (qr.create)", $results);
        self::assert(Rbac::can($campaignUser, 'links.create') === true, "Campaign manager can create links (links.create)", $results);
        self::assert(Rbac::can($campaignUser, 'links.delete') === false, "Campaign manager cannot delete links (links.delete)", $results);
        self::assert(Rbac::can($campaignUser, 'users.manage') === false, "Campaign manager cannot manage users (users.manage)", $results);

        // 4. Viewer role: read-only stats
        $viewerRole = Rbac::getRole('viewer');
        $viewerUser = (object)['id' => 20, 'admin' => 0, 'role_id' => $viewerRole->id];
        self::assert(Rbac::can($viewerUser, 'stats.view') === true, "Viewer can view statistics (stats.view)", $results);
        self::assert(Rbac::can($viewerUser, 'qr.create') === false, "Viewer cannot create QR codes", $results);

        // 5. Custom role creation
        $customId = Rbac::createRole('Imprimeur Partenaire', 'printer_partner', ['qr.view', 'qrtemplates.view', 'qr.export'], 'Accès imprimeur packaging');
        self::assert($customId > 0, "Custom role 'printer_partner' created with specific permissions", $results);

        $printerUser = (object)['id' => 30, 'admin' => 0, 'role_id' => $customId];
        self::assert(Rbac::can($printerUser, 'qr.view') === true, "Custom role has qr.view permission", $results);
        self::assert(Rbac::can($printerUser, 'links.create') === false, "Custom role cannot create links", $results);
    }

    public static function testEmail2FATemporaryCodesAndAntiAbuse(&$results): void {
        echo "Running Phase3ModernizationTest::testEmail2FATemporaryCodesAndAntiAbuse...\n";

        Email2FA::createTable();
        $pdo = DB::get_db();
        $prefix = defined('DBprefix') ? DBprefix : '';

        // 1. Generate 6-digit OTP code
        $userId = 77;
        $otp = Email2FA::generateCode($userId);
        self::assert(strlen($otp) === 6 && ctype_digit($otp), "OTP is a 6-digit numeric string ({$otp})", $results);

        // 2. Verify code is NOT stored plaintext in database
        $stmt = $pdo->prepare("SELECT * FROM `{$prefix}email2fa` WHERE `userid` = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        self::assert($row !== false, "Record exists in email2fa table", $results);
        self::assert($row->code_hash !== $otp && strlen($row->code_hash) >= 60, "OTP is cryptographically hashed in database (never stored plaintext)", $results);

        // 3. Invalidate previous code when generating a new one
        $newOtp = Email2FA::generateCode($userId);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}email2fa` WHERE `userid` = {$userId}")->fetchColumn();
        self::assert($count === 1, "Generating new OTP automatically invalidates previous pending codes", $results);

        // 4. Failed verification increments attempts and returns remaining chances
        $resWrong = Email2FA::verifyCode($userId, '000000');
        self::assert(
            $resWrong['success'] === false && $resWrong['remaining_attempts'] === 2,
            "Wrong code fails and decrements remaining attempts (2 remaining)",
            $results
        );

        // 5. Exceeding max attempts (3) invalidates code
        Email2FA::verifyCode($userId, '111111');
        $res3 = Email2FA::verifyCode($userId, '222222');
        self::assert(
            $res3['success'] === false && $res3['remaining_attempts'] === 0,
            "Third incorrect attempt permanently invalidates code",
            $results
        );

        $existsAfterLimit = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}email2fa` WHERE `userid` = {$userId}")->fetchColumn();
        self::assert($existsAfterLimit === 0, "Code record deleted after 3 failed attempts to prevent brute-force", $results);

        // 6. Successful verification consumes code (anti-replay)
        $goodOtp = Email2FA::generateCode($userId);
        $resOk = Email2FA::verifyCode($userId, $goodOtp);
        self::assert($resOk['success'] === true, "Valid OTP verification succeeds", $results);

        $resReplay = Email2FA::verifyCode($userId, $goodOtp);
        self::assert($resReplay['success'] === false, "Replaying the same OTP is rejected (single-use consumption)", $results);

        // 7. Emergency backup codes generation
        $backupCodes = Email2FA::generateBackupCodes(8);
        self::assert(count($backupCodes) === 8, "Generates 8 emergency recovery backup codes", $results);
        self::assert(preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $backupCodes[0]['code']) === 1, "Backup code formatted as XXXX-XXXX", $results);
    }

    public static function testUtmManagerAndWhatsAppTargetSafety(&$results): void {
        echo "Running Phase3ModernizationTest::testUtmManagerAndWhatsAppTargetSafety...\n";

        UtmManager::createTable();

        // 1. Save and retrieve parameter template
        $templateData = [
            'utm_source'   => 'packaging_qr',
            'utm_medium'   => 'print_label',
            'utm_campaign' => 'export_madagascar_2026',
            'promo_batch'  => 'BATCH-42',
        ];
        $tmplId = UtmManager::saveTemplate(15, 'Campagne Emballage Vanille', $templateData);
        self::assert($tmplId > 0, "UTM parameter template saved with ID {$tmplId}", $results);

        $loaded = UtmManager::getTemplate($tmplId, 15);
        self::assert(
            $loaded !== null && $loaded->params['utm_source'] === 'packaging_qr',
            "UTM parameter template correctly retrieved with parameters intact",
            $results
        );

        // 2. Application to clean standard URL
        $url1 = 'https://example.com/product/vanilla';
        $applied1 = UtmManager::applyParameters($url1, ['utm_source' => 'qr', 'utm_medium' => 'scan']);
        self::assert(
            $applied1 === 'https://example.com/product/vanilla?utm_source=qr&utm_medium=scan',
            "Parameters cleanly appended with '?' to URL without parameters",
            $results
        );

        // 3. Application to URL with existing query string (merges cleanly)
        $url2 = 'https://example.com/shop?cat=spices&sort=price';
        $applied2 = UtmManager::applyParameters($url2, ['utm_source' => 'qr']);
        self::assert(
            strpos($applied2, 'cat=spices') !== false &&
            strpos($applied2, 'sort=price') !== false &&
            strpos($applied2, 'utm_source=qr') !== false &&
            substr_count($applied2, '?') === 1,
            "Parameters merged with existing query parameters without duplicate '?' or '&'",
            $results
        );

        // 4. Preservation of URL fragment anchor (#reviews)
        $urlWithHash = 'https://example.com/product#customer-reviews';
        $appliedWithHash = UtmManager::applyParameters($urlWithHash, ['utm_source' => 'qr_tag']);
        self::assert(
            str_ends_with($appliedWithHash, '#customer-reviews') &&
            strpos($appliedWithHash, '?utm_source=qr_tag') !== false,
            "URL fragment (#customer-reviews) preserved at the very end of the URL",
            $results
        );

        // 5. CRITICAL: WhatsApp target preservation
        $waTarget = 'https://wa.me/261340000000?text=' . urlencode('Bonjour, commande lot #2026');
        $appliedWa = UtmManager::applyParameters($waTarget, ['utm_source' => 'packaging']);
        self::assert(
            strpos($appliedWa, 'text=' . urlencode('Bonjour, commande lot #2026')) !== false &&
            strpos($appliedWa, 'utm_source=packaging') !== false,
            "WhatsApp wa.me prefilled message text is strictly preserved without corruption",
            $results
        );
    }
}
