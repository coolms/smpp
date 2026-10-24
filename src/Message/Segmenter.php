<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Message;

use CoolMS\Smpp\Encoding\DataCoding;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Pdu\Tag;
use CoolMS\Smpp\Pdu\Tlv;

use function chr;
use function count;
use function intdiv;
use function ord;
use function pack;
use function strlen;
use function substr;

/**
 * Splits a text into what submit_sm can carry. A text that fits one SMS is one segment without a header; a longer
 * one is several parts with a User Data Header each (3GPP TS 23.040 section 9.2.3.24.1, .8), or one message_payload.
 *
 * Limits follow the 140 octets of an SMS's user data. GSM 7-bit travels unpacked in short_message (one septet per
 * octet, the SMSC packs it), but its budget is the packed one: 160 septets alone, (140 - UDH) * 8 / 7 after a header.
 * A part never ends between an escape and its extension code, or between the two units of a UTF-16 surrogate pair.
 */
final readonly class Segmenter
{
    private const int USER_DATA_OCTETS = 140;

    private const int MAX_PARTS = 255;

    public function __construct(
        private Concatenation $concatenation = Concatenation::Udh8,
        private bool $allowLatin1 = false,
    ) {
    }

    /**
     * @param DataCoding|null $coding    null chooses the best that holds the text
     * @param int             $reference the concatenation reference all parts share; unique per destination
     *
     * @return list<Segment>
     */
    public function split(string $text, ?DataCoding $coding = null, int $reference = 0): array
    {
        $coding ??= DataCoding::bestFor($text, $this->allowLatin1);
        $encoded = $coding->encode($text);
        if (strlen($encoded) <= $this->capacity($coding, 0)) {
            return [new Segment($encoded, $coding)];
        }
        if (Concatenation::Payload === $this->concatenation) {
            return [new Segment('', $coding, 0, [Tlv::of(Tag::MessagePayload, $encoded)])];
        }
        $limit = Concatenation::Udh16 === $this->concatenation ? 0xFFFF : 0xFF;
        if ($reference < 0 || $reference > $limit) {
            throw new MalformedPdu('A ' . (0xFF === $limit ? '8' : '16') . '-bit concatenation reference is 0 to ' . $limit . '; ' . $reference . ' is not.');
        }
        $chunks = $this->chunks($encoded, $coding, $this->capacity($coding, $this->concatenation->headerLength()));
        $total = count($chunks);
        if ($total > self::MAX_PARTS) {
            throw new MalformedPdu('The text needs ' . $total . ' parts; a concatenated SMS has at most 255.');
        }
        $segments = [];
        foreach ($chunks as $i => $chunk) {
            $segments[] = new Segment($this->header($reference, $total, $i + 1) . $chunk, $coding, ShortMessage::ESM_UDHI);
        }

        return $segments;
    }

    /** How many SMS the text is billed as: one with message_payload, which leaves splitting to the SMSC. */
    public function parts(string $text, ?DataCoding $coding = null): int
    {
        return Concatenation::Payload === $this->concatenation ? 1 : count($this->split($text, $coding));
    }

    /** The short_message bytes a part holds after a $header-octet UDH, in this coding's units. */
    private function capacity(DataCoding $coding, int $header): int
    {
        $bits = (self::USER_DATA_OCTETS - $header) * 8;

        return match ($coding) {
            DataCoding::Default => intdiv($bits, 7),
            DataCoding::Ucs2 => intdiv($bits, 16) * 2,
            default => self::USER_DATA_OCTETS - $header,
        };
    }

    /** @return list<string> */
    private function chunks(string $encoded, DataCoding $coding, int $capacity): array
    {
        $chunks = [];
        $length = strlen($encoded);
        for ($at = 0; $at < $length; $at += $take) {
            $take = $capacity;
            if ($at + $take < $length) {
                $take -= $this->keepTogether($encoded, $coding, $at + $take);
            }
            $chunks[] = substr($encoded, $at, $take);
        }

        return $chunks;
    }

    /** How many bytes to give back so the part does not end inside an escape sequence or a surrogate pair. */
    private function keepTogether(string $encoded, DataCoding $coding, int $end): int
    {
        if (DataCoding::Default === $coding) {
            // Count the escapes in the run right before the cut: an odd number means the last one is cut off.
            $escapes = 0;
            for ($i = $end - 1; $i >= 0 && "\x1B" === $encoded[$i]; --$i) {
                ++$escapes;
            }

            return $escapes % 2;
        }
        if (DataCoding::Ucs2 === $coding) {
            $high = ord($encoded[$end - 2]);

            return $high >= 0xD8 && $high <= 0xDB ? 2 : 0;
        }

        return 0;
    }

    private function header(int $reference, int $total, int $sequence): string
    {
        return Concatenation::Udh16 === $this->concatenation
            ? "\x06\x08\x04" . pack('n', $reference) . chr($total) . chr($sequence)
            : "\x05\x00\x03" . chr($reference) . chr($total) . chr($sequence);
    }
}
