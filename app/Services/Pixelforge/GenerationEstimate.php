<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * What the UI shows before a generation is submitted.
 *
 * Pixelforge only reveals two of the four token counts up front, so the honest quote is
 * a range: `max` assumes the worst case for every unknown, `typical` assumes the likely
 * case. We always reserve against `max`, so a balance can never be overdrawn by a
 * generation that costs more than quoted.
 */
final class GenerationEstimate
{
    public function __construct(
        public readonly int $textTokensTypical,
        public readonly int $textTokensMax,
        public readonly int $imageTokens,
        public readonly int $outputTokens,
        public readonly int $reasoningTokensTypical,
        public readonly int $reasoningTokensMax,
        public readonly int $typicalProviderCostNano,
        public readonly int $maxProviderCostNano,
        public readonly int $typicalChargedNano,
        public readonly int $maxChargedNano,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text_tokens' => [
                'typical' => $this->textTokensTypical,
                'max' => $this->textTokensMax,
            ],
            'image_tokens' => $this->imageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => [
                'typical' => $this->reasoningTokensTypical,
                'max' => $this->reasoningTokensMax,
            ],
            'provider_cost' => [
                'typical' => Money::toDecimal($this->typicalProviderCostNano),
                'max' => Money::toDecimal($this->maxProviderCostNano),
            ],
            'charged' => [
                'typical' => Money::toDecimal($this->typicalChargedNano),
                'max' => Money::toDecimal($this->maxChargedNano),
                'typical_display' => Money::display($this->typicalChargedNano),
                'max_display' => Money::display($this->maxChargedNano),
            ],
        ];
    }
}
