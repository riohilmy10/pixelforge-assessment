<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for missed webhooks: poll non-terminal generations once a minute. The
// command caps itself at 20 provider calls per run and skips recently synced rows.
Schedule::command('pixelforge:poll')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Processed webhook deliveries carry the full provider payload and are only kept for
// debugging, so bound how long they accumulate.
Schedule::command('pixelforge:prune-webhook-events')
    ->dailyAt('03:00')
    ->onOneServer();

// A webhook row is inserted before its job is queued; if the process dies in between, no
// duplicate delivery arrives to save it. Sweep old unprocessed rows and dispatch again.
Schedule::command('pixelforge:retry-unprocessed-webhooks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// A settled row remains recoverable if the image job could not be queued.
Schedule::command('pixelforge:recover-generation-images')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
