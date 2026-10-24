<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

use function intdiv;
use function max;
use function stream_select;

final class StreamSelectWaiter implements Waiter
{
    public function readable(mixed $stream, float $seconds): bool
    {
        $read = [$stream];
        $none = [];

        return $this->select($read, $none, $seconds);
    }

    public function writable(mixed $stream, float $seconds): bool
    {
        $none = [];
        $write = [$stream];

        return $this->select($none, $write, $seconds);
    }

    /**
     * @param array<resource> $read
     * @param array<resource> $write
     */
    private function select(array $read, array $write, float $seconds): bool
    {
        $except = [];
        $micro = (int) (max(0.0, $seconds) * 1e6);

        return (int) @stream_select($read, $write, $except, intdiv($micro, 1_000_000), $micro % 1_000_000) > 0;
    }
}
