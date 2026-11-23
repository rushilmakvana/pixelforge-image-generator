<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimal_strings_without_floats(): void
    {
        $this->assertSame(169_080_000, Money::parse('0.169080000'));
        $this->assertSame(12_500_000_000, Money::parse('12.50'));
        $this->assertSame(5_000_000_000, Money::parse('5'));
        $this->assertSame(-1, Money::parse('-0.000000001'));
    }

    public function test_formats(): void
    {
        $this->assertSame('0.046125000', Money::toDecimal(46_125_000));
        $this->assertSame('$0.046125', Money::format(46_125_000));
        $this->assertSame('$5.00', Money::format(5_000_000_000));
        $this->assertSame('$1,234.50', Money::format(1_234_500_000_000));
        $this->assertSame('-$0.12', Money::format(-120_000_000));
        $this->assertSame('$0.00', Money::format(0));
    }
}
