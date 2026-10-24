<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit;

use CoolMS\Smpp\Exception\BindFailed;
use CoolMS\Smpp\Exception\ConnectionClosed;
use CoolMS\Smpp\Exception\ProtocolError;
use CoolMS\Smpp\Exception\TimedOut;
use CoolMS\Smpp\Message\DeliveryReceipt;
use CoolMS\Smpp\Message\MessageState;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindMode;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\CommandStatus;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Session;
use CoolMS\Smpp\SessionOptions;
use CoolMS\Smpp\Tests\Support\FakeSmsc;
use CoolMS\Smpp\Transport\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The ESME side of SMPP 3.4 sessions (section 2), against an SMSC in the test's own process. */
final class SessionTest extends TestCase
{
    private FakeSmsc $smsc;

    #[Test]
    public function itBindsAsTransceiverAndNamesTheSmsc(): void
    {
        $session = $this->session();
        $resp = $session->bind(BindMode::Transceiver, new Bind('esme', 'secret'));

        self::assertSame('FAKESMSC', $resp->systemId);
        self::assertTrue($session->isBound());
        $sent = $this->smsc->sent(CommandId::BindTransceiver);
        self::assertCount(1, $sent);
        self::assertSame('esme', Bind::decode($sent[0]->body)->systemId);
    }

    #[Test]
    public function aRefusedBindSaysWhy(): void
    {
        $this->smsc->password = 'other';
        try {
            $this->session()->bind(BindMode::Transmitter, new Bind('esme', 'secret'));
            self::fail('bound with a wrong password');
        } catch (BindFailed $failed) {
            self::assertSame(CommandStatus::InvalidPassword->value, $failed->status);
            self::assertStringContainsString('ESME_RINVPASWD', $failed->getMessage());
        }
    }

