<?php

declare(strict_types=1);

namespace CoolMS\Smpp;

use CoolMS\Smpp\Pdu\CommandStatus;

/**
 * How one submit_sm ended: the SMSC's status and message id; or no answer in time (timedOut); or the session ended
 * first (lost). In the last two there is no status, and the message may or may not have been accepted.
 */
final readonly class SubmitResult
{
    public function __construct(
        public int $sequence,
        public ?int $status,
        public string $messageId = '',
        public bool $timedOut = false,
        public bool $lost = false,
    ) {
    }

    public function isOk(): bool
    {
        return CommandStatus::Ok->value === $this->status;
    }

    /**
     * Whether sending again may succeed: the SMSC was busy, or there was no answer. A message with no answer may
     * still have been accepted, so resending risks a duplicate; that choice is the caller's.
     */
    public function isTransient(): bool
    {
        return null === $this->status || CommandStatus::isTransient($this->status);
    }
}
