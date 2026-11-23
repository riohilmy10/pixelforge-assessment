<?php

namespace Tests\Feature\Pixelforge;

use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\GenerationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use PixelforgeTestHelpers, RefreshDatabase;

    public function test_prune_removes_only_old_processed_webhook_events(): void
    {
        $event = fn (string $id, ?\DateTimeInterface $processedAt): WebhookEvent => WebhookEvent::create([
            'pixelforge_event_id' => $id,
            'type' => 'generation.succeeded',
            'payload' => ['ok' => true],
            'received_at' => now()->subDays(90),
            'processed_at' => $processedAt,
        ]);

        $event('evt_old_processed', now()->subDays(31));
        $event('evt_recent_processed', now()->subDays(2));
        $event('evt_old_unprocessed', null);

        $this->artisan('pixelforge:prune-webhook-events')->assertSuccessful();

        $this->assertDatabaseMissing('webhook_events', ['pixelforge_event_id' => 'evt_old_processed']);
        $this->assertDatabaseHas('webhook_events', ['pixelforge_event_id' => 'evt_recent_processed']);
        $this->assertDatabaseHas('webhook_events', ['pixelforge_event_id' => 'evt_old_unprocessed']);
    }

    public function test_retry_unprocessed_webhooks_only_dispatches_old_pending_events(): void
    {
        Queue::fake();

        $event = fn (string $id, \DateTimeInterface $receivedAt): WebhookEvent => WebhookEvent::create([
            'pixelforge_event_id' => $id,
            'type' => 'generation.succeeded',
            'payload' => ['ok' => true],
            'received_at' => $receivedAt,
            'processed_at' => null,
        ]);

        $old = $event('evt_old_unprocessed', now()->subMinutes(10));
        $event('evt_recent_unprocessed', now()->subMinute());

        $this->artisan('pixelforge:retry-unprocessed-webhooks')->assertSuccessful();

        Queue::assertPushed(
            ProcessWebhookEvent::class,
            fn (ProcessWebhookEvent $job): bool => $job->webhookEventId === $old->id,
        );
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_image_download_http_failures_are_thrown_for_the_job_to_retry(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('nope', 500)]);

        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_SUCCEEDED,
            'image_url' => 'https://pixelforge.test/images/failing.png',
        ]);

        $this->expectException(\RuntimeException::class);

        app(GenerationWriter::class)->storeImageFor($generation->id);
    }

    public function test_expired_provider_urls_are_not_returned_when_no_copy_exists(): void
    {
        $user = User::factory()->create();

        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_SUCCEEDED,
            'image_url' => 'https://pixelforge.test/images/expired.png',
            'image_url_expires_at' => now()->subMinute(),
            'stored_image_path' => null,
        ]);

        $this->assertNull($generation->imageUrl());
    }

    public function test_stored_images_use_the_configured_disk(): void
    {
        Storage::fake('public');
        config(['filesystems.image_disk' => 'public']);

        $user = User::factory()->create();

        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_SUCCEEDED,
            'image_url' => 'https://pixelforge.test/images/original.png',
            'stored_image_path' => 'generations/copy.png',
        ]);

        $this->assertStringContainsString('/storage/generations/copy.png', (string) $generation->imageUrl());
    }
}
