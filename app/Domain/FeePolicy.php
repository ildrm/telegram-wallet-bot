<?php

namespace App\Domain;

use DomainException;

final class FeePolicy
{
    public function calculate(string $kind, string $amount): string
    {
        Amount::unsigned($amount, true);
        $policy = config('wallet.fees.'.$kind, []);
        $fixed = Amount::unsigned((string) ($policy['fixed'] ?? '0'));
        $bps = (string) ($policy['bps'] ?? '0');
        if (! preg_match('/^[0-9]{1,5}$/D', $bps) || (int) $bps > 10000) {
            throw new DomainException('Invalid fee policy.');
        }
        // Round percentage upward to the next base unit, never binary floating point.
        $fee = Amount::add($fixed, bcdiv(Amount::add(bcmul($amount, $bps, 0), '9999'), '10000', 0));
        $min = Amount::unsigned((string) ($policy['min'] ?? '0'));
        $max = isset($policy['max']) ? Amount::unsigned((string) $policy['max']) : null;
        if ($max !== null && Amount::cmp($max, $min) < 0) {
            throw new DomainException('Invalid fee policy range.');
        }
        if (Amount::cmp($fee, $min) < 0) {
            $fee = $min;
        }
        if ($max !== null && Amount::cmp($fee, $max) > 0) {
            $fee = $max;
        }

        return $fee;
    }
}
