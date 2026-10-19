<?php

namespace Tests\Unit;

use App\Domain\Amount;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    public function test_exact_values_beyond_machine_integer_and_eth_roundtrip(): void
    {
        $this->assertSame('1000000000000000000000000000001', Amount::add('1000000000000000000000000000000', '1'));
        $this->assertSame('1234567890123456789', Amount::parse('1.234567890123456789'));
        $this->assertSame('0.000000000000000001', Amount::format('1'));
        $this->assertSame('1.234567890123456789', Amount::format('1234567890123456789'));
    }

    public function test_exponent_and_fractional_base_units_are_rejected(): void
    {
        foreach (['1e18', '0.5', '01', '-1', ' 1', '1.0', str_repeat('9', 61)] as $value) {
            try {
                Amount::unsigned($value);
                $this->fail('Accepted '.$value);
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_too_precise_eth_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        Amount::parse('0.0000000000000000001');
    }
}
