<?php

namespace App\Middleware;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

/**
 * Fixed-window rate limiter, keyed by IP (falls back to authenticated user
 * id when available) and bucketed per minute. Storage is a flat file per
 * bucket under storage/ratelimit/ — fine for a single PHP-FPM box or a
 * school/portfolio deployment.
 *
 * In production behind multiple app servers you'd swap this for Redis
 * (INCR + EXPIRE is the standard pattern) without touching any controller
 * code — that's the point of isolating this behind one middleware class.
 */
class RateLimitMiddleware
{
    private int $maxRequests;
    private int $windowSeconds;
    private string $storageDir;

    public function __construct()
    {
        $this->maxRequests = (int) Env::get('RATE_LIMIT_MAX_REQUESTS', 60);
        $this->windowSeconds = (int) Env::get('RATE_LIMIT_WINDOW_SECONDS', 60);
        $this->storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
    }

    public function __invoke(Request $request): void
    {
        $identity = $request->user['id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucket = floor(time() / $this->windowSeconds);
        $file = $this->storageDir . '/' . md5($identity) . "_{$bucket}.count";

        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $count = (int) fread($fh, 20);
        $count++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $count);
        flock($fh, LOCK_UN);
        fclose($fh);

        header("X-RateLimit-Limit: {$this->maxRequests}");
        header('X-RateLimit-Remaining: ' . max(0, $this->maxRequests - $count));

        if ($count > $this->maxRequests) {
            Response::error('Too many requests. Please slow down.', 429);
        }
    }
}
