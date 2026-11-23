<?php

namespace Tests\Feature\Pixelforge;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class AmbiguousSubmissionRecoveryTest extends TestCase
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

    private function ambiguousGeneration(): Generation
    {
        $generation = $this->createGeneration(User::factory()->create());

        $generation->forceFill([
            'status' => Generation::STATUS_FAILED,
            'error_code' => 'submit_failed',
            'error_message' => 'The connection was lost.',
            'submission_attempted_at' => now()->subMinutes(10),
        ])->save();

        return $generation;
    }

    public function test_a_queued_replay_reopens_the_generation_for_normal_polling(): void
    {
        $generation = $this->ambiguousGeneration();

        Http::fake([
            '*/generations' => Http::response($this->generationObject([
                'id' => 'gen_recovered',
                'status' => Generation::STATUS_QUEUED,
            ]), 202),
        ]);

        $this->artisan('pixelforge:reconcile-ambiguous-submissions')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('gen_recovered', $generation->pixelforge_id);
        $this->assertNull($generation->error_code);
        $this->assertNull($generation->error_message);
        $this->assertNotNull($generation->submission_recovery_attempted_at);
    }

    public function test_a_succeeded_replay_settles_the_generation(): void
    {
        Queue::fake();

        $generation = $this->ambiguousGeneration();

        Http::fake([
            '*/generations' => Http::response($this->succeededObject($generation->id), 202),
        ]);

        $this->artisan('pixelforge:reconcile-ambiguous-submissions')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame($this->expectedChargedNano(), $generation->charged_nano);
        $this->assertNull($generation->error_code);
    }

    public function test_a_provider_failure_replay_closes_the_ambiguous_case(): void
    {
        $generation = $this->ambiguousGeneration();

        Http::fake([
            '*/generations' => Http::response($this->generationObject([
                'id' => 'gen_failed',
                'status' => Generation::STATUS_FAILED,
                'error' => ['code' => 'generation_failed', 'message' => 'The model failed.'],
            ]), 202),
        ]);

        $this->artisan('pixelforge:reconcile-ambiguous-submissions')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->charged_nano);
    }

    public function test_a_definitive_rejection_replaces_the_provisional_failure(): void
    {
        $generation = $this->ambiguousGeneration();

        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'Your prompt was rejected.',
                ],
            ], 400),
        ]);

        $this->artisan('pixelforge:reconcile-ambiguous-submissions')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
    }

    public function test_an_unreachable_provider_leaves_the_row_recoverable(): void
    {
        $generation = $this->ambiguousGeneration();

        Http::fake(fn () => throw new ConnectionException('Still offline'));

        $this->artisan('pixelforge:reconcile-ambiguous-submissions')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('submit_failed', $generation->error_code);
        $this->assertNotNull($generation->submission_recovery_attempted_at);
    }
}
