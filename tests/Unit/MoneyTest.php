<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_published_rates_into_nano_per_token(): void
    {
        $this->assertSame(5_000, Money::rateToNanoPerToken('5.00'));
        $this->assertSame(10_000, Money::rateToNanoPerToken('10.00'));
        $this->assertSame(40_000, Money::rateToNanoPerToken('40.00'));
        $this->assertSame(12_500, Money::rateToNanoPerToken('12.50'));
        $this->assertSame(1_000, Money::rateToNanoPerToken('1.00'));
    }

    public function test_parses_decimal_amounts_exactly(): void
    {
        $this->assertSame(46_125_000, Money::fromDecimal('0.046125000'));
        $this->assertSame(373_875_000, Money::fromDecimal('0.373875000'));
        $this->assertSame(0, Money::fromDecimal('0.000000000'));
        $this->assertSame(-1_500_000_000, Money::fromDecimal('-1.500000000'));
    }

    public function test_formats_like_pixelforge(): void
    {
        $this->assertSame('0.046125000', Money::toDecimal(46_125_000));
        $this->assertSame('0.373875000', Money::toDecimal(373_875_000));
        $this->assertSame('$0.046125', Money::display(46_125_000));
        $this->assertSame('$0', Money::display(0));
    }

    public function test_applies_twenty_five_percent_markup(): void
    {
        $this->assertSame(125_000, Money::withMarkup(100_000));
        // 46_125_000 * 1.25 would drift as a float; integers must give this exactly.
        $this->assertSame(57_656_250, Money::withMarkup(46_125_000));
    }

    public function test_reproduces_the_documented_maximum_generation_cost(): void
    {
        // Docs, "Worked example: the most a generation can cost":
        // high quality 1536x1024, 400-character prompt, 2 reference images.
        $cost = Money::cost(115, Money::rateToNanoPerToken('5.00'))      // input text, +15%
            + Money::cost(1_530, Money::rateToNanoPerToken('10.00'))     // 2 x 765 image tokens
            + Money::cost(6_450, Money::rateToNanoPerToken('40.00'))     // output, high 1536x1024
            + Money::cost(8_000, Money::rateToNanoPerToken('12.50'));    // reasoning, high maximum

        $this->assertSame(373_875_000, $cost);
        $this->assertSame('0.373875000', Money::toDecimal($cost));
    }
}
