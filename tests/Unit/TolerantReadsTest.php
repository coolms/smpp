<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit;

use CoolMS\Smpp\Message\DeliveryReceipt;
use CoolMS\Smpp\Message\MessageState;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindMode;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\CommandStatus;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Session;
use CoolMS\Smpp\Tests\Support\FakeSmsc;
use CoolMS\Smpp\Transport\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function pack;
use function strlen;

/**
 * What a real SMSC sends is read, even where it bends SMPP 3.4: a receipt is not lost because its sender numbered
 * it past 0x7FFFFFFF or wrote a service_type longer than five characters. What this library sends stays strict.
 */
final class TolerantReadsTest extends TestCase
{
    private FakeSmsc $smsc;

    #[Test]
    public function aReceiptNumberedPastTheSpecsRangeIsReadAndAnsweredWithTheSameNumber(): void
    {
        $session = $this->bound();
        $seen = null;
        $session->onDeliver(static function (ShortMessage $m, ?DeliveryReceipt $r) use (&$seen): int {
            $seen = $r;

            return 0;
        });
        $this->smsc->then(fn (FakeSmsc $smsc) => $smsc->send(new Pdu(CommandId::DeliverSm->value, 0, 0x8000_1234, $this->receiptBody('smscsim'))));
        $session->poll(1.0);

        self::assertSame('7157439422297304012', $seen?->messageId);
        $resp = $this->smsc->sent(CommandId::DeliverSmResp);
        self::assertSame(0x8000_1234, $resp[0]->sequence);
        self::assertSame(CommandStatus::Ok->value, $resp[0]->status);
    }

    #[Test]
    public function aServiceTypeLongerThanTheSpecIsReadNotRefused(): void
    {
        $message = ShortMessage::decode($this->receiptBody('smscsim'));

        self::assertSame('smscsim', $message->serviceType);
        self::assertSame(MessageState::Delivered, DeliveryReceipt::from($message)?->state);
    }

    #[Test]
    public function whatThisLibrarySendsStaysWithinTheSpec(): void
    {
        $this->expectException(\CoolMS\Smpp\Exception\MalformedPdu::class);
        new ShortMessage('a', 'b', 'x', serviceType: 'smscsim')->encode();
    }

    protected function setUp(): void
    {
        $this->smsc = new FakeSmsc();
    }

    private function bound(): Session
    {
        $session = new Session(new Connection($this->smsc->client, $this->smsc, $this->smsc->clock), $this->smsc->clock);
        $session->bind(BindMode::Transceiver, new Bind('esme', 'secret'));

        return $session;
    }

    /** A delivery receipt's deliver_sm body as the simulator writes it: its own service_type, the receipt text, TLVs. */
    private function receiptBody(string $serviceType): string
    {
        $text = 'id:7157439422297304012 sub:001 dlvrd:001 submit date:2610241200 done date:2610241200 stat:DELIVRD err:000 Text:...';
        $receiptedId = "7157439422297304012\0";

        return $serviceType . "\0" . chr(0) . chr(0) . "375291234567\0" . chr(0) . chr(0) . "Shop\0"
            . chr(0x04) . chr(0) . chr(0) . "\0\0" . chr(0) . chr(0) . chr(0) . chr(0)
            . chr(strlen($text)) . $text
            . pack('nn', 0x001E, strlen($receiptedId)) . $receiptedId
            . pack('nnC', 0x0427, 1, 2);
    }
}
