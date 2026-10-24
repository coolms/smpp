<?php

declare(strict_types=1);

namespace CoolMS\Smpp;

use Closure;
use CoolMS\Smpp\Exception\BindFailed;
use CoolMS\Smpp\Exception\ConnectionClosed;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Exception\ProtocolError;
use CoolMS\Smpp\Exception\SmppException;
use CoolMS\Smpp\Exception\TimedOut;
use CoolMS\Smpp\Message\DeliveryReceipt;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindMode;
use CoolMS\Smpp\Pdu\BindResp;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\CommandStatus;
use CoolMS\Smpp\Pdu\DataSm;
use CoolMS\Smpp\Pdu\MessageIdResp;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Transport\Clock;
use CoolMS\Smpp\Transport\Connection;
use CoolMS\Smpp\Transport\MonotonicClock;
use Throwable;

use function array_splice;
use function array_values;
use function count;
use function max;
use function min;

/**
 * One ESME session (SMPP 3.4 section 2): bind, then submit_sm with up to a window of requests outstanding, while
 * deliver_sm, data_sm, enquire_link and unbind from the SMSC are answered as they come.
 *
 * Nothing runs in the background: the session works when it is called -- send(), submit(), poll(), drain() -- and
 * then also keeps the link alive (enquire_link after the idle interval), times out requests the SMSC leaves
 * unanswered, and holds sends back after a throttling response.
 *
 * When the session ends -- unbind, the SMSC's unbind, a dead link, a transport failure -- every request still
 * outstanding is reported lost, and every result stays readable through results() and poll().
 *
 * The log hook sees each PDU's command, sequence and status ("C" sent, "S" received); never a body, so never a
 * password or a message text.
 */
final class Session
{
    private ?BindMode $mode = null;
    private bool $closed = false;
    private int $nextSequence;

    /** @var array<int, float> sequence => when the request expires */
    private array $outstanding = [];

    /** @var list<SubmitResult> */
    private array $completed = [];

    private float $lastActivity;
    private ?int $enquireLink = null;
    private float $enquireLinkSentAt = 0.0;
    private float $pausedUntil = 0.0;
    private float $pauseStartedAt = -INF;
    private float $backoff;

    /** @var (Closure(ShortMessage, ?DeliveryReceipt): int)|null */
    private ?Closure $onDeliver = null;

    /**
     * @param (Closure(string, string): void)|null $log
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new MonotonicClock(),
        private readonly SessionOptions $options = new SessionOptions(),
        private readonly ?Closure $log = null,
    ) {
        $this->nextSequence = $options->firstSequence;
        $this->lastActivity = $clock->now();
        $this->backoff = $options->throttleBackoff;
    }

    /**
     * Binds and waits for the answer. A refusal is BindFailed with the SMSC's status; an answer that is not this
     * bind's response is a ProtocolError; no answer is TimedOut. On every failure the connection is closed.
     */
    public function bind(BindMode $mode, Bind $bind): BindResp
    {
        $this->open();
        if (null !== $this->mode) {
            throw new ProtocolError('The session is already bound.');
        }
        try {
            return $this->bindAndWait($mode, $bind);
        } catch (SmppException $failed) {
            $this->close();

            throw $failed;
        }
    }

    public function isBound(): bool
    {
        return null !== $this->mode && !$this->closed;
    }

    /** @param Closure(ShortMessage, ?DeliveryReceipt): int $handler returns the response status, 0 to accept */
    public function onDeliver(Closure $handler): void
    {
        $this->onDeliver = $handler;
    }

    /**
     * Sends a submit_sm without waiting for its response, once the window has room and no throttling pause runs;
     * returns its sequence number. Its result comes from poll(), drain() or results().
     */
    public function send(ShortMessage $message): int
    {
        $this->mustSubmit();
        while (count($this->outstanding) >= $this->options->windowSize || $this->clock->now() < $this->pausedUntil) {
            $wait = count($this->outstanding) >= $this->options->windowSize
                ? $this->options->responseTimeout
                : $this->pausedUntil - $this->clock->now();
            $this->step(max(0.0, $wait));
            $this->open();
        }
        $sequence = $this->sequence();
        $this->write(Pdu::request(CommandId::SubmitSm, $sequence, $message->encode()));
        $this->outstanding[$sequence] = $this->clock->now() + $this->options->responseTimeout;

        return $sequence;
    }

