<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use CoolMS\Smpp\Exception\MalformedPdu;

use function strlen;

/**
 * The body submit_sm and deliver_sm share (sections 4.4.1 and 4.6.1): addresses, esm_class, data_coding, the
 * short_message of up to 254 octets, and the TLVs after it. deliver_sm leaves schedule_delivery_time,
 * validity_period, replace_if_present_flag and sm_default_msg_id empty or zero, as section 4.6.1 requires.
 */
final readonly class ShortMessage
{
    /** esm_class bit 6: the short_message begins with a User Data Header (UDHI). */
    public const int ESM_UDHI = 0x40;

    /** esm_class bits 2-5 on a deliver_sm: what kind of message it is (section 5.2.12). */
    public const int ESM_TYPE_MASK = 0x3C;

    public const int ESM_TYPE_DELIVERY_RECEIPT = 0x04;

    public const int ESM_TYPE_INTERMEDIATE_NOTIFICATION = 0x20;

    /** registered_delivery bits 0-1: ask for a receipt on final success or failure (01) or on failure only (10). */
    public const int RECEIPT_ON_FINAL = 0x01;

    public const int RECEIPT_ON_FAILURE = 0x02;

    public const int MAX_SHORT_MESSAGE = 254;

    /** @param list<Tlv> $tlvs */
    public function __construct(
        public string $sourceAddr,
        public string $destinationAddr,
        public string $shortMessage = '',
        public int $dataCoding = 0,
        public int $esmClass = 0,
        public int $registeredDelivery = 0,
        public int $sourceTon = 0,
        public int $sourceNpi = 0,
        public int $destinationTon = 1,
        public int $destinationNpi = 1,
        public string $serviceType = '',
        public int $protocolId = 0,
        public int $priority = 0,
        public string $scheduleDeliveryTime = '',
        public string $validityPeriod = '',
        public int $replaceIfPresent = 0,
        public int $smDefaultMsgId = 0,
        public array $tlvs = [],
    ) {
        if (strlen($shortMessage) > self::MAX_SHORT_MESSAGE) {
            throw new MalformedPdu('short_message holds at most 254 octets; use message_payload or segments for ' . strlen($shortMessage) . '.');
        }
    }

    public static function decode(string $body): self
    {
        $r = new Reader($body);
        $serviceType = $r->cString('service_type', 6);
        $sourceTon = $r->uint8('source_addr_ton');
        $sourceNpi = $r->uint8('source_addr_npi');
        $sourceAddr = $r->cString('source_addr', 21);
        $destinationTon = $r->uint8('dest_addr_ton');
        $destinationNpi = $r->uint8('dest_addr_npi');
        $destinationAddr = $r->cString('destination_addr', 21);
        $esmClass = $r->uint8('esm_class');
        $protocolId = $r->uint8('protocol_id');
        $priority = $r->uint8('priority_flag');
        $schedule = $r->cString('schedule_delivery_time', 17);
        $validity = $r->cString('validity_period', 17);
        $registered = $r->uint8('registered_delivery');
        $replace = $r->uint8('replace_if_present_flag');
        $dataCoding = $r->uint8('data_coding');
        $default = $r->uint8('sm_default_msg_id');
        $shortMessage = $r->octets($r->uint8('sm_length'), 'short_message');

        return new self(
            $sourceAddr,
            $destinationAddr,
            $shortMessage,
            $dataCoding,
            $esmClass,
            $registered,
            $sourceTon,
            $sourceNpi,
            $destinationTon,
            $destinationNpi,
            $serviceType,
            $protocolId,
            $priority,
            $schedule,
            $validity,
            $replace,
            $default,
            $r->tlvs(),
        );
    }

    public function encode(): string
    {
        return new Writer()
            ->cString($this->serviceType, 6, 'service_type')
            ->uint8($this->sourceTon, 'source_addr_ton')
            ->uint8($this->sourceNpi, 'source_addr_npi')
            ->cString($this->sourceAddr, 21, 'source_addr')
            ->uint8($this->destinationTon, 'dest_addr_ton')
            ->uint8($this->destinationNpi, 'dest_addr_npi')
            ->cString($this->destinationAddr, 21, 'destination_addr')
            ->uint8($this->esmClass, 'esm_class')
            ->uint8($this->protocolId, 'protocol_id')
            ->uint8($this->priority, 'priority_flag')
            ->cString($this->scheduleDeliveryTime, 17, 'schedule_delivery_time')
            ->cString($this->validityPeriod, 17, 'validity_period')
            ->uint8($this->registeredDelivery, 'registered_delivery')
            ->uint8($this->replaceIfPresent, 'replace_if_present_flag')
            ->uint8($this->dataCoding, 'data_coding')
            ->uint8($this->smDefaultMsgId, 'sm_default_msg_id')
            ->uint8(strlen($this->shortMessage), 'sm_length')
            ->octets($this->shortMessage)
            ->tlvs($this->tlvs)
            ->bytes();
    }

    public function tlv(Tag $tag): ?Tlv
    {
        foreach ($this->tlvs as $tlv) {
            if ($tag->value === $tlv->tag) {
                return $tlv;
            }
        }

        return null;
    }

    public function hasUdh(): bool
    {
        return 0 !== ($this->esmClass & self::ESM_UDHI);
    }

    /** A deliver_sm that is a delivery receipt (esm_class type 0001 or 1000: an SMSC or intermediate notification). */
    public function isReceipt(): bool
    {
        $type = $this->esmClass & self::ESM_TYPE_MASK;

        return self::ESM_TYPE_DELIVERY_RECEIPT === $type || self::ESM_TYPE_INTERMEDIATE_NOTIFICATION === $type;
    }

    /** The message's user data: short_message, or the message_payload TLV when short_message is empty. */
    public function payload(): string
    {
        return '' === $this->shortMessage ? ($this->tlv(Tag::MessagePayload)->value ?? '') : $this->shortMessage;
    }
}
