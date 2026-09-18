<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Ticket;

class DashboardController
{
    /** GET /api/admin/dashboard — aggregate stats, admin only. */
    public function stats(Request $request): void
    {
        Response::success(Ticket::dashboardStats());
    }
}
