<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Encoding;

use CoolMS\Smpp\Exception\MalformedPdu;

use function intdiv;
use function mb_check_encoding;
use function mb_convert_encoding;
use function strlen;

/**
 * UCS-2 as SMSCs and handsets use it today (data_coding 0x08): UTF-16 big-endian, so a character outside the Basic
 * Multilingual Plane -- an emoji -- is a surrogate pair of two 16-bit units, and counts as two against the limit.
 */
final class Ucs2
{
    public static function encode(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new MalformedPdu('The text is not valid UTF-8.');
        }

        return mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    }

    public static function decode(string $octets): string
    {
        if (0 !== strlen($octets) % 2) {
            throw new MalformedPdu('UCS-2 data is whole 16-bit units; ' . strlen($octets) . ' octets is not.');
        }

        return mb_convert_encoding($octets, 'UTF-8', 'UTF-16BE');
    }

    /** The length in 16-bit units: what an SMS counts. */
    public static function units(string $text): int
    {
        return intdiv(strlen(self::encode($text)), 2);
    }
}
