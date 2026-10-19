<?php

namespace App\Support;

final class CanonicalJson
{
    /** Order JSON object keys recursively while preserving list order and scalar types. */
    public static function encode(mixed $value): string
    {
        return json_encode(self::ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::ordered($child);
        }

        return $value;
    }
}
