<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Encoding;

use CoolMS\Smpp\Exception\MalformedPdu;

use function chr;
use function intdiv;
use function mb_str_split;
use function ord;
use function strlen;

/**
 * The GSM 7-bit default alphabet (3GPP TS 23.038 section 6.2.1) and its extension table (6.2.1.1), reached by the
 * escape 0x1B.
 *
 * SMPP carries data_coding 0 in short_message one septet per octet ("unpacked"), which is what encode() and decode()
 * read and write; pack() and unpack() give the 7-bit packed form some SMSCs and every handset use, with fill bits
 * after a User Data Header.
 */
final class Gsm7
{
    public const int ESCAPE = 0x1B;

    /** The basic table, by septet value. Index 0x1B is the escape, not a character. */
    private const array BASIC = [
        '@', '£', '$', '¥', 'è', 'é', 'ù', 'ì', 'ò', 'Ç', "\n", 'Ø', 'ø', "\r", 'Å', 'å',
        'Δ', '_', 'Φ', 'Γ', 'Λ', 'Ω', 'Π', 'Ψ', 'Σ', 'Θ', 'Ξ', "\x1B", 'Æ', 'æ', 'ß', 'É',
        ' ', '!', '"', '#', '¤', '%', '&', "'", '(', ')', '*', '+', ',', '-', '.', '/',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ':', ';', '<', '=', '>', '?',
        '¡', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O',
        'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'Ä', 'Ö', 'Ñ', 'Ü', '§',
        '¿', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o',
        'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', 'ä', 'ö', 'ñ', 'ü', 'à',
    ];

    /** The extension table: the code after the escape. */
    private const array EXTENSION = [
        0x0A => "\f", 0x14 => '^', 0x28 => '{', 0x29 => '}', 0x2F => '\\',
        0x3C => '[', 0x3D => '~', 0x3E => ']', 0x40 => '|', 0x65 => '€',
    ];

    /** @var array<string, int>|null */
    private static ?array $basicIndex = null;

    /** @var array<string, int>|null */
    private static ?array $extensionIndex = null;

    public static function canEncode(string $text): bool
    {
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            if (null === self::lookup($character)) {
                return false;
            }
        }

        return true;
    }

    /** Septets, one per octet, an extension character as the escape and its code. */
    public static function encode(string $text): string
    {
        $septets = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            $septets .= self::lookup($character) ?? throw new MalformedPdu('"' . $character . '" is not in the GSM 7-bit alphabet; send it as UCS-2.');
        }

        return $septets;
    }

    /** The length in septets: what an SMS counts, an extension character being two. */
    public static function septets(string $text): int
    {
        return strlen(self::encode($text));
    }

    /**
     * Unpacked septets back to text. An escape followed by a code the extension table does not define reads as a
     * space, as 23.038 lets a handset show it; a trailing lone escape is dropped.
     */
    public static function decode(string $septets): string
    {
        $text = '';
        $length = strlen($septets);
        for ($i = 0; $i < $length; ++$i) {
            $code = ord($septets[$i]) & 0x7F;
            if (self::ESCAPE === $code) {
                if (++$i >= $length) {
                    break;
                }
                $text .= self::EXTENSION[ord($septets[$i]) & 0x7F] ?? ' ';
                continue;
            }
            $text .= self::BASIC[$code];
        }

        return $text;
    }

    /**
     * Septets (one per octet) packed 8 to 7 octets, least significant bit first (23.038 section 6.1.2.1.1), after
     * $fillBits zero bits -- the padding that brings the text to a septet boundary after a User Data Header.
     */
    public static function pack(string $septets, int $fillBits = 0): string
    {
        $bits = 0;
        $count = $fillBits;
        $packed = '';
        $length = strlen($septets);
        for ($i = 0; $i < $length; ++$i) {
            $bits |= (ord($septets[$i]) & 0x7F) << $count;
            $count += 7;
            while ($count >= 8) {
                $packed .= chr($bits & 0xFF);
                $bits >>= 8;
                $count -= 8;
            }
        }

        return $count > 0 ? $packed . chr($bits & 0xFF) : $packed;
    }

    /** $count septets from packed octets that start with $fillBits fill bits. */
    public static function unpack(string $packed, int $count, int $fillBits = 0): string
    {
        $septets = '';
        for ($n = 0; $n < $count; ++$n) {
            $bit = $fillBits + 7 * $n;
            $octet = intdiv($bit, 8);
            $shift = $bit % 8;
            $value = (ord($packed[$octet] ?? "\0") >> $shift) | (ord($packed[$octet + 1] ?? "\0") << (8 - $shift));
            $septets .= chr($value & 0x7F);
        }

        return $septets;
    }

    /** The septet(s) for one character, or null when GSM 7-bit cannot hold it. */
    private static function lookup(string $character): ?string
    {
        self::$basicIndex ??= self::index(self::BASIC);
        self::$extensionIndex ??= self::index(self::EXTENSION);
        if ("\x1B" !== $character && isset(self::$basicIndex[$character])) {
            return chr(self::$basicIndex[$character]);
        }
        if (isset(self::$extensionIndex[$character])) {
            return chr(self::ESCAPE) . chr(self::$extensionIndex[$character]);
        }

        return null;
    }

    /**
     * @param array<int, string> $table
     *
     * @return array<string, int>
     */
    private static function index(array $table): array
    {
        $index = [];
        foreach ($table as $code => $character) {
            $index[$character] ??= $code;
        }

        return $index;
    }
}
