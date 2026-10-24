<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

/**
 * Where an SMSC listens. TLS is the default and is always verified against the host's name; plain TCP has to be asked
 * for, because SMPP sends the password in clear.
 */
final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public bool $tls = true,
        public ?string $caFile = null,
    ) {
    }

    public function uri(): string
    {
        return ($this->tls ? 'tls://' : 'tcp://') . $this->host . ':' . $this->port;
    }

    /** @return array<string, bool|string> */
    public function sslOptions(): array
    {
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'allow_self_signed' => false,
        ];
        if (null !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        return $options;
    }
}
