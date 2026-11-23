<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Turns token counts into money. Everything is integer nanodollars.
 *
 *   provider cost = Σ tokens × rate / 1,000,000          (rates are USD per 1M tokens)
 *   user charge   = provider cost × (1 + markup)
 *
 * With Pixelforge's rates (5, 10, 40, 12.50) every provider cost is a multiple of
 * 2,500 nanodollars, so the 25% markup is also exact. If rates ever stop dividing
 * evenly we round *up* to the next nanodollar, never in the user's favour by more
 * than a billionth of a dollar, and never below Pixelforge's own figure.
 */
class PricingCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private int $markupBps) {}

    /**
     * @param  array<string, int>  $tokens  keyed by TOKEN_TYPES
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by TOKEN_TYPES
     */
    public function providerCost(array $tokens, array $rates): int
    {
        $numerator = 0; // nanodollars × 1,000,000

        foreach (self::TOKEN_TYPES as $type) {
            $numerator += (int) ($tokens[$type] ?? 0) * Money::parse((string) ($rates[$type] ?? '0'));
        }

        return self::ceilDiv($numerator, 1_000_000);
    }

    public function userCharge(int $providerCostNanos): int
    {
        return $providerCostNanos + self::ceilDiv($providerCostNanos * $this->markupBps, 10_000);
    }

    /**
     * Up-front estimate. Output and image tokens are exact; text tokens and
     * reasoning tokens are only known after completion, so we give a range.
     *
     * @param  array{rates: array<string,string>, tokens: array}  $pricing
     */
    public function estimate(array $pricing, int $promptChars, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $t = $pricing['tokens'];
        $perToken = (int) $t['characters_per_text_token'];
        $variancePct = (int) round(((float) $t['text_token_variance']) * 100);

        $textExpected = self::ceilDiv($promptChars, $perToken);
        $text = [
            'min' => intdiv($textExpected * (100 - $variancePct), 100),
            'expected' => $textExpected,
            'max' => self::ceilDiv($textExpected * (100 + $variancePct), 100),
        ];

        $reasoning = $t['reasoning'][$quality];
        $reasoning = [
            'min' => (int) $reasoning['min'],
            'expected' => intdiv((int) $reasoning['min'] + (int) $reasoning['max'], 2),
            'max' => (int) $reasoning['max'],
        ];

        $image = $referenceImages * (int) $t['per_reference_image'];
        $output = (int) $t['output'][$quality][$size];

        $tokensFor = fn (string $point) => [
            'input_text_tokens' => $text[$point],
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning[$point],
        ];

        $provider = [];
        foreach (['min', 'expected', 'max'] as $point) {
            $provider[$point] = $this->providerCost($tokensFor($point), $pricing['rates']);
        }

        return new CostEstimate(
            providerMin: $provider['min'],
            providerExpected: $provider['expected'],
            providerMax: $provider['max'],
            chargeMin: $this->userCharge($provider['min']),
            chargeExpected: $this->userCharge($provider['expected']),
            chargeMax: $this->userCharge($provider['max']),
            tokens: [
                'input_text_tokens' => $text,
                'input_image_tokens' => $image,
                'output_tokens' => $output,
                'reasoning_tokens' => $reasoning,
            ],
        );
    }

    private static function ceilDiv(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }
}
