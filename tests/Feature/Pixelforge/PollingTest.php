<?php

namespace Tests\Feature\Pixelforge;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class PollingTest extends TestCase
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

    public function test_polling_reconciles_a_generation_when_no_webhook_arrives(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_PROCESSING,
            'pixelforge_id' => 'gen_poll_1',
        ]);

        Http::fake([
            'https://pixelforge.test/v1/generations/gen_poll_1' => Http::response(
                $this->succeededObject($generation->id, [
                    'id' => 'gen_poll_1',
                    'metadata' => ['generation_id' => $generation->id],
                ]),
            ),
            'https://pixelforge.test/images/*' => Http::response('PNG-BYTES', 200),
        ]);

        $this->artisan('pixelforge:poll')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame($this->expectedProviderCostNano(), $generation->provider_cost_nano);
        $this->assertSame($this->expectedChargedNano(), $generation->charged_nano);
        $this->assertNotNull($generation->last_synced_at);
    }

    public function test_polling_skips_terminal_and_recently_synced_rows(): void
    {
        $user = User::factory()->create();
        $this->createGeneration($user, [
            'status' => Generation::STATUS_SUCCEEDED,
            'pixelforge_id' => 'gen_done',
        ]);
        $this->createGeneration($user, [
            'status' => Generation::STATUS_QUEUED,
            'pixelforge_id' => 'gen_fresh',
            'last_synced_at' => now(),
        ]);

        Http::fake();

        $this->artisan('pixelforge:poll')->assertSuccessful();

        Http::assertNothingSent();
    }
}
