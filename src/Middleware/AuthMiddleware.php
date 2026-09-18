<?php

namespace App\Middleware;

use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use RuntimeException;

/**
 * Verifies the Bearer token and attaches the decoded identity to
 * $request->user as ['id' => ..., 'email' => ..., 'userType' => ...].
 * Controllers never touch the JWT directly — they just read $request->user.
 */
class AuthMiddleware
{
    public function __invoke(Request $request): void
    {
        $token = $request->bearerToken();

        if (!$token) {
            Response::error('Authentication required. Missing Bearer token.', 401);
        }

        try {
            $payload = Jwt::decode($token);
        } catch (RuntimeException $e) {
            Response::error('Invalid or expired token: ' . $e->getMessage(), 401);
            return;
        }

        $request->user = [
            'id' => $payload['sub'] ?? null,
            'email' => $payload['email'] ?? null,
            'userType' => $payload['userType'] ?? null,
        ];
    }
}
