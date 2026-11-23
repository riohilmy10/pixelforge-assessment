<?php

namespace Tests\Feature\Pixelforge;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    private function fakePricing(): void
    {
        Http::fake([
            '*/pricing' => Http::response([
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
            ]),
        ]);
    }

    private function service(): PricingService
    {
        return new PricingService(new PixelforgeClient('https://pixelforge.test/v1', 'sk_test'));
    }

    public function test_rates_are_converted_to_nano_per_token(): void
    {
        $this->fakePricing();

        $this->assertSame([
            'input_text_tokens' => 5_000,
            'input_image_tokens' => 10_000,
            'output_tokens' => 40_000,
            'reasoning_tokens' => 12_500,
        ], $this->service()->rates());
    }

    public function test_estimate_matches_the_documented_maximum_for_a_worst_case_generation(): void
    {
        $this->fakePricing();

        // The docs' worked example: high quality, 1536x1024, 400 characters, 2 references.
        $estimate = $this->service()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(100, $estimate->textTokensTypical);
        $this->assertSame(115, $estimate->textTokensMax);
        $this->assertSame(1_530, $estimate->imageTokens);
        $this->assertSame(6_450, $estimate->outputTokens);
        $this->assertSame(4_500, $estimate->reasoningTokensTypical);
        $this->assertSame(8_000, $estimate->reasoningTokensMax);

        $this->assertSame('0.373875000', Money::toDecimal($estimate->maxProviderCostNano));
        $this->assertSame('0.467343750', Money::toDecimal($estimate->maxChargedNano));
    }

    public function test_markup_is_always_added_on_top_of_the_provider_cost(): void
    {
        $this->fakePricing();

        $estimate = $this->service()->estimate('A lighthouse', '1024x1024', 'low');

        $this->assertSame(
            Money::withMarkup($estimate->typicalProviderCostNano, 25),
            $estimate->typicalChargedNano,
        );
        $this->assertSame(
            Money::withMarkup($estimate->maxProviderCostNano, 25),
            $estimate->maxChargedNano,
        );
    }

    public function test_typical_estimate_never_exceeds_the_maximum(): void
    {
        $this->fakePricing();

        foreach (['low', 'medium', 'high'] as $quality) {
            foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
                $estimate = $this->service()->estimate('prompt', $size, $quality, 1);

                $this->assertLessThanOrEqual($estimate->maxProviderCostNano, $estimate->typicalProviderCostNano);
                $this->assertLessThanOrEqual($estimate->maxChargedNano, $estimate->typicalChargedNano);
            }
        }
    }

    public function test_settled_cost_uses_the_rates_snapshotted_on_the_generation(): void
    {
        // A generation billed at old rates must settle at those rates, not today's pricing.
        $snapshotRates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        $usage = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];

        // 12 x 5000 + 765 x 10000 + 6450 x 40000 + 3184 x 12500
        $this->assertSame(305_510_000, PricingService::costOfUsage($usage, $snapshotRates));
        $this->assertSame('0.305510000', Money::toDecimal(PricingService::costOfUsage($usage, $snapshotRates)));
    }
}
