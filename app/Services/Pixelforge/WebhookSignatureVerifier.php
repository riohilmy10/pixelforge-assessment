<?php

namespace App\Services\Pixelforge;

/**
 * Verifies Pixelforge webhook signatures exactly as the API docs describe.
 *
 * The header looks like `t=<unix timestamp>,v1=<hex hmac>`, and the signed content is
 * the literal string `<timestamp>.<raw request body>`, keyed with the signing secret of
 * the API key that created the generation. The raw body matters: re-encoding decoded
 * JSON can change whitespace and break the HMAC, so this runs before any JSON decoding.
 *
 * Two more rules:
 *  - `hash_equals` prevents timing leaks.
 *  - timestamps older than 5 minutes are rejected so a captured request cannot be replayed.
 */
class WebhookSignatureVerifier
{
    /** Docs: reject timestamps older than this many seconds. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * @param  string  $payload  the raw request body, untouched
     * @param  string|null  $header  the Pixelforge-Signature header
     */
    public function isValid(string $payload, ?string $header): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        // Without a configured secret nothing can be trusted. Treat every request as
        // invalid rather than accepting unsigned events in a misconfigured environment.
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $parts = $this->parseHeader($header);
        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');

        if ($timestamp <= 0 || $signature === '') {
            return false;
        }

        // Replay window: each delivery is signed with a fresh timestamp, so anything old
        // is either a replay or a very delayed delivery; both are safe to reject.
        if (abs((int) now()->timestamp - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Split `t=...,v1=...` into a keyed array. Unknown keys are kept but ignored.
     *
     * @return array<string, string>
     */
    private function parseHeader(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

            if ($key !== '') {
                $parts[trim($key)] = trim($value);
            }
        }

        return $parts;
    }
}
