<?php

namespace App\Core;

/**
 * Small router: register(METHOD, pattern, handler, middlewares[]).
 * Patterns use {param} placeholders, e.g. /api/tickets/{id}.
 * No framework, no reflection magic — every route is explicit in routes.php
 * so you can trace any URL to its exact handler in one file.
 */
class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function get(string $pattern, callable $handler, array $mw = []): void { $this->add('GET', $pattern, $handler, $mw); }
    public function post(string $pattern, callable $handler, array $mw = []): void { $this->add('POST', $pattern, $handler, $mw); }
    public function put(string $pattern, callable $handler, array $mw = []): void { $this->add('PUT', $pattern, $handler, $mw); }
    public function patch(string $pattern, callable $handler, array $mw = []): void { $this->add('PATCH', $pattern, $handler, $mw); }
    public function delete(string $pattern, callable $handler, array $mw = []): void { $this->add('DELETE', $pattern, $handler, $mw); }

    public function dispatch(Request $request): void
    {
        $matchedPath = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $matchedPath = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            // extract named params only
            $params = array_filter(
                $matches,
                fn($key) => !is_int($key),
                ARRAY_FILTER_USE_KEY
            );
            $request->params = $params;

            foreach ($route['middlewares'] as $middleware) {
                $middleware($request);
            }

            ($route['handler'])($request);
            return;
        }

        if ($matchedPath) {
            Response::error('Method not allowed', 405);
        }
        Response::error('Route not found', 404);
    }
}
