<?php

namespace App\Http\Middleware;

use App\Services\Pixelforge\WebhookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects unsigned or stale Pixelforge webhooks before the controller or any JSON
 * decoding touches them. The webhook route is registered outside the `web` middleware
 * group, so there is no session or CSRF token involved.
 */
class VerifyPixelforgeSignature
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $valid = $this->verifier->isValid(
            $request->getContent(),
            $request->header('Pixelforge-Signature'),
        );

        // Docs' example aborts with 400 for a bad signature. Returning non-2xx also tells
        // Pixelforge to retry, which is correct: a transient secret mismatch can be fixed.
        if (! $valid) {
            // Log just enough to debug a mismatch without storing the body or secret.
            Log::warning('pixelforge.webhook_signature_invalid', [
                'ip' => $request->ip(),
                'has_signature_header' => $request->header('Pixelforge-Signature') !== null,
            ]);

            abort(400, 'Invalid Pixelforge signature.');
        }

        return $next($request);
    }
}
