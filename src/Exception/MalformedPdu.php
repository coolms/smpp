<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Exception;

use RuntimeException;

/**
 * Bytes that are not the PDU they claim to be: a length out of range, a C-octet string without its NUL or longer than
 * its field allows, a TLV that runs past the body. The connection is not trusted after one.
 */
final class MalformedPdu extends RuntimeException implements SmppException
{
}
