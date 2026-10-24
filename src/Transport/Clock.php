<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

/** Monotonic seconds: what timeouts and keepalives are measured on, never the wall clock, which steps. */
interface Clock
{
    public function now(): float;
}
