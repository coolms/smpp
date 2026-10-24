<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

/**
 * The body of data_sm (section 4.7.1): addresses of up to 64 characters, esm_class, registered_delivery,
 * data_coding, and everything else in TLVs -- the text in message_payload. It has no short_message, priority,
 * schedule or validity fields; reading it with submit_sm's layout misreads every field after the addresses.
 */
final readonly class DataSm
{
    /** @param list<Tlv> $tlvs */
    public function __construct(
        public string $sourceAddr,
        public string $destinationAddr,
        public int $esmClass = 0,
        public int $registeredDelivery = 0,
        public int $dataCoding = 0,
        public int $sourceTon = 0,
        public int $sourceNpi = 0,
        public int $destinationTon = 0,
        public int $destinationNpi = 0,
        public string $serviceType = '',
        public array $tlvs = [],
    ) {
    }

    public static function decode(string $body): self
    {
        $r = new Reader($body);
        $serviceType = $r->cString('service_type', 6);
        $sourceTon = $r->uint8('source_addr_ton');
        $sourceNpi = $r->uint8('source_addr_npi');
        $sourceAddr = $r->cString('source_addr', 65);
        $destinationTon = $r->uint8('dest_addr_ton');
        $destinationNpi = $r->uint8('dest_addr_npi');
        $destinationAddr = $r->cString('destination_addr', 65);

        return new self(
            $sourceAddr,
            $destinationAddr,
            $r->uint8('esm_class'),
            $r->uint8('registered_delivery'),
            $r->uint8('data_coding'),
            $sourceTon,
            $sourceNpi,
            $destinationTon,
            $destinationNpi,
            $serviceType,
            $r->tlvs(),
        );
    }

    public function encode(): string
    {
        return new Writer()
            ->cString($this->serviceType, 6, 'service_type')
            ->uint8($this->sourceTon, 'source_addr_ton')
            ->uint8($this->sourceNpi, 'source_addr_npi')
            ->cString($this->sourceAddr, 65, 'source_addr')
            ->uint8($this->destinationTon, 'dest_addr_ton')
            ->uint8($this->destinationNpi, 'dest_addr_npi')
            ->cString($this->destinationAddr, 65, 'destination_addr')
            ->uint8($this->esmClass, 'esm_class')
            ->uint8($this->registeredDelivery, 'registered_delivery')
            ->uint8($this->dataCoding, 'data_coding')
            ->tlvs($this->tlvs)
            ->bytes();
    }

    /**
     * The same message as deliver_sm carries it, so one handler reads both: the text stays in message_payload.
     * Addresses longer than deliver_sm's 20 characters are kept as given; ShortMessage does not re-check them.
     */
    public function asShortMessage(): ShortMessage
    {
        return new ShortMessage(
            sourceAddr: $this->sourceAddr,
            destinationAddr: $this->destinationAddr,
            dataCoding: $this->dataCoding,
            esmClass: $this->esmClass,
            registeredDelivery: $this->registeredDelivery,
            sourceTon: $this->sourceTon,
            sourceNpi: $this->sourceNpi,
            destinationTon: $this->destinationTon,
            destinationNpi: $this->destinationNpi,
            serviceType: $this->serviceType,
            tlvs: $this->tlvs,
        );
    }
}
