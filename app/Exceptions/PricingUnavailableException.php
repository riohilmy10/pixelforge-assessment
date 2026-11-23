<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Raised when live pricing cannot be loaded from Pixelforge.
 *
 * The generation service throws this instead of letting the transport exception escape,
 * so controllers stay free of try/catch blocks. Laravel renders it:
 *  - JSON callers (the live quote endpoint) get a 503 with a short message
 *  - normal page submits are redirected back with a flash error, and nothing is created
 */
class PricingUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'Pixelforge pricing is unavailable right now. Nothing was submitted.',
            0,
            $previous,
        );
    }

    /**
     * Render the failure in the shape the caller expects.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 503);
        }

        return back()->with('error', $this->getMessage());
    }
}
