<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Message;

/** How a text longer than one SMS travels. */
enum Concatenation
{
    /** One submit_sm per part, each with a UDH carrying an 8-bit reference (information element 0x00). */
    case Udh8;

    /** As Udh8 with a 16-bit reference (information element 0x08): fewer collisions, one character less per part. */
    case Udh16;

    /** One submit_sm with the whole text in message_payload; the SMSC splits it. Not every SMSC accepts this. */
    case Payload;

    /** The User Data Header's length in octets, its own length byte included. */
    public function headerLength(): int
    {
        return match ($this) {
            self::Udh8 => 6,
            self::Udh16 => 7,
            self::Payload => 0,
        };
    }
}
