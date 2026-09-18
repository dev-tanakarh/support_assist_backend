<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Alert;
use App\Models\User;

class AdminController
{
    /** GET /api/admin/users?type=END_USER|TECHNICIAN|ADMIN (optional) */
    public function listUsers(Request $request): void
    {
        $type = $request->query['type'] ?? null;
        $users = User::all($type);
        Response::success(array_map(fn($u) => User::toPublicArray($u), $users));
    }

    /** POST /api/admin/technicians — create a technician account. */
    public function createTechnician(Request $request): void
    {
        $errors = $request->validateRequired(['name', 'email', 'password']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $email = strtolower(trim($request->input('email')));
        if (User::findByEmail($email)) {
            Response::error('An account with this email already exists.', 409);
        }

        $hash = password_hash($request->input('password'), PASSWORD_BCRYPT, ['cost' => 10]);
        $technician = User::create(
            trim($request->input('name')),
            $email,
            $request->input('phone'),
            $hash,
            'TECHNICIAN'
        );

        Response::success(User::toPublicArray($technician), 201, 'Technician created.');
    }

    /** DELETE /api/admin/technicians/{id} */
    public function deleteTechnician(Request $request): void
    {
        $id = $request->params['id'];
        $user = User::findById($id);
        if (!$user || $user['user_type'] !== 'TECHNICIAN') {
            Response::error('Technician not found.', 404);
        }

        User::delete($id);
        Response::success(null, 200, 'Technician removed.');
    }

    /**
     * PATCH /api/admin/users/{id}/status — {"isActive": true|false}.
     * Deactivated accounts are blocked at login with a clear reason — see
     * AuthController::login(), which already checked is_active before this
     * endpoint existed to actually set it.
     */
    public function setUserStatus(Request $request): void
    {
        $id = $request->params['id'];
        $user = User::findById($id);
        if (!$user) {
            Response::error('User not found.', 404);
        }
        if ($user['user_type'] === 'ADMIN') {
            Response::error('Admin accounts cannot be deactivated from here.', 403);
        }

        $isActive = $request->input('isActive');
        if ($isActive === null) {
            Response::error('isActive is required.', 422);
        }

        User::setActive($id, (bool) $isActive);
        Response::success(null, 200, $isActive ? 'Account activated.' : 'Account deactivated.');
    }

    /** POST /api/admin/alerts — broadcast a custom alert to one or all end-users. */
    public function sendAlert(Request $request): void
    {
        $errors = $request->validateRequired(['title']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $title = $request->input('title');
        $message = $request->input('message');
        $targetUserId = $request->input('userId'); // null => broadcast to all END_USERs

        $recipients = $targetUserId
            ? array_filter([User::findById($targetUserId)])
            : User::all('END_USER');

        if (!$recipients) {
            Response::error('No matching recipient(s) found.', 404);
        }

        foreach ($recipients as $recipient) {
            Alert::create($recipient['id'], $title, 'SYSTEM', $message);
        }

        Response::success(null, 200, count($recipients) . ' alert(s) sent.');
    }
}
