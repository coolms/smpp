<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Exception;

use RuntimeException;

/** The stream ended, or a write could not complete: the session is over. */
final class ConnectionClosed extends RuntimeException implements SmppException
{
}
