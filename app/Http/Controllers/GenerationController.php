<?php

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HTTP entry points for the generator.
 *
 * Every method is intentionally a thin adapter: it asks the service for data and renders
 * or redirects the result. Business rules, money calculations and persistence live in
 * {@see GenerationService}, never here.
 */
class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generations) {}

    /**
     * Dashboard: generator form, live quote source and recent generations.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', $this->generations->dashboard($request->user()));
    }

    /**
     * Full generation history, paginated newest first.
     */
    public function history(Request $request): Response
    {
        return Inertia::render('Generations/Index', $this->generations->history($request->user()));
    }

    /**
     * JSON quote for the form. The only mapping here is the estimate's own array shape.
     */
    public function estimate(EstimateGenerationRequest $request): JsonResponse
    {
        $estimate = $this->generations->estimate($request->estimateData());

        return response()->json(['estimate' => $estimate->toArray()]);
    }

    /**
     * Create the generation and queue the provider request.
     */
    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        $this->generations->submit($request->user(), $request->generationData());

        return to_route('dashboard')
            ->with('status', 'Generation queued. You will see the final price when it finishes.');
    }
}
