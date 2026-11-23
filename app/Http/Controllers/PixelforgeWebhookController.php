<?php

namespace App\Http\Controllers;

use App\Services\WebhookService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Pixelforge webhooks.
 *
 * Signature verification happens in middleware. The service records the delivery,
 * deduplicates it and queues the state change. This method only acknowledges.
 */
class PixelforgeWebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    /**
     * Accept one delivery and return 204 within the provider's 10 second budget.
     */
    public function __invoke(Request $request): Response
    {
        $this->webhooks->accept($request->getContent());

        return response()->noContent();
    }
}
