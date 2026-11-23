<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Exceptions\IdempotencyConflictException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RejectedException;
use App\Services\Pixelforge\Exceptions\RetryableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over Pixelforge's REST API.
 *
 * Everything provider-specific about transport lives here: bearer auth, no redirect
 * following, and translating the documented error envelope into typed exceptions that
 * the rest of the app can branch on by `code`.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.pixelforge.base_url'),
            (string) config('services.pixelforge.api_key'),
        );
    }

    /**
     * @param  array<string>  $referenceImages
     * @param  array<string, string>  $metadata  string keys and values, at most 20 keys
     * @return array<string, mixed> the generation object
     *
     * @throws RetryableException|RejectedException|IdempotencyConflictException|PixelforgeException
     */
    public function createGeneration(
        string $idempotencyKey,
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages = [],
        array $metadata = [],
    ): array {
        $payload = [
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
        ];

        if ($referenceImages !== []) {
            $payload['reference_images'] = $referenceImages;
        }

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $this->send(
            $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $payload)
        );
    }

    /**
     * @return array<string, mixed> the generation object
     */
    public function getGeneration(string $id): array
    {
        return $this->send($this->request()->get('/generations/'.urlencode($id)));
    }

    /**
     * @param  string|null  $status  queued|processing|succeeded|failed
     * @return array<string, mixed>
     */
    public function listGenerations(int $limit = 100, ?string $status = null, ?string $cursor = null): array
    {
        $query = array_filter([
            'limit' => $limit,
            'status' => $status,
            'cursor' => $cursor,
        ]);

        return $this->send($this->request()->get('/generations', $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send($this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * Pricing needs no authentication, but sending the key does no harm.
     */
    /**
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return $this->send($this->request()->get('/pricing'));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->withoutRedirecting();
    }

    /**
     * @return array<string, mixed>
     */
    private function send(Response $response): array
    {
        $remaining = $response->header('X-RateLimit-Remaining');

        if ($remaining) {
            Log::debug('pixelforge.rate_limit', [
                'remaining' => $remaining,
                'limit' => $response->header('X-RateLimit-Limit'),
                'path' => $response->effectiveUri(),
            ]);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw $this->exceptionFor($response);
    }

    private function exceptionFor(Response $response): PixelforgeException
    {
        $error = $response->json('error') ?? [];
        $errorCode = $error['code'] ?? null;
        $type = $error['type'] ?? null;
        $message = $error['message'] ?? "Pixelforge responded with HTTP {$response->status()}.";
        $errors = $error['errors'] ?? [];
        $status = $response->status();

        if ($errorCode === 'idempotency_key_reused') {
            return new IdempotencyConflictException($message, $errorCode, $type, $status, $errors);
        }

        $isRetryable = $status === 429
            || $status === 503
            || in_array($errorCode, ['overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded'], true);

        if ($isRetryable) {
            return new RetryableException(
                $message,
                $errorCode,
                $type,
                $status,
                $errors,
                retryAfter: $this->retryAfter($response),
            );
        }

        if ($status >= 400 && $status < 500) {
            return new RejectedException($message, $errorCode, $type, $status, $errors);
        }

        return new PixelforgeException($message, $errorCode, $type, $status, $errors);
    }

    /**
     * Docs: respect Retry-After, in seconds. Pixelforge sends it on 429s and overloads.
     */
    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : 5;
    }
}
