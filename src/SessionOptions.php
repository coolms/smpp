<?php

declare(strict_types=1);

namespace CoolMS\Smpp;

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
        /** After ESME_RTHROTTLED or ESME_RMSGQFUL, no request is sent for this long; it doubles while it repeats. */
        public float $throttleBackoff = 1.0,
        public float $maxThrottleBackoff = 30.0,
        /** The first sequence number used: 1 unless a test needs to see the wrap at 0x7FFFFFFF. */
        public int $firstSequence = 1,
    ) {
    }
}
