<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require_once __DIR__ . '/../src/autoload.php';

Env::load(__DIR__ . '/../.env');

// ---------------------------------------------------------------------------
// CORS: only reflect origins explicitly configured by the operator.
// Set APP_CORS_ORIGINS to a comma-separated list of exact frontend origins.
// Requests from native mobile clients (without Origin) remain supported.
// ---------------------------------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) Env::get('APP_CORS_ORIGINS', ''))
)));

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Lightweight platform health check. Does not require database availability.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    Response::json(['status' => 'ok']);
}

// ---------------------------------------------------------------------------
// Global error handling: never leak stack traces to API clients.
// ---------------------------------------------------------------------------
$debug = Env::get('APP_DEBUG', 'false') === 'true';

set_exception_handler(function (Throwable $e) use ($debug) {
    error_log('[Unhandled] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error(
        $debug ? $e->getMessage() : 'An unexpected error occurred.',
        500,
        $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : null
    );
});

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$router = new Router();
require __DIR__ . '/../src/routes.php';

$request = new Request();
$router->dispatch($request);
