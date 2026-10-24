<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Message;

use CoolMS\Smpp\Encoding\DataCoding;
use CoolMS\Smpp\Encoding\Latin1;
use CoolMS\Smpp\Encoding\Ucs2;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Pdu\Tag;
use DateTimeImmutable;
use DateTimeZone;

use function ord;
use function preg_match;
use function strlen;
use function trim;

/**
 * A delivery receipt: a deliver_sm whose esm_class marks it as one (section 5.2.12). Its facts come from the
 * receipted_message_id and message_state TLVs when the SMSC sends them, else from the Appendix B text
 * ("id:… sub:… dlvrd:… submit date:… done date:… stat:… err:… text:…"), read tolerantly: any case, "submit date",
 * "submit_date" or "submitdate", dates with or without seconds, missing fields, full words for the state.
 *
 * The receipt's dates are the SMSC's local time; no zone is stated, so they are read in the zone the caller gives.
 * A date that is not a real date is left out, never guessed.
 */
final readonly class DeliveryReceipt
{
    /** @param array{int, int}|null $networkError network type and error code, from the network_error_code TLV */
    public function __construct(
        public string $messageId,
        public MessageState $state,
        public ?int $submitted = null,
        public ?int $delivered = null,
        public ?DateTimeImmutable $submitDate = null,
        public ?DateTimeImmutable $doneDate = null,
        public ?string $error = null,
        public ?array $networkError = null,
        public string $text = '',
    ) {
    }

    /** The receipt a deliver_sm carries, or null when it is an ordinary message or names no message id. */
    public static function from(ShortMessage $deliverSm, ?DateTimeZone $zone = null): ?self
    {
        if (!$deliverSm->isReceipt()) {
            return null;
        }
        $zone ??= new DateTimeZone('UTC');
        $text = self::text($deliverSm);
        $head = 1 === preg_match('/^(.*?)\btext:(.*)$/is', $text, $split) ? $split[1] : $text;
        $tlvId = $deliverSm->tlv(Tag::ReceiptedMessageId)?->asString();
        $id = null !== $tlvId && '' !== $tlvId ? $tlvId : self::field($head, 'id', '\S+');
        if (null === $id || '' === $id) {
            return null;
        }
        $state = self::stateFromTlv($deliverSm) ?? MessageState::fromText(self::field($head, 'stat', '[A-Za-z_]+') ?? '');
        $submitted = self::field($head, 'sub', '\d+');
        $delivered = self::field($head, 'dlvrd', '\d+');

        return new self(
            $id,
            $state,
            null === $submitted ? null : (int) $submitted,
            null === $delivered ? null : (int) $delivered,
            self::date(self::field($head, 'submit[ _]?date', '\d{10}(?:\d{2}(?:\d{2})?)?'), $zone),
            self::date(self::field($head, 'done[ _]?date', '\d{10}(?:\d{2}(?:\d{2})?)?'), $zone),
            self::field($head, 'err', '\w+'),
            self::networkError($deliverSm),
            trim($split[2] ?? ''),
        );
    }

    /**
     * The receipt's text. Appendix B text is ASCII, and SMSCs send it under data_coding 0 as plain bytes, not as
     * GSM 03.38 septets -- read that way, "_", "$" and "@" would turn into other characters and ids would stop
     * matching. So data_coding 0 (and 1, and anything not text-bearing) reads as bytes; UCS-2 and Latin-1 as such.
     */
    private static function text(ShortMessage $deliverSm): string
    {
        $raw = $deliverSm->payload();
        try {
            return match (DataCoding::fromByte($deliverSm->dataCoding)) {
                DataCoding::Ucs2 => Ucs2::decode($raw),
                default => Latin1::decode($raw),
            };
        } catch (MalformedPdu) {
            return Latin1::decode($raw);
        }
    }

    /** The message_state TLV's state, or null when it is absent or not a one-byte value (the text decides then). */
    private static function stateFromTlv(ShortMessage $deliverSm): ?MessageState
    {
        $tlv = $deliverSm->tlv(Tag::MessageState);
        if (null === $tlv) {
            return null;
        }
        try {
            return MessageState::tryFrom($tlv->asInt()) ?? MessageState::Unknown;
        } catch (MalformedPdu) {
            return null;
        }
    }

    private static function field(string $text, string $key, string $value): ?string
    {
        return 1 === preg_match('/(?<![\w])' . $key . ':\s*(' . $value . ')/i', $text, $match) ? $match[1] : null;
    }

    private static function date(?string $digits, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (null === $digits) {
            return null;
        }
        // 10 digits: YYMMDDhhmm (Appendix B); 12: with seconds; 14: a four-digit year with seconds, as some SMSCs write.
        $format = match (strlen($digits)) {
            14 => 'YmdHis',
            12 => 'ymdHis',
            default => 'ymdHi',
        };
        $date = DateTimeImmutable::createFromFormat('!' . $format, $digits, $zone);

        // createFromFormat rolls "month 99" over into a later year; a date that does not print back is not a date.
        return false !== $date && $date->format($format) === $digits ? $date : null;
    }

    /** @return array{int, int}|null */
    private static function networkError(ShortMessage $deliverSm): ?array
    {
        $value = $deliverSm->tlv(Tag::NetworkErrorCode)?->value;
        if (null === $value || 3 !== strlen($value)) {
            return null;
        }

        return [ord($value[0]), (ord($value[1]) << 8) | ord($value[2])];
    }
}
