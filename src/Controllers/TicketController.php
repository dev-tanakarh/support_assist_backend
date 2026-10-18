<?php

namespace App\Controllers;

use App\Core\FileUpload;
use App\Core\Request;
use App\Core\Response;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\User;
use RuntimeException;
use App\Core\Database;

class TicketController
{
    private const VALID_STATUSES = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
    private const VALID_PRIORITIES = ['HIGH', 'MEDIUM', 'LOW'];

    /** GET /api/tickets — filterable, paginated list scoped to the caller's role. */
    public function index(Request $request): void
    {
        $result = Ticket::listFor($request->user, [
            'status' => $request->query['status'] ?? null,
            'priority' => $request->query['priority'] ?? null,
            'category_id' => $request->query['category_id'] ?? null,
            'q' => $request->query['q'] ?? null,
            'unassigned' => $request->query['unassigned'] ?? null,
            'page' => $request->query['page'] ?? 1,
            'per_page' => $request->query['per_page'] ?? 20,
        ]);

        Response::success([
            'tickets' => array_map(fn($t) => Ticket::toArray($t), $result['items']),
            'pagination' => $result['meta'],
        ]);
    }

    /** GET /api/tickets/recent — 4 most recent, for the Home screen. */
    public function recent(Request $request): void
    {
        $limit = (int) ($request->query['limit'] ?? 4);
        $tickets = Ticket::recentFor($request->user, $limit);
        Response::success(array_map(fn($t) => Ticket::toArray($t), $tickets));
    }

    /** GET /api/tickets/{id} — full detail including status history. */
    public function show(Request $request): void
    {
        $ticket = Ticket::findById($request->params['id']);
        if (!$ticket) {
            Response::error('Ticket not found.', 404);
        }

        $this->assertCanView($request->user, $ticket);

        Response::success(Ticket::toArray($ticket, includeHistory: true));
    }

