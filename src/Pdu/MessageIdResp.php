<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

/**
 * The body of submit_sm_resp and deliver_sm_resp (sections 4.4.2, 4.6.2): one message_id, at most 65 bytes with its
 * NUL. deliver_sm_resp leaves it empty. An error response may have no body at all, which reads as an empty id.
 */
final readonly class MessageIdResp
{
    public function __construct(public string $messageId)
    {
    }

    public static function decode(string $body): self
    {
        return new self('' === $body ? '' : new Reader($body)->cString('message_id', 65));
    }

    public function encode(): string
    {
        return new Writer()->cString($this->messageId, 65, 'message_id')->bytes();
    }
}
