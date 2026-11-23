<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one stored webhook event to its generation.
 *
 * The event row was created by the controller, which means this is the first and only
 * time we will process that event id. Even so, the writer is idempotent: duplicates at
 * the provider level and out-of-order deliveries still resolve to one final state.
 */
class ProcessWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Retries are safe because the writer only moves forward and only settles once.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * Coalesce a webhook-driven dispatch and a recovery sweep for the same event. The
     * lock expires so a genuinely stuck or lost job can be picked up again.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $webhookEventId) {}

    public function uniqueId(): string
    {
        return (string) $this->webhookEventId;
    }

    /**
     * Match the event to a local generation and apply it.
     *
     * Matching order follows the docs: our own `metadata.generation_id` survives every
     * event, then the provider generation id is the fallback. A webhook can arrive before
     * the create request returns, but the row already exists by then, so the match works.
     */
    public function handle(GenerationWriter $writer): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        // A redelivery is deduped before enqueueing, but guard the job itself too in case
        // it is retried after it already succeeded.
        if ($event->processed_at !== null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;

        if (! is_array($object)) {
            $this->markProcessed($event);

            return;
        }

        $generation = $this->findGeneration($event, $object);

        if ($generation === null) {
            // Could be a test event, another environment's key, or a generation deleted
            // from our side. Record it as processed and keep the payload for debugging.
            Log::warning('pixelforge.webhook_generation_not_found', [
                'event_id' => $event->pixelforge_event_id,
                'type' => $event->type,
                'pixelforge_id' => $event->generation_pixelforge_id,
            ]);

            $this->markProcessed($event);

            return;
        }

        $writer->apply($generation, $object);

        Log::info('pixelforge.webhook_processed', [
            'event_id' => $event->pixelforge_event_id,
            'type' => $event->type,
            'generation_id' => $generation->id,
            'status' => $generation->fresh()->status,
        ]);

        $this->markProcessed($event);
    }

    /**
     * Resolve our local row, preferring our own metadata id over the provider id.
     *
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(WebhookEvent $event, array $object): ?Generation
    {
        $localId = $object['metadata']['generation_id'] ?? null;

        if (is_string($localId)) {
            $generation = Generation::query()->find($localId);

            if ($generation !== null) {
                return $generation;
            }
        }

        if ($event->generation_pixelforge_id !== null) {
            return Generation::query()
                ->where('pixelforge_id', $event->generation_pixelforge_id)
                ->first();
        }

        return null;
    }

    /**
     * Mark the event done. This is bookkeeping only; the generation row is the source of
     * truth for what the user sees and what was charged.
     */
    private function markProcessed(WebhookEvent $event): void
    {
        $event->forceFill(['processed_at' => now()])->save();
    }

    /**
     * If the job exhausts its retries, leave a trace but do not crash the event row. The
     * polling reconciler is the safety net that still brings the generation to a final
     * state from the provider's own record.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('pixelforge.webhook_processing_failed', [
            'webhook_event_id' => $this->webhookEventId,
            'message' => $e?->getMessage(),
        ]);
    }
}
