<?php

namespace App\Models;

use App\Core\Database;
use App\Services\FcmService;

class Alert
{
    private const COLOR_MAP = [
        'STATUS_CHANGE' => '#2196F3',
        'ASSIGNMENT' => '#FF9800',
        'TECH_ACCEPTED' => '#4CAF50',
        'NEW_TICKET' => '#9C27B0',
        'REVIEW_RECEIVED' => '#FFC107',
        'SYSTEM' => '#607D8B',
    ];

    public static function create(string $userId, string $title, string $type, ?string $message = null, ?string $ticketId = null): array
    {
        $stmt = Database::connection()->prepare("
            INSERT INTO alerts (user_id, ticket_id, title, message, type, color_code)
            VALUES (:user_id, :ticket_id, :title, :message, :type, :color)
            RETURNING *
        ");
        $stmt->execute([
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'color' => self::COLOR_MAP[$type] ?? '#607D8B',
        ]);
        $alert = $stmt->fetch();

        // Push is best-effort and happens *after* the row is committed: the
        // in-app alerts list (and the long-poll endpoint below) must never
        // depend on FCM being reachable. This is the only place that sends
        // pushes — every ticket-affecting action already calls Alert::create,
        // so hooking in here covers all of them instead of duplicating an
        // FcmService::send() call in TicketController, AdminController, etc.
        $token = User::fcmToken($userId);
        if ($token) {
            FcmService::send($token, $alert['title'], $alert['message'] ?? '', [
                'type' => $alert['type'],
                'ticketId' => $alert['ticket_id'] ?? '',
            ]);
        }

        return $alert;
    }

    public static function forUser(string $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = Database::connection()->prepare("
            SELECT * FROM alerts WHERE user_id = :user_id
            ORDER BY created_at DESC LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Alerts created after $sinceUtc for this user — the "what changed" query the poll endpoint loops on. */
    public static function newerThan(string $userId, string $sinceUtc): array
    {
        $stmt = Database::connection()->prepare("
            SELECT * FROM alerts
            WHERE user_id = :user_id AND created_at > :since
            ORDER BY created_at ASC
        ");
        $stmt->execute(['user_id' => $userId, 'since' => $sinceUtc]);
        return $stmt->fetchAll();
    }

    public static function markRead(int $alertId, string $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE alerts SET is_read = TRUE WHERE id = :id AND user_id = :user_id'
        );
        return $stmt->execute(['id' => $alertId, 'user_id' => $userId]);
    }

    public static function toArray(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'title' => $a['title'],
            'message' => $a['message'],
            'type' => $a['type'],
            'colorCode' => $a['color_code'],
            'ticketId' => $a['ticket_id'],
            'isRead' => (bool) $a['is_read'],
            'timeAgo' => Ticket::timeAgo($a['created_at']),
            'createdAt' => $a['created_at'],
        ];
    }
}