    /** Sends one submit_sm and waits for its result; other results that arrive meanwhile wait for poll(). */
    public function submit(ShortMessage $message): SubmitResult
    {
        $sequence = $this->send($message);
        while (true) {
            foreach ($this->completed as $i => $result) {
                if ($result->sequence === $sequence) {
                    array_splice($this->completed, $i, 1);

                    return $result;
                }
            }
            $this->open();
            $this->step($this->options->responseTimeout);
        }
    }

    /**
     * Works for up to $seconds -- answering the SMSC, keeping the link alive, timing out requests -- and returns
     * every submit result completed since the last call. After the session has ended it only returns them.
     *
     * @return list<SubmitResult>
     */
    public function poll(float $seconds): array
    {
        $deadline = $this->clock->now() + $seconds;
        while (!$this->closed) {
            $this->step(max(0.0, $deadline - $this->clock->now()));
            if ($this->clock->now() >= $deadline) {
                break;
            }
        }

        return $this->results();
    }

    /**
     * Waits until no request is outstanding (each answered, timed out or lost), at most $seconds.
     *
     * @return list<SubmitResult>
     */
    public function drain(float $seconds): array
    {
        $deadline = $this->clock->now() + $seconds;
        while (!$this->closed && [] !== $this->outstanding && $this->clock->now() < $deadline) {
            $this->step(max(0.0, $deadline - $this->clock->now()));
        }

        return $this->results();
    }

    /**
     * Every result completed since the last call, the session ended or not.
     *
     * @return list<SubmitResult>
     */
    public function results(): array
    {
        $results = $this->completed;
        $this->completed = [];

        return $results;
    }

    /** Asks the SMSC to end the session, waits for its answer, and closes the connection. */
    public function unbind(): void
    {
        if (!$this->isBound()) {
            $this->close();

            return;
        }
        $request = Pdu::request(CommandId::Unbind, $this->sequence());
        try {
            $this->write($request);
            $deadline = $this->clock->now() + $this->options->responseTimeout;
            while (!$this->closed && ($left = $deadline - $this->clock->now()) > 0) {
                $pdu = $this->read($left);
                if (null !== $pdu && CommandId::UnbindResp === $pdu->command() && $pdu->sequence === $request->sequence) {
                    break;
                }
                if (null !== $pdu) {
                    $this->handle($pdu);
                }
            }
        } finally {
            $this->close();
        }
    }

    /** Ends the session without unbinding: the connection closes and outstanding requests are reported lost. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->mode = null;
        foreach ($this->outstanding as $sequence => $_) {
            $this->completed[] = new SubmitResult($sequence, null, lost: true);
        }
        $this->outstanding = [];
        $this->connection->close();
    }

    private function bindAndWait(BindMode $mode, Bind $bind): BindResp
    {
        $request = Pdu::request($mode->command(), $this->sequence(), $bind->encode());
        $this->write($request);
        $deadline = $this->clock->now() + $this->options->responseTimeout;
        while (($left = $deadline - $this->clock->now()) > 0) {
            $pdu = $this->read($left);
            if (null === $pdu) {
                continue;
            }
            if ($pdu->command() !== $mode->command()->response() || $pdu->sequence !== $request->sequence) {
                if (CommandId::GenericNack === $pdu->command() && $pdu->sequence === $request->sequence) {
                    throw new BindFailed($pdu->status);
                }

                throw new ProtocolError('The bind was answered by ' . $pdu->describe() . '.');
            }
            if (CommandStatus::Ok->value !== $pdu->status) {
                throw new BindFailed($pdu->status);
            }
            $this->mode = $mode;

            return BindResp::decode($pdu->body);
        }

        throw new TimedOut('The SMSC did not answer the bind within ' . $this->options->responseTimeout . ' s.');
    }

    /** One turn: read for at most $seconds (less when a keepalive or a timeout is due), handle it, check the clocks. */
    private function step(float $seconds): void
    {
        $this->open();
        $pdu = $this->read(min($seconds, $this->untilNextDuty()));
        if (null !== $pdu) {
            $this->handle($pdu);
        }
        $this->duties();
    }