    /** POST /api/tickets — accepts JSON or multipart (for attachment). */
    public function create(Request $request): void
    {
        $errors = $request->validateRequired(['subject', 'description']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $priority = strtoupper($request->input('priority', 'MEDIUM'));
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            Response::error('Validation failed', 422, ['priority' => 'Must be one of: ' . implode(', ', self::VALID_PRIORITIES)]);
        }

        
$attachmentUrl = null;
$attachmentThumbnailUrl = null;
$uploadedFile = $request->file('attachment');

try {
    $image = FileUpload::storeTicketAttachment($uploadedFile);

    if ($image !== null) {
        $attachmentUrl = $image['url'];
        $attachmentThumbnailUrl = $image['thumbnailUrl'];
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}


        $ticket = Ticket::create([
            'user_id' => $request->user['id'],
            'subject' => $request->input('subject'),
            'description' => $request->input('description'),
            'category_id' => $request->input('category_id'),
            'department' => $request->input('department'),
            'device_type' => $request->input('device_type'),
            'room' => $request->input('room'),
            'attachment_url' => $attachmentUrl,
            'attachment_thumbnail_url' => $attachmentThumbnailUrl,
            'priority' => $priority,
        ]);

        // Save uploaded image metadata in PostgreSQL.
if ($attachmentUrl !== null) {
    $absolutePath = dirname(__DIR__, 2)
    . '/storage/uploads/'
    . substr($attachmentUrl, strlen('/storage/uploads/'));

    if (!is_file($absolutePath)) {
        throw new RuntimeException(
            'Uploaded image could not be found after saving.'
        );
    }

    $mimeType = mime_content_type($absolutePath);
    $fileSize = filesize($absolutePath);

    if ($mimeType === false || $fileSize === false) {
        throw new RuntimeException(
            'Could not read uploaded image metadata.'
        );
    }

    
$stmt = Database::connection()->prepare("
    INSERT INTO images (
        filename,
        file_path,
        mime_type,
        file_size
    )
    VALUES (
        :filename,
        :file_path,
        :mime_type,
        :file_size
    )
");

$stmt->execute([
    'filename' => basename($uploadedFile['name'] ?? 'image.webp'),
    'file_path' => $attachmentUrl,
    'mime_type' => $mimeType,
    'file_size' => $fileSize,
]);

}

        // Notify admins a new ticket needs triage.
        foreach (User::all('ADMIN') as $admin) {
            Alert::create(
                $admin['id'],
                "New ticket: {$ticket['subject']}",
                'NEW_TICKET',
                "Ticket {$ticket['id']} was submitted and needs a technician.",
                $ticket['id']
            );
        }

        Response::success(Ticket::toArray($ticket), 201, 'Ticket created.');
    }

    /**
     * PATCH /api/tickets/{id}/status — technician/admin changes ticket status.
     * Also fires the "ticket status changed" alert to the reporting end-user.
     */
    public function updateStatus(Request $request): void
    {
        $ticket = Ticket::findById($request->params['id']);
        if (!$ticket) {
            Response::error('Ticket not found.', 404);
        }

        $userType = $request->user['userType'];
        if ($userType === 'TECHNICIAN' && $ticket['assigned_technician_id'] !== $request->user['id']) {
            Response::error('You can only update tickets assigned to you.', 403);
        }
        if ($userType === 'END_USER') {
            Response::error('Only technicians and admins can change ticket status.', 403);
        }

        $newStatus = strtoupper($request->input('status', ''));
        if (!in_array($newStatus, self::VALID_STATUSES, true)) {
            Response::error('Validation failed', 422, ['status' => 'Must be one of: ' . implode(', ', self::VALID_STATUSES)]);
        }

        $updated = Ticket::updateStatus($ticket['id'], $newStatus, $request->user['id'], $request->input('note'));

        Alert::create(
            $ticket['user_id'],
            "Ticket {$ticket['id']} is now {$newStatus}",
            'STATUS_CHANGE',
            "Your ticket \"{$ticket['subject']}\" changed from {$ticket['status']} to {$newStatus}.",
            $ticket['id']
        );

        Response::success(Ticket::toArray($updated), 200, 'Status updated.');
    }

    /** POST /api/tickets/{id}/assign — admin assigns a technician. */
    public function assign(Request $request): void
    {
        $ticket = Ticket::findById($request->params['id']);
        if (!$ticket) {
            Response::error('Ticket not found.', 404);
        }

        $technicianId = $request->input('technicianId');
        if (!$technicianId) {
            Response::error('technicianId is required.', 422);
        }

        $technician = User::findById($technicianId);
        if (!$technician || $technician['user_type'] !== 'TECHNICIAN') {
            Response::error('technicianId must reference an existing technician.', 422);
        }

        $updated = Ticket::assignTechnician($ticket['id'], $technicianId);

        Alert::create(
            $ticket['user_id'],
            'A technician has been assigned to your ticket',
            'ASSIGNMENT',
            "{$technician['name']} is now handling ticket {$ticket['id']}.",
            $ticket['id']
        );
        Alert::create(
            $technicianId,
            "You've been assigned ticket {$ticket['id']}",
            'ASSIGNMENT',
            $ticket['subject'],
            $ticket['id']
        );

        Response::success(Ticket::toArray($updated), 200, 'Technician assigned.');
    }

    /** POST /api/tickets/{id}/accept — technician self-assigns an unclaimed ticket. */
    public function accept(Request $request): void
    {
        $ticket = Ticket::findById($request->params['id']);
        if (!$ticket) {
            Response::error('Ticket not found.', 404);
        }
        if ($ticket['assigned_technician_id'] !== null) {
            Response::error('This ticket is already assigned.', 409);
        }

        $updated = Ticket::assignTechnician($ticket['id'], $request->user['id']);
        Ticket::updateStatus($ticket['id'], 'IN_PROGRESS', $request->user['id'], 'Accepted by technician');
        $updated = Ticket::findById($ticket['id']);

        $technician = User::findById($request->user['id']);
        Alert::create(
            $ticket['user_id'],
            'A technician has accepted your ticket',
            'TECH_ACCEPTED',
            "{$technician['name']} is now working on \"{$ticket['subject']}\".",
            $ticket['id']
        );

        Response::success(Ticket::toArray($updated), 200, 'Ticket accepted.');
    }

    
private function assertCanView(array $viewer, array $ticket): void
{
    if ($viewer['userType'] === 'ADMIN') {
        return;
    }

    if ($viewer['userType'] === 'TECHNICIAN') {
        $assignedTechnicianId = $ticket['assigned_technician_id'] ?? null;

        // Technicians may view their own assigned tickets
        // and tickets that have not yet been assigned.
        if (
            $assignedTechnicianId === null ||
            $assignedTechnicianId === '' ||
            $assignedTechnicianId === $viewer['id']
        ) {
            return;
        }
    }

    if (
        $viewer['userType'] === 'END_USER' &&
        $ticket['user_id'] === $viewer['id']
    ) {
        return;
    }

    Response::error(
        'You do not have permission to view this ticket.',
        403
    );
}

}
