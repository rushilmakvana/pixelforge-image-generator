<?php

namespace App\Services\Billing;

use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $requiredNanos, public readonly int $balanceNanos)
    {
        parent::__construct('Insufficient credits.');
    }
}
