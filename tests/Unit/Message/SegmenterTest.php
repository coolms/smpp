<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit\Message;

use CoolMS\Smpp\Encoding\DataCoding;
use CoolMS\Smpp\Encoding\Gsm7;
use CoolMS\Smpp\Encoding\Ucs2;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Message\Concatenation;
use CoolMS\Smpp\Message\Segmenter;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Pdu\Tag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function implode;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Long messages: concatenation by User Data Header (3GPP TS 23.040 section 9.2.3.24.1 and .8, information elements
 * 0x00 and 0x08) or one message_payload TLV (SMPP 3.4 section 5.3.2.32). The limits are the 140 octets of an SMS's
 * user data: 160 GSM septets or 70 UCS-2 units alone, 153 or 67 after a 6-octet UDH, 152 or 66 after a 7-octet one.
 */
final class SegmenterTest extends TestCase
{
    #[Test]
    public function aTextThatFitsIsOneSegmentWithoutAHeader(): void
    {
        $segments = new Segmenter()->split(str_repeat('a', 160));

        self::assertCount(1, $segments);
        self::assertSame(str_repeat('a', 160), $segments[0]->shortMessage);
        self::assertSame(0, $segments[0]->esmClass);
        self::assertSame(DataCoding::Default, $segments[0]->dataCoding);
    }

    #[Test]
    public function aLongerGsmTextIsSplitInto153SeptetPartsWithAnEightBitReference(): void
    {
        $segments = new Segmenter()->split(str_repeat('a', 161), reference: 0x2A);

        self::assertCount(2, $segments);
        self::assertSame('0500032a0201', bin2hex(substr($segments[0]->shortMessage, 0, 6)));
        self::assertSame('0500032a0202', bin2hex(substr($segments[1]->shortMessage, 0, 6)));
        self::assertSame(153, strlen($segments[0]->shortMessage) - 6);
        self::assertSame(8, strlen($segments[1]->shortMessage) - 6);
        self::assertSame(ShortMessage::ESM_UDHI, $segments[0]->esmClass);
    }

    #[Test]
    public function anExtensionCharacterIsNeverSplitFromItsEscape(): void
    {
        // 152 septets, then '€' (escape + code): the escape would be septet 153, so both move to the next part.
        $segments = new Segmenter()->split(str_repeat('a', 152) . '€' . str_repeat('b', 10));

        self::assertSame(152, strlen($segments[0]->shortMessage) - 6);
        self::assertSame(Gsm7::encode('€' . str_repeat('b', 10)), substr($segments[1]->shortMessage, 6));
    }

    #[Test]
    public function aSixteenBitReferenceUsesItsOwnElementAndOneSeptetLess(): void
    {
        $segments = new Segmenter(Concatenation::Udh16)->split(str_repeat('a', 300), reference: 0x1234);

        self::assertCount(2, $segments);
        self::assertSame('06080412340201', bin2hex(substr($segments[0]->shortMessage, 0, 7)));
        self::assertSame(152, strlen($segments[0]->shortMessage) - 7);
    }

    #[Test]
    public function ucs2FitsSeventyUnitsAloneAndSixtySevenPerPart(): void
    {
        self::assertCount(1, new Segmenter()->split(str_repeat('Ж', 70)));
        $segments = new Segmenter()->split(str_repeat('Ж', 71));

        self::assertCount(2, $segments);
        self::assertSame(DataCoding::Ucs2, $segments[0]->dataCoding);
        self::assertSame(134, strlen($segments[0]->shortMessage) - 6);
        self::assertSame(Ucs2::encode(str_repeat('Ж', 4)), substr($segments[1]->shortMessage, 6));
    }

    #[Test]
    public function aSurrogatePairIsNeverSplitAcrossParts(): void
    {
        // 78 units in all, so parts of 67: after 66 units comes an emoji of two, which would end at 68, so the pair
        // starts the next part.
        $segments = new Segmenter()->split(str_repeat('Ж', 66) . '🙂' . str_repeat('Ж', 10));

        self::assertSame(132, strlen($segments[0]->shortMessage) - 6);
        self::assertSame(Ucs2::encode('🙂' . str_repeat('Ж', 10)), substr($segments[1]->shortMessage, 6));
    }

    #[Test]
    public function messagePayloadCarriesTheWholeTextInOneSubmit(): void
    {
        $text = str_repeat('x', 500);
        $segments = new Segmenter(Concatenation::Payload)->split($text);

        self::assertCount(1, $segments);
        self::assertSame('', $segments[0]->shortMessage);
        self::assertSame(0, $segments[0]->esmClass);
        self::assertSame(Tag::MessagePayload->value, $segments[0]->tlvs[0]->tag);
        self::assertSame(Gsm7::encode($text), $segments[0]->tlvs[0]->value);
    }

    #[Test]
    public function theSegmentsReadBackAsTheOriginalText(): void
    {
        $text = 'Ваш заказ 12345 готов. ' . str_repeat('Спасибо! ', 20) . '🙂';
        $parts = [];
        foreach (new Segmenter()->split($text) as $segment) {
            $parts[] = $segment->dataCoding->decode(substr($segment->shortMessage, 6));
        }

        self::assertSame($text, implode('', $parts));
    }

    #[Test]
    public function aCodingThatCannotHoldTheTextIsRefused(): void
    {
        $this->expectException(MalformedPdu::class);
        new Segmenter()->split('Привет', DataCoding::Default);
    }

    #[Test]
    public function aReferenceMustFitItsElement(): void
    {
        $this->expectException(MalformedPdu::class);
        new Segmenter()->split(str_repeat('a', 200), reference: 256);
    }

    #[Test]
    public function moreThan255PartsIsRefused(): void
    {
        $this->expectException(MalformedPdu::class);
        new Segmenter()->split(str_repeat('a', 153 * 255 + 1));
    }

    #[Test]
    public function thePartCountIsKnownBeforeSending(): void
    {
        self::assertSame(1, new Segmenter()->parts(str_repeat('a', 160)));
        self::assertSame(2, new Segmenter()->parts(str_repeat('a', 161)));
        self::assertSame(3, new Segmenter()->parts(str_repeat('Ж', 135)));
        self::assertSame(1, new Segmenter(Concatenation::Payload)->parts(str_repeat('a', 1000)), 'one submit; the SMSC splits it');
    }
}
