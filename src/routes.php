<?php

use App\Controllers\AdminController;
use App\Controllers\AlertController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\ProfileController;
use App\Controllers\ReviewController;
use App\Controllers\TicketController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;

/** @var Router $router */

$auth = new AuthMiddleware();
$rateLimit = new RateLimitMiddleware();

$authController = new AuthController();
$profileController = new ProfileController();
$categoryController = new CategoryController();
$ticketController = new TicketController();
$alertController = new AlertController();
$adminController = new AdminController();
$reviewController = new ReviewController();
$dashboardController = new DashboardController();

// ---------------------------------------------------------------------------
// 1. Authentication (public)
// ---------------------------------------------------------------------------
$router->post('/api/signup', [$authController, 'signup'], [$rateLimit]);
$router->post('/api/login', [$authController, 'login'], [$rateLimit]);
$router->post('/api/forgot-password', [$authController, 'forgotPassword'], [$rateLimit]);
$router->post('/api/reset-password', [$authController, 'resetPassword'], [$rateLimit]);
$router->post('/api/refresh', [$authController, 'refresh'], [$rateLimit]);

// ---------------------------------------------------------------------------
// 2. Profile
// ---------------------------------------------------------------------------
$router->get('/api/profile', [$profileController, 'show'], [$auth]);
$router->patch('/api/profile', [$profileController, 'update'], [$auth]);
$router->post('/api/user/fcm-token', [$profileController, 'updateFcmToken'], [$auth]);

// ---------------------------------------------------------------------------
// 3. Categories
// ---------------------------------------------------------------------------
$router->get('/api/categories', [$categoryController, 'index'], [$auth]);

// ---------------------------------------------------------------------------
// 4. Tickets — every route is role-aware inside the controller/model layer;
//    an END_USER only ever sees their own tickets, a TECHNICIAN only sees
//    tickets assigned to them (or the unassigned pool), ADMIN sees all.
// ---------------------------------------------------------------------------
$router->get('/api/tickets', [$ticketController, 'index'], [$auth]);
$router->get('/api/tickets/recent', [$ticketController, 'recent'], [$auth]);
$router->get('/api/tickets/{id}', [$ticketController, 'show'], [$auth]);
$router->post('/api/tickets', [$ticketController, 'create'], [$auth, $rateLimit]);
$router->patch('/api/tickets/{id}/status', [$ticketController, 'updateStatus'], [$auth, RoleMiddleware::allow('TECHNICIAN', 'ADMIN')]);
$router->post('/api/tickets/{id}/assign', [$ticketController, 'assign'], [$auth, RoleMiddleware::allow('ADMIN')]);
$router->post('/api/tickets/{id}/accept', [$ticketController, 'accept'], [$auth, RoleMiddleware::allow('TECHNICIAN')]);
$router->post('/api/tickets/{id}/review', [$reviewController, 'create'], [$auth, RoleMiddleware::allow('END_USER')]);

// ---------------------------------------------------------------------------
// 5. Alerts
// ---------------------------------------------------------------------------
$router->get('/api/alerts', [$alertController, 'index'], [$auth]);
$router->get('/api/alerts/poll', [$alertController, 'poll'], [$auth]);
$router->patch('/api/alerts/{id}/read', [$alertController, 'markRead'], [$auth]);

// ---------------------------------------------------------------------------
// 6. Admin
// ---------------------------------------------------------------------------
$adminOnly = [$auth, RoleMiddleware::allow('ADMIN')];
$router->get('/api/admin/users', [$adminController, 'listUsers'], $adminOnly);
$router->post('/api/admin/technicians', [$adminController, 'createTechnician'], $adminOnly);
$router->delete('/api/admin/technicians/{id}', [$adminController, 'deleteTechnician'], $adminOnly);
$router->patch('/api/admin/users/{id}/status', [$adminController, 'setUserStatus'], $adminOnly);
$router->post('/api/admin/alerts', [$adminController, 'sendAlert'], $adminOnly);
$router->get('/api/admin/dashboard', [$dashboardController, 'stats'], $adminOnly);
