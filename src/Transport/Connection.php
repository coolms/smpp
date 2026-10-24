<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

use CoolMS\Smpp\Exception\ConnectionClosed;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\Reader;

use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function max;
use function stream_set_blocking;
use function strlen;
use function substr;

/**
 * PDUs over one stream: framed by command_length, which is checked against the limit before the body is read, so a
 * peer cannot make the client buffer what it declares. Reads never block: the Waiter waits.
 */
final class Connection
{
    /** The largest PDU read: a full submit_sm with a 64 KiB message_payload fits. */
    public const int DEFAULT_MAX_LENGTH = 70_000;

    private string $buffer = '';

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly Waiter $waiter = new StreamSelectWaiter(),
        private readonly Clock $clock = new MonotonicClock(),
        private readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
    ) {
        stream_set_blocking($stream, false);
    }

    public function write(Pdu $pdu): void
    {
        $bytes = $pdu->encode();
        while ('' !== $bytes) {
            $written = is_resource($this->stream) ? @fwrite($this->stream, $bytes) : false;
            if (false === $written) {
                throw new ConnectionClosed('The connection closed while ' . $pdu->describe() . ' was being sent.');
            }
            if (0 === $written) {
                // A full send buffer on a non-blocking socket: give the peer a moment rather than spin.
                $this->waiter->readable($this->stream, 0.001);
                continue;
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** The next whole PDU, or null when none arrived within $seconds. */
    public function read(float $seconds): ?Pdu
    {
        $deadline = $this->clock->now() + $seconds;
        while (true) {
            $pdu = $this->take();
            if (null !== $pdu) {
                return $pdu;
            }
            $this->fill();
            $pdu = $this->take();
            if (null !== $pdu) {
                return $pdu;
            }
            $left = $deadline - $this->clock->now();
            if ($left <= 0 || !$this->waiter->readable($this->stream, max(0.0, $left))) {
                if ($this->clock->now() >= $deadline) {
                    return null;
                }
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function fill(): void
    {
        if (!is_resource($this->stream)) {
            throw new ConnectionClosed('The connection is closed.');
        }
        $chunk = @fread($this->stream, 65536);
        if (false === $chunk || ('' === $chunk && feof($this->stream))) {
            throw new ConnectionClosed('The SMSC closed the connection.');
        }
        $this->buffer .= $chunk;
    }

    private function take(): ?Pdu
    {
        if (strlen($this->buffer) < 4) {
            return null;
        }
        $length = Reader::unsigned('N', substr($this->buffer, 0, 4), 'command_length');
        if ($length < Pdu::HEADER_LENGTH || $length > $this->maxLength) {
            throw new MalformedPdu('A PDU declared ' . $length . ' bytes; ' . Pdu::HEADER_LENGTH . ' to ' . $this->maxLength . ' are accepted.');
        }
        if (strlen($this->buffer) < $length) {
            return null;
        }
        $pdu = Pdu::decode(substr($this->buffer, 0, $length));
        $this->buffer = substr($this->buffer, $length);

        return $pdu;
    }
}
