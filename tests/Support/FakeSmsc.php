<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Support;

use Closure;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindResp;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\CommandStatus;
use CoolMS\Smpp\Pdu\MessageIdResp;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\Reader;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Transport\Waiter;
use RuntimeException;

use function array_shift;
use function count;
use function fread;
use function fwrite;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * An SMSC in the test's own process, on one end of a socket pair. It is also the client's Waiter: whenever the client
 * waits, the SMSC first reads what the client wrote and answers it, then -- if nothing is there to read -- the fake
 * clock moves on by the time the client would have waited. Every flow is deterministic and nothing sleeps.
 */
final class FakeSmsc implements Waiter
{
    /** @var resource */
    public readonly mixed $client;

    /** @var list<Pdu> every PDU the client sent, in order */
    public array $received = [];

    /** The most submit_sm the client had outstanding at once. */
    public int $mostOutstanding = 0;

    /** @var list<int> statuses for the next submit_sm responses, in order; Ok once used up */
    public array $submitStatuses = [];

    /** Answer submit_sm only when release() is called (to fill the client's window). */
    public bool $holdSubmitResponses = false;

    /** Answer enquire_link at all. */
    public bool $answerEnquireLink = true;

    /** Answer binds at all (off when a test scripts the answer itself). */
    public bool $answerBinds = true;

    public string $password = 'secret';

    /** @var resource */
    private mixed $server;

    private string $buffer = '';
    private int $sequence = 0;
    private int $messageIds = 0;

    /** @var list<Pdu> submit_sm waiting for release() */
    private array $held = [];

    /** @var list<Closure(self): void> */
    private array $script = [];

    public function __construct(public readonly FakeClock $clock = new FakeClock())
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $pair) {
            throw new RuntimeException('no socket pair');
        }
        [$this->client, $this->server] = $pair;
        stream_set_blocking($this->server, false);
    }

    public function readable(mixed $stream, float $seconds): bool
    {
        $this->serve();
        if ($this->pending($stream)) {
            return true;
        }
        $this->clock->advance($seconds);
        $this->serve();

        return $this->pending($stream);
    }

    /** Runs once at the SMSC's next turn: a deliver_sm to push, an unbind to send. */
    public function then(Closure $step): void
    {
        $this->script[] = $step;
    }

    public function send(Pdu $pdu): void
    {
        fwrite($this->server, $pdu->encode());
    }

    public function nextSequence(): int
    {
        return ++$this->sequence;
    }

    public function deliver(ShortMessage $message): int
    {
        $sequence = $this->nextSequence();
        $this->send(Pdu::request(CommandId::DeliverSm, $sequence, $message->encode()));

        return $sequence;
    }

    /** Answers every held submit_sm. */
    public function release(): void
    {
        foreach ($this->held as $submit) {
            $this->answerSubmit($submit);
        }
        $this->held = [];
    }

    /** @return list<Pdu> the client's PDUs with this command, including any written since the client last waited */
    public function sent(CommandId $id): array
    {
        $this->serve();

        return array_values(array_filter($this->received, static fn (Pdu $pdu): bool => $id->value === $pdu->commandId));
    }

    private function serve(): void
    {
        while ('' !== ($chunk = (string) fread($this->server, 65536))) {
            $this->buffer .= $chunk;
        }
        while (strlen($this->buffer) >= 4) {
            $length = Reader::unsigned('N', substr($this->buffer, 0, 4), 'command_length');
            if (strlen($this->buffer) < $length) {
                break;
            }
            $pdu = Pdu::decode(substr($this->buffer, 0, $length));
            $this->buffer = substr($this->buffer, $length);
            $this->received[] = $pdu;
            $this->answer($pdu);
        }
        while ([] !== $this->script) {
            $step = array_shift($this->script);
            $step($this);
        }
    }

    private function answer(Pdu $pdu): void
    {
        $command = $pdu->command();
        if (null === $command || $command->isResponse()) {
            return;
        }
        match ($command) {
            CommandId::BindTransmitter, CommandId::BindReceiver, CommandId::BindTransceiver => $this->answerBinds ? $this->send($pdu->respond(
                Bind::decode($pdu->body)->passwordIs($this->password) ? CommandStatus::Ok->value : CommandStatus::InvalidPassword->value,
                new BindResp('FAKESMSC')->encode(),
            )) : null,
            CommandId::SubmitSm => $this->onSubmit($pdu),
            CommandId::EnquireLink => $this->answerEnquireLink ? $this->send($pdu->respond()) : null,
            CommandId::Unbind => $this->send($pdu->respond()),
            default => null,
        };
    }

    private function onSubmit(Pdu $submit): void
    {
        if ($this->holdSubmitResponses) {
            $this->held[] = $submit;
            $this->mostOutstanding = max($this->mostOutstanding, count($this->held));

            return;
        }
        $this->answerSubmit($submit);
    }

    private function answerSubmit(Pdu $submit): void
    {
        $status = array_shift($this->submitStatuses) ?? CommandStatus::Ok->value;
        $body = CommandStatus::Ok->value === $status ? new MessageIdResp('MSG' . ++$this->messageIds)->encode() : '';
        $this->send($submit->respond($status, $body));
    }

    /** @param resource $stream */
    private function pending(mixed $stream): bool
    {
        $read = [$stream];
        $none = [];

        return (int) stream_select($read, $none, $none, 0) > 0;
    }
}
