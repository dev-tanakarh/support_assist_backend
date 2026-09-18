<?php

namespace App\Models;

use App\Core\Database;

class User
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $email, ?string $phone, string $passwordHash, string $userType = 'END_USER'): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, phone, password_hash, user_type)
             VALUES (:name, :email, :phone, :password_hash, :user_type)
             RETURNING *'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => $passwordHash,
            'user_type' => $userType,
        ]);
        return $stmt->fetch();
    }

    public static function allTechnicians(): array
    {
        $stmt = Database::connection()->query(
            "SELECT id, name, email, phone, created_at FROM users WHERE user_type = 'TECHNICIAN' AND is_active = TRUE ORDER BY name"
        );
        return $stmt->fetchAll();
    }

    public static function all(?string $userType = null): array
    {
        $sql = "
            SELECT u.id, u.name, u.email, u.phone, u.user_type, u.is_active, u.created_at,
                   AVG(r.rating) AS avg_rating, COUNT(r.id) AS review_count
            FROM users u
            LEFT JOIN reviews r ON r.technician_id = u.id
        ";
        $params = [];
        if ($userType) {
            $sql .= ' WHERE u.user_type = :type';
            $params['type'] = $userType;
        }
        $sql .= ' GROUP BY u.id ORDER BY u.created_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Stores/overwrites the device's push token. Called on login and whenever FCM rotates it. */
    public static function updateFcmToken(string $id, string $token): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET fcm_token = :token, updated_at = now() WHERE id = :id'
        );
        return $stmt->execute(['token' => $token, 'id' => $id]);
    }

    /** Null-safe: not every user has installed the app / granted notification permission. */
    public static function fcmToken(string $id): ?string
    {
        $stmt = Database::connection()->prepare('SELECT fcm_token FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $token = $stmt->fetchColumn();
        return $token ?: null;
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public static function setActive(string $id, bool $isActive): bool
    {
        $stmt = Database::connection()->prepare('UPDATE users SET is_active = :active, updated_at = now() WHERE id = :id');
        return $stmt->execute(['active' => $isActive, 'id' => $id]);
    }

    public static function toPublicArray(array $user): array
    {
        $result = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'userType' => $user['user_type'],
            'isActive' => (bool) $user['is_active'],
            'createdAt' => $user['created_at'],
        ];

        // Only present when this row came from User::all() (the admin user
        // list) — PDO_PGSQL returns AVG()/COUNT() as strings, same
        // stringification gotcha as the dashboard stats, hence the casts.
        if (array_key_exists('avg_rating', $user)) {
            $result['rating'] = $user['avg_rating'] !== null ? round((float) $user['avg_rating'], 1) : null;
            $result['reviewCount'] = (int) $user['review_count'];
        }

        return $result;
    }
}
