<?php

namespace Tests\Concerns;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared fixtures for the Pixelforge feature tests.
 *
 * Keeping the pricing payload and the webhook signing here means every test exercises the
 * same documented numbers (the rates and token tables from the API reference), and the
 * signature helper signs exactly the raw bytes that get posted.
 */
trait PixelforgeTestHelpers
{
    /**
     * The pricing document exactly as GET /v1/pricing returns it.
     *
     * @return array<string, mixed>
     */
    protected function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /**
     * Fake the pricing endpoint so the estimate service never makes a real request.
     */
    protected function fakePricing(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*' => Http::response(['id' => 'gen_unmatched'], 202),
        ]);
    }

    /**
     * A minimal generation row for webhook/state-machine tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createGeneration(User $user, array $overrides = []): Generation
    {
        return Generation::query()->create(array_merge([
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => Generation::STATUS_PENDING,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_provider_cost_nano' => 0,
            'estimated_charged_nano' => $this->expectedChargedNano(),
        ], $overrides));
    }

    /**
     * Build a provider generation object. Tests override `status`, `usage`, `error`, and
     * the timestamps to simulate lifecycle events.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function generationObject(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen_test_123',
            'object' => 'generation',
            'status' => Generation::STATUS_QUEUED,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-22T00:00:00Z',
            'started_at' => null,
            'completed_at' => null,
        ], $overrides);
    }

    /**
     * A succeeded object with the documented usage shape and a fake PNG URL.
     *
     * @return array<string, mixed>
     */
    protected function succeededObject(string $generationId, array $overrides = []): array
    {
        return $this->generationObject(array_merge([
            'status' => Generation::STATUS_SUCCEEDED,
            'metadata' => ['generation_id' => $generationId],
            'usage' => [
                'input_text_tokens' => 100,
                'input_image_tokens' => 765,
                'output_tokens' => 1100,
                'reasoning_tokens' => 200,
                'total_tokens' => 2165,
            ],
            'image_url' => 'https://pixelforge.test/images/gen_test_123.png?signature=abc',
            'image_url_expires_at' => '2026-11-23T00:00:00Z',
            'completed_at' => '2026-11-22T00:01:00Z',
        ], $overrides));
    }

    /**
     * Build and sign a webhook body exactly as Pixelforge does.
     *
     * @param  array<string, mixed>  $event
     * @return array{body: string, headers: array<string, string>}
     */
    protected function signedWebhook(array $event, ?int $timestamp = null): array
    {
        $timestamp ??= now()->timestamp;
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $secret = (string) config('services.pixelforge.webhook_secret');
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return [
            'body' => $body,
            'headers' => [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'HTTP_PIXELFORGE_EVENT_ID' => (string) ($event['id'] ?? ''),
                'HTTP_PIXELFORGE_EVENT_TYPE' => (string) ($event['type'] ?? ''),
                'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            ],
        ];
    }

    /**
     * Post a signed webhook through the real route and middleware.
     *
     * @param  array<string, mixed>  $event
     */
    protected function postWebhook(array $event, ?int $timestamp = null): TestResponse
    {
        $signed = $this->signedWebhook($event, $timestamp);

        return $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        );
    }

    /**
     * Provider cost for the standard succeeded usage, using the rates above.
     *
     * 100×5,000 + 765×10,000 + 1,100×40,000 + 200×12,500 = 54,650,000 nano.
     */
    protected function expectedProviderCostNano(): int
    {
        return 54_650_000;
    }

    /**
     * 25% markup on the standard provider cost, rounded half up to the nano.
     */
    protected function expectedChargedNano(): int
    {
        return Money::withMarkup($this->expectedProviderCostNano());
    }

    /**
     * Convenience for tests that need a resolved pricing service.
     */
    protected function pricingService(): PricingService
    {
        return app(PricingService::class);
    }
}
