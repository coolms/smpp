<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Message;

use function ctype_digit;
use function ctype_xdigit;
use function hexdec;
use function ltrim;
use function strlen;
use function strtolower;

/**
 * Matching a receipt to its submission. SMSCs write the same message id differently in submit_sm_resp and in the
 * receipt: case, leading zeros, and -- a known habit -- hex in one and decimal in the other.
 */
final class MessageId
{
    public static function same(string $a, string $b): bool
    {
        if ('' === $a || '' === $b) {
            return false;
        }
        if (self::canonical($a) === self::canonical($b)) {
            return true;
        }

        return self::hexEqualsDecimal($a, $b) || self::hexEqualsDecimal($b, $a);
    }

    private static function canonical(string $id): string
    {
        $lower = strtolower($id);
        $trimmed = ltrim($lower, '0');

        return ctype_xdigit($lower) ? ('' === $trimmed ? '0' : $trimmed) : $lower;
    }

    /** $hex read as hexadecimal equals $decimal; only for values that fit 63 bits, so the arithmetic is exact. */
    private static function hexEqualsDecimal(string $hex, string $decimal): bool
    {
        $hex = ltrim($hex, '0');
        $decimal = ltrim($decimal, '0');
        if ('' === $hex || '' === $decimal || !ctype_xdigit($hex) || !ctype_digit($decimal) || strlen($hex) > 15 || strlen($decimal) > 18) {
            return false;
        }

        return (int) hexdec($hex) === (int) $decimal;
    }
}
