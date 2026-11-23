<?php

namespace Tests\Feature\Pixelforge;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationWriter;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class GenerationSubmitTest extends TestCase
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

    public function test_guests_cannot_submit_or_estimate(): void
    {
        $this->post(route('generations.store'), [])->assertRedirect(route('login'));
        $this->post(route('generations.estimate'), [])->assertRedirect(route('login'));
    }

    public function test_estimate_uses_the_documented_worst_case(): void
    {
        $this->fakePricing();
        $user = User::factory()->create();

        // The docs' worked example: high quality 1536x1024, 400 characters, 2 references.
        $response = $this->actingAs($user)->postJson(route('generations.estimate'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);

        $response->assertOk()
            ->assertJsonPath('estimate.text_tokens.typical', 100)
            ->assertJsonPath('estimate.text_tokens.max', 115)
            ->assertJsonPath('estimate.image_tokens', 1530)
            ->assertJsonPath('estimate.output_tokens', 6450)
            ->assertJsonPath('estimate.reasoning_tokens.max', 8000)
            ->assertJsonPath('estimate.provider_cost.max', '0.373875000')
            ->assertJsonPath('estimate.charged.max', '0.467343750');
    }

    public function test_store_snapshots_the_worst_case_quote_and_queues_the_job(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'quoted_max' => '0.467343750',
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);

        $response->assertRedirect(route('dashboard'));

        $generation = Generation::query()->sole();

        $this->assertSame(Generation::STATUS_PENDING, $generation->status);
        $this->assertCount(2, $generation->reference_images);
        $this->assertSame(Money::fromDecimal('0.373875000'), $generation->estimated_provider_cost_nano);
        $this->assertSame(Money::fromDecimal('0.467343750'), $generation->estimated_charged_nano);
        $this->assertNull($generation->charged_nano);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $generation->id);
    }

    public function test_changed_quote_must_be_confirmed_before_queueing(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->create();
        $inputs = [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ];

        $this->actingAs($user)->post(route('generations.store'), $inputs)
            ->assertSessionHasErrors('quoted_max');

        $this->from(route('dashboard'))->post(route('generations.store'), $inputs + ['quoted_max' => '0.010000000'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error')
            ->assertSessionHas('updated_quote.charged.max', '0.086275000');

        $this->assertDatabaseEmpty('generations');
        Queue::assertNothingPushed();

        $this->post(route('generations.store'), $inputs + ['quoted_max' => '0.086275000'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertSame(86_275_000, Generation::query()->sole()->estimated_charged_nano);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_store_validation_rejects_inputs_the_provider_would_reject(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'ok',
            'size' => '999x999',
            'quality' => 'ultra',
            'reference_images' => array_fill(0, 5, 'https://example.com/a.png'),
        ])->assertSessionHasErrors(['size', 'quality', 'reference_images']);

        $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => str_repeat('a', 4001),
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertSessionHasErrors(['prompt']);

        $this->assertSame(0, Generation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_pricing_outage_returns_503_and_creates_nothing(): void
    {
        Http::fake([
            '*/pricing' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'code' => 'overloaded',
                    'message' => 'Pixelforge is temporarily overloaded.',
                ],
            ], 503, ['Retry-After' => '2']),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('generations.estimate'), [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertStatus(503);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('generations.store'), [
                'quoted_max' => '0.086281250',
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_backoff_exposes_the_documented_delays_to_the_queue(): void
    {
        $this->assertSame([10, 30, 120, 600, 1800], (new SubmitGeneration('generation-id'))->backoff());
    }

    public function test_a_lost_connection_is_replayed_once_before_the_row_fails(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        $succeeded = $this->succeededObject($generation->id);
        $calls = 0;

        Http::fake(function () use ($succeeded, &$calls) {
            if ($calls++ === 0) {
                throw new ConnectionException('Connection reset');
            }

            return Http::response($succeeded, 202);
        });

        $job = new SubmitGeneration($generation->id);
        $originalPayload = serialize($job);

        try {
            $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));
            $this->fail('The transport exception should have propagated.');
        } catch (ConnectionException) {
            // The worker calls failed() after this exception.
        }

        unserialize($originalPayload)->failed(new ConnectionException('Connection reset'));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame($this->expectedChargedNano(), $generation->charged_nano);
        $this->assertNull($generation->error_code);

        Http::assertSent(fn ($request): bool => $request->header('Idempotency-Key')[0] === $generation->idempotency_key);
    }

    public function test_ambiguous_provider_error_with_a_code_accepts_late_success(): void
    {
        Queue::fake();
        $generation = $this->createGeneration(User::factory()->create());
        Http::fake(['*/generations' => Http::response([
            'error' => ['code' => 'upstream_error', 'message' => 'Upstream response timed out.'],
        ], 500)]);

        $job = new SubmitGeneration($generation->id);
        $originalPayload = serialize($job);

        try {
            $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));
            $this->fail('Expected a provider error.');
        } catch (PixelforgeException $e) {
            unserialize($originalPayload)->failed($e);
        }

        Http::assertSentCount(2);
        $this->assertSame('submit_failed', $generation->fresh()->error_code);
        $this->assertSame('Upstream response timed out.', $generation->fresh()->error_message);

        app(GenerationWriter::class)->apply($generation, $this->succeededObject($generation->id));

        $this->assertTrue($generation->fresh()->isSucceeded());
    }

    public function test_pricing_transport_failure_is_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('generations.estimate'), [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertStatus(503);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('generations.store'), [
                'quoted_max' => '0.086281250',
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_submit_job_sends_the_stored_idempotency_key_and_metadata(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        Http::fake([
            '*/generations' => Http::response($this->generationObject([
                'metadata' => ['generation_id' => $generation->id],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ]), 202),
        ]);

        $job = new SubmitGeneration($generation->id);
        $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));

        Http::assertSent(function ($request) use ($generation): bool {
            return $request->method() === 'POST'
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['metadata'] === ['generation_id' => $generation->id]
                && $request['prompt'] === $generation->prompt;
        });

        $generation->refresh();

        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('gen_test_123', $generation->pixelforge_id);
        $this->assertSame('5.00', $generation->rates['input_text_tokens']);
        $this->assertNotNull($generation->submitted_at);
    }

    public function test_policy_rejection_fails_the_generation_without_a_cost(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'The prompt was rejected.',
                ],
            ], 400),
        ]);

        $job = new SubmitGeneration($generation->id);
        $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));

        app(GenerationWriter::class)->apply($generation, $this->succeededObject($generation->id));
        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertNull($generation->provider_cost_nano);
        $this->assertNull($generation->charged_nano);
    }

    public function test_daily_limit_fails_immediately_instead_of_retrying(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'code' => 'daily_limit_exceeded',
                    'message' => 'The daily generation quota is used up.',
                ],
            ], 429),
        ]);

        $job = new SubmitGeneration($generation->id);
        $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('daily_limit_exceeded', $generation->error_code);
        $this->assertNull($generation->charged_nano);
    }

    public function test_overload_is_released_after_the_larger_of_retry_after_and_backoff(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'code' => 'overloaded',
                    'message' => 'Pixelforge is temporarily overloaded.',
                ],
            ], 503, ['Retry-After' => '2']),
        ]);

        $job = new SubmitGeneration($generation->id);

        // The queue's job is mocked so we can assert the released delay. The first
        // attempt's fallback is 10s, which is longer than this Retry-After of 2s.
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(10);

        $job->setJob($queueJob);
        $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));

        // Still non-terminal: the provider may still create it on a later attempt.
        $this->assertSame(Generation::STATUS_PENDING, $generation->fresh()->status);
    }

    public function test_idempotency_conflict_is_logged_and_fails_the_generation(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'idempotency_error',
                    'code' => 'idempotency_key_reused',
                    'message' => 'The Idempotency-Key was already used with different parameters.',
                ],
            ], 409),
        ]);

        $job = new SubmitGeneration($generation->id);
        $job->handle(app(PixelforgeClient::class), app(GenerationWriter::class));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('idempotency_key_reused', $generation->error_code);
    }

    public function test_exhausted_job_that_never_submitted_does_not_replay(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        (new SubmitGeneration($generation->id))->failed(new \RuntimeException('boom'));

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        Http::assertNothingSent();
        $this->assertSame('submit_not_attempted', $generation->error_code);
        $this->assertNull($generation->charged_nano);
    }
}
