<?php

namespace App\Services;

use App\Exceptions\PricingUnavailableException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\GenerationEstimate;
use App\Services\Pixelforge\PricingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

/**
 * All reading and writing for generations.
 *
 * Controllers only render or redirect what this service returns. The service owns the
 * quote, the database row, the queued provider call, and the display-ready arrays used by
 * the dashboard and history pages.
 *
 * Money rules live here and in {@see GenerationWriter}:
 *  - the price shown before submit is a range (typical and worst case)
 *  - the worst case is snapshotted on the row as the promise made to the user
 *  - the actual cost is computed once, on success, from the provider's token usage and
 *    the rates stored on that generation
 */
class GenerationService
{
    /** The only sizes Pixelforge accepts. Shared with the UI to keep both sides in sync. */
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    /** The only qualities Pixelforge accepts. */
    public const QUALITIES = ['low', 'medium', 'high'];

    /** Product limits, also sent to the UI so the counter and input guards cannot drift. */
    public const PROMPT_MAX = 4000;

    public const REFERENCE_IMAGES_MAX = 4;

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Data for the dashboard: the generator options plus the latest generations.
     *
     * The newest twelve are enough to make the page useful without loading the full
     * history; the dedicated history page owns pagination.
     *
     * @return array{generations: array<int, array<string, mixed>>, options: array<string, mixed>}
     */
    public function dashboard(User $user): array
    {
        return [
            'generations' => $this->recentGenerations($user),
            'options' => $this->options(),
        ];
    }

    /**
     * Data for the paginated history page, newest first.
     *
     * Each row is converted to the shared UI shape, which includes display-ready money
     * strings and the stored-or-provider image URL.
     *
     * @return array{generations: LengthAwarePaginator<int, array<string, mixed>>}
     */
    public function history(User $user): array
    {
        return [
            'generations' => $user->generations()
                ->latest()
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Generation $generation): array => $generation->toUiArray()),
        ];
    }

    /**
     * Quote a generation without creating anything.
     *
     * @param  array{prompt?: string|null, size: string, quality: string, reference_images?: array<int, string>}  $data
     */
    public function estimate(array $data): GenerationEstimate
    {
        return $this->quote(
            prompt: $data['prompt'] ?? '',
            size: $data['size'],
            quality: $data['quality'],
            referenceImages: count($data['reference_images'] ?? []),
        );
    }

    /**
     * Create a generation and queue the provider request.
     *
     * The row is created before the API call so a webhook that arrives before the 202
     * response still has a local record. The idempotency key is generated once and stored,
     * so every retry of the job replays the same provider request instead of creating a
     * second billable generation.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>}  $data
     */
    public function submit(User $user, array $data): Generation
    {
        $references = array_values($data['reference_images'] ?? []);

        $estimate = $this->quote(
            prompt: $data['prompt'],
            size: $data['size'],
            quality: $data['quality'],
            referenceImages: count($references),
        );

        $generation = Generation::query()->create([
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => Generation::STATUS_PENDING,
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $references,
            // Snapshot the worst case: this is the "up to" figure the user saw. The actual
            // charge is written later on success and can only be lower.
            'estimated_provider_cost_nano' => $estimate->maxProviderCostNano,
            'estimated_charged_nano' => $estimate->maxChargedNano,
        ]);

        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }

    /**
     * Build a quote and translate transport failures into a domain exception.
     *
     * This keeps HTTP-shaped error handling out of the controller and out of the submit
     * flow. {@see PricingUnavailableException} decides how to render itself.
     */
    private function quote(string $prompt, string $size, string $quality, int $referenceImages): GenerationEstimate
    {
        try {
            return $this->pricing->estimate(
                prompt: $prompt,
                size: $size,
                quality: $quality,
                referenceImages: $referenceImages,
            );
        } catch (PixelforgeException|ConnectionException $e) {
            throw new PricingUnavailableException($e);
        }
    }

    /**
     * Latest rows for the dashboard, already shaped for the UI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentGenerations(User $user): array
    {
        return $user->generations()
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (Generation $generation): array => $generation->toUiArray())
            ->all();
    }

    /**
     * The static choices and limits the generator form offers.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'sizes' => self::SIZES,
            'qualities' => self::QUALITIES,
            'prompt_max' => self::PROMPT_MAX,
            'reference_images_max' => self::REFERENCE_IMAGES_MAX,
        ];
    }
}
