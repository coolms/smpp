<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use CoolMS\Smpp\Exception\MalformedPdu;

use function pack;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * One SMPP PDU: the 16-byte header (command_length, command_id, command_status, sequence_number; section 3.2) and the
 * body, kept as bytes. The command id is an int, because a PDU with an id SMPP 3.4 does not define must still be read
 * -- to be answered with generic_nack and ESME_RINVCMDID -- rather than end the session.
 */
final readonly class Pdu
{
    public const int HEADER_LENGTH = 16;

    /** A sequence number is 1 to 0x7FFFFFFF (section 3.2). */
    public const int MAX_SEQUENCE = 0x7FFFFFFF;

    public function __construct(
        public int $commandId,
        public int $status,
        public int $sequence,
        public string $body = '',
    ) {
        if ($sequence < 0 || $sequence > self::MAX_SEQUENCE) {
            throw new MalformedPdu(sprintf('A sequence number is 0 to 0x%08X; %d is not.', self::MAX_SEQUENCE, $sequence));
        }
    }

    public static function request(CommandId $id, int $sequence, string $body = ''): self
    {
        return new self($id->value, CommandStatus::Ok->value, $sequence, $body);
    }

    /** The response to this request, with the same sequence number. */
    public function respond(int $status = 0, string $body = ''): self
    {
        $response = $this->command()?->response();
        if (null === $response) {
            throw new MalformedPdu('0x' . sprintf('%08X', $this->commandId) . ' is not a request that is answered.');
        }

        return new self($response->value, $status, $this->sequence, $body);
    }

    /** The command, or null for an id SMPP 3.4 does not define. */
    public function command(): ?CommandId
    {
        return CommandId::tryFrom($this->commandId);
    }

    public function isResponse(): bool
    {
        return 0 !== ($this->commandId & 0x80000000);
    }

    public function encode(): string
    {
        return pack('NNNN', self::HEADER_LENGTH + strlen($this->body), $this->commandId, $this->status, $this->sequence) . $this->body;
    }

    /**
     * One whole PDU, as framed by its command_length. $bytes must be exactly that long; the transport reads the
     * length first and refuses one above its limit before reading the rest.
     */
    public static function decode(string $bytes): self
    {
        if (strlen($bytes) < self::HEADER_LENGTH) {
            throw new MalformedPdu('A PDU is at least 16 bytes; this one is ' . strlen($bytes) . '.');
        }
        /** @var array{length: int, id: int, status: int, sequence: int} $header */
        $header = unpack('Nlength/Nid/Nstatus/Nsequence', $bytes);
        if ($header['length'] !== strlen($bytes)) {
            throw new MalformedPdu('command_length says ' . $header['length'] . ' bytes; ' . strlen($bytes) . ' were given.');
        }

        return new self($header['id'], $header['status'], $header['sequence'], substr($bytes, self::HEADER_LENGTH));
    }

    public function describe(): string
    {
        return sprintf(
            '%s seq=%d status=%s',
            $this->command()->name ?? sprintf('0x%08X', $this->commandId),
            $this->sequence,
            CommandStatus::describe($this->status),
        );
    }
}