    private function handle(Pdu $pdu): void
    {
        $command = $pdu->command();
        if (null === $command) {
            // A response is never answered (section 3.2), even one this library does not know.
            if ($pdu->isResponse()) {
                return;
            }
            $this->write(new Pdu(CommandId::GenericNack->value, CommandStatus::InvalidCommandId->value, $pdu->sequence));

            return;
        }
        match ($command) {
            CommandId::SubmitSmResp => $this->complete($pdu->sequence, $pdu->status, CommandStatus::Ok->value === $pdu->status ? $this->messageId($pdu) : ''),
            // A generic_nack to our enquire_link still answers it: the link is alive.
            CommandId::GenericNack => $this->enquireLink === $pdu->sequence ? $this->enquireLink = null : $this->complete($pdu->sequence, $pdu->status),
            CommandId::EnquireLinkResp => $this->enquireLink === $pdu->sequence ? $this->enquireLink = null : null,
            CommandId::EnquireLink => $this->write($pdu->respond()),
            CommandId::DeliverSm => $this->delivered($pdu, static fn (string $body): ShortMessage => ShortMessage::decode($body)),
            CommandId::DataSm => $this->delivered($pdu, static fn (string $body): ShortMessage => DataSm::decode($body)->asShortMessage()),
            CommandId::Unbind => $this->unboundBySmsc($pdu),
            // Section 4.12: alert_notification has no response.
            CommandId::AlertNotification => null,
            default => $command->isResponse() ? null : $this->write(new Pdu(CommandId::GenericNack->value, CommandStatus::InvalidCommandId->value, $pdu->sequence)),
        };
    }

    /**
     * deliver_sm or data_sm: decoded, handed to the handler with its receipt, and answered on every path. A handler
     * that throws gets the SMSC a temporary error (it delivers again), and its exception goes on to the caller.
     *
     * @param Closure(string): ShortMessage $decode
     */
    private function delivered(Pdu $pdu, Closure $decode): void
    {
        // deliver_sm_resp and data_sm_resp both carry a message_id, empty here (sections 4.6.2, 4.7.2).
        $body = MessageIdResp::forResponse('');
        if (!($this->mode?->mayReceive() ?? false)) {
            $this->write($pdu->respond(CommandStatus::IncorrectBindStatus->value, $body));

            return;
        }
        try {
            $message = $decode($pdu->body);
        } catch (MalformedPdu) {
            // It will not read better next time: a permanent refusal, so the SMSC does not resend it forever.
            $this->write($pdu->respond(CommandStatus::PermanentAppError->value, $body));

            return;
        }
        try {
            $receipt = DeliveryReceipt::from($message);
        } catch (MalformedPdu) {
            $receipt = null;
        }
        try {
            $status = null === $this->onDeliver ? CommandStatus::Ok->value : ($this->onDeliver)($message, $receipt);
        } catch (Throwable $handlerFailed) {
            try {
                $this->write($pdu->respond(CommandStatus::TemporaryAppError->value, $body));
            } catch (ConnectionClosed|TimedOut) {
                // The session has ended; the handler's failure is still the one to report.
            }

            throw $handlerFailed;
        }
        $this->write($pdu->respond($status, $body));
    }

    private function unboundBySmsc(Pdu $pdu): void
    {
        $this->write($pdu->respond());
        $this->close();
    }

