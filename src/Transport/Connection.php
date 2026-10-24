<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

use CoolMS\Smpp\Exception\ConnectionClosed;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Exception\TimedOut;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\Reader;

use function fclose;
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
 * peer cannot make the client buffer what it declares. Reads and writes never block; the Waiter waits, with a
 * deadline both ways. On end of stream or a framing error the stream is closed: nothing after a bad frame can be
 * trusted to start on a PDU boundary.
 */
final class Connection
{
    /** The largest PDU read: a full submit_sm with a 64 KiB message_payload fits. */
    public const int DEFAULT_MAX_LENGTH = 70_000;

    private const int CHUNK = 65536;

    private string $buffer = '';

    /** The peer has closed: what is buffered is still handed out, then the connection ends. */
    private bool $ended = false;

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly Waiter $waiter = new IoPollWaiter(),
        private readonly Clock $clock = new MonotonicClock(),
        private readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
        private readonly float $writeTimeout = 10.0,
    ) {
        stream_set_blocking($stream, false);
    }

    public function write(Pdu $pdu): void
    {
        $bytes = $pdu->encode();
        $deadline = $this->clock->now() + $this->writeTimeout;
        while ('' !== $bytes) {
            $written = is_resource($this->stream) ? @fwrite($this->stream, $bytes) : false;
            if (false === $written) {
                $this->close();

                throw new ConnectionClosed('The connection closed while ' . $pdu->describe() . ' was being sent.');
            }
            if (0 === $written) {
                // The send buffer is full: wait until the SMSC reads, but not past the deadline.
                $left = $deadline - $this->clock->now();
                if ($left <= 0 || (!$this->waiter->writable($this->stream, $left) && $this->clock->now() >= $deadline)) {
                    $this->close();

                    throw new TimedOut('The SMSC did not read ' . $pdu->describe() . ' within ' . $this->writeTimeout . ' s.');
                }
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
            if ($this->ended) {
                $this->close();

                throw new ConnectionClosed('The SMSC closed the connection.');
            }
            $left = $deadline - $this->clock->now();
            if ($left <= 0 || (!$this->waiter->readable($this->stream, max(0.0, $left)) && $this->clock->now() >= $deadline)) {
                return null;
            }
        }
    }

    public function close(): void
    {
        $this->buffer = '';
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Everything readable now, not one chunk: a TLS record can leave decrypted bytes inside PHP, where a select on
     * the socket would not see them. Up to one largest PDU and a chunk, so a fast sender cannot grow the buffer
     * without bound; read() takes from the buffer and calls again before it waits, so the rest is read then.
     */
    private function fill(): void
    {
        if ($this->ended) {
            return;
        }
        if (!is_resource($this->stream)) {
            throw new ConnectionClosed('The connection is closed.');
        }
        while (strlen($this->buffer) < $this->maxLength + self::CHUNK) {
            $chunk = @fread($this->stream, self::CHUNK);
            if (false === $chunk || ('' === $chunk && feof($this->stream))) {
                $this->ended = true;

                return;
            }
            if ('' === $chunk) {
                return;
            }
            $this->buffer .= $chunk;
        }
    }

    private function take(): ?Pdu
    {
        if (strlen($this->buffer) < 4) {
            return null;
        }
        $length = Reader::unsigned('N', substr($this->buffer, 0, 4), 'command_length');
        if ($length < Pdu::HEADER_LENGTH || $length > $this->maxLength) {
            $this->close();

            throw new MalformedPdu('A PDU declared ' . $length . ' bytes; ' . Pdu::HEADER_LENGTH . ' to ' . $this->maxLength . ' are accepted. The connection is closed.');
        }
        if (strlen($this->buffer) < $length) {
            return null;
        }
        $pdu = Pdu::decode(substr($this->buffer, 0, $length));
        $this->buffer = substr($this->buffer, $length);

        return $pdu;
    }
}
