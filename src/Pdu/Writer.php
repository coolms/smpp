<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use CoolMS\Smpp\Exception\MalformedPdu;

use function chr;
use function pack;
use function str_contains;
use function strlen;

/** Writes a PDU body field by field, refusing a value its field cannot hold rather than truncating it. */
final class Writer
{
    private string $bytes = '';

    public function uint8(int $value, string $field): self
    {
        if ($value < 0 || $value > 0xFF) {
            throw new MalformedPdu($field . ' is one octet; ' . $value . ' does not fit.');
        }
        $this->bytes .= chr($value);

        return $this;
    }

    public function uint16(int $value, string $field): self
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new MalformedPdu($field . ' is two octets; ' . $value . ' does not fit.');
        }
        $this->bytes .= pack('n', $value);

        return $this;
    }

    public function uint32(int $value, string $field): self
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new MalformedPdu($field . ' is four octets; ' . $value . ' does not fit.');
        }
        $this->bytes .= pack('N', $value);

        return $this;
    }

    /** A C-octet string of at most $max bytes including its NUL; a NUL inside the value is refused. */
    public function cString(string $value, int $max, string $field): self
    {
        if (str_contains($value, "\0")) {
            throw new MalformedPdu($field . ' cannot hold a NUL byte.');
        }
        if (strlen($value) + 1 > $max) {
            throw new MalformedPdu($field . ' holds at most ' . ($max - 1) . ' characters; "' . $value . '" has ' . strlen($value) . '.');
        }
        $this->bytes .= $value . "\0";

        return $this;
    }

    public function octets(string $value): self
    {
        $this->bytes .= $value;

        return $this;
    }

    /** @param list<Tlv> $tlvs */
    public function tlvs(array $tlvs): self
    {
        foreach ($tlvs as $tlv) {
            $this->bytes .= $tlv->encode();
        }

        return $this;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}
