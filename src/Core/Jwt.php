<?php

namespace App\Core;

use RuntimeException;

/**
 * Minimal HS256 JWT implementation.
 *
 * Why hand-rolled instead of firebase/php-jwt: this project has zero
 * Composer dependencies by design (see README "Architecture Decisions").
 * HS256 is: base64url(header) + "." + base64url(payload), signed with
 * HMAC-SHA256 over that string, base64url-encoded as the third segment.
 * Anyone maintaining this should be able to read this file top to bottom
 * and understand exactly what a JWT is — that's the point of writing it
 * by hand for a learning project.
 */
class Jwt
{
    private static function secret(): string
    {
        $secret = Env::get('JWT_SECRET');
        if (!$secret || $secret === 'change_this_to_a_long_random_string' || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be configured with at least 32 characters.');
        }
        return $secret;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * @param array $claims Custom claims, e.g. ['sub' => $userId, 'role' => 'ADMIN']
     * @param int $ttlSeconds Token lifetime
     */
    public static function encode(array $claims, int $ttlSeconds): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        $segments = [
            self::b64url(json_encode($header)),
            self::b64url(json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, self::secret(), true);
        $segments[] = self::b64url($signature);

        return implode('.', $segments);
    }

    /**
     * @return array Decoded payload
     * @throws RuntimeException on invalid signature, malformed token, or expiry
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token');
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $signingInput = "$headerB64.$payloadB64";
        $expectedSig = self::b64url(hash_hmac('sha256', $signingInput, self::secret(), true));

        if (!hash_equals($expectedSig, $sigB64)) {
            throw new RuntimeException('Invalid token signature');
        }

        $payload = json_decode(self::b64urlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Malformed token payload');
        }

        if (isset($payload['exp']) && time() >= $payload['exp']) {
            throw new RuntimeException('Token expired');
        }

        return $payload;
    }
}
