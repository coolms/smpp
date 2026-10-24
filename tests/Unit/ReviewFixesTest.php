<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit;

use CoolMS\Smpp\Exception\BindFailed;
use CoolMS\Smpp\Exception\ConnectionClosed;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Exception\TimedOut;
use CoolMS\Smpp\Message\DeliveryReceipt;
use CoolMS\Smpp\Message\MessageId;
use CoolMS\Smpp\Message\MessageState;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindMode;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\CommandStatus;
use CoolMS\Smpp\Pdu\DataSm;
use CoolMS\Smpp\Pdu\MessageIdResp;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Pdu\Tag;
use CoolMS\Smpp\Pdu\Tlv;
use CoolMS\Smpp\Session;
use CoolMS\Smpp\SessionOptions;
use CoolMS\Smpp\Tests\Support\FakeClock;
use CoolMS\Smpp\Tests\Support\FakeSmsc;
use CoolMS\Smpp\Transport\Connection;
use CoolMS\Smpp\Transport\Endpoint;
use CoolMS\Smpp\Transport\Opener;
use CoolMS\Smpp\Transport\StreamSelectWaiter;
use CoolMS\Smpp\Transport\Waiter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function fclose;
use function fwrite;
use function pack;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrpos;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/** The defects found in review of the first version, each pinned. */
final class ReviewFixesTest extends TestCase
{
    private FakeSmsc $smsc;

    #[Test]
    public function receiptTextUnderDataCodingZeroIsReadAsAsciiSoIdsKeepTheirPunctuation(): void
    {
        $receipt = DeliveryReceipt::from(new ShortMessage('a', 'b', 'id:A_1$x@ submit_date:2610240330 stat:DELIVRD', esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT));

        self::assertNotNull($receipt);
        self::assertSame('A_1$x@', $receipt->messageId);
        self::assertSame('2026-10-24 03:30', $receipt->submitDate?->format('Y-m-d H:i'));
    }

    #[Test]
    public function aMalformedMessageStateTlvFallsBackToTheText(): void
    {
        $receipt = DeliveryReceipt::from(new ShortMessage('a', 'b', 'id:9 stat:DELIVRD', esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT, tlvs: [Tlv::of(Tag::MessageState, "\x00\x00\x02")]));

        self::assertSame(MessageState::Delivered, $receipt?->state);
    }

    #[Test]
    public function fourteenDigitReceiptDatesAreRead(): void
    {
        $receipt = DeliveryReceipt::from(new ShortMessage('a', 'b', 'id:9 submit date:20261024033012 stat:DELIVRD', esmClass: ShortMessage::ESM_TYPE_DELIVERY_RECEIPT));

        self::assertSame('2026-10-24 03:30:12', $receipt?->submitDate?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function twoDecimalIdsNeverMatchAsHex(): void
    {
        self::assertFalse(MessageId::same('10', '16'));
        self::assertFalse(MessageId::same('4096', '16534'));
        self::assertTrue(MessageId::same('1F', '31'), 'a hex id with letters still matches its decimal form');
    }

    #[Test]
    public function aHandlerThatThrowsStillAnswersTheDeliverSm(): void
    {
        $session = $this->bound();
        $session->onDeliver(static fn (): int => throw new RuntimeException('handler broke'));
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->deliver(new ShortMessage('a', 'b', 'hello')));
        try {
            $session->poll(1.0);
            self::fail('the handler\'s exception was swallowed');
        } catch (RuntimeException $error) {
            self::assertSame('handler broke', $error->getMessage());
        }

        self::assertSame(CommandStatus::TemporaryAppError->value, $this->smsc->sent(CommandId::DeliverSmResp)[0]->status);
    }

    #[Test]
    public function aDataSmIsReadByItsOwnLayoutAndAnsweredWithAMessageId(): void
    {
        $session = $this->bound();
        $seen = [];
        $session->onDeliver(static function (ShortMessage $message) use (&$seen): int {
            $seen[] = $message->payload();

            return 0;
        });
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(Pdu::request(CommandId::DataSm, 40, new DataSm('447700900123', 'Shop', tlvs: [Tlv::of(Tag::MessagePayload, 'mo text')])->encode())));
        $session->poll(1.0);

        self::assertSame(['mo text'], $seen);
        $resp = $this->smsc->sent(CommandId::DataSmResp);
        self::assertSame([0, 40], [$resp[0]->status, $resp[0]->sequence]);
        self::assertSame('', MessageIdResp::decode($resp[0]->body)->messageId);
        self::assertSame("\0", $resp[0]->body);
    }

    #[Test]
    public function anAlertNotificationGetsNoResponse(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(Pdu::request(CommandId::AlertNotification, 41)));
        $session->poll(1.0);

