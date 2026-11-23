<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Drops processed webhook deliveries after they are no longer useful for debugging.
 *
 * Unprocessed rows are kept: they are either in flight or evidence of a processing
 * failure. Each row carries the full provider payload, so without this the table grows
 * without bound.
 */
class PruneWebhookEvents extends Command
{
    protected $signature = 'pixelforge:prune-webhook-events {--days=30}';

    protected $description = 'Delete processed Pixelforge webhook events older than the retention window';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if ($days === false || $days < 1) {
            $this->error('The --days option must be a positive integer.');

            return self::FAILURE;
        }

        $deleted = WebhookEvent::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} processed webhook event(s).");

        return self::SUCCESS;
    }
}
