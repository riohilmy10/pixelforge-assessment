<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Turns token counts into money, using rates read from Pixelforge rather than constants.
 *
 * Rates can change, so nothing outside this class hardcodes a price. A generation keeps
 * the rates it was billed at (the API sends them on the generation object), which is what
 * settlement uses — never the current pricing table.
 */
class PricingService
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return cache()->remember('pixelforge.pricing', now()->addHour(), fn (): array => $this->client->pricing());
    }

    /**
     * @return array<string, int> nano per token, keyed by token type
     */
    public function rates(): array
    {
        return self::nanoRates($this->pricing()['rates']);
    }

    /**
     * Convert the `rates` object the API sends (either the pricing table or the snapshot
     * on a generation) into nano per token.
     *
     * @param  array<string, string|int>  $rates
     * @return array<string, int>
     */
    public static function nanoRates(array $rates): array
    {
        return array_map(
            fn (string|int $rate): int => Money::rateToNanoPerToken((string) $rate),
            $rates,
        );
    }

    /**
     * Cost of a generation's usage at the rates it was billed at.
     *
     * @param  array<string, int|string>  $usage
     * @param  array<string, string|int>  $rates
     */
    public static function costOfUsage(array $usage, array $rates): int
    {
        $nano = self::nanoRates($rates);

        return Money::cost((int) $usage['input_text_tokens'], $nano['input_text_tokens'])
            + Money::cost((int) $usage['input_image_tokens'], $nano['input_image_tokens'])
            + Money::cost((int) $usage['output_tokens'], $nano['output_tokens'])
            + Money::cost((int) $usage['reasoning_tokens'], $nano['reasoning_tokens']);
    }

    /**
     * Quote a generation before submitting it.
     *
     * Docs, "How tokens are counted":
     *  - input text is ~1 token per 4 Unicode characters, ±15%, only known at completion
     *  - input image is exactly 765 per reference image
     *  - output is exact, by quality and size
     *  - reasoning is only known at completion, within a range set by quality
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages = 0): GenerationEstimate
    {
        $pricing = $this->pricing();
        $rates = self::nanoRates($pricing['rates']);
        $tokens = $pricing['tokens'];

        $characters = mb_strlen($prompt, 'UTF-8');
        $charactersPerToken = max(1, (int) $tokens['characters_per_text_token']);
        $variance = (float) $tokens['text_token_variance'];

        $textTypical = (int) ceil($characters / $charactersPerToken);
        $textMax = (int) ceil($textTypical * (1 + $variance));

        $image = $referenceImages * (int) $tokens['per_reference_image'];
        $output = (int) $tokens['output'][$quality][$size];

        $reasoningMin = (int) $tokens['reasoning'][$quality]['min'];
        $reasoningMax = (int) $tokens['reasoning'][$quality]['max'];
        $reasoningTypical = (int) round(($reasoningMin + $reasoningMax) / 2);

        $provider = fn (int $text, int $reasoning): int => Money::cost($text, $rates['input_text_tokens'])
            + Money::cost($image, $rates['input_image_tokens'])
            + Money::cost($output, $rates['output_tokens'])
            + Money::cost($reasoning, $rates['reasoning_tokens']);

        $markup = (int) config('services.pixelforge.markup_percent');
        $typicalProvider = $provider($textTypical, $reasoningTypical);
        $maxProvider = $provider($textMax, $reasoningMax);

        return new GenerationEstimate(
            textTokensTypical: $textTypical,
            textTokensMax: $textMax,
            imageTokens: $image,
            perReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $output,
            reasoningTokensTypical: $reasoningTypical,
            reasoningTokensMax: $reasoningMax,
            typicalProviderCostNano: $typicalProvider,
            maxProviderCostNano: $maxProvider,
            typicalChargedNano: Money::withMarkup($typicalProvider, $markup),
            maxChargedNano: Money::withMarkup($maxProvider, $markup),
        );
    }
}
