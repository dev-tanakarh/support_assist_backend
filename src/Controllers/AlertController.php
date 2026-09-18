<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Alert;

class AlertController
{
    /** GET /api/alerts */
    public function index(Request $request): void
    {
        $page = (int) ($request->query['page'] ?? 1);
        $perPage = (int) ($request->query['per_page'] ?? 20);

        $alerts = Alert::forUser($request->user['id'], $page, $perPage);
        Response::success(array_map(fn($a) => Alert::toArray($a), $alerts));
    }

    /**
     * GET /api/alerts/poll?since=<ISO8601>&timeout=25
     *
     * Long-polling, not a WebSocket: the request just stays open, re-checking
     * the DB every second, and returns the moment something new shows up (or
     * once $timeout elapses with nothing). Client's loop is: call this,
     * whatever comes back, call it again with the new `serverTime` as the
     * next `since`. That gives near-instant updates without a persistent
     * connection or a second server process — appropriate for this stack.
     *
     * Every ticket-affecting action already writes a row to `alerts`
     * (see Alert::create, called from TicketController), so this single
     * query is a reliable proxy for "did anything change for this user".
     *
     * IMPORTANT (see RUNNING.md): each open poll holds a PHP-FPM worker for
     * up to $timeout seconds. Size pm.max_children and nginx's
     * fastcgi_read_timeout for your real concurrent-user count, or every
     * worker will be parked here and normal requests will queue behind them.
     */
    public function poll(Request $request): void
    {
        $since = $request->query['since'] ?? null;
        if (!$since || strtotime($since) === false) {
            Response::error('A valid `since` ISO-8601 timestamp is required.', 422);
        }

        $timeout = min((int) ($request->query['timeout'] ?? 25), 30); // hard cap: see RUNNING.md
        set_time_limit($timeout + 5);

        $deadline = microtime(true) + $timeout;
        $alerts = [];

        while (microtime(true) < $deadline) {
            $alerts = Alert::newerThan($request->user['id'], $since);
            if (!empty($alerts)) {
                break;
            }
            usleep(1_000_000); // 1s between checks — cheap enough at this scale, see RUNNING.md for the tradeoff
        }

        Response::success([
            'alerts' => array_map(fn($a) => Alert::toArray($a), $alerts),
            'serverTime' => gmdate('c'), // client uses this as the next `since` — never its own clock
        ]);
    }

    /** PATCH /api/alerts/{id}/read */
    public function markRead(Request $request): void
    {
        $ok = Alert::markRead((int) $request->params['id'], $request->user['id']);
        if (!$ok) {
            Response::error('Alert not found.', 404);
        }
        Response::success(null, 200, 'Alert marked as read.');
    }
}
