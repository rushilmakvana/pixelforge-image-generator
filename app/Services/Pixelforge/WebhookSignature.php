<?php

namespace App\Services\Pixelforge;

/**
 * Pixelforge-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "{t}.{raw body}")>
 */
class WebhookSignature
{
    public function __construct(
        private string $secret,
        private int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $rawBody, ?string $header, ?int $now = null): bool
    {
        if ($this->secret === '' || ! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false; // Missing or stale: blocks replays of old captured deliveries.
        }

        $expected = self::sign($this->secret, $timestamp, $rawBody);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
