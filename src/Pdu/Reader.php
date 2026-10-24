<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use CoolMS\Smpp\Exception\MalformedPdu;

use function is_int;
use function max;
use function ord;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * Reads a PDU body field by field. Every read checks the bytes are there, and a C-octet string its NUL and its field's
 * maximum (section 3.1: the maximum counts the NUL), so a short or hostile body ends in MalformedPdu, never in a
 * misread.
 */
final class Reader
{
    /** The longest C-octet string read, NUL included, whatever its field's length in the spec. */
    public const int LENIENT_MAX = 256;

    private int $at = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    /** One big-endian unsigned integer: 'n' for 16 bits, 'N' for 32. */
    public static function unsigned(string $format, string $bytes, string $field): int
    {
        $value = unpack($format, $bytes);
        if (false === $value || !is_int($value[1] ?? null)) {
            throw new MalformedPdu($field . ' is not a ' . ('n' === $format ? '16' : '32') . '-bit integer.');
        }

        return $value[1];
    }

    public function uint8(string $field): int
    {
        return ord($this->take(1, $field));
    }

    public function uint16(string $field): int
    {
        return self::unsigned('n', $this->take(2, $field), $field);
    }

    public function uint32(string $field): int
    {
        return self::unsigned('N', $this->take(4, $field), $field);
    }

    /** A C-octet string of at most $max bytes including its NUL. */
    /**
     * A C-octet string, up to its NUL. `$max` is the field's length in SMPP 3.4, and a longer value is read anyway: an
     * SMSC that writes a seven-character service_type still sent a receipt. Only a value past {@see LENIENT_MAX} is
     * refused, where a missing NUL has most likely run the read into the next field.
     */
    public function cString(string $field, int $max): string
    {
        $nul = strpos($this->bytes, "\0", $this->at);
        if (false === $nul) {
            throw new MalformedPdu($field . ' has no terminating NUL.');
        }
        $length = $nul - $this->at;
        if ($length + 1 > max($max, self::LENIENT_MAX)) {
            throw new MalformedPdu($field . ' is ' . ($length + 1) . ' bytes with its NUL; at most ' . max($max, self::LENIENT_MAX) . ' are read.');
        }
        $value = substr($this->bytes, $this->at, $length);
        $this->at = $nul + 1;

        return $value;
    }

    /** An octet string whose length was given by an earlier field. */
    public function octets(int $length, string $field): string
    {
        return 0 === $length ? '' : $this->take($length, $field);
    }

    /** @return list<Tlv> every TLV up to the end of the body */
    public function tlvs(): array
    {
        $tlvs = [];
        while (!$this->done()) {
            $tag = $this->uint16('a TLV tag');
            $length = $this->uint16('a TLV length');
            $tlvs[] = new Tlv($tag, $this->octets($length, 'TLV 0x' . dechex($tag)));
        }

        return $tlvs;
    }

    public function done(): bool
    {
        return $this->at >= strlen($this->bytes);
    }

    private function take(int $length, string $field): string
    {
        if ($this->at + $length > strlen($this->bytes)) {
            throw new MalformedPdu($field . ' runs past the end of the PDU.');
        }
        $value = substr($this->bytes, $this->at, $length);
        $this->at += $length;

        return $value;
    }
}
