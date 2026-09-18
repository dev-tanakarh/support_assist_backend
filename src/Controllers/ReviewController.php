<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Alert;
use App\Models\Ticket;

class ReviewController
{
    /** POST /api/tickets/{id}/review — end-user rates a resolved/closed ticket. */
    public function create(Request $request): void
    {
        $ticket = Ticket::findById($request->params['id']);
        if (!$ticket) {
            Response::error('Ticket not found.', 404);
        }
        if ($ticket['user_id'] !== $request->user['id']) {
            Response::error('You can only review your own tickets.', 403);
        }
        if (!in_array($ticket['status'], ['RESOLVED', 'CLOSED'], true)) {
            Response::error('You can only review resolved or closed tickets.', 409);
        }

        $rating = (int) $request->input('rating', 0);
        if ($rating < 1 || $rating > 5) {
            Response::error('Validation failed', 422, ['rating' => 'Must be between 1 and 5.']);
        }

        $db = Database::connection();
        $existing = $db->prepare('SELECT id FROM reviews WHERE ticket_id = :id');
        $existing->execute(['id' => $ticket['id']]);
        if ($existing->fetch()) {
            Response::error('This ticket has already been reviewed.', 409);
        }

        $stmt = $db->prepare("
            INSERT INTO reviews (ticket_id, user_id, technician_id, rating, comment)
            VALUES (:ticket_id, :user_id, :technician_id, :rating, :comment)
            RETURNING *
        ");
        $stmt->execute([
            'ticket_id' => $ticket['id'],
            'user_id' => $request->user['id'],
            'technician_id' => $ticket['assigned_technician_id'],
            'rating' => $rating,
            'comment' => $request->input('comment'),
        ]);
        $review = $stmt->fetch();

        if ($ticket['assigned_technician_id']) {
            Alert::create(
                $ticket['assigned_technician_id'],
                "You received a {$rating}-star review",
                'REVIEW_RECEIVED',
                "Ticket {$ticket['id']}: " . ($request->input('comment') ?? ''),
                $ticket['id']
            );
        }

        Response::success([
            'id' => (int) $review['id'],
            'ticketId' => $review['ticket_id'],
            'rating' => (int) $review['rating'],
            'comment' => $review['comment'],
            'createdAt' => $review['created_at'],
        ], 201, 'Review submitted.');
    }
}
