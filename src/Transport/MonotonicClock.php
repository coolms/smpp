<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

use function hrtime;

final class MonotonicClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
