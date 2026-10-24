<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

/**
 * Waits until a stream can be read or written, or the time is up. The one place the library blocks: Io\Poll by default
 * (IoPollWaiter), stream_select() where it is wanted (StreamSelectWaiter), and in tests a fake SMSC and a fake clock.
 */
interface Waiter
{
    /** @param resource $stream */
    public function readable(mixed $stream, float $seconds): bool;

    /** @param resource $stream */
    public function writable(mixed $stream, float $seconds): bool;
}
