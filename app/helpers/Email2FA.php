<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  Email 2FA Helper (Two-Factor Authentication via Temporary One-Time Password)
 *  Independent internal development.
 * ====================================================================================
 */

namespace Helpers;

use Core\DB;
use Core\Helper;

class Email2FA {

    const MAX_ATTEMPTS = 3;
    const EXPIRY_MINUTES = 10;

    /**
     * Create table if needed
     */
    public static function createTable(): void {
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();
        if (!$pdo) return;

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT(11) AUTO_INCREMENT PRIMARY KEY';

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}email2fa` (
            `id` {$autoInc},
            `userid` INT(11) NOT NULL,
            `code_hash` VARCHAR(255) NOT NULL,
            `attempts` INT(2) NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL
        )";
        $pdo->exec($sql);
    }

    /**
     * Generate and persist a new 6-digit OTP code for a user
     * Returns the plaintext code (to be emailed to the user).
     */
    public static function generateCode(int $userid): string {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        // 1. Invalidate previous pending codes for this user
        $stmtDel = $pdo->prepare("DELETE FROM `{$prefix}email2fa` WHERE `userid` = :uid");
        $stmtDel->execute([':uid' => $userid]);

        // 2. Cryptographically secure 6-digit number (100000 - 999999)
        $plainCode = (string) random_int(100000, 999999);
        $hash = password_hash($plainCode, PASSWORD_DEFAULT);

        $expiresAt = date('Y-m-d H:i:s', time() + (self::EXPIRY_MINUTES * 60));
        $createdAt = date('Y-m-d H:i:s');

        $stmtIns = $pdo->prepare("INSERT INTO `{$prefix}email2fa` (`userid`, `code_hash`, `attempts`, `expires_at`, `created_at`) VALUES (:uid, :hash, 0, :exp, :created)");
        $stmtIns->execute([
            ':uid'     => $userid,
            ':hash'    => $hash,
            ':exp'     => $expiresAt,
            ':created' => $createdAt,
        ]);

        return $plainCode;
    }

    /**
     * Verify an OTP code entered by the user
     */
    public static function verifyCode(int $userid, string $inputCode): array {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $inputCode = trim($inputCode);

        // Fetch latest active code
        $stmt = $pdo->prepare("SELECT * FROM `{$prefix}email2fa` WHERE `userid` = :uid ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([':uid' => $userid]);
        $record = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$record) {
            return [
                'success' => false,
                'error'   => "Aucun code d'authentification actif trouvé. Veuillez en demander un nouveau.",
            ];
        }

        // Check expiration
        if (strtotime($record->expires_at) < time()) {
            $stmtDel = $pdo->prepare("DELETE FROM `{$prefix}email2fa` WHERE `id` = :id");
            $stmtDel->execute([':id' => $record->id]);
            return [
                'success' => false,
                'error'   => "Le code de vérification a expiré (validité 10 minutes). Veuillez en demander un nouveau.",
            ];
        }

        // Check maximum attempts
        if ((int)$record->attempts >= self::MAX_ATTEMPTS) {
            $stmtDel = $pdo->prepare("DELETE FROM `{$prefix}email2fa` WHERE `id` = :id");
            $stmtDel->execute([':id' => $record->id]);
            return [
                'success' => false,
                'error'   => "Nombre maximal de tentatives dépassé. Le code a été invalidé par sécurité.",
            ];
        }

        // Verify hash
        if (password_verify($inputCode, $record->code_hash)) {
            // Code is valid: consume it immediately to prevent replay attacks
            $stmtDel = $pdo->prepare("DELETE FROM `{$prefix}email2fa` WHERE `id` = :id");
            $stmtDel->execute([':id' => $record->id]);

            return [
                'success' => true,
                'message' => 'Authentification 2FA réussie.',
            ];
        }

        // Invalid code: increment attempt counter
        $newAttempts = (int)$record->attempts + 1;
        $remaining = self::MAX_ATTEMPTS - $newAttempts;

        if ($remaining <= 0) {
            $stmtDel = $pdo->prepare("DELETE FROM `{$prefix}email2fa` WHERE `id` = :id");
            $stmtDel->execute([':id' => $record->id]);
            return [
                'success'            => false,
                'remaining_attempts' => 0,
                'error'              => "Code incorrect. Nombre maximal de tentatives atteint, code invalidé.",
            ];
        }

        $stmtUp = $pdo->prepare("UPDATE `{$prefix}email2fa` SET `attempts` = :att WHERE `id` = :id");
        $stmtUp->execute([':att' => $newAttempts, ':id' => $record->id]);

        return [
            'success'            => false,
            'remaining_attempts' => $remaining,
            'error'              => "Code de vérification incorrect. Il vous reste {$remaining} tentative(s).",
        ];
    }

    /**
     * Generate set of emergency recovery backup codes
     * Returns array of ['plain' => '...', 'hash' => '...']
     */
    public static function generateBackupCodes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4))); // 8-char hex code (e.g., 4F3A8B1C)
            $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4);
            $codes[] = [
                'code' => $formatted,
                'hash' => password_hash($formatted, PASSWORD_DEFAULT),
            ];
        }
        return $codes;
    }
}
