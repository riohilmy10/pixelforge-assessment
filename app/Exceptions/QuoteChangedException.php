<?php

namespace App\Exceptions;

use App\Services\Pixelforge\GenerationEstimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class QuoteChangedException extends RuntimeException
{
    public function __construct(private readonly GenerationEstimate $estimate)
    {
        parent::__construct('The price changed. Review the updated estimate and submit again to confirm.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'estimate' => $this->estimate->toArray()], 409);
        }

        return back()->with([
            'error' => $this->getMessage(),
            'updated_quote' => $this->estimate->toArray(),
        ]);
    }
}
