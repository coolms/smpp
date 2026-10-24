<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

/**
 * Waits until a stream has bytes to read, or the time is up. The one place the library blocks: stream_select today;
 * an Io\Poll implementation drops in here, and tests put a fake SMSC and a fake clock behind it.
 */
interface Waiter
{
    /** @param resource $stream */
    public function readable(mixed $stream, float $seconds): bool;
}
