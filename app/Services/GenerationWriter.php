<?php

namespace App\Services;

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Applies a Pixelforge generation object to our local row.
 *
 * This is deliberately the only place that moves a generation into a terminal state.
 * Webhooks, the submit job and the polling reconciler all funnel through here, so the
 * rules below hold no matter which source saw the change first:
 *
 *  1. `succeeded` and `failed` are final. A late event never reopens them.
 *  2. Status only moves forward: pending < queued < processing < succeeded = failed.
 *  3. Cost is recorded exactly once, under the generation row lock, and only on success.
 *
 * Duplicate webhooks, out-of-order webhooks, a webhook that beats the 202 response, and
 * two workers racing on the same generation are all reduced to the same safe outcome.
 */
class GenerationWriter
{
    /**
     * Apply a provider generation object, which may be the 202 response, a webhook body
     * or a polled `GET /v1/generations/{id}` result.
     *
     * The database work happens in one transaction with a row lock. Downloads happen
     * after the transaction so a slow image fetch never holds the row lock (webhooks
     * have a 10 second delivery budget, and the controller already returns 204, but the
     * worker should still be fast).
     *
     * @param  array<string, mixed>  $object  the provider generation object
     */
    public function apply(Generation $generation, array $object): Generation
    {
        $fresh = DB::transaction(function () use ($generation, $object): Generation {
            $locked = Generation::query()
                ->whereKey($generation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Terminal states are final. This is what makes duplicate and out-of-order
            // webhooks harmless: the second `succeeded`, or a late `processing`, is a no-op.
            if ($locked->isTerminal()) {
                return $locked;
            }

            $incoming = (string) ($object['status'] ?? $locked->status);

            // Status is a one-way ladder. A retried `generation.processing` arriving after
            // `generation.succeeded` has a lower rank and is ignored.
            if (Generation::rank($incoming) < Generation::rank($locked->status)) {
                return $locked;
            }

            $locked->status = $incoming;
            $locked->last_synced_at = now();

            // The provider id is stable; copy it as soon as any response carries it.
            if (! empty($object['id'])) {
                $locked->pixelforge_id = $object['id'];
            }

            // Rates are fixed per generation. The object repeats them on every event, and
            // they are what settlement must use even if the pricing table changed since.
            if (! empty($object['rates']) && is_array($object['rates'])) {
                $locked->rates = $object['rates'];
            }

            if (! empty($object['started_at'])) {
                $locked->started_at = CarbonImmutable::parse($object['started_at']);
            }

            if ($incoming === Generation::STATUS_SUCCEEDED) {
                $this->writeSuccess($locked, $object);
            } elseif ($incoming === Generation::STATUS_FAILED) {
                $this->writeFailure($locked, $object);
            } else {
                // queued / processing: no money changes yet. Mark the first acceptance so
                // the poller knows the create request has returned.
                $locked->submitted_at ??= now();
                $locked->save();
            }

            return $locked;
        });

        // Pixelforge image URLs die after 24h. Store a local copy after the money is
        // already safe, so a slow or failed download never blocks settlement or the
        // webhook worker. The job is unique per generation, so a poller/webhook race
        // cannot download the same image twice.
        if ($fresh->isSucceeded() && $fresh->stored_image_path === null) {
            StoreGenerationImage::dispatch($fresh->id);
        }

        return $fresh->refresh();
    }

    /**
     * Fail a generation for a reason we learned locally instead of from a provider event:
     * content policy rejection, daily quota, idempotency conflict, or exhausted retries.
     *
     * A failure never bills: the status and error are recorded and no cost columns are
     * written.
     */
    public function failLocal(Generation $generation, string $errorCode, string $errorMessage): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage): Generation {
            $locked = Generation::query()
                ->whereKey($generation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // If a webhook already settled it while we were retrying, that terminal state
            // wins and this late local failure is discarded.
            if ($locked->isTerminal()) {
                return $locked;
            }

            $locked->status = Generation::STATUS_FAILED;
            $locked->error_code = $errorCode;
            $locked->error_message = $errorMessage;
            $locked->completed_at = now();
            $locked->last_synced_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Write a succeeded generation and settle it.
     *
     * Money rule: Pixelforge reports token usage, never a price. We multiply each token
     * count by the rates saved on the generation (not the current pricing table) and
     * then add the 25% markup. Integer nanodollars keep this exact.
     *
     * @param  array<string, mixed>  $object
     */
    private function writeSuccess(Generation $locked, array $object): void
    {
        $usage = $object['usage'] ?? null;
        $rates = $object['rates'] ?? $locked->rates;

        if (! is_array($usage) || ! is_array($rates) || $rates === []) {
            // Should not happen: the docs guarantee usage and rates on success. Throwing
            // keeps the job retryable rather than silently charging the wrong amount.
            throw new \RuntimeException("Succeeded generation {$locked->id} has no usage or rates.");
        }

        // Provider cost is the sum of token counts × per-token rates; markup is applied
        // to that exact integer, rounding half-up to the nearest nanodollar.
        $providerCostNano = PricingService::costOfUsage($usage, $rates);
        $chargedNano = Money::withMarkup($providerCostNano, (int) config('services.pixelforge.markup_percent'));

        // The quote is the promise shown to the user. Rates are fixed per generation,
        // but a stale pricing cache can mean the snapshot was computed with older rates;
        // never hide that silently if the final charge exceeds the stated maximum.
        if ($chargedNano > $locked->estimated_charged_nano) {
            Log::warning('pixelforge.charge_exceeded_estimate', [
                'generation_id' => $locked->id,
                'estimated_charged_nano' => $locked->estimated_charged_nano,
                'charged_nano' => $chargedNano,
            ]);
        }

        $locked->status = Generation::STATUS_SUCCEEDED;
        $locked->usage = $usage;
        $locked->provider_cost_nano = $providerCostNano;
        $locked->charged_nano = $chargedNano;
        $locked->image_url = $object['image_url'] ?? null;
        $locked->image_url_expires_at = ! empty($object['image_url_expires_at'])
            ? CarbonImmutable::parse($object['image_url_expires_at'])
            : null;
        $locked->error_code = null;
        $locked->error_message = null;
        // Pixelforge bills on the UTC day the generation *completed*, so this timestamp
        // is also the billing date used by the admin report.
        $locked->completed_at = ! empty($object['completed_at'])
            ? CarbonImmutable::parse($object['completed_at'])
            : now();
        $locked->submitted_at ??= now();
        $locked->save();
    }

    /**
     * Write a failed generation. Nothing is charged, even if the provider had started
     * processing it; the error is stored so the UI can explain what happened.
     *
     * @param  array<string, mixed>  $object
     */
    private function writeFailure(Generation $locked, array $object): void
    {
        $error = $object['error'] ?? [];

        $locked->status = Generation::STATUS_FAILED;
        $locked->error_code = is_array($error) ? ($error['code'] ?? 'generation_failed') : 'generation_failed';
        $locked->error_message = is_array($error)
            ? ($error['message'] ?? 'The generation failed. You were not charged.')
            : 'The generation failed. You were not charged.';
        $locked->completed_at = ! empty($object['completed_at'])
            ? CarbonImmutable::parse($object['completed_at'])
            : now();
        $locked->submitted_at ??= now();
        $locked->save();
    }

    /**
     * Download the finished PNG for the deployed demo.
     *
     * Pixelforge's signed URL expires 24h after completion. We keep our own copy on the
     * configured image disk so the reviewer can still see it later. This runs from a
     * queued, unique job and is best-effort: the provider URL remains in `image_url` as
     * a fallback, and failing to download must never undo a settled generation.
     */
    public function storeImageFor(string $generationId): void
    {
        $generation = Generation::query()->find($generationId);

        if ($generation === null || ! $generation->isSucceeded() || $generation->stored_image_path !== null) {
            return;
        }

        if ($generation->image_url === null) {
            return;
        }

        try {
            $response = Http::timeout(30)->withoutRedirecting()->get($generation->image_url);

            if (! $response->successful()) {
                Log::warning('pixelforge.image_download_failed', [
                    'generation_id' => $generation->id,
                    'status' => $response->status(),
                ]);

                return;
            }

            $disk = Storage::disk((string) config('filesystems.image_disk', 'public'));
            $path = "generations/{$generation->id}.png";

            // `throw` is disabled on the disks, so `put()` reports failure as `false`.
            // Writing the path without checking would make the UI prefer a missing file
            // over Pixelforge's still-valid URL.
            if (! $disk->put($path, $response->body())) {
                Log::warning('pixelforge.image_store_failed', [
                    'generation_id' => $generation->id,
                ]);

                return;
            }

            // Only write the path; never touch money columns outside the settlement path.
            $generation->forceFill(['stored_image_path' => $path])->save();
        } catch (Throwable $e) {
            Log::warning('pixelforge.image_download_error', [
                'generation_id' => $generation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
