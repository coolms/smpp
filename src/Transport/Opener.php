<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

use CoolMS\Smpp\Exception\CouldNotOpen;

use function stream_context_create;
use function stream_socket_client;

use const STREAM_CLIENT_CONNECT;

final readonly class Opener
{
    public function __construct(
        private Waiter $waiter = new StreamSelectWaiter(),
        private Clock $clock = new MonotonicClock(),
        private int $maxPduLength = Connection::DEFAULT_MAX_LENGTH,
    ) {
    }

    public function open(Endpoint $endpoint, float $timeout = 10.0): Connection
    {
        $context = stream_context_create(['ssl' => $endpoint->sslOptions()]);
        $stream = @stream_socket_client($endpoint->uri(), $code, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (false === $stream) {
            throw new CouldNotOpen('Could not connect to ' . $endpoint->host . ':' . $endpoint->port . ': ' . ('' !== $message ? $message : 'error ' . $code) . '.');
        }

        return new Connection($stream, $this->waiter, $this->clock, $this->maxPduLength);
    }
}
