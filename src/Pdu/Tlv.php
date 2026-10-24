<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use CoolMS\Smpp\Exception\MalformedPdu;

use function chr;
use function ord;
use function pack;
use function strlen;
use function unpack;

/** One optional parameter: a 16-bit tag, a 16-bit length and that many bytes of value (section 5.3.1). */
final readonly class Tlv
{
    public function __construct(
        public int $tag,
        public string $value,
    ) {
        if ($tag < 0 || $tag > 0xFFFF) {
            throw new MalformedPdu('A TLV tag is 16 bits; ' . $tag . ' is not.');
        }
        if (strlen($value) > 0xFFFF) {
            throw new MalformedPdu('A TLV value is at most 65535 bytes.');
        }
    }

    public static function of(Tag $tag, string $value): self
    {
        return new self($tag->value, $value);
    }

    public static function uint8(Tag $tag, int $value): self
    {
        return new self($tag->value, chr($value & 0xFF));
    }

    public static function uint16(Tag $tag, int $value): self
    {
        return new self($tag->value, pack('n', $value & 0xFFFF));
    }

    /** A C-octet string value: the bytes and their terminating NUL. */
    public static function cString(Tag $tag, string $value): self
    {
        return new self($tag->value, $value . "\0");
    }

    public function encode(): string
    {
        return pack('nn', $this->tag, strlen($this->value)) . $this->value;
    }

    public function name(): ?Tag
    {
        return Tag::tryFrom($this->tag);
    }

    /** The value read as an unsigned integer of its own length (1, 2 or 4 bytes). */
    public function asInt(): int
    {
        return match (strlen($this->value)) {
            1 => ord($this->value),
            2 => (int) unpack('n', $this->value)[1],
            4 => (int) unpack('N', $this->value)[1],
            default => throw new MalformedPdu('TLV 0x' . dechex($this->tag) . ' is ' . strlen($this->value) . ' bytes, not an integer.'),
        };
    }

    /** The value read as a C-octet string, its NUL dropped; a value without one is taken whole (SMSCs differ). */
    public function asString(): string
    {
        $nul = strpos($this->value, "\0");

        return false === $nul ? $this->value : substr($this->value, 0, $nul);
    }
}
