<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Exception;

use RuntimeException;

/** No answer came within the time allowed, measured on the monotonic clock. */
final class TimedOut extends RuntimeException implements SmppException
{
}
