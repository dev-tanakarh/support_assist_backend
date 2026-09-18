<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Usage in routes.php:
 *   $router->get('/api/admin/users', [$adminController, 'listUsers'],
 *       [new AuthMiddleware(), RoleMiddleware::allow('ADMIN')]);
 *
 * Must run AFTER AuthMiddleware (needs $request->user populated).
 */
class RoleMiddleware
{
    public static function allow(string ...$roles): callable
    {
        return function (Request $request) use ($roles) {
            $userType = $request->user['userType'] ?? null;
            if (!in_array($userType, $roles, true)) {
                Response::error('You do not have permission to perform this action.', 403);
            }
        };
    }
}
