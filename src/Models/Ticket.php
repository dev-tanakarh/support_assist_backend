<?php

namespace App\Models;

use App\Core\Database;
use PDO;

class Ticket
{
    /** SLA windows in hours, keyed by priority. Resume talking point:
     *  this is where you'd plug in a configurable SLA policy table instead
     *  of hardcoding it, if this became a real product. */
    private const SLA_HOURS = [
        'HIGH' => 4,
        'MEDIUM' => 24,
        'LOW' => 72,
    ];

    private const SELECT_BASE = "
        SELECT t.*, 
               c.name AS category_name,
               u.name AS reporter_name, u.email AS reporter_email,
               tech.name AS technician_name, tech.email AS technician_email
        FROM tickets t
        LEFT JOIN ticket_categories c ON c.id = t.category_id
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN users tech ON tech.id = t.assigned_technician_id
    ";

    public static function generateId(): string
    {
        $year = (int) date('Y');
        $stmt = Database::connection()->prepare('SELECT next_ticket_number(:year) AS id');
        $stmt->execute(['year' => $year]);
        return $stmt->fetch()['id'];
    }

    public static function create(array $data): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $id = self::generateId();
            $priority = $data['priority'] ?? 'MEDIUM';
            $slaHours = self::SLA_HOURS[$priority] ?? 24;

