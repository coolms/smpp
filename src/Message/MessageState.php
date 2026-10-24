<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Message;

use function strtoupper;
use function trim;

/** A message's state (SMPP 3.4 section 5.2.28, the message_state TLV), read from a receipt's TLV or its "stat:". */
enum MessageState: int
{
    case Enroute = 1;
    case Delivered = 2;
    case Expired = 3;
    case Deleted = 4;
    case Undeliverable = 5;
    case Accepted = 6;
    case Unknown = 7;
    case Rejected = 8;
    case Skipped = 9;

    /** A receipt's "stat:" word: the Appendix B abbreviations, the full words some SMSCs write, else Unknown. */
    public static function fromText(string $word): self
    {
        return match (strtoupper(trim($word))) {
            'ENROUTE' => self::Enroute,
            'DELIVRD', 'DELIVERED' => self::Delivered,
            'EXPIRED' => self::Expired,
            'DELETED' => self::Deleted,
            'UNDELIV', 'UNDELIVERABLE' => self::Undeliverable,
            'ACCEPTD', 'ACCEPTED' => self::Accepted,
            'REJECTD', 'REJECTED' => self::Rejected,
            'SKIPPED' => self::Skipped,
            default => self::Unknown,
        };
    }

    /** Whether nothing more will happen to the message: every state but en route, accepted and unknown. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Enroute, self::Accepted, self::Unknown => false,
            default => true,
        };
    }
}
