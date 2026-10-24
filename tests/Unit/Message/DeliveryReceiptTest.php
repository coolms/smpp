<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit\Message;

use CoolMS\Smpp\Encoding\Ucs2;
use CoolMS\Smpp\Message\DeliveryReceipt;
use CoolMS\Smpp\Message\MessageId;
use CoolMS\Smpp\Message\MessageState;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Pdu\Tag;
use CoolMS\Smpp\Pdu\Tlv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Delivery receipts (SMPP 3.4 Appendix B and section 5.2.12): a deliver_sm whose esm_class says "receipt", with the
 * receipt's facts in its text, its TLVs, or both -- written differently by every SMSC.
 */
final class DeliveryReceiptTest extends TestCase
{
    /** @return iterable<string, array{string, string, MessageState}> */
    public static function variations(): iterable
    {
        yield 'upper-case keys' => ['ID:abc123 SUB:001 DLVRD:000 SUBMIT DATE:2310241530 DONE DATE:2310241532 STAT:UNDELIV ERR:001', 'abc123', MessageState::Undeliverable];
        yield 'underscored dates' => ['id:42 submit_date:2310241530 done_date:2310241532 stat:EXPIRED err:0', '42', MessageState::Expired];
        yield 'joined dates, seconds' => ['id:42 submitdate:231024153012 donedate:231024153512 stat:REJECTD', '42', MessageState::Rejected];
        yield 'no sub and dlvrd' => ['id:7f3e stat:DELIVRD err:000', '7f3e', MessageState::Delivered];
        yield 'full words' => ['id:9 stat:DELIVERED err:0', '9', MessageState::Delivered];
        yield 'full word undeliverable' => ['id:9 stat:UNDELIVERABLE', '9', MessageState::Undeliverable];
        yield 'accepted' => ['id:9 stat:ACCEPTD', '9', MessageState::Accepted];
        yield 'enroute' => ['id:9 stat:ENROUTE', '9', MessageState::Enroute];
        yield 'uuid id' => ['id:3f1c9a2e-7b4d-4c1e-9f0a-2b6d8e4c1a77 stat:DELIVRD', '3f1c9a2e-7b4d-4c1e-9f0a-2b6d8e4c1a77', MessageState::Delivered];
        yield 'unknown state word' => ['id:9 stat:WEIRD', '9', MessageState::Unknown];
        yield 'extra spaces' => ['id: 55  sub:001   stat: DELIVRD', '55', MessageState::Delivered];
    }

    #[Test]
    public function theAppendixBFormatReadsInFull(): void
    {
        $receipt = self::receipt('id:0123456789 sub:001 dlvrd:001 submit date:2310241530 done date:2310241531 stat:DELIVRD err:000 text:Hello there');

        self::assertNotNull($receipt);
        self::assertSame('0123456789', $receipt->messageId);
        self::assertSame(MessageState::Delivered, $receipt->state);
        self::assertSame(1, $receipt->submitted);
        self::assertSame(1, $receipt->delivered);
        self::assertSame('2023-10-24 15:30', $receipt->submitDate?->format('Y-m-d H:i'));
        self::assertSame('2023-10-24 15:31', $receipt->doneDate?->format('Y-m-d H:i'));
        self::assertSame('000', $receipt->error);
        self::assertSame('Hello there', $receipt->text);
    }

    #[Test]
    #[DataProvider('variations')]
    public function smscVariationsReadTheSame(string $text, string $id, MessageState $state): void
    {
        $receipt = self::receipt($text);

        self::assertNotNull($receipt);
        self::assertSame($id, $receipt->messageId);
        self::assertSame($state, $receipt->state);
    }

    #[Test]
    public function theTlvsOutrankTheText(): void
    {
        $receipt = DeliveryReceipt::from(new ShortMessage('447700900123', 'Shop', 'id:111 stat:ENROUTE', esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT, tlvs: [
            Tlv::cString(Tag::ReceiptedMessageId, 'ABC'),
            Tlv::uint8(Tag::MessageState, 2),
            Tlv::of(Tag::NetworkErrorCode, "\x03\x00\x22"),
        ]));

        self::assertNotNull($receipt);
        self::assertSame('ABC', $receipt->messageId);
        self::assertSame(MessageState::Delivered, $receipt->state);
        self::assertSame([3, 0x22], $receipt->networkError);
    }

    #[Test]
    public function aReceiptWithOnlyTlvsAndNoTextReads(): void
    {
        $receipt = DeliveryReceipt::from(new ShortMessage('a', 'b', '', esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT, tlvs: [
            Tlv::cString(Tag::ReceiptedMessageId, 'Z9'),
            Tlv::uint8(Tag::MessageState, 5),
        ]));

        self::assertSame(['Z9', MessageState::Undeliverable], [$receipt?->messageId, $receipt?->state]);
    }

    #[Test]
    public function aUcs2ReceiptTextIsDecodedFirst(): void
    {
        $receipt = DeliveryReceipt::from(new ShortMessage('a', 'b', Ucs2::encode('id:77 stat:DELIVRD'), dataCoding: 0x08, esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT));

        self::assertSame(['77', MessageState::Delivered], [$receipt?->messageId, $receipt?->state]);
    }

    #[Test]
    public function anOrdinaryMessageIsNoReceipt(): void
    {
        self::assertNull(DeliveryReceipt::from(new ShortMessage('a', 'b', 'id:1 stat:DELIVRD')));
    }

    #[Test]
    public function aReceiptWithoutAnIdIsNoReceipt(): void
    {
        self::assertNull(self::receipt('stat:DELIVRD err:000'));
    }

    #[Test]
    public function aBadDateIsLeftOutNotGuessed(): void
    {
        $receipt = self::receipt('id:1 submit date:2399991530 done date:abc stat:DELIVRD');

        self::assertNull($receipt?->submitDate);
        self::assertNull($receipt?->doneDate);
    }

    #[Test]
    public function finalStatesAreKnown(): void
    {
        self::assertTrue(MessageState::Delivered->isFinal());
        self::assertTrue(MessageState::Undeliverable->isFinal());
        self::assertFalse(MessageState::Enroute->isFinal());
        self::assertFalse(MessageState::Accepted->isFinal());
    }

    #[Test]
    public function messageIdsMatchAcrossHexAndDecimalAndLeadingZeros(): void
    {
        self::assertTrue(MessageId::same('0A1B', 'a1b'));
        self::assertTrue(MessageId::same('00123', '123'));
        self::assertTrue(MessageId::same('1F', '31'), 'hex in submit_sm_resp, decimal in the receipt');
        self::assertTrue(MessageId::same('31', '1F'));
        self::assertFalse(MessageId::same('123', '124'));
        self::assertFalse(MessageId::same('', ''));
        self::assertFalse(MessageId::same('abc-1', 'abc-2'));
    }

    private static function receipt(string $text): ?DeliveryReceipt
    {
        return DeliveryReceipt::from(new ShortMessage('447700900123', 'Shop', $text, esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT));
    }
}