    private function complete(int $sequence, int $status, string $messageId = ''): void
    {
        if (!isset($this->outstanding[$sequence])) {
            return;
        }
        $sentAt = $this->outstanding[$sequence] - $this->options->responseTimeout;
        unset($this->outstanding[$sequence]);
        $this->completed[] = new SubmitResult($sequence, $status, $messageId);
        if (CommandStatus::Throttled->value === $status || CommandStatus::MessageQueueFull->value === $status) {
            // One pause per episode: a throttle for a request sent before the last pause began belongs to that
            // pause, whenever it is answered, and so does one answered while a pause runs.
            $now = $this->clock->now();
            if ($sentAt > $this->pauseStartedAt && $now >= $this->pausedUntil) {
                $this->pauseStartedAt = $now;
                $this->pausedUntil = $now + $this->backoff;
                $this->backoff = min($this->backoff * 2, $this->options->maxThrottleBackoff);
            }
        } elseif (CommandStatus::Ok->value === $status) {
            $this->backoff = $this->options->throttleBackoff;
        }
    }

    private function messageId(Pdu $pdu): string
    {
        try {
            return MessageIdResp::decode($pdu->body)->messageId;
        } catch (MalformedPdu) {
            return '';
        }
    }

    /** Keepalive and request timeouts, measured now. */
    private function duties(): void
    {
        if ($this->closed) {
            return;
        }
        $now = $this->clock->now();
        foreach ($this->outstanding as $sequence => $expires) {
            if ($now >= $expires) {
                unset($this->outstanding[$sequence]);
                $this->completed[] = new SubmitResult($sequence, null, timedOut: true);
            }
        }
        if (null !== $this->enquireLink && $now - $this->enquireLinkSentAt >= $this->options->responseTimeout) {
            $this->close();

            throw new TimedOut('The SMSC did not answer enquire_link within ' . $this->options->responseTimeout . ' s; the link is dead.');
        }
        if (null === $this->enquireLink && null !== $this->mode && $now - $this->lastActivity >= $this->options->enquireLinkInterval) {
            $this->enquireLink = $this->sequence();
            $this->enquireLinkSentAt = $now;
            $this->write(Pdu::request(CommandId::EnquireLink, $this->enquireLink));
        }
    }

    /** Seconds until the next keepalive, enquire_link deadline or request expiry is due. */
    private function untilNextDuty(): float
    {
        $due = null === $this->enquireLink
            ? $this->lastActivity + $this->options->enquireLinkInterval
            : $this->enquireLinkSentAt + $this->options->responseTimeout;
        if ([] !== $this->outstanding) {
            $due = min($due, ...array_values($this->outstanding));
        }

        return max(0.0, $due - $this->clock->now());
    }

    private function mustSubmit(): void
    {
        $this->open();
        if (null === $this->mode) {
            throw new ProtocolError('Bind before sending: the session is not bound.');
        }
        if (!$this->mode->maySubmit()) {
            throw new ProtocolError('A receiver bind may not submit (section 2.2); bind as transmitter or transceiver.');
        }
    }

    private function sequence(): int
    {
        $sequence = $this->nextSequence;
        $this->nextSequence = $sequence >= Pdu::MAX_SEQUENCE ? 1 : $sequence + 1;

        return $sequence;
    }

    /** Sends one PDU; a transport failure ends the session before it is thrown. */
    private function write(Pdu $pdu): void
    {
        $this->log('C', $pdu->describe());
        try {
            $this->connection->write($pdu);
        } catch (ConnectionClosed|TimedOut $failed) {
            $this->close();

            throw $failed;
        }
        $this->lastActivity = $this->clock->now();
    }

    /** Reads one PDU; a transport failure or a framing error ends the session before it is thrown. */
    private function read(float $seconds): ?Pdu
    {
        try {
            $pdu = $this->connection->read($seconds);
        } catch (ConnectionClosed|MalformedPdu $failed) {
            $this->close();

            throw $failed;
        }
        if (null !== $pdu) {
            $this->log('S', $pdu->describe());
            $this->lastActivity = $this->clock->now();
        }

        return $pdu;
    }

    private function open(): void
    {
        if ($this->closed) {
            throw new ConnectionClosed('The session has ended.');
        }
    }

    private function log(string $direction, string $line): void
    {
        if (null !== $this->log) {
            ($this->log)($direction, $line);
        }
    }
}
