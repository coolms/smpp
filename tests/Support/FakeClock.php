<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Support;

use CoolMS\Smpp\Transport\Clock;
use LogicException;

final class FakeClock implements Clock
{
    /** A test that waits longer than a simulated day is stuck, not slow: it fails instead of hanging. */
    private const float LIMIT = 86_400.0;

    private float $start;

    public function __construct(private float $now = 1000.0)
    {
        $this->start = $now;
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
        if ($this->now - $this->start > self::LIMIT) {
            throw new LogicException('The fake clock passed a day: the code under test waits forever.');
        }
    }
}
