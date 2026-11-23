<?php

namespace App\Console\Commands;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

/**
 * Keeps `submit_failed` rows recoverable after the queue's retry window.
 *
 * A lost response can leave a locally failed row while Pixelforge actually accepted the
 * generation. Replaying the stored idempotency key resolves the case: the provider either
 * returns the existing generation (queued/processing/succeeded/failed) or creates the one
 * that never reached it. Definitive rejections replace the provisional failure code, so
 * the row leaves this sweep.
 */
class ReconcileAmbiguousSubmissions extends Command
{
    protected $signature = 'pixelforge:reconcile-ambiguous-submissions {--minutes=5} {--limit=50}';

    protected $description = 'Replay ambiguous Pixelforge submissions until they are resolved';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($minutes === false || $minutes < 1 || $limit === false || $limit < 1) {
            $this->error('--minutes and --limit must be positive integers.');

            return self::FAILURE;
        }

        $generations = Generation::query()
            ->where('status', Generation::STATUS_FAILED)
            ->where('error_code', 'submit_failed')
            ->whereNotNull('submission_attempted_at')
            ->where(fn ($query) => $query
                ->whereNull('submission_recovery_attempted_at')
                ->orWhere('submission_recovery_attempted_at', '<=', now()->subMinutes($minutes)))
            // PostgreSQL sorts NULLs last on ASC by default; untouched rows must be
            // replayed before previously attempted ones so a small --limit cannot starve.
            ->orderByRaw('submission_recovery_attempted_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($generations as $generation) {
            $generation->forceFill(['submission_recovery_attempted_at' => now()])->save();

            (new SubmitGeneration($generation->id))->replaySubmit($generation);
        }

        $this->info("Reconciled {$generations->count()} ambiguous submission(s).");

        return self::SUCCESS;
    }
}
