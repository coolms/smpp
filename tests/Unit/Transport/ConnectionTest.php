<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit\Transport;

use CoolMS\Smpp\Exception\ConnectionClosed;
use CoolMS\Smpp\Exception\CouldNotOpen;
use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Tests\Support\FakeClock;
use CoolMS\Smpp\Transport\Connection;
use CoolMS\Smpp\Transport\Endpoint;
use CoolMS\Smpp\Transport\Opener;
use CoolMS\Smpp\Transport\Waiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function fwrite;
use function pack;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class ConnectionTest extends TestCase
{
    /** @var resource */
    private mixed $client;

    /** @var resource */
    private mixed $peer;

    private FakeClock $clock;

    #[Test]
    public function aPduSplitAcrossReadsIsReassembled(): void
    {
        $bytes = Pdu::request(CommandId::EnquireLink, 3)->encode();
        $chunks = [substr($bytes, 0, 2), substr($bytes, 2, 9), substr($bytes, 11)];
        $connection = new Connection($this->client, $this->feeding($chunks), $this->clock);

        self::assertSame(3, $connection->read(5.0)?->sequence);
    }

    #[Test]
    public function twoPdusInOneReadComeOneAtATime(): void
    {
        fwrite($this->peer, Pdu::request(CommandId::EnquireLink, 1)->encode() . Pdu::request(CommandId::EnquireLink, 2)->encode());
        $connection = new Connection($this->client, $this->feeding([]), $this->clock);

        $first = $connection->read(1.0);
        $second = $connection->read(1.0);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame([1, 2], [$first->sequence, $second->sequence]);
    }

    #[Test]
    public function anOversizedLengthIsRefusedBeforeTheBodyIsRead(): void
    {
        fwrite($this->peer, pack('N', 1_000_000));
        $connection = new Connection($this->client, $this->feeding([]), $this->clock);
        $this->expectException(MalformedPdu::class);
        $connection->read(1.0);
    }

    #[Test]
    public function aLengthBelowTheHeaderIsRefused(): void
    {
        fwrite($this->peer, pack('N', 12) . "\0\0\0\0\0\0\0\0");
        $connection = new Connection($this->client, $this->feeding([]), $this->clock);
        $this->expectException(MalformedPdu::class);
        $connection->read(1.0);
    }

    #[Test]
    public function nothingWithinTheTimeIsNull(): void
    {
        $connection = new Connection($this->client, $this->feeding([]), $this->clock);

        self::assertNull($connection->read(2.0));
        self::assertGreaterThanOrEqual(1002.0, $this->clock->now());
    }

    #[Test]
    public function aClosedPeerEndsTheConnection(): void
    {
        fclose($this->peer);
        $connection = new Connection($this->client, $this->feeding([]), $this->clock);
        $this->expectException(ConnectionClosed::class);
        $connection->read(1.0);
    }

    #[Test]
    public function theOpenerConnectsOverTcp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $name);
        $connection = new Opener()->open(new Endpoint($host, (int) $port, tls: false), 2.0);
        $accepted = stream_socket_accept($server, 2.0);
        self::assertNotFalse($accepted);
        fwrite($accepted, Pdu::request(CommandId::EnquireLink, 4)->encode());

        self::assertSame(4, $connection->read(2.0)?->sequence);
    }

    #[Test]
    public function aRefusedConnectionSaysWhere(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        [$host, $port] = explode(':', (string) stream_socket_get_name($server, false));
        fclose($server);
        $this->expectException(CouldNotOpen::class);
        $this->expectExceptionMessage('127.0.0.1:' . $port);
        new Opener()->open(new Endpoint($host, (int) $port, tls: false), 1.0);
    }

    #[Test]
    public function tlsIsTheDefaultAndVerifiesThePeer(): void
    {
        $endpoint = new Endpoint('smsc.example.test', 3550);

        self::assertTrue($endpoint->tls);
        self::assertSame('tls://smsc.example.test:3550', $endpoint->uri());
        self::assertSame(['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'smsc.example.test', 'allow_self_signed' => false], $endpoint->sslOptions());
    }

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $pair) {
            throw new RuntimeException('no socket pair');
        }
        [$this->client, $this->peer] = $pair;
        $this->clock = new FakeClock();
    }

    /** @param list<string> $chunks written to the peer one per wait, as a slow network would deliver them */
    private function feeding(array $chunks): Waiter
    {
        return new class($this->peer, $chunks, $this->clock) implements Waiter {
            /**
             * @param resource     $peer
             * @param list<string> $chunks
             */
            public function __construct(private mixed $peer, private array $chunks, private FakeClock $clock)
            {
            }

            public function readable(mixed $stream, float $seconds): bool
            {
                if ([] === $this->chunks) {
                    $this->clock->advance($seconds);

                    return false;
                }
                fwrite($this->peer, array_shift($this->chunks));

                return true;
            }

            public function writable(mixed $stream, float $seconds): bool
            {
                return true;
            }
        };
    }
}