            $stmt = $db->prepare("
                INSERT INTO tickets
                    (id, user_id, subject, description, category_id, department,
                     device_type, room, attachment_url, attachment_thumbnail_url, priority, due_at)
                VALUES
                    (:id, :user_id, :subject, :description, :category_id, :department,
                     :device_type, :room, :attachment_url, :attachment_thumbnail_url, :priority, now() + (:sla_hours || ' hours')::interval)
                RETURNING *
            ");
            $stmt->execute([
                'id' => $id,
                'user_id' => $data['user_id'],
                'subject' => $data['subject'],
                'description' => $data['description'],
                'category_id' => $data['category_id'] ?? null,
                'department' => $data['department'] ?? null,
                'device_type' => $data['device_type'] ?? null,
                'room' => $data['room'] ?? null,
                'attachment_url' => $data['attachment_url'] ?? null,
                'attachment_thumbnail_url' => $data['attachment_thumbnail_url'] ?? null,
                'priority' => $priority,
                'sla_hours' => $slaHours,
            ]);
            $ticket = $stmt->fetch();

            // Initial status history row
            $db->prepare("
                INSERT INTO ticket_status_history (ticket_id, old_status, new_status, changed_by, note)
                VALUES (:ticket_id, NULL, 'OPEN', :changed_by, 'Ticket created')
            ")->execute(['ticket_id' => $id, 'changed_by' => $data['user_id']]);

            $db->commit();
            return self::findById($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT_BASE . ' WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Role-aware ticket listing with optional filters.
     * $viewer = ['id' => ..., 'userType' => ...]
     * $filters may include: status, priority, category_id, q (full-text search),
     * unassigned (bool, technicians browsing open pool), page, per_page
     */
    public static function listFor(array $viewer, array $filters = []): array
    {
        $where = [];
        $params = [];

        if ($viewer['userType'] === 'END_USER') {
            $where[] = 't.user_id = :viewer_id';
            $params['viewer_id'] = $viewer['id'];
        } elseif ($viewer['userType'] === 'TECHNICIAN') {
            if (!empty($filters['unassigned'])) {
                $where[] = 't.assigned_technician_id IS NULL';
            } else {
                $where[] = 't.assigned_technician_id = :viewer_id';
                $params['viewer_id'] = $viewer['id'];
            }
        }
        // ADMIN sees everything unless filters narrow it down.

        if (!empty($filters['status'])) {
            $where[] = 't.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 't.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 't.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = "t.search_vector @@ plainto_tsquery('english', :q)";
            $params['q'] = $filters['q'];
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = Database::connection()->prepare("SELECT COUNT(*) FROM tickets t $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = self::SELECT_BASE . " $whereSql ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":$k", $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public static function recentFor(array $viewer, int $limit = 4): array
    {
        $where = 't.user_id = :viewer_id';
        if ($viewer['userType'] === 'TECHNICIAN') {
            $where = 't.assigned_technician_id = :viewer_id';
        } elseif ($viewer['userType'] === 'ADMIN') {
            $where = '1=1';
        }

        $sql = self::SELECT_BASE . " WHERE $where ORDER BY t.created_at DESC LIMIT :limit";
        $stmt = Database::connection()->prepare($sql);
        if ($where !== '1=1') {
            $stmt->bindValue(':viewer_id', $viewer['id']);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function statusHistory(string $ticketId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT h.*, u.name AS changed_by_name
            FROM ticket_status_history h
            LEFT JOIN users u ON u.id = h.changed_by
            WHERE h.ticket_id = :id
            ORDER BY h.changed_at ASC
        ");
        $stmt->execute(['id' => $ticketId]);
        return $stmt->fetchAll();
    }

    public static function updateStatus(string $ticketId, string $newStatus, string $changedBy, ?string $note = null): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $current = self::findById($ticketId);
            $oldStatus = $current['status'];

            $resolvedAtSql = in_array($newStatus, ['RESOLVED', 'CLOSED'], true) ? ', resolved_at = now()' : '';
            $stmt = $db->prepare("UPDATE tickets SET status = :status $resolvedAtSql WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $ticketId]);

            $db->prepare("
                INSERT INTO ticket_status_history (ticket_id, old_status, new_status, changed_by, note)
                VALUES (:ticket_id, :old_status, :new_status, :changed_by, :note)
            ")->execute([
                'ticket_id' => $ticketId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_by' => $changedBy,
                'note' => $note,
            ]);

            $db->commit();
            return self::findById($ticketId);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function assignTechnician(string $ticketId, string $technicianId): array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE tickets SET assigned_technician_id = :tech WHERE id = :id RETURNING *'
        );
        $stmt->execute(['tech' => $technicianId, 'id' => $ticketId]);
        return self::findById($ticketId);
    }

    /** Dashboard aggregates: counts by status, avg resolution time, SLA breaches. */
    public static function dashboardStats(): array
    {
        $db = Database::connection();

        $byStatus = array_map(
            static fn($row) => ['status' => $row['status'], 'count' => (int) $row['count']],
            $db->query('SELECT status, COUNT(*) AS count FROM tickets GROUP BY status')->fetchAll()
        );
        $byPriority = array_map(
            static fn($row) => ['priority' => $row['priority'], 'count' => (int) $row['count']],
            $db->query('SELECT priority, COUNT(*) AS count FROM tickets GROUP BY priority')->fetchAll()
        );

        $avgResolution = $db->query("
            SELECT AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))) AS avg_seconds
            FROM tickets WHERE resolved_at IS NOT NULL
        ")->fetch();

        $overdue = $db->query("
            SELECT COUNT(*) FROM tickets
            WHERE due_at < now() AND status NOT IN ('RESOLVED', 'CLOSED')
        ")->fetchColumn();

        $topTechnicians = array_map(
            static fn($row) => ['name' => $row['name'], 'resolved_count' => (int) $row['resolved_count']],
            $db->query("
                SELECT u.name, COUNT(*) AS resolved_count
                FROM tickets t JOIN users u ON u.id = t.assigned_technician_id
                WHERE t.status = 'RESOLVED' OR t.status = 'CLOSED'
                GROUP BY u.name ORDER BY resolved_count DESC LIMIT 5
            ")->fetchAll()
        );

        return [
            'byStatus' => $byStatus,
            'byPriority' => $byPriority,
            'avgResolutionSeconds' => $avgResolution['avg_seconds'] !== null ? (float) $avgResolution['avg_seconds'] : null,
            'overdueCount' => (int) $overdue,
            'topTechnicians' => $topTechnicians,
        ];
    }

    /** Shape a DB row into the JSON contract the Java/JS clients expect. */
    public static function toArray(array $t, bool $includeHistory = false): array
    {
        $out = [
            'id' => $t['id'],
            'subject' => $t['subject'],
            'description' => $t['description'],
            'status' => $t['status'],
            'priority' => $t['priority'],
            'department' => $t['department'],
            'deviceType' => $t['device_type'],
            'room' => $t['room'] ?? null,
            'attachmentUrl' => $t['attachment_url'],
            'attachmentThumbnailUrl' => $t['attachment_thumbnail_url'] ?? null,
            'category' => $t['category_id'] ? ['id' => (int) $t['category_id'], 'name' => $t['category_name']] : null,
            'reporter' => [
                'id' => $t['user_id'],
                'name' => $t['reporter_name'] ?? null,
                'email' => $t['reporter_email'] ?? null,
            ],
            'assignedTechnician' => $t['assigned_technician_id'] ? [
                'id' => $t['assigned_technician_id'],
                'name' => $t['technician_name'] ?? null,
                'email' => $t['technician_email'] ?? null,
            ] : null,
            'createdAt' => $t['created_at'],
            'updatedAt' => $t['updated_at'],
            'dueAt' => $t['due_at'],
            'resolvedAt' => $t['resolved_at'],
            'isOverdue' => $t['due_at'] !== null
                && $t['resolved_at'] === null
                && strtotime($t['due_at']) < time(),
            'timeAgo' => self::timeAgo($t['created_at']),
        ];

        if ($includeHistory) {
            $out['statusHistory'] = array_map(static function ($h) {
                return [
                    'oldStatus' => $h['old_status'],
                    'newStatus' => $h['new_status'],
                    'changedBy' => $h['changed_by_name'],
                    'note' => $h['note'],
                    'changedAt' => $h['changed_at'],
                ];
            }, self::statusHistory($t['id']));
        }

        return $out;
    }

    public static function timeAgo(string $timestamp): string
    {
        $diff = time() - strtotime($timestamp);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min' . (floor($diff / 60) == 1 ? '' : 's') . ' ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
        if ($diff < 2592000) return floor($diff / 86400) . ' day' . (floor($diff / 86400) == 1 ? '' : 's') . ' ago';
        return floor($diff / 2592000) . ' month' . (floor($diff / 2592000) == 1 ? '' : 's') . ' ago';
    }
}
