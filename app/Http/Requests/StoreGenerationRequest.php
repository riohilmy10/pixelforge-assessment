<?php

namespace App\Http\Requests;

use App\Services\GenerationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a generation submit.
 *
 * These rules mirror the Pixelforge create endpoint exactly, so an invalid request is
 * rejected locally instead of spending a provider request. Unknown fields are not
 * accepted by the provider, so the controller only ever sends the keys validated here.
 */
class StoreGenerationRequest extends FormRequest
{
    /**
     * The route already sits behind `auth` and `verified` middleware.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Drop blank reference-image inputs before validation, so an empty repeater row does
     * not fail the `url` rule. The provider rejects unknown/empty values, and so do we.
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
            // Docs: at most 4,000 characters. Unicode characters, not bytes.
            'quoted_max' => ['required', 'string', 'regex:/^-?\d+\.\d{9}$/D', 'max:24'],
            'prompt' => ['required', 'string', 'max:'.GenerationService::PROMPT_MAX],
            // Docs: exactly these three values; the default is 1024x1024 but the UI always
            // sends an explicit choice.
            'size' => ['required', 'string', Rule::in(GenerationService::SIZES)],
            'quality' => ['required', 'string', Rule::in(GenerationService::QUALITIES)],
            // Docs: optional http(s) URLs, at most 4.
            'reference_images' => ['nullable', 'array', 'max:'.GenerationService::REFERENCE_IMAGES_MAX],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * The validated payload in the exact shape GenerationService::submit expects.
     *
     * Keeping the conversion on the request means the controller never touches raw
     * validated arrays and the service never has to guess which keys are present.
     *
     * @return array{quoted_max: string, prompt: string, size: string, quality: string, reference_images: array<int, string>}
     */
    public function generationData(): array
    {
        return [
            'quoted_max' => (string) $this->validated('quoted_max'),
            'prompt' => (string) $this->validated('prompt'),
            'size' => (string) $this->validated('size'),
            'quality' => (string) $this->validated('quality'),
            'reference_images' => array_values((array) ($this->validated('reference_images') ?? [])),
        ];
    }
}
