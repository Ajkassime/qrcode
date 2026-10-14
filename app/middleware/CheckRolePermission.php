<?php
/**
 * ====================================================================================
 *                           Mission Codex — Modernisation 2026
 * ----------------------------------------------------------------------------------
 *  Middleware: CheckRolePermission
 *  Server-side RBAC protection preventing unauthorized access and privilege escalation.
 * ====================================================================================
 */

namespace Middleware;

use Core\Auth;
use Core\Helper;
use Core\Response;
use Helpers\Rbac;

class CheckRolePermission {

    /**
     * Check permission on current request
     */
    public static function check(string $permission): bool {
        if (!Auth::check()) {
            if (request()->isAjax()) {
                Response::factory(['error' => 'Authentification requise.'], 401)->json();
                exit;
            }
            Helper::redirect('user/login')->with('danger', 'Veuillez vous connecter pour accéder à cette ressource.');
            exit;
        }

        $user = Auth::user();
        if (!Rbac::can($user, $permission)) {
            if (request()->isAjax()) {
                Response::factory([
                    'error'      => 'Accès interdit: permission insuffisante.',
                    'permission' => $permission
                ], 403)->json();
                exit;
            }

            \GemError::trigger(403, "Accès refusé. Vous ne disposez pas de la permission requise ({$permission}).");
            exit;
        }

        return true;
    }
}
