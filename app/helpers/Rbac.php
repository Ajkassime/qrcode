<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  RBAC Helper: Role-Based Access Control and Granular Server-side Permissions
 *  Independent internal development.
 * ====================================================================================
 */

namespace Helpers;

use Core\DB;
use Core\Helper;

class Rbac {

    /**
     * Cache roles in memory
     */
    private static array $rolesCache = [];

    /**
     * Create table and default system roles if not present
     */
    public static function createTable(): void {
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();
        if (!$pdo) return;

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT(11) AUTO_INCREMENT PRIMARY KEY';

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}roles` (
            `id` {$autoInc},
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(64) NOT NULL UNIQUE,
            `description` TEXT NULL,
            `permissions` TEXT NOT NULL,
            `is_system` INT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL
        )";
        $pdo->exec($sql);

        // Seed default system roles if empty
        $stmt = $pdo->query("SELECT COUNT(*) FROM `{$prefix}roles`");
        if ((int)$stmt->fetchColumn() === 0) {
            self::seedDefaultRoles();
        }
    }

    /**
     * Seed predefined system roles
     */
    public static function seedDefaultRoles(): void {
        $defaults = [
            [
                'name'        => 'Super Administrateur',
                'slug'        => 'superadmin',
                'description' => 'Accès complet et illimité à l\'ensemble de la plateforme',
                'permissions' => ['*'],
                'is_system'   => 1,
            ],
            [
                'name'        => 'Administrateur',
                'slug'        => 'admin',
                'description' => 'Gestion des liens, QR codes, campagnes et statistiques',
                'permissions' => ['links.*', 'qr.*', 'stats.*', 'users.view', 'domains.*', 'qrtemplates.*'],
                'is_system'   => 1,
            ],
            [
                'name'        => 'Gestionnaire de Campagnes',
                'slug'        => 'campaign_manager',
                'description' => 'Création et gestion des QR codes packaging et campagnes de liens',
                'permissions' => ['links.create', 'links.edit', 'links.view', 'qr.create', 'qr.edit', 'qr.view', 'stats.view', 'qrtemplates.view'],
                'is_system'   => 1,
            ],
            [
                'name'        => 'Observateur Analytique',
                'slug'        => 'viewer',
                'description' => 'Consultation des tableaux de bord et statistiques en lecture seule',
                'permissions' => ['stats.view', 'links.view', 'qr.view'],
                'is_system'   => 1,
            ],
        ];

        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}roles` (`name`, `slug`, `description`, `permissions`, `is_system`, `created_at`) VALUES (:name, :slug, :description, :permissions, :is_system, :created_at)");

        foreach ($defaults as $r) {
            $stmt->execute([
                ':name'        => $r['name'],
                ':slug'        => $r['slug'],
                ':description' => $r['description'],
                ':permissions' => json_encode($r['permissions']),
                ':is_system'   => $r['is_system'],
                ':created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Get role by ID or slug
     */
    public static function getRole($idOrSlug): ?object {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $cacheKey = (string) $idOrSlug;
        if (isset(self::$rolesCache[$cacheKey])) {
            return self::$rolesCache[$cacheKey];
        }

        if (is_numeric($idOrSlug)) {
            $stmt = $pdo->prepare("SELECT * FROM `{$prefix}roles` WHERE `id` = :val LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `{$prefix}roles` WHERE `slug` = :val LIMIT 1");
        }
        $stmt->execute([':val' => $idOrSlug]);
        $role = $stmt->fetch(\PDO::FETCH_OBJ);

        if ($role) {
            $role->permissionList = json_decode($role->permissions, true) ?: [];
            self::$rolesCache[$cacheKey] = $role;
            self::$rolesCache[(string)$role->id] = $role;
            self::$rolesCache[$role->slug] = $role;
        }

        return $role ?: null;
    }

    /**
     * Verify if a user possesses a specific permission
     */
    public static function can($user, string $permission): bool {
        if (!$user) {
            return false;
        }

        // 1. Existing Legacy Admins: absolute preservation
        // If user->admin == 1 and no specific role is assigned, grant full access
        $roleId = $user->role_id ?? null;
        if (!empty($user->admin) && empty($roleId)) {
            return true;
        }

        // 2. If user has an assigned role, evaluate permissions
        if (!empty($roleId)) {
            $role = self::getRole($roleId);
            if ($role) {
                $perms = $role->permissionList ?? [];

                // Global wildcard
                if (in_array('*', $perms, true)) {
                    return true;
                }

                // Exact match
                if (in_array($permission, $perms, true)) {
                    return true;
                }

                // Prefix wildcard (e.g., 'links.*' covers 'links.delete')
                if (strpos($permission, '.') !== false) {
                    $prefix = explode('.', $permission)[0] . '.*';
                    if (in_array($prefix, $perms, true)) {
                        return true;
                    }
                }

                return false;
            }
        }

        // 3. Fallback for standard non-admin users: standard user actions
        $standardAllowed = ['links.create', 'links.edit', 'links.view', 'qr.create', 'qr.edit', 'qr.view', 'stats.view'];
        return in_array($permission, $standardAllowed, true);
    }

    /**
     * Create a new custom role
     */
    public static function createRole(string $name, string $slug, array $permissions, string $description = ''): int {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $stmt = $pdo->prepare("INSERT INTO `{$prefix}roles` (`name`, `slug`, `description`, `permissions`, `is_system`, `created_at`) VALUES (:name, :slug, :description, :permissions, 0, :created_at)");
        $stmt->execute([
            ':name'        => Helper::clean($name, 3),
            ':slug'        => preg_replace('/[^a-z0-9_-]/', '', strtolower($slug)),
            ':description' => Helper::clean($description, 3),
            ':permissions' => json_encode(array_values(array_unique($permissions))),
            ':created_at'  => date('Y-m-d H:i:s'),
        ]);

        self::$rolesCache = [];
        return (int) $pdo->lastInsertId();
    }

    /**
     * Update an existing role
     */
    public static function updateRole(int $id, array $data): bool {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = "`name` = :name";
            $params[':name'] = Helper::clean($data['name'], 3);
        }
        if (isset($data['description'])) {
            $fields[] = "`description` = :description";
            $params[':description'] = Helper::clean($data['description'], 3);
        }
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $fields[] = "`permissions` = :permissions";
            $params[':permissions'] = json_encode(array_values(array_unique($data['permissions'])));
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE `{$prefix}roles` SET " . implode(', ', $fields) . " WHERE `id` = :id";
        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute($params);

        self::$rolesCache = [];
        return $res;
    }

    /**
     * List all roles
     */
    public static function listRoles(): array {
        self::createTable();
        $prefix = defined('DBprefix') ? DBprefix : '';
        $pdo = DB::get_db();

        $stmt = $pdo->query("SELECT * FROM `{$prefix}roles` ORDER BY `is_system` DESC, `id` ASC");
        $roles = [];
        while ($r = $stmt->fetch(\PDO::FETCH_OBJ)) {
            $r->permissionList = json_decode($r->permissions, true) ?: [];
            $roles[] = $r;
        }
        return $roles;
    }
}
