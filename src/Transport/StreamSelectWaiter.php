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
        $micro = (int) (max(0.0, $seconds) * 1e6);

        return (int) @stream_select($read, $none, $none, intdiv($micro, 1_000_000), $micro % 1_000_000) > 0;
    }
}
