<?php

namespace App\Exceptions;

use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The user's balance cannot cover the worst-case quote, so the generation is refused
 * before anything is sent to Pixelforge. Nothing is created, held or billed.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNano,
        public readonly int $availableNano,
    ) {
        parent::__construct(
            'This generation needs up to '.Money::display($requiredNano)
            .' but you only have '.Money::display($availableNano).' available.',
        );
    }

    /**
     * Render the refusal in the shape the caller expects. The dashboard adds a "Buy
     * credits" affordance next to this message.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'required_nano' => $this->requiredNano,
                'available_nano' => $this->availableNano,
            ], 402);
        }

        return back()->with('error', $this->getMessage().' Buy credits to continue.');
    }
}
