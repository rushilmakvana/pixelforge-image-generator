<?php

namespace Tests\Unit;

use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingCatalog;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    private const RATES = PricingCatalog::FALLBACK['rates'];

    private PricingCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PricingCalculator(markupBps: 2500);
    }

    public function test_usage_example_from_the_docs_is_exact(): void
    {
        $cost = $this->calc->providerCost([
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ], self::RATES);

        $this->assertSame('0.169080000', Money::toDecimal($cost));
    }

    public function test_generation_example_from_the_docs(): void
    {
        $cost = $this->calc->providerCost([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
        ], self::RATES);

        // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 millionths of a dollar
        $this->assertSame('0.305510000', Money::toDecimal($cost));
        $this->assertSame('0.381887500', Money::toDecimal($this->calc->userCharge($cost)));
    }

    public function test_markup_is_exactly_25_percent(): void
    {
        $this->assertSame(125, $this->calc->userCharge(100));
        $this->assertSame(Money::parse('0.467343750'), $this->calc->userCharge(Money::parse('0.373875')));
    }

    public function test_max_estimate_matches_the_docs_worked_example(): void
    {
        $estimate = $this->calc->estimate(PricingCatalog::FALLBACK, 400, '1536x1024', 'high', 2);

        $this->assertSame('0.373875000', Money::toDecimal($estimate->providerMax));
        $this->assertSame(115, $estimate->tokens['input_text_tokens']['max']);
        $this->assertSame(85, $estimate->tokens['input_text_tokens']['min']);
    }

    public function test_estimate_range_brackets_any_possible_actual_cost(): void
    {
        $estimate = $this->calc->estimate(PricingCatalog::FALLBACK, 50, '1024x1024', 'medium', 0);

        $cheapest = $this->calc->providerCost(['input_text_tokens' => 11, 'output_tokens' => 1100, 'reasoning_tokens' => 200], self::RATES);
        $dearest = $this->calc->providerCost(['input_text_tokens' => 15, 'output_tokens' => 1100, 'reasoning_tokens' => 2000], self::RATES);

        $this->assertLessThanOrEqual($cheapest, $estimate->providerMin);
        $this->assertGreaterThanOrEqual($dearest, $estimate->providerMax);
    }
}
