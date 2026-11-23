<?php

namespace App\Console\Commands;

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Console\Command;
use Throwable;

class RecoverGenerationImages extends Command
{
    protected $signature = 'pixelforge:recover-generation-images';

    protected $description = 'Queue missing copies of settled images whose provider URLs are still usable';

    public function handle(): int
    {
        $generations = Generation::query()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->whereNull('stored_image_path')
            ->whereNotNull('image_url')
            ->where('last_synced_at', '<=', now()->subMinutes(5))
            ->where(fn ($query) => $query->whereNull('image_url_expires_at')->orWhere('image_url_expires_at', '>', now()))
            ->lazyById();

        foreach ($generations as $generation) {
            try {
                StoreGenerationImage::dispatch($generation->id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
