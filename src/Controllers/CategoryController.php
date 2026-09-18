<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class CategoryController
{
    public function index(Request $request): void
    {
        $rows = Database::connection()
            ->query('SELECT id, name FROM ticket_categories ORDER BY name')
            ->fetchAll();

        Response::success(array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $rows));
    }
}
