<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationWriter;
use App\Services\Pixelforge\Exceptions\RetryableException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for missed or delayed webhooks.
 *
 * Webhook delivery is at-least-once but not guaranteed: if every attempt fails, the docs
 * tell us to poll. This command asks Pixelforge for any generation we have not reached a
 * terminal state on and applies the same writer as webhooks, so polling and webhooks can
 * race freely without double-charging.
 *
 * It is intentionally small per run (20) to stay well inside the 60 requests/minute
 * budget, and skips rows synced in the last 30 seconds.
 */
class PollGenerations extends Command
{
    protected $signature = 'pixelforge:poll';

    protected $description = 'Poll Pixelforge for generations that have not reached a final state';

    /** A row polled within this window is considered fresh enough. */
    private const STALE_AFTER_SECONDS = 30;

    /** Stay far below the provider's 60 requests/minute limit. */
    private const BATCH_SIZE = 20;

    public function handle(PixelforgeClient $client, GenerationWriter $writer): int
    {
        $generations = Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereNotIn('status', Generation::terminalStatuses())
            ->where(function ($query): void {
                $query->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<=', now()->subSeconds(self::STALE_AFTER_SECONDS));
            })
            ->orderByRaw('last_synced_at ASC NULLS FIRST')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($generations as $generation) {
            try {
                $object = $client->getGeneration($generation->pixelforge_id);

                // Same state machine and same single-write settlement as a webhook.
                $writer->apply($generation, $object);
            } catch (RetryableException $e) {
                // Rate limit or overload: stop this batch rather than pile on. The next
                // scheduled run will pick up where we left off.
                Log::info('pixelforge.poll_deferred', [
                    'generation_id' => $generation->id,
                    'code' => $e->errorCode,
                    'retry_after' => $e->retryAfter,
                ]);

                break;
            } catch (Throwable $e) {
                // A single bad row should not stop the reconciler. Record the attempt so
                // the next run waits instead of hammering the same failure.
                Log::warning('pixelforge.poll_failed', [
                    'generation_id' => $generation->id,
                    'message' => $e->getMessage(),
                ]);

                $generation->forceFill(['last_synced_at' => now()])->save();
            }
        }

        return self::SUCCESS;
    }
}
