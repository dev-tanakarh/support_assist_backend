<?php

namespace App\Controllers;

use App\Core\FileUpload;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use RuntimeException;

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
        $avatarUrl = $user['avatar_url'] ?? null;
        $avatarThumbnailUrl = $user['avatar_thumbnail_url'] ?? null;
        try {
            $uploadedAvatar = FileUpload::storeProfileImage($request->file('avatar'));
            if ($uploadedAvatar !== null) {
                $avatarUrl = $uploadedAvatar['url'];
                $avatarThumbnailUrl = $uploadedAvatar['thumbnailUrl'];
            }
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }

        $stmt = \App\Core\Database::connection()->prepare(
            'UPDATE users SET name = :name, phone = :phone, avatar_url = :avatar_url, avatar_thumbnail_url = :avatar_thumbnail_url, updated_at = now() WHERE id = :id RETURNING *'
        );
        $stmt->execute(['name' => $name, 'phone' => $phone, 'avatar_url' => $avatarUrl, 'avatar_thumbnail_url' => $avatarThumbnailUrl, 'id' => $user['id']]);

        Response::success(User::toPublicArray($stmt->fetch()), 200, 'Profile updated.');
    }

    /** POST /api/profile/avatar — stores the signed-in user's profile picture. */
    public function uploadAvatar(Request $request): void
    {
        $user = User::findById($request->user['id']);
        if (!$user) {
            Response::error('User not found.', 404);
        }

        try {
            $avatar = FileUpload::storeProfileImage($request->file('avatar'));
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        if ($avatar === null) {
            Response::error('Choose an image to upload.', 422);
        }

        $stmt = \App\Core\Database::connection()->prepare(
            'UPDATE users SET avatar_url = :avatar_url, avatar_thumbnail_url = :avatar_thumbnail_url, updated_at = now() WHERE id = :id RETURNING *'
        );
        $stmt->execute([
            'avatar_url' => $avatar['url'],
            'avatar_thumbnail_url' => $avatar['thumbnailUrl'],
            'id' => $user['id'],
        ]);

        Response::success(User::toPublicArray($stmt->fetch()), 200, 'Profile picture updated.');
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