    #[Test]
    public function aBindAnsweredByAnotherCommandIsAProtocolError(): void
    {
        $session = $this->session();
        $this->smsc->answerBinds = false;
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(new Pdu(CommandId::SubmitSmResp->value, 0, 1)));
        $this->expectException(ProtocolError::class);
        $session->bind(BindMode::Transmitter, new Bind('esme', 'secret'));
    }

    #[Test]
    public function aSubmitReturnsTheSmscsMessageId(): void
    {
        $session = $this->bound();
        $result = $session->submit(new ShortMessage('Shop', '447700900123', 'Hi'));

        self::assertTrue($result->isOk());
        self::assertSame('MSG1', $result->messageId);
        $submits = $this->smsc->sent(CommandId::SubmitSm);
        self::assertSame('Hi', ShortMessage::decode($submits[0]->body)->shortMessage);
        self::assertSame($result->sequence, $submits[0]->sequence);
    }

    #[Test]
    public function theWindowBoundsTheRequestsOutstanding(): void
    {
        $session = $this->bound(new SessionOptions(windowSize: 2));
        $this->smsc->holdSubmitResponses = true;
        $session->send(new ShortMessage('a', '1', 'one'));
        $session->send(new ShortMessage('a', '2', 'two'));
        // The third waits for room: the SMSC answers what it holds when the client next waits.
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->release());
        $session->send(new ShortMessage('a', '3', 'three'));
        $this->smsc->holdSubmitResponses = false;
        $this->smsc->release();
        $results = $session->drain(5.0);

        self::assertSame(2, $this->smsc->mostOutstanding);
        self::assertCount(3, $results);
        self::assertSame(['MSG1', 'MSG2', 'MSG3'], array_map(static fn ($r) => $r->messageId, $results));
    }

    #[Test]
    public function aThrottledResponseIsTransientAndTheNextSendWaitsTheBackoff(): void
    {
        $session = $this->bound(new SessionOptions(throttleBackoff: 2.0));
        $this->smsc->submitStatuses = [CommandStatus::Throttled->value];
        $throttled = $session->submit(new ShortMessage('a', '1', 'x'));

        self::assertFalse($throttled->isOk());
        self::assertTrue($throttled->isTransient());
        $before = $this->smsc->clock->now();
        $session->submit(new ShortMessage('a', '1', 'x'));
        self::assertGreaterThanOrEqual(2.0, $this->smsc->clock->now() - $before, 'the retry was written before the backoff passed');
    }

    #[Test]
    public function aReceiptIsHandedOverAndAnswered(): void
    {
        $session = $this->bound();
        $seen = [];
        $session->onDeliver(static function (ShortMessage $message, ?DeliveryReceipt $receipt) use (&$seen): int {
            $seen[] = $receipt;

            return CommandStatus::Ok->value;
        });
        $sequence = 0;
        $this->smsc->then(static function (FakeSmsc $smsc) use (&$sequence): void {
            $sequence = $smsc->deliver(new ShortMessage('447700900123', 'Shop', 'id:MSG1 sub:001 dlvrd:001 submit date:2610240330 done date:2610240331 stat:DELIVRD err:000 text:Hi', esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT));
        });
        $session->poll(1.0);

        self::assertCount(1, $seen);
        self::assertSame(MessageState::Delivered, $seen[0]?->state);
        $resp = $this->smsc->sent(CommandId::DeliverSmResp);
        self::assertCount(1, $resp);
        self::assertSame($sequence, $resp[0]->sequence);
        self::assertSame(0, $resp[0]->status);
    }

    #[Test]
    public function aDeliverSmToATransmitterIsRefusedAsTheWrongBindState(): void
    {
        $session = $this->bound(mode: BindMode::Transmitter);
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->deliver(new ShortMessage('a', 'b', 'hello')));
        $session->poll(1.0);

        self::assertSame(CommandStatus::IncorrectBindStatus->value, $this->smsc->sent(CommandId::DeliverSmResp)[0]->status);
    }

    #[Test]
    public function theSmscsEnquireLinkIsAnswered(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(Pdu::request(CommandId::EnquireLink, 77)));
        $session->poll(1.0);

        self::assertSame(77, $this->smsc->sent(CommandId::EnquireLinkResp)[0]->sequence);
    }

    #[Test]
    public function anIdleSessionSendsEnquireLink(): void
    {
        $session = $this->bound(new SessionOptions(enquireLinkInterval: 30.0));
        $session->poll(31.0);

        self::assertCount(1, $this->smsc->sent(CommandId::EnquireLink));
    }

    #[Test]
    public function anUnansweredEnquireLinkEndsTheSession(): void
    {
        $session = $this->bound(new SessionOptions(enquireLinkInterval: 30.0, responseTimeout: 5.0));
        $this->smsc->answerEnquireLink = false;
        $this->expectException(TimedOut::class);
        $session->poll(60.0);
    }

    #[Test]
    public function anUnansweredSubmitTimesOutWithoutBlockingTheRest(): void
    {
        $session = $this->bound(new SessionOptions(responseTimeout: 5.0));
        $this->smsc->holdSubmitResponses = true;
        $result = $session->submit(new ShortMessage('a', '1', 'x'));

        self::assertTrue($result->timedOut);
        self::assertFalse($result->isOk());
        self::assertTrue($result->isTransient(), 'a timeout may have been delivered; whether to resend is the caller\'s');
    }

    #[Test]
    public function aGenericNackFailsTheRequestItNames(): void
    {
        $session = $this->bound();
        $this->smsc->holdSubmitResponses = true;
        $sequence = $session->send(new ShortMessage('a', '1', 'x'));
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(new Pdu(CommandId::GenericNack->value, CommandStatus::InvalidCommandLength->value, $sequence)));
        $results = $session->poll(1.0);

        self::assertCount(1, $results);
        self::assertSame(CommandStatus::InvalidCommandLength->value, $results[0]->status);
    }

    #[Test]
    public function anUnknownCommandIsNackedNotFatal(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(new Pdu(0x00000777, 0, 9)));
        $session->poll(1.0);

        $nack = $this->smsc->sent(CommandId::GenericNack);
        self::assertSame([CommandStatus::InvalidCommandId->value, 9], [$nack[0]->status, $nack[0]->sequence]);
        self::assertTrue($session->isBound());
    }

    #[Test]
    public function anUnbindFromTheSmscIsAnsweredAndEndsTheSession(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(Pdu::request(CommandId::Unbind, 5)));
        $session->poll(1.0);

        self::assertSame(5, $this->smsc->sent(CommandId::UnbindResp)[0]->sequence);
        self::assertFalse($session->isBound());
        $this->expectException(ConnectionClosed::class);
        $session->submit(new ShortMessage('a', '1', 'x'));
    }

    #[Test]
    public function unbindWaitsForItsResponse(): void
    {
        $session = $this->bound();
        $session->unbind();

        self::assertCount(1, $this->smsc->sent(CommandId::Unbind));
        self::assertFalse($session->isBound());
    }

    #[Test]
    public function aReceiverMayNotSubmit(): void
    {
        $session = $this->bound(mode: BindMode::Receiver);
        $this->expectException(ProtocolError::class);
        $session->submit(new ShortMessage('a', '1', 'x'));
    }

    #[Test]
    public function nothingIsSentBeforeABind(): void
    {
        $this->expectException(ProtocolError::class);
        $this->session()->submit(new ShortMessage('a', '1', 'x'));
    }

    #[Test]
    public function sequenceNumbersWrapAfterTheLargest(): void
    {
        $session = $this->bound(new SessionOptions(firstSequence: 0x7FFFFFFF));
        $next = $session->submit(new ShortMessage('a', '1', 'x'));

        self::assertSame(0x7FFFFFFF, $this->smsc->sent(CommandId::BindTransceiver)[0]->sequence);
        self::assertSame(1, $next->sequence);
    }

    protected function setUp(): void
    {
        $this->smsc = new FakeSmsc();
    }

    private function session(SessionOptions $options = new SessionOptions()): Session
    {
        return new Session(new Connection($this->smsc->client, $this->smsc, $this->smsc->clock), $this->smsc->clock, $options);
    }

    private function bound(SessionOptions $options = new SessionOptions(), BindMode $mode = BindMode::Transceiver): Session
    {
        $session = $this->session($options);
        $session->bind($mode, new Bind('esme', 'secret'));

        return $session;
    }
}
