<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Message;

use CoolMS\Smpp\Encoding\DataCoding;
use CoolMS\Smpp\Pdu\Tlv;

/** What one submit_sm carries of a text: its short_message (with any UDH), esm_class, data_coding and TLVs. */
final readonly class Segment
{
    /** @param list<Tlv> $tlvs */
    public function __construct(
        public string $shortMessage,
        public DataCoding $dataCoding,
        public int $esmClass = 0,
        public array $tlvs = [],
    ) {
    }
}
