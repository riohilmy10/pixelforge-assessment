<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Re-dispatches stored webhook events whose processing job was lost or interrupted.
 *
 * A row can be inserted before its job is queued (and the process can die in between), or
 * the worker can exhaust its retries. Looking only at the unique event id would treat
 * those rows as duplicates forever, so this sweep gives them another chance. The writer
 * is idempotent, so replaying an event that was already applied is harmless.
 */
class RetryUnprocessedWebhooks extends Command
{
    protected $signature = 'pixelforge:retry-unprocessed-webhooks {--minutes=5} {--limit=100}';

    protected $description = 'Dispatch stored Pixelforge webhook events that never finished processing';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($minutes === false || $minutes < 1 || $limit === false || $limit < 1) {
            $this->error('--minutes and --limit must be positive integers.');

            return self::FAILURE;
        }

        $events = WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('received_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $events->each(fn (WebhookEvent $event) => ProcessWebhookEvent::dispatch($event->id));

        $this->info("Dispatched {$events->count()} unprocessed webhook event(s).");

        return self::SUCCESS;
    }
}
