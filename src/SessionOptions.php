<?php

declare(strict_types=1);

namespace CoolMS\Smpp;

use CoolMS\Smpp\Pdu\Pdu;
use InvalidArgumentException;

/** How a session paces itself. Times are seconds on the monotonic clock. */
final readonly class SessionOptions
{
    public function __construct(
        /** Requests outstanding at once (section 2.7: the window); further sends wait for a response. */
        public int $windowSize = 10,
        /** How long a request waits for its response before it is reported timed out. */
        public float $responseTimeout = 10.0,
        /** After this long with nothing sent or received, the session asks enquire_link to keep the link alive. */
        public float $enquireLinkInterval = 30.0,
        /**
         * After ESME_RTHROTTLED or ESME_RMSGQFUL, no request is sent for this long. A further throttle after the
         * pause has ended doubles it, up to the maximum; a success sets it back.
         */
        public float $throttleBackoff = 1.0,
        public float $maxThrottleBackoff = 30.0,
        /** The first sequence number used: 1 unless a test needs to see the wrap at 0x7FFFFFFF. */
        public int $firstSequence = 1,
    ) {
        if ($windowSize < 1) {
            throw new InvalidArgumentException('The window holds at least one request.');
        }
        if ($responseTimeout <= 0 || $enquireLinkInterval <= 0 || $throttleBackoff < 0 || $maxThrottleBackoff < $throttleBackoff) {
            throw new InvalidArgumentException('Times are positive, and the maximum backoff is at least the first.');
        }
        if ($firstSequence < 1 || $firstSequence > Pdu::MAX_SEQUENCE) {
            throw new InvalidArgumentException('A sequence number is 1 to 0x7FFFFFFF.');
        }
    }
}
