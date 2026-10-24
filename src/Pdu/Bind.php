<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use SensitiveParameter;

/**
 * The body of bind_transmitter, bind_receiver and bind_transceiver (section 4.1.1). The password is at most 8
 * characters (9 with its NUL); it never appears in a trace, a dump or describe().
 */
final readonly class Bind
{
    public const int INTERFACE_VERSION = 0x34;

    public function __construct(
        public string $systemId,
        #[SensitiveParameter]
        private string $password,
        public string $systemType = '',
        public int $interfaceVersion = self::INTERFACE_VERSION,
        public int $addressTon = 0,
        public int $addressNpi = 0,
        public string $addressRange = '',
    ) {
    }

    public function encode(): string
    {
        return new Writer()
            ->cString($this->systemId, 16, 'system_id')
            ->cString($this->password, 9, 'password')
            ->cString($this->systemType, 13, 'system_type')
            ->uint8($this->interfaceVersion, 'interface_version')
            ->uint8($this->addressTon, 'addr_ton')
            ->uint8($this->addressNpi, 'addr_npi')
            ->cString($this->addressRange, 41, 'address_range')
            ->bytes();
    }

    /** An SMSC-side read, for tests and simulators; the password is checked with passwordIs(), never returned. */
    public static function decode(string $body): self
    {
        $reader = new Reader($body);

        return new self(
            $reader->cString('system_id', 16),
            $reader->cString('password', 9),
            $reader->cString('system_type', 13),
            $reader->uint8('interface_version'),
            $reader->uint8('addr_ton'),
            $reader->uint8('addr_npi'),
            $reader->cString('address_range', 41),
        );
    }

    public function passwordIs(#[SensitiveParameter] string $password): bool
    {
        return hash_equals($this->password, $password);
    }

    /** @return array<string, mixed> what a dump of this object shows: never the password */
    public function __debugInfo(): array
    {
        return ['systemId' => $this->systemId, 'password' => '<secret>', 'systemType' => $this->systemType];
    }
}
