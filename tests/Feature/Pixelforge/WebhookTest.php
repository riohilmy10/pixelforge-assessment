<?php

namespace Tests\Feature\Pixelforge;

use App\Jobs\ProcessWebhookEvent;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\GenerationWriter;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use PixelforgeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
            'services.pixelforge.api_key' => 'sk_test',
            'services.pixelforge.webhook_secret' => 'whsec_test',
            'services.pixelforge.markup_percent' => 25,
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $id, string $type, array $object): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-22T00:01:00Z',
            'data' => ['object' => $object],
        ];
    }

    public function test_invalid_signature_is_rejected_before_any_processing(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        $event = $this->event('evt_bad', 'generation.succeeded', $this->succeededObject($generation->id));
        $body = json_encode($event);

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.now()->timestamp.',v1=not-the-signature',
        ], $body);

        $response->assertStatus(400);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(Generation::STATUS_PENDING, $generation->fresh()->status);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        $event = $this->event('evt_stale', 'generation.succeeded', $this->succeededObject($generation->id));

        $this->postWebhook($event, now()->subSeconds(301)->timestamp)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_valid_signature_with_an_unusable_body_is_rejected(): void
    {
        $signed = $this->signedWebhook(['not' => 'an event']);

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], $signed['headers'], $signed['body']);

        $response->assertStatus(400);
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_ping_is_acknowledged_and_marked_processed(): void
    {
        Queue::fake();

        $response = $this->postWebhook($this->event('evt_ping', 'ping', []));

        $response->assertNoContent();
        Queue::assertNothingPushed();
        $this->assertNotNull(WebhookEvent::query()->sole()->processed_at);
    }

    public function test_success_webhook_settles_exact_cost_and_stores_the_image(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://pixelforge.test/images/*' => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $user = User::factory()->create();

        // The local row exists before the create request returns, which is exactly the
        // "webhook before POST returns" case the docs warn about.
        $generation = $this->createGeneration($user);

        Queue::fake();

        $event = $this->event('evt_success', 'generation.succeeded', $this->succeededObject($generation->id));

        $this->postWebhook($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        Queue::assertPushed(ProcessWebhookEvent::class);

        (new ProcessWebhookEvent(WebhookEvent::query()->sole()->id))
            ->handle(app(GenerationWriter::class));

        Queue::assertPushed(
            StoreGenerationImage::class,
            fn (StoreGenerationImage $job): bool => $job->generationId === $generation->id,
        );

        // The image copy runs in its own job; execute it to assert the stored path.
        (new StoreGenerationImage($generation->id))->handle(app(GenerationWriter::class));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame($this->expectedProviderCostNano(), $generation->provider_cost_nano);
        $this->assertSame($this->expectedChargedNano(), $generation->charged_nano);
        $this->assertSame('0.068312500', Money::toDecimal($generation->charged_nano));
        $this->assertSame('gen_test_123', $generation->pixelforge_id);
        $this->assertNotNull($generation->completed_at);
        $this->assertSame("generations/{$generation->id}.png", $generation->stored_image_path);
        Storage::disk('public')->assertExists("generations/{$generation->id}.png");
        $this->assertNotNull(WebhookEvent::query()->sole()->processed_at);
    }

    public function test_duplicate_delivery_is_stored_once_and_dispatched_once(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $generation = $this->createGeneration($user);
        $event = $this->event('evt_duplicate', 'generation.processing', $this->generationObject([
            'status' => Generation::STATUS_PROCESSING,
            'metadata' => ['generation_id' => $generation->id],
        ]));

        $this->postWebhook($event)->assertNoContent();
        $this->postWebhook($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_processing_after_succeeded_cannot_regress_or_double_charge(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://pixelforge.test/images/*' => Http::response('PNG-BYTES', 200),
        ]);

        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        $writer = app(GenerationWriter::class);

        $writer->apply($generation, $this->succeededObject($generation->id));
        $charged = $generation->fresh()->charged_nano;

        // A retried processing event arrives after success. It must be a no-op.
        $writer->apply($generation->fresh(), $this->generationObject([
            'status' => Generation::STATUS_PROCESSING,
            'metadata' => ['generation_id' => $generation->id],
        ]));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame($charged, $generation->charged_nano);
    }

    public function test_applying_the_same_success_twice_does_not_change_the_cost(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://pixelforge.test/images/*' => Http::response('PNG-BYTES', 200),
        ]);

        $user = User::factory()->create();
        $generation = $this->createGeneration($user);
        $object = $this->succeededObject($generation->id);

        $writer = app(GenerationWriter::class);
        $writer->apply($generation, $object);
        $writer->apply($generation->fresh(), $object);

        $generation->refresh();

        $this->assertSame($this->expectedChargedNano(), $generation->charged_nano);
        $this->assertSame($this->expectedProviderCostNano(), $generation->provider_cost_nano);
    }

    public function test_failed_webhook_records_the_error_and_no_cost(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        $failed = $this->generationObject([
            'status' => Generation::STATUS_FAILED,
            'metadata' => ['generation_id' => $generation->id],
            'error' => [
                'code' => 'generation_failed',
                'message' => 'The model could not produce an image for the prompt.',
            ],
            'completed_at' => '2026-11-22T00:01:00Z',
        ]);

        $this->postWebhook($this->event('evt_failed', 'generation.failed', $failed))->assertNoContent();

        (new ProcessWebhookEvent(WebhookEvent::query()->sole()->id))
            ->handle(app(GenerationWriter::class));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->provider_cost_nano);
        $this->assertNull($generation->charged_nano);
    }

    public function test_unknown_generation_is_recorded_but_does_not_break_the_worker(): void
    {
        Queue::fake();

        $event = $this->event('evt_orphan', 'generation.succeeded', $this->succeededObject('00000000-0000-0000-0000-000000000000'));

        $this->postWebhook($event)->assertNoContent();

        (new ProcessWebhookEvent(WebhookEvent::query()->sole()->id))
            ->handle(app(GenerationWriter::class));

        $this->assertNotNull(WebhookEvent::query()->sole()->processed_at);
        $this->assertSame(0, Generation::query()->count());
    }
}
