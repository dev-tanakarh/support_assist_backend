<?php

namespace App\Services;

use App\Core\Env;
use RuntimeException;

/**
 * Sends push notifications via Firebase Cloud Messaging's HTTP v1 API.
 *
 * Why not the "legacy" FCM API with a single server key? Google shut it off
 * in June 2024 — it no longer works, full stop. HTTP v1 requires an OAuth2
 * access token signed with a service account's private key. There IS an
 * official firebase-php SDK that does this for you, but it pulls in Guzzle
 * + half a dozen transitive packages, and this project deliberately has
 * zero Composer dependencies (see Env.php's docblock — the whole point is
 * "clone + configure a webserver, nothing else to install"). The JWT signing
 * FCM needs is ~30 lines with PHP's built-in openssl extension, so that's
 * what this does instead of taking on a dependency for one HTTP call.
 *
 * Setup (do this once):
 *  1. Firebase Console → Project Settings → Service Accounts → Generate new
 *     private key. Downloads a JSON file — do NOT commit it.
 *  2. Put its path in .env as FCM_SERVICE_ACCOUNT_PATH, and your Firebase
 *     project id as FCM_PROJECT_ID (see .env.example).
 *  3. storage/ is already gitignored — that's where the cached access
 *     token lives (fcm_access_token.json), so add the service account
 *     JSON there too rather than anywhere web-servable.
 */
class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Sends a single notification. Never throws out to the caller — a push
     * failure (bad token, FCM outage, missing config) should never break
     * the ticket/alert flow that triggered it. Returns true/false instead.
     */
    public static function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        $projectId = Env::get('FCM_PROJECT_ID');
        if (!$projectId) {
            return false; // not configured yet — fine in dev, just skip silently
        }

        try {
            $accessToken = self::getAccessToken();
        } catch (RuntimeException $e) {
            error_log('[FcmService] Could not obtain access token: ' . $e->getMessage());
            return false;
        }

        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                // Every value in `data` must be a string — FCM requirement.
                'data' => array_map('strval', $data),
            ],
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            // 404/400 here usually means a stale device token (app was
            // uninstalled, token rotated). Worth clearing it so we stop
            // trying — left as a TODO rather than guessed at.
            error_log("[FcmService] Send failed ({$status}): {$response}");
            return false;
        }
        return true;
    }

    /** Access tokens last 1hr; cache to a file so we're not re-authing on every single alert. */
    private static function getAccessToken(): string
    {
        $cachePath = __DIR__ . '/../../storage/fcm_access_token.json';

        if (file_exists($cachePath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if ($cached && $cached['expires_at'] > time() + 60) {
                return $cached['access_token'];
            }
        }

        $keyPath = Env::get('FCM_SERVICE_ACCOUNT_PATH');
        if (!$keyPath || !file_exists($keyPath)) {
            throw new RuntimeException('FCM_SERVICE_ACCOUNT_PATH is not set or the file does not exist.');
        }
        $serviceAccount = json_decode(file_get_contents($keyPath), true);

        $now = time();
        $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64url(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        $signed = openssl_sign("{$header}.{$claims}", $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new RuntimeException('Failed to sign FCM JWT — check the private key in the service account file.');
        }
        $jwt = "{$header}.{$claims}." . self::base64url($signature);

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = json_decode(curl_exec($ch), true);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !isset($response['access_token'])) {
            throw new RuntimeException('OAuth token exchange failed: ' . json_encode($response));
        }

        file_put_contents($cachePath, json_encode([
            'access_token' => $response['access_token'],
            'expires_at' => $now + (int) $response['expires_in'],
        ]));

        return $response['access_token'];
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
