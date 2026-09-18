<?php

namespace App\Core;

class Request
{
    public array $body;
    public array $query;
    public array $params = []; // route params, filled in by Router
    public string $method;
    public string $path;

    /** @var array{id:string,email:string,userType:string}|null set by AuthMiddleware */
    public ?array $user = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->query = $_GET;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'multipart/form-data')) {
            // PHP already parsed this into $_POST / $_FILES for us.
            $this->body = $_POST;
        } else {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '{}', true);
            $this->body = is_array($decoded) ? $decoded : [];
        }
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$header && function_exists('apache_request_headers')) {
            $header = apache_request_headers()['Authorization'] ?? null;
        }
        // Some FPM/Nginx setups strip the Authorization header from
        // $_SERVER unless explicitly forwarded — see docker/nginx.conf,
        // which adds fastcgi_param HTTP_AUTHORIZATION explicitly.
        if (!$header && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        return substr($header, 7);
    }

    /**
     * Validate required fields are present and non-empty.
     * @return array<string,string> field => error message, empty if valid
     */
    public function validateRequired(array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            $value = $this->body[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $errors[$field] = "The {$field} field is required.";
            }
        }
        return $errors;
    }
}
