<?php

namespace App\Services;

use App\Exceptions\InvalidWebhookPayloadException;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

/**
 * Accepts Pixelforge webhook deliveries.
 *
 * The signature has already been verified by middleware against the raw body. This
 * service owns everything after that: parsing, deduplication, the ping shortcut, and
 * queueing the state change. The controller only calls it and returns 204.
 */
class WebhookService
{
    /**
     * Record one delivery, or return silently when it is a duplicate.
     *
     * Deduplication happens at the database level: `insertOrIgnore` on the unique
     * `pixelforge_event_id` inserts one row for the first delivery and zero rows for every
     * redelivery. A duplicate therefore never creates a second event row or a second job.
     *
     * @param  string  $rawPayload  the exact request body already covered by the signature
     *
     * @throws InvalidWebhookPayloadException when the body is not a JSON event with an id
     */
    public function accept(string $rawPayload): void
    {
        $event = json_decode($rawPayload, true);

        if (! is_array($event) || ! isset($event['id'])) {
            Log::warning('pixelforge.webhook_invalid_payload', [
                'bytes' => strlen($rawPayload),
            ]);

            throw new InvalidWebhookPayloadException;
        }

        $eventId = (string) $event['id'];
        $type = (string) ($event['type'] ?? 'unknown');
        $generationPixelforgeId = $event['data']['object']['id'] ?? null;
        $now = now();

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'pixelforge_event_id' => $eventId,
            'type' => $type,
            // Kept for debugging and as a fallback match key; the metadata id is preferred.
            'generation_pixelforge_id' => $generationPixelforgeId,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Already seen. The docs allow repeated delivery; the caller still gets 204 so the
        // provider stops retrying. An event row is not proof its job ran, so the scheduled
        // `pixelforge:retry-unprocessed-webhooks` sweep recovers interrupted dispatches.
        if ($inserted === 0) {
            Log::info('pixelforge.webhook_duplicate', [
                'event_id' => $eventId,
                'type' => $type,
            ]);

            return;
        }

        $webhookEvent = WebhookEvent::query()
            ->where('pixelforge_event_id', $eventId)
            ->firstOrFail();

        // "Send test event" in the dashboard has no generation to update.
        if ($webhookEvent->type === 'ping') {
            $webhookEvent->forceFill(['processed_at' => $now])->save();

            Log::info('pixelforge.webhook_ping', [
                'event_id' => $eventId,
            ]);

            return;
        }

        Log::info('pixelforge.webhook_accepted', [
            'event_id' => $eventId,
            'type' => $type,
            'pixelforge_id' => $generationPixelforgeId,
            'webhook_event_id' => $webhookEvent->id,
        ]);

        // The state change is slow work; the docs require responding well under 10 seconds.
        ProcessWebhookEvent::dispatch($webhookEvent->id);
    }
}
