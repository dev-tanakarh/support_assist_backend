<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Env;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class AuthController
{
    public function signup(Request $request): void
    {
        $errors = $request->validateRequired(['name', 'email', 'password']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $email = strtolower(trim($request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Validation failed', 422, ['email' => 'Must be a valid email address.']);
        }

        $password = $request->input('password');
        if (strlen($password) < 8) {
            Response::error('Validation failed', 422, ['password' => 'Must be at least 8 characters.']);
        }

        if (User::findByEmail($email)) {
            Response::error('An account with this email already exists.', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        // Public signup always creates an END_USER — ADMIN/TECHNICIAN accounts
        // are created by an admin via /api/admin/technicians, never self-serve.
        $user = User::create(
            trim($request->input('name')),
            $email,
            $request->input('phone'),
            $hash,
            'END_USER'
        );

        $this->issueTokensAndRespond($user, 201);
    }

    public function login(Request $request): void
    {
        $errors = $request->validateRequired(['email', 'password']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $email = strtolower(trim($request->input('email')));
        $user = User::findByEmail($email);

        // Same error for "no such user" and "wrong password" — don't leak
        // which one it was, that's an account-enumeration vector.
        if (!$user || !password_verify($request->input('password'), $user['password_hash'])) {
            Response::error('Invalid email or password.', 401);
        }

        if (!$user['is_active']) {
            Response::error('This account has been deactivated.', 403);
        }

        $this->issueTokensAndRespond($user, 200);
    }

    /**
     * POST /api/forgot-password — {"email": "..."}.
     *
     * Always returns the same generic success message whether or not the
     * email matches an account — returning different responses for "no such
     * user" vs "reset sent" is an account-enumeration vector, same reasoning
     * as login()'s single "Invalid email or password" message.
     *
     * This project has no email-sending infrastructure (deliberately zero
     * Composer dependencies — see RUNNING.md), so instead of emailing the
     * token, it's written to the PHP error log — visible right in the
     * terminal running `php -S`, or your `docker logs` output under Docker.
     * That's fine for local dev/demo purposes but NOT how this should work
     * for real users: before this goes anywhere near production, swap the
     * error_log() call below for an actual email send (e.g. PHPMailer, or
     * any transactional email API) with a link containing the token.
     */
    public function forgotPassword(Request $request): void
    {
        $errors = $request->validateRequired(['email']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $email = strtolower(trim($request->input('email')));
        $user = User::findByEmail($email);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            Database::connection()->prepare("
                INSERT INTO password_resets (user_id, token_hash, expires_at)
                VALUES (:user_id, :hash, now() + interval '30 minutes')
            ")->execute([
                'user_id' => $user['id'],
                'hash' => hash('sha256', $token),
            ]);

            // TODO(production): replace with an actual email send.
            error_log("[PasswordReset] Reset token for {$email}: {$token} (valid 30 minutes)");
        }

        Response::success(null, 200, 'If an account with that email exists, a reset link has been sent.');
    }

    /** POST /api/reset-password — {"token": "...", "newPassword": "..."}. */
    public function resetPassword(Request $request): void
    {
        $errors = $request->validateRequired(['token', 'newPassword']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $newPassword = $request->input('newPassword');
        if (strlen($newPassword) < 8) {
            Response::error('Validation failed', 422, ['newPassword' => 'Must be at least 8 characters.']);
        }

        $tokenHash = hash('sha256', $request->input('token'));
        $stmt = Database::connection()->prepare("
            SELECT * FROM password_resets
            WHERE token_hash = :hash AND used = FALSE AND expires_at > now()
        ");
        $stmt->execute(['hash' => $tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            Response::error('This reset link is invalid or has expired.', 401);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        Database::connection()
            ->prepare('UPDATE users SET password_hash = :hash, updated_at = now() WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $reset['user_id']]);

        Database::connection()
            ->prepare('UPDATE password_resets SET used = TRUE WHERE id = :id')
            ->execute(['id' => $reset['id']]);

        // Password changed -> force re-login everywhere, same as if the
        // account had been compromised and the owner is now locking it down.
        Database::connection()
            ->prepare('UPDATE refresh_tokens SET revoked = TRUE WHERE user_id = :id')
            ->execute(['id' => $reset['user_id']]);

        Response::success(null, 200, 'Password reset. Please log in with your new password.');
    }

    public function refresh(Request $request): void
    {
        $refreshToken = $request->input('refreshToken');
        if (!$refreshToken) {
            Response::error('refreshToken is required.', 422);
        }

        $tokenHash = hash('sha256', $refreshToken);
        $stmt = Database::connection()->prepare("
            SELECT * FROM refresh_tokens
            WHERE token_hash = :hash AND revoked = FALSE AND expires_at > now()
        ");
        $stmt->execute(['hash' => $tokenHash]);
        $stored = $stmt->fetch();

        if (!$stored) {
            Response::error('Invalid or expired refresh token.', 401);
        }

        $user = User::findById($stored['user_id']);
        if (!$user) {
            Response::error('User no longer exists.', 401);
        }

        // Rotate: revoke the old refresh token, issue a new pair.
        Database::connection()
            ->prepare('UPDATE refresh_tokens SET revoked = TRUE WHERE id = :id')
            ->execute(['id' => $stored['id']]);

        $this->issueTokensAndRespond($user, 200);
    }

    private function issueTokensAndRespond(array $user, int $status): void
    {
        $accessTtl = (int) Env::get('JWT_ACCESS_TTL_SECONDS', 3600);
        $refreshTtl = (int) Env::get('JWT_REFRESH_TTL_SECONDS', 1209600);

        $accessToken = Jwt::encode([
            'sub' => $user['id'],
            'email' => $user['email'],
            'userType' => $user['user_type'],
        ], $accessTtl);

        $refreshTokenPlain = bin2hex(random_bytes(32));
        Database::connection()->prepare("
            INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
            VALUES (:user_id, :hash, now() + (:ttl || ' seconds')::interval)
        ")->execute([
            'user_id' => $user['id'],
            'hash' => hash('sha256', $refreshTokenPlain),
            'ttl' => $refreshTtl,
        ]);

        Response::success([
            'accessToken' => $accessToken,
            'refreshToken' => $refreshTokenPlain,
            'expiresIn' => $accessTtl,
            'user' => User::toPublicArray($user),
        ], $status);
    }
}
