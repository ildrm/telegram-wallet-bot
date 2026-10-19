<?php

namespace App\Telegram;

use InvalidArgumentException;

final class Amount
{
    public static function parse(string $text): string
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,18}))?$/D', trim($text), $matches)) {
            throw new InvalidArgumentException('Enter a positive ETH amount using at most 18 decimal places. Example: 0.01');
        }
        $value = ltrim($matches[1].str_pad($matches[2] ?? '', 18, '0'), '0');
        if ($value === '') {
            throw new InvalidArgumentException('The amount must be greater than zero.');
        }

        return $value;
    }

    public static function format(string $units): string
    {
        $digits = str_pad($units, 19, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -18), '0');

        return substr($digits, 0, -18).($fraction === '' ? '' : '.'.$fraction).' ETH';
    }
}
