<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/** All amounts in nanodollars. "charge" = what the user pays (cost + markup). */
final class CostEstimate
{
    public function __construct(
        public readonly int $providerMin,
        public readonly int $providerExpected,
        public readonly int $providerMax,
        public readonly int $chargeMin,
        public readonly int $chargeExpected,
        public readonly int $chargeMax,
        public readonly array $tokens,
    ) {}

    public function toArray(): array
    {
        return [
            'min' => Money::format($this->chargeMin),
            'expected' => Money::format($this->chargeExpected),
            'max' => Money::format($this->chargeMax),
            'max_nanos' => $this->chargeMax,
            'tokens' => $this->tokens,
        ];
    }
}
