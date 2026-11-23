<?php

namespace App\Http\Requests;

use App\Services\GenerationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the live quote endpoint.
 *
 * The quote only needs the inputs that affect token counts: prompt length, size, quality
 * and how many reference images there are. It never creates anything or touches credits.
 */
class EstimateGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalise blank repeater rows exactly as the submit request does, so the estimate
     * shown before submit matches the estimate reserved during submit.
     */
    protected function prepareForValidation(): void
    {
        $references = $this->input('reference_images');

        if (is_array($references)) {
            $this->merge([
                'reference_images' => array_values(array_filter(
                    array_map(static fn ($url) => is_string($url) ? trim($url) : $url, $references),
                    static fn ($url) => $url !== null && $url !== '',
                )),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // An empty prompt is legal while the user is still typing; the quote then
            // covers image/output/reasoning tokens and zero text tokens.
            'prompt' => ['nullable', 'string', 'max:'.GenerationService::PROMPT_MAX],
            'size' => ['required', 'string', Rule::in(GenerationService::SIZES)],
            'quality' => ['required', 'string', Rule::in(GenerationService::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'max:'.GenerationService::REFERENCE_IMAGES_MAX],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * The validated payload in the exact shape GenerationService::estimate expects.
     *
     * The prompt is optional because the form asks for a quote while the user is still
     * typing; an empty prompt still has output, image and reasoning tokens.
     *
     * @return array{prompt: string|null, size: string, quality: string, reference_images: array<int, string>}
     */
    public function estimateData(): array
    {
        $prompt = $this->validated('prompt');

        return [
            'prompt' => $prompt === null ? null : (string) $prompt,
            'size' => (string) $this->validated('size'),
            'quality' => (string) $this->validated('quality'),
            'reference_images' => array_values((array) ($this->validated('reference_images') ?? [])),
        ];
    }
}
