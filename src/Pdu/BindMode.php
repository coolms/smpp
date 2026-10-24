<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

/** How an ESME binds (section 2.2): to send only, to receive only, or both on one connection. */
enum BindMode
{
    case Transmitter;
    case Receiver;
    case Transceiver;

    public function command(): CommandId
    {
        return match ($this) {
            self::Transmitter => CommandId::BindTransmitter,
            self::Receiver => CommandId::BindReceiver,
            self::Transceiver => CommandId::BindTransceiver,
        };
    }

    public function maySubmit(): bool
    {
        return self::Receiver !== $this;
    }

    public function mayReceive(): bool
    {
        return self::Transmitter !== $this;
    }
}
