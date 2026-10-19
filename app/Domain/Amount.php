<?php

namespace App\Domain;

use DomainException;

/** Exact ETH base units only. No exponent, sign, decimal point, or float input. */
final class Amount
{
    public static function unsigned(string $value, bool $positive = false): string
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,59})$/D', $value) || ($positive && $value === '0')) {
            throw new DomainException('Enter a positive integer amount in wei (at most 60 digits).');
        }

        return $value;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 0);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 0);
    }

    public static function neg(string $a): string
    {
        return bcsub('0', $a, 0);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }

    public static function format(string $wei): string
    {
        $negative = str_starts_with($wei, '-');
        $wei = ltrim($wei, '-');
        $value = str_pad($wei, 19, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($value, -18), '0');

        return ($negative ? '-' : '').substr($value, 0, -18).($fraction === '' ? '' : '.'.$fraction);
    }

    public static function parse(string $eth): string
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,30})(?:\.([0-9]{1,18}))?$/D', $eth, $m)) {
            throw new DomainException('Use an ETH amount with up to 18 decimal places.');
        }

        return self::unsigned(ltrim($m[1].str_pad($m[2] ?? '', 18, '0'), '0') ?: '0', true);
    }
}
