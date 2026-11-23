<?php

namespace App\Jobs;

use App\Services\GenerationWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Copies a finished image off Pixelforge's expiring URL onto our disk.
 *
 * Keeping the download out of the webhook/polling state transition keeps those jobs fast.
 * The job is unique per generation, so a webhook and the reconciler cannot fetch and
 * write the same file twice.
 */
class StoreGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(GenerationWriter $writer): void
    {
        $writer->storeImageFor($this->generationId);
    }
}
