<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Exception;

use RuntimeException;

/**
 * Well-formed PDUs in an order SMPP 3.4 does not allow: a response nobody asked for, a request the session state
 * forbids (a deliver_sm before a receiver bind), a bind answered by another command.
 */
final class ProtocolError extends RuntimeException implements SmppException
{
}
