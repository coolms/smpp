<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Encoding;

use CoolMS\Smpp\Exception\MalformedPdu;

use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_ord;
use function mb_str_split;

/** ISO-8859-1 (data_coding 0x03): one octet per character, U+0000 to U+00FF. */
final class Latin1
{
    public static function canEncode(string $text): bool
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return false;
        }
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            if (mb_ord($character, 'UTF-8') > 0xFF) {
                return false;
            }
        }

        return true;
    }

    public static function encode(string $text): string
    {
        if (!self::canEncode($text)) {
            throw new MalformedPdu('The text holds characters ISO-8859-1 cannot; send it as UCS-2.');
        }

        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }

    public static function decode(string $octets): string
    {
        return mb_convert_encoding($octets, 'UTF-8', 'ISO-8859-1');
    }
}
