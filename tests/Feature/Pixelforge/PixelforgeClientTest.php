<?php

namespace Tests\Feature\Pixelforge;

use App\Services\Pixelforge\Exceptions\IdempotencyConflictException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RejectedException;
use App\Services\Pixelforge\Exceptions\RetryableException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    private function client(): PixelforgeClient
    {
        return new PixelforgeClient('https://pixelforge.test/v1', 'sk_test');
    }

    private function error(string $code, string $type, int $status, array $extra = [], array $headers = []): mixed
    {
        return Http::response([
            'error' => array_merge(['type' => $type, 'code' => $code, 'message' => "Error {$code}."], $extra),
        ], $status, $headers);
    }

    public function test_rejects_a_non_https_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PixelforgeClient('http://pixelforge.test/v1', 'sk_test');
    }

    public function test_sends_bearer_token_idempotency_key_and_metadata(): void
    {
        Http::fake(['*' => Http::response(['id' => 'gen_1'], 202)]);

        $this->client()->createGeneration(
            'a-uuid-key',
            'A lighthouse',
            '1536x1024',
            'high',
            ['https://example.com/style.png'],
            ['order_id' => '1042'],
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://pixelforge.test/v1/generations'
                && $request->method() === 'POST'
                && $request->hasHeader('Idempotency-Key', 'a-uuid-key')
                && $request->hasHeader('Authorization', 'Bearer sk_test')
                && $request['metadata'] === ['order_id' => '1042']
                && $request['reference_images'] === ['https://example.com/style.png'];
        });
    }

    public function test_successful_create_returns_the_generation_object(): void
    {
        Http::fake(['*' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

        $generation = $this->client()->createGeneration('key', 'prompt', '1024x1024', 'medium');

        $this->assertSame('gen_1', $generation['id']);
        $this->assertSame('queued', $generation['status']);
    }

    public function test_successful_create_without_provider_id_throws_a_provider_exception(): void
    {
        Http::fake(['*' => Http::response(['status' => 'queued'], 202)]);

        $this->expectException(PixelforgeException::class);
        $this->client()->createGeneration('key', 'prompt', '1024x1024', 'medium');
    }

    public function test_successful_pricing_without_rates_throws_a_provider_exception(): void
    {
        Http::fake(['*' => Http::response(['object' => 'pricing'], 200)]);

        $this->expectException(PixelforgeException::class);
        $this->client()->pricing();
    }

    public function test_rate_limit_exposes_retry_after(): void
    {
        Http::fake(['*' => $this->error('rate_limit_exceeded', 'rate_limit_error', 429, [], ['Retry-After' => '17'])]);

        try {
            $this->client()->createGeneration('key', 'prompt', '1024x1024', 'medium');
            $this->fail('Expected a retryable exception.');
        } catch (RetryableException $e) {
            $this->assertSame(17, $e->retryAfter);
            $this->assertTrue($e->isRetryable());
            $this->assertSame(429, $e->status);
        }
    }

    public function test_overload_is_retryable(): void
    {
        Http::fake(['*' => $this->error('overloaded', 'api_error', 503, [], ['Retry-After' => '2'])]);

        $this->expectException(RetryableException::class);
        $this->client()->createGeneration('key', 'prompt', '1024x1024', 'medium');
    }

    public function test_daily_limit_is_not_retried_in_a_tight_loop(): void
    {
        Http::fake(['*' => $this->error('daily_limit_exceeded', 'rate_limit_error', 429)]);

        try {
            $this->client()->createGeneration('key', 'prompt', '1024x1024', 'medium');
            $this->fail('Expected a retryable exception.');
        } catch (RetryableException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_content_policy_violation_is_a_rejection(): void
    {
        Http::fake(['*' => $this->error('content_policy_violation', 'invalid_request_error', 400)]);

        $this->expectException(RejectedException::class);
        $this->client()->createGeneration('key', '[blocked] prompt', '1024x1024', 'medium');
    }

    public function test_validation_failure_exposes_the_first_invalid_parameter(): void
    {
        Http::fake(['*' => $this->error('validation_failed', 'invalid_request_error', 422, [
            'param' => 'size',
            'errors' => ['size' => ['The size must be one of: 1024x1024, 1536x1024, 1024x1536.']],
        ])]);

        try {
            $this->client()->createGeneration('key', 'prompt', 'wrong', 'medium');
            $this->fail('Expected a rejection.');
        } catch (RejectedException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertArrayHasKey('size', $e->errors);
        }
    }

    public function test_reused_idempotency_key_is_its_own_exception(): void
    {
        Http::fake(['*' => $this->error('idempotency_key_reused', 'idempotency_error', 409)]);

        $this->expectException(IdempotencyConflictException::class);
        $this->client()->createGeneration('key', 'different prompt', '1024x1024', 'medium');
    }

    public function test_unexpected_server_error_is_a_generic_exception(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        $this->expectException(PixelforgeException::class);
        $this->client()->createGeneration('key', 'prompt', '1024x1024', 'medium');
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test'])]);

        try {
            $this->client()->getGeneration('gen_1');
            $this->fail('Expected an exception.');
        } catch (PixelforgeException $e) {
            // Not a 2xx, so it surfaces as an error; the point is that exactly one
            // request was made and it was not followed to the Location header.
            $this->assertSame(302, $e->status);
            Http::assertSentCount(1);
        }
    }
}
