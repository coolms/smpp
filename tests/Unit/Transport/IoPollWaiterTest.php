<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit\Transport;

use CoolMS\Smpp\Transport\IoPollWaiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function fread;
use function fwrite;
use function hrtime;
use function str_repeat;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class IoPollWaiterTest extends TestCase
{
    /** @var resource */
    private mixed $client;

    /** @var resource */
    private mixed $peer;

    #[Test]
    public function aQuietStreamIsNotReadableWithinTheWait(): void
    {
        self::assertFalse(new IoPollWaiter()->readable($this->client, 0.05));
    }

    #[Test]
    public function aQuietStreamIsWaitedOnForTheWholeWait(): void
    {
        $start = hrtime(true);
        new IoPollWaiter()->readable($this->client, 0.2);

        self::assertGreaterThan(0.15, (hrtime(true) - $start) / 1e9, 'the wait returned before its time');
    }

    #[Test]
    public function aStreamWithDataIsReadable(): void
    {
        fwrite($this->peer, 'x');

        self::assertTrue(new IoPollWaiter()->readable($this->client, 1.0));
    }

    /**
     * PHP 8.6's own Io\Poll does not see bytes PHP already read from the socket; the waiter asks the stream first.
     * Under the polyfill (stream_select()) this passes with or without that check, since stream_select() counts the
     * buffer itself: the case guards the behaviour on 8.6, where the two differ.
     */
    #[Test]
    public function bytesPhpAlreadyHoldsAreReadableWithoutWaiting(): void
    {
        fwrite($this->peer, 'xy');
        fread($this->client, 1);
        self::assertGreaterThan(0, stream_get_meta_data($this->client)['unread_bytes'], 'the second byte waits in PHP');

        self::assertTrue(new IoPollWaiter()->readable($this->client, 0.0));
    }

    #[Test]
    public function aPeerThatHungUpMakesTheStreamReadableSoTheReadCanSaySo(): void
    {
        fclose($this->peer);

        self::assertTrue(new IoPollWaiter()->readable($this->client, 1.0));
    }

    #[Test]
    public function aStreamWithRoomToWriteIsWritable(): void
    {
        self::assertTrue(new IoPollWaiter()->writable($this->client, 1.0));
    }

    #[Test]
    public function aFullSendBufferIsNotWritableWithinTheWait(): void
    {
        stream_set_blocking($this->client, false);
        while (fwrite($this->client, str_repeat('x', 65536)) > 0) {
            // Fill the send buffer: nobody reads the other end.
        }

        self::assertFalse(new IoPollWaiter()->writable($this->client, 0.05));
    }

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $pair) {
            throw new RuntimeException('no socket pair');
        }
        [$this->client, $this->peer] = $pair;
    }
}
