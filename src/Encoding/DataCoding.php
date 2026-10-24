<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Encoding;

/**
 * The data_coding values a client meets (SMPP 3.4 section 5.2.19). Default is the GSM 7-bit alphabet, sent unpacked;
 * Octet stands for binary data in either of its codes (0x02, 0x04) and for the 0xF4-0xF7 message class codings.
 */
enum DataCoding: int
{
    case Default = 0x00;
    case Ascii = 0x01;
    case Octet = 0x04;
    case Latin1 = 0x03;
    case Ucs2 = 0x08;

    /**
     * The coding for a text: GSM 7-bit when every character is in it (the most characters per SMS), else Latin-1 when
     * allowed and enough, else UCS-2. Latin-1 is opt-in: not every SMSC passes data_coding 3 to the handset intact.
     */
    public static function bestFor(string $text, bool $allowLatin1 = false): self
    {
        if (Gsm7::canEncode($text)) {
            return self::Default;
        }

        return $allowLatin1 && Latin1::canEncode($text) ? self::Latin1 : self::Ucs2;
    }

    /** What a received data_coding byte means; anything not text-bearing reads as Octet. */
    public static function fromByte(int $byte): self
    {
        return match (true) {
            0x00 === $byte => self::Default,
            0x01 === $byte => self::Ascii,
            0x03 === $byte => self::Latin1,
            0x08 === $byte, 0x18 === $byte => self::Ucs2,
            0xF0 === ($byte & 0xFC) => self::Default,
            default => self::Octet,
        };
    }

    public function encode(string $text): string
    {
        return match ($this) {
            self::Default => Gsm7::encode($text),
            self::Ucs2 => Ucs2::encode($text),
            self::Latin1 => Latin1::encode($text),
            self::Ascii, self::Octet => $text,
        };
    }

    public function decode(string $octets): string
    {
        return match ($this) {
            self::Default => Gsm7::decode($octets),
            self::Ucs2 => Ucs2::decode($octets),
            self::Latin1 => Latin1::decode($octets),
            self::Ascii, self::Octet => $octets,
        };
    }

    /** The bits one character unit takes on the air interface: 7 for GSM, 8 for Latin-1 and octets, 16 for UCS-2. */
    public function unitBits(): int
    {
        return match ($this) {
            self::Default => 7,
            self::Ucs2 => 16,
            default => 8,
        };
    }
}
