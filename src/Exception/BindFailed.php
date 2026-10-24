<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Exception;

use CoolMS\Smpp\Pdu\CommandStatus;
use RuntimeException;

/** The SMSC answered the bind with an error status: a wrong password (ESME_RINVPASWD), an unknown system id, and so on. */
final class BindFailed extends RuntimeException implements SmppException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('The SMSC refused the bind: ' . CommandStatus::describe($status) . '.', $status);
    }
}