        self::assertSame([], $this->smsc->sent(CommandId::GenericNack));
    }

    #[Test]
    public function resultsSurviveAnUnbindAndTheInFlightAreReportedLost(): void
    {
        $session = $this->bound();
        $this->smsc->holdSubmitResponses = true;
        $sequence = $session->send(new ShortMessage('a', '1', 'x'));
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(Pdu::request(CommandId::Unbind, 5)));
        $results = $session->poll(1.0);

        self::assertCount(1, $results);
        self::assertSame($sequence, $results[0]->sequence);
        self::assertTrue($results[0]->lost);
        self::assertNull($results[0]->status);
        self::assertSame([], $session->poll(1.0), 'poll after the end returns what is left, without throwing');
    }

    #[Test]
    public function aPeerThatHangsUpEndsTheSessionAndReportsTheInFlight(): void
    {
        $session = $this->bound();
        $this->smsc->holdSubmitResponses = true;
        $session->send(new ShortMessage('a', '1', 'x'));
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->hangUp());
        try {
            $session->poll(1.0);
            self::fail('a closed peer went unnoticed');
        } catch (ConnectionClosed) {
            self::assertFalse($session->isBound());
        }
        $results = $session->results();
        self::assertCount(1, $results);
        self::assertTrue($results[0]->lost);
    }

    #[Test]
    public function aSendThePeerCannotTakeEndsTheSessionAndReportsTheInFlight(): void
    {
        $session = $this->bound();
        $this->smsc->holdSubmitResponses = true;
        $session->send(new ShortMessage('a', '1', 'x'));
        $this->smsc->hangUp();
        try {
            $session->send(new ShortMessage('a', '2', 'y'));
            self::fail('a failed write went unnoticed');
        } catch (ConnectionClosed) {
            self::assertFalse($session->isBound());
        }
        $results = $session->results();
        self::assertCount(1, $results);
        self::assertTrue($results[0]->lost);
    }

    #[Test]
    public function aDeliverSmThatCannotBeReadIsRefusedWithTheResponseBody(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(Pdu::request(CommandId::DeliverSm, $smsc->nextSequence(), 'x')));
        $session->poll(1.0);

        $resp = $this->smsc->sent(CommandId::DeliverSmResp);
        self::assertSame(CommandStatus::PermanentAppError->value, $resp[0]->status);
        self::assertSame("\0", $resp[0]->body);
    }

    #[Test]
    public function aFramingErrorEndsTheSession(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->raw(pack('N', 5) . 'xxxx'));
        try {
            $session->poll(1.0);
            self::fail('a 5-byte PDU was accepted');
        } catch (MalformedPdu) {
            self::assertFalse($session->isBound());
        }
    }

    #[Test]
    public function throttlingEscalatesOncePerEpisodeNotPerResponse(): void
    {
        $session = $this->bound(new SessionOptions(throttleBackoff: 1.0, maxThrottleBackoff: 30.0));
        $this->smsc->submitStatuses = [CommandStatus::Throttled->value, CommandStatus::Throttled->value, CommandStatus::Throttled->value];
        $this->smsc->holdSubmitResponses = true;
        $session->send(new ShortMessage('a', '1', 'x'));
        $session->send(new ShortMessage('a', '2', 'x'));
        $session->send(new ShortMessage('a', '3', 'x'));
        $this->smsc->holdSubmitResponses = false;
        $this->smsc->release();
        $session->drain(5.0);
        $before = $this->smsc->clock->now();
        $session->submit(new ShortMessage('a', '4', 'x'));

        self::assertLessThan(2.0, $this->smsc->clock->now() - $before, 'three throttles in one window paused for 1 s, not 8');
    }

    #[Test]
    public function aFailedBindClosesTheConnection(): void
    {
        $this->smsc->password = 'other';
        try {
            $this->session()->bind(BindMode::Transmitter, new Bind('esme', 'secret'));
            self::fail('bound');
        } catch (BindFailed) {
            self::assertTrue($this->smsc->peerClosed());
        }
    }

    #[Test]
    public function aSystemIdTooLongIsNotRepeatedInTheError(): void
    {
        try {
            new Bind(str_repeat('s', 15) . 'Z', 'p')->encode();
            self::fail('a 16-character system id was encoded');
        } catch (MalformedPdu $error) {
            self::assertStringNotContainsString(str_repeat('s', 15) . 'Z', $error->getMessage());
        }
    }

    #[Test]
    public function aWriteTheSmscNeverReadsTimesOut(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $pair) {
            throw new RuntimeException('no socket pair');
        }
        [$client] = $pair;
        stream_set_blocking($client, false);
        while (fwrite($client, str_repeat('x', 65536)) > 0) {
            // Fill the send buffer: nobody reads the other end.
        }
        $clock = new FakeClock();
        $waiter = new class($clock) implements Waiter {
            public function __construct(private FakeClock $clock)
            {
            }

            public function readable(mixed $stream, float $seconds): bool
            {
                $this->clock->advance($seconds);

                return false;
            }

            public function writable(mixed $stream, float $seconds): bool
            {
                $this->clock->advance($seconds);

                return false;
            }
        };
        $this->expectException(TimedOut::class);
        new Connection($client, $waiter, $clock, writeTimeout: 5.0)->write(Pdu::request(CommandId::EnquireLink, 1));
    }

    #[Test]
    public function aFastSenderCannotGrowTheReadBufferPastOnePduAndAChunk(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $pair) {
            throw new RuntimeException('no socket pair');
        }
        [$client, $peer] = $pair;
        stream_set_blocking($peer, false);
        $pdus = str_repeat(Pdu::request(CommandId::EnquireLink, 1)->encode(), 4096);
        $queued = 0;
        while (($written = fwrite($peer, $pdus)) > 0) {
            $queued += $written;
        }
        $connection = new Connection($client, new StreamSelectWaiter(), new FakeClock(), maxLength: 100);
        self::assertNotNull($connection->read(0.0));

        $buffered = strlen((string) new ReflectionProperty(Connection::class, 'buffer')->getValue($connection));
        self::assertGreaterThan(100 + 2 * 65536, $queued, 'the peer queued more than the cap, or the case shows nothing');
        self::assertLessThan(100 + 2 * 65536, $buffered);
    }

    #[Test]
    public function theOpenerGivesItsConnectionsTheWriteTimeout(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $server) {
            throw new RuntimeException('no local server');
        }
        $address = (string) stream_socket_get_name($server, false);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        $connection = new Opener(writeTimeout: 2.5)->open(new Endpoint('127.0.0.1', $port, tls: false));

        self::assertSame(2.5, new ReflectionProperty(Connection::class, 'writeTimeout')->getValue($connection));
        $connection->close();
        fclose($server);
    }

    #[Test]
    public function aGenericNackToOurEnquireLinkStillAnswersIt(): void
    {
        $session = $this->bound(new SessionOptions(enquireLinkInterval: 30.0, responseTimeout: 5.0));
        $this->smsc->answerEnquireLink = false;
        $session->poll(30.5);
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(new Pdu(CommandId::GenericNack->value, CommandStatus::InvalidCommandId->value, $smsc->sent(CommandId::EnquireLink)[0]->sequence)));
        $session->poll(10.0);

        self::assertTrue($session->isBound());
    }

    #[Test]
    public function aResponseWithAnUnknownIdIsNotAnswered(): void
    {
        $session = $this->bound();
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->send(new Pdu(0x80000999, 0, 5)));
        $session->poll(1.0);

        self::assertSame([], $this->smsc->sent(CommandId::GenericNack));
    }

    #[Test]
    public function aHandlerFailureIsReportedEvenWhenItsAnswerCannotBeSent(): void
    {
        $session = $this->bound();
        $smsc = $this->smsc;
        $session->onDeliver(static function () use ($smsc): int {
            $smsc->hangUp();

            throw new RuntimeException('handler broke');
        });
        $this->smsc->then(static fn (FakeSmsc $smsc) => $smsc->deliver(new ShortMessage('a', 'b', 'hello')));
        try {
            $session->poll(1.0);
            self::fail('nothing was thrown');
        } catch (RuntimeException $error) {
            self::assertSame('handler broke', $error->getMessage());
        }
        self::assertFalse($session->isBound());
    }

    #[Test]
    public function theFirstSequenceIsOneOrMore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionOptions(firstSequence: 0);
    }

    #[Test]
    public function aTimedOutResultHasNoStatus(): void
    {
        $session = $this->bound(new SessionOptions(responseTimeout: 5.0));
        $this->smsc->holdSubmitResponses = true;
        $result = $session->submit(new ShortMessage('a', '1', 'x'));

        self::assertTrue($result->timedOut);
        self::assertNull($result->status);
    }

    protected function setUp(): void
    {
        $this->smsc = new FakeSmsc();
    }

    private function session(SessionOptions $options = new SessionOptions()): Session
    {
        return new Session(new Connection($this->smsc->client, $this->smsc, $this->smsc->clock), $this->smsc->clock, $options);
    }

    private function bound(SessionOptions $options = new SessionOptions()): Session
    {
        $session = $this->session($options);
        $session->bind(BindMode::Transceiver, new Bind('esme', 'secret'));

        return $session;
    }
}
