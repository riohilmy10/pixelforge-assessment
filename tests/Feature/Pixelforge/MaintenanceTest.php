<?php

namespace Tests\Feature\Pixelforge;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
