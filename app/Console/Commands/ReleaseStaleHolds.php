<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Releases credit holds that belong to ambiguous submissions Pixelforge never confirmed.
 *
 * The reconciliation sweep only retries recent `submit_failed` rows. A row that stays
 * unresolved past the age limit is closed here, so a permanently unreachable provider
 * cannot freeze a user's credits forever. The generation remains failed and its error
 * message explains that the hold was returned.
 */
class ReleaseStaleHolds extends Command
{
    protected $signature = 'pixelforge:release-stale-holds {--hours=24} {--limit=100}';

    protected $description = 'Release credit holds for unresolved ambiguous submissions';

    public function handle(CreditService $credits): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($hours === false || $hours < 1 || $limit === false || $limit < 1) {
            $this->error('--hours and --limit must be positive integers.');

            return self::FAILURE;
        }

        $generations = Generation::query()
            ->where('status', Generation::STATUS_FAILED)
            ->where('error_code', 'submit_failed')
            ->where('reserved_nano', '>', 0)
            ->whereNotNull('submission_attempted_at')
            ->where('submission_attempted_at', '<=', now()->subHours($hours))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($generations as $generation) {
            DB::transaction(function () use ($credits, $generation): void {
                $credits->release($generation, 'Ambiguous submission expired — hold released');

                $generation->forceFill([
                    'error_code' => 'submit_expired',
                    'error_message' => 'Pixelforge did not confirm this submission in time. The credit hold was released.',
                ])->save();
            });
        }

        $this->info("Released {$generations->count()} stale credit hold(s).");

        return self::SUCCESS;
    }
}
