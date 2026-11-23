<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a webhook passes signature verification but its body is not a usable event.
 *
 * Rendering this as a 400 makes the failure visible in Pixelforge's delivery log while
 * keeping the webhook controller a single call.
 */
class InvalidWebhookPayloadException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid event payload.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => $this->getMessage()], 400);
    }
}
