<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Exception;

use RuntimeException;

/** The TCP (or TLS) connection to the SMSC could not be opened. */
final class CouldNotOpen extends RuntimeException implements SmppException
{
}
