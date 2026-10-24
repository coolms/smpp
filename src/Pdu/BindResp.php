<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

/**
 * The body of a bind response (section 4.1.2): the SMSC's system_id, and its sc_interface_version TLV when it sends
 * one. An error response may carry no body at all (section 4.1.2: "if the command_status is non-zero, the body
 * may be absent"), so an empty body reads as an empty system id.
 */
final readonly class BindResp
{
    /** @param list<Tlv> $tlvs */
    public function __construct(
        public string $systemId,
        public array $tlvs = [],
    ) {
    }

    public static function decode(string $body): self
    {
        if ('' === $body) {
            return new self('');
        }
        $reader = new Reader($body);

        return new self($reader->cString('system_id', 16), $reader->tlvs());
    }

    public function encode(): string
    {
        return new Writer()->cString($this->systemId, 16, 'system_id')->tlvs($this->tlvs)->bytes();
    }

    /** The interface version the SMSC states (0x34 for SMPP 3.4), or null when it states none. */
    public function interfaceVersion(): ?int
    {
        foreach ($this->tlvs as $tlv) {
            if (Tag::ScInterfaceVersion->value === $tlv->tag) {
                return $tlv->asInt();
            }
        }

        return null;
    }
}
