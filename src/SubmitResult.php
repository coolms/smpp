<?php

declare(strict_types=1);

namespace CoolMS\Smpp;

use CoolMS\Smpp\Pdu\CommandStatus;

/** How one submit_sm ended: the SMSC's status and message id, or no answer in time. */
final readonly class SubmitResult
{
    public function __construct(
        public int $sequence,
        public int $status,
        public string $messageId = '',
        public bool $timedOut = false,
    ) {
    }

    public function isOk(): bool
    {
        return !$this->timedOut && CommandStatus::Ok->value === $this->status;
    }

    /**
     * Whether sending again may succeed: the SMSC was busy, or it did not answer. A timed-out message may still have
     * been accepted, so resending risks a duplicate; that choice is the caller's.
     */
    public function isTransient(): bool
    {
        return $this->timedOut || CommandStatus::isTransient($this->status);
    }
}
