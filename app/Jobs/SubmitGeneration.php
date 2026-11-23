<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationWriter;
use App\Services\Pixelforge\Exceptions\IdempotencyConflictException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RejectedException;
use App\Services\Pixelforge\Exceptions\RetryableException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one `POST /v1/generations` for a local generation row.
 *
 * Retrying is safe because the row's `idempotency_key` is sent on every attempt: the
 * provider either creates the generation once or replays the same one. The job also
 * reuses the row, so a webhook arriving before the 202 still lands on the right record.
 *
 * Failure mapping:
 *  - 429/503 → release the job after `Retry-After` (at least our own growing backoff)
 *  - daily quota → fail the generation immediately; retrying today cannot succeed
 *  - policy rejection → fail the generation; nothing was created or billed
 *  - idempotency conflict → a bug on our side; log loudly and fail without billing
 *  - anything else after all attempts → `failed()` records the failure cleanly
 */
class SubmitGeneration implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Exponential-ish fallback delays. If Pixelforge's `Retry-After` is longer, we use
     * that instead; if it is shorter, we still back off further on each attempt.
     *
     * @var array<int, int>
     */
    private const BACKOFF_SECONDS = [10, 30, 120, 600, 1800];

    public function __construct(public readonly string $generationId) {}

    /**
     * The docs require a retry cap, so never retry forever.
     */
    public function tries(): int
    {
        return 5;
    }

    /**
     * Let Laravel apply the same delays to uncaught transport errors (for example a
     * connection reset) as the explicit 429/503 releases below.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    /**
     * Dispatch the create request and apply whatever the provider reports.
     */
    public function handle(PixelforgeClient $client, GenerationWriter $writer): void
    {
        $generation = Generation::query()->find($this->generationId);

        // A webhook may have already finished this generation before the job ran, or the
        // row may have been deleted. Either way there is nothing useful to send.
        if ($generation === null || $generation->isTerminal()) {
            return;
        }

        // The queue restores the original job payload before calling failed().
        if ($generation->submission_attempted_at === null) {
            $generation->forceFill(['submission_attempted_at' => now()])->save();
        }

        try {
            $object = $client->createGeneration(
                idempotencyKey: $generation->idempotency_key,
                prompt: $generation->prompt,
                size: $generation->size,
                quality: $generation->quality,
                referenceImages: $generation->reference_images ?? [],
                // Docs: metadata is returned on the generation and every event, which is
                // the most reliable way to match an early webhook to our local row.
                metadata: ['generation_id' => $generation->id],
            );
        } catch (RetryableException $e) {
            $this->handleRetryable($e, $writer, $generation);

            return;
        } catch (RejectedException $e) {
            // 4xx refusals (policy, invalid params, bad key) cannot be fixed by retrying.
            // Nothing was created, so mark the row failed with the provider's reason.
            $writer->failLocal(
                $generation,
                $e->errorCode ?? 'rejected',
                $e->getMessage(),
            );

            return;
        } catch (IdempotencyConflictException $e) {
            // Same key, different parameters: our stored request must have drifted, which
            // is always a bug. Never bill for it; leave a loud trace for investigation.
            Log::critical('pixelforge.idempotency_conflict', [
                'generation_id' => $generation->id,
                'idempotency_key' => $generation->idempotency_key,
                'message' => $e->getMessage(),
            ]);

            $writer->failLocal($generation, 'idempotency_key_reused', $e->getMessage());

            return;
        }

        // 202 queued, or possibly a later state if the provider was very fast. Both are
        // handled by the same writer, including settlement when it is already succeeded.
        $writer->apply($generation, $object);
    }

    /**
     * Retry after the provider's delay when that is safe, or fail now when the limit
     * resets on a longer horizon (the daily quota).
     */
    private function handleRetryable(RetryableException $e, GenerationWriter $writer, Generation $generation): void
    {
        if (! $e->isRetryable()) {
            $writer->failLocal(
                $generation,
                $e->errorCode ?? 'daily_limit_exceeded',
                $e->getMessage(),
            );

            return;
        }

        // `release` counts as an attempt, so the normal tries cap still applies. Taking
        // the max of Retry-After and our own backoff respects the provider while also
        // backing off further on repeated hits.
        $this->release(max($e->retryAfter, $this->backoffForAttempt()));
    }

    /**
     * Pick the fallback delay for the current attempt (1-based).
     */
    private function backoffForAttempt(): int
    {
        $index = min(max($this->attempts() - 1, 0), count(self::BACKOFF_SECONDS) - 1);

        return self::BACKOFF_SECONDS[$index];
    }

    /**
     * Called by the queue after the final attempt fails. Record the failure so the row
     * does not remain pending forever and the user sees a terminal state with a reason.
     */
    public function failed(?Throwable $e): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->isTerminal()) {
            return;
        }

        // A connection failure after the request left our host is ambiguous: the provider
        // may have accepted the generation even though we never saw the 202. Replaying the
        // same idempotency key is safe and either returns the existing generation or
        // creates the one that never reached us.
        if ($generation->submission_attempted_at !== null) {
            $replayed = $this->replaySubmit($generation);

            if ($replayed) {
                return;
            }
        }

        app(GenerationWriter::class)->failLocal(
            $generation,
            $generation->submission_attempted_at === null ? 'submit_not_attempted' : 'submit_failed',
            $e?->getMessage() ?? 'The generation could not be submitted after several attempts.',
        );
    }

    /**
     * One final idempotent attempt when the queue gives up on an ambiguous error.
     *
     * Public because the scheduled reconciliation command replays the same key for rows
     * that outlived the queue's retry window.
     *
     * Returns true when the provider answered and the generation was applied or failed
     * for a definitive reason. Returns false when the provider is still unreachable, in
     * which case the caller records a retryable `submit_failed` state that a late
     * success webhook is still allowed to settle.
     */
    public function replaySubmit(Generation $generation): bool
    {
        try {
            $object = app(PixelforgeClient::class)->createGeneration(
                idempotencyKey: $generation->idempotency_key,
                prompt: $generation->prompt,
                size: $generation->size,
                quality: $generation->quality,
                referenceImages: $generation->reference_images ?? [],
                metadata: ['generation_id' => $generation->id],
            );

            app(GenerationWriter::class)->apply($generation, $object);

            return true;
        } catch (RejectedException $e) {
            app(GenerationWriter::class)->failLocal($generation, $e->errorCode ?? 'rejected', $e->getMessage());

            return true;
        } catch (IdempotencyConflictException $e) {
            Log::critical('pixelforge.idempotency_conflict', [
                'generation_id' => $generation->id,
                'idempotency_key' => $generation->idempotency_key,
                'message' => $e->getMessage(),
            ]);

            app(GenerationWriter::class)->failLocal($generation, 'idempotency_key_reused', $e->getMessage());

            return true;
        } catch (ConnectionException|PixelforgeException $e) {
            if ($e instanceof RetryableException && ! $e->isRetryable()) {
                app(GenerationWriter::class)->failLocal($generation, $e->errorCode ?? 'daily_limit_exceeded', $e->getMessage());

                return true;
            }

            Log::warning('pixelforge.submit_final_replay_failed', [
                'generation_id' => $generation->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        } catch (Throwable $e) {
            // The replay is best-effort; never let it prevent the row from being recorded.
            Log::error('pixelforge.submit_final_replay_error', [
                'generation_id' => $generation->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
