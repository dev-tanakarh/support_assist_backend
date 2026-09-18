<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class ProfileController
{
    public function show(Request $request): void
    {
        $user = User::findById($request->user['id']);
        if (!$user) {
            Response::error('User not found.', 404);
        }
        Response::success(User::toPublicArray($user));
    }

    public function update(Request $request): void
    {
        $user = User::findById($request->user['id']);
        if (!$user) {
            Response::error('User not found.', 404);
        }

        $name = $request->input('name', $user['name']);
        $phone = $request->input('phone', $user['phone']);

        $stmt = \App\Core\Database::connection()->prepare(
            'UPDATE users SET name = :name, phone = :phone, updated_at = now() WHERE id = :id RETURNING *'
        );
        $stmt->execute(['name' => $name, 'phone' => $phone, 'id' => $user['id']]);

        Response::success(User::toPublicArray($stmt->fetch()), 200, 'Profile updated.');
    }

    /** POST /api/user/fcm-token — mobile app registers/refreshes its FCM device token. */
    public function updateFcmToken(Request $request): void
    {
        $token = $request->input('fcm_token');
        if (!$token || strlen($token) > 255) {
            Response::error('Validation failed', 422, ['fcm_token' => 'Required, max 255 characters.']);
        }

        User::updateFcmToken($request->user['id'], $token);
        Response::success(null, 200, 'Token saved.');
    }
}
