<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit;

use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindMode;
use CoolMS\Smpp\Session;
use CoolMS\Smpp\Tests\Support\FakeSmsc;
use CoolMS\Smpp\Transport\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;
use function print_r;
use function var_export;

/** The bind password reaches the wire and nowhere else: not a stack trace, a dump, or the session's log. */
final class SecretsStayOutOfTracesTest extends TestCase
{
    private const string PASSWORD = 'Pw9xQ!77z';

    #[Test]
    public function aPasswordTooLongForItsFieldIsNotInTheTrace(): void
    {
        try {
            new Bind('esme', self::PASSWORD)->encode();
            self::fail('a 9-character password was encoded');
        } catch (MalformedPdu $error) {
            self::assertStringNotContainsString(self::PASSWORD, var_export($error->getTrace(), true));
            self::assertStringNotContainsString(self::PASSWORD, $error->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, (string) $error);
        }
    }

    #[Test]
    public function aBindDumpShowsNoPassword(): void
    {
        self::assertStringNotContainsString('s3cret', print_r(new Bind('esme', 's3cret'), true));
    }

    #[Test]
    public function theSessionLogNamesPdusNotTheirBodies(): void
    {
        $smsc = new FakeSmsc();
        $smsc->password = 's3cret';
        $lines = [];
        $session = new Session(new Connection($smsc->client, $smsc, $smsc->clock), $smsc->clock, log: static function (string $direction, string $line) use (&$lines): void {
            $lines[] = $direction . ' ' . $line;
        });
        $session->bind(BindMode::Transmitter, new Bind('esme', 's3cret'));

        self::assertSame(['C BindTransmitter seq=1 status=ESME_ROK (0x00000000)', 'S BindTransmitterResp seq=1 status=ESME_ROK (0x00000000)'], $lines);
        self::assertStringNotContainsString('s3cret', implode("\n", $lines));
    }
}
