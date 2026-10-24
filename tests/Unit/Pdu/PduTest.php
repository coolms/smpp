<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit\Pdu;

use CoolMS\Smpp\Exception\MalformedPdu;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindResp;
use CoolMS\Smpp\Pdu\CommandId;
use CoolMS\Smpp\Pdu\CommandStatus;
use CoolMS\Smpp\Pdu\MessageIdResp;
use CoolMS\Smpp\Pdu\Pdu;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Pdu\Tag;
use CoolMS\Smpp\Pdu\Tlv;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function hex2bin;
use function str_repeat;
use function str_replace;

/** Byte-exact vectors, worked out by hand from the field tables of SMPP 3.4 sections 3.2, 4.1, 4.4 and 4.6. */
final class PduTest extends TestCase
{
    #[Test]
    public function aBindTransmitterIsTheHeaderThenSevenFieldsInOrder(): void
    {
        $pdu = Pdu::request(CommandId::BindTransmitter, 1, new Bind('SMPP3TEST', 'secret08', 'SUBMIT1', 0x34, 1, 1)->encode());

        self::assertSame(self::hex('
            0000002F 00000002 00000000 00000001
            534D5050335445535400
            736563726574303800
            5355424D49543100
            34 01 01 00
        '), bin2hex($pdu->encode()));
    }

    #[Test]
    public function aBindReadsBackAndChecksItsPasswordWithoutShowingIt(): void
    {
        $bind = Bind::decode(new Bind('esme', 'pw', 'cp', 0x34, 5, 0, '^447')->encode());

        self::assertSame(['esme', 'cp', 0x34, 5, 0, '^447'], [$bind->systemId, $bind->systemType, $bind->interfaceVersion, $bind->addressTon, $bind->addressNpi, $bind->addressRange]);
        self::assertTrue($bind->passwordIs('pw'));
        self::assertFalse($bind->passwordIs('PW'));
        self::assertStringNotContainsString('pw', print_r($bind, true));
    }

    #[Test]
    public function aSubmitSmIsEveryMandatoryFieldThenTheTlvs(): void
    {
        $body = new ShortMessage(
            sourceAddr: 'Shop',
            destinationAddr: '447700900123',
            shortMessage: 'Hi',
            registeredDelivery: ShortMessage::RECEIPT_ON_FINAL,
            sourceTon: 5,
            sourceNpi: 0,
            tlvs: [Tlv::uint16(Tag::UserMessageReference, 7)],
        )->encode();

        self::assertSame(self::hex('
            00
            05 00 53686F7000
            01 01 34343737303039303031323300
            00 00 00
            00
            00
            01 00 00 00
            02 4869
            0204 0002 0007
        '), bin2hex($body));
        self::assertSame(['Shop', '447700900123', 'Hi', 1, 7], [
            ShortMessage::decode($body)->sourceAddr,
            ShortMessage::decode($body)->destinationAddr,
            ShortMessage::decode($body)->shortMessage,
            ShortMessage::decode($body)->registeredDelivery,
            ShortMessage::decode($body)->tlv(Tag::UserMessageReference)?->asInt(),
        ]);
    }

    #[Test]
    public function aResponseCarriesItsRequestsSequenceAndTheResponseBit(): void
    {
        $request = Pdu::request(CommandId::SubmitSm, 0x7FFFFFFF);
        $response = $request->respond(CommandStatus::Throttled->value);

        self::assertSame(self::hex('00000010 80000004 00000058 7FFFFFFF'), bin2hex($response->encode()));
        self::assertTrue($response->isResponse());
        self::assertSame(CommandId::SubmitSmResp, $response->command());
    }

    #[Test]
    public function genericNackAndEnquireLinkAreHeadersOnly(): void
    {
        self::assertSame(self::hex('00000010 00000015 00000000 00000009'), bin2hex(Pdu::request(CommandId::EnquireLink, 9)->encode()));
        self::assertSame(self::hex('00000010 80000000 00000003 00000004'), bin2hex(new Pdu(CommandId::GenericNack->value, 3, 4)->encode()));
    }

    #[Test]
    public function decodingTakesExactlyWhatTheLengthSays(): void
    {
        $bytes = hex2bin(self::hex('00000011 80000004 00000000 00000002 00'));
        self::assertNotFalse($bytes);
        $pdu = Pdu::decode($bytes);

        self::assertSame([CommandId::SubmitSmResp, 0, 2, ''], [$pdu->command(), $pdu->status, $pdu->sequence, MessageIdResp::decode($pdu->body)->messageId]);
        $this->expectException(MalformedPdu::class);
        Pdu::decode($bytes . 'x');
    }

    #[Test]
    public function anUnknownCommandIdIsStillReadSoItCanBeNacked(): void
    {
        $bytes = hex2bin(self::hex('00000010 00000777 00000000 00000005'));
        self::assertNotFalse($bytes);
        $pdu = Pdu::decode($bytes);

        self::assertNull($pdu->command());
        self::assertSame(0x777, $pdu->commandId);
    }

    #[Test]
    public function aCStringMayNotExceedItsFieldWithTheNul(): void
    {
        // system_id is 16 octets with its NUL: 15 characters fit, 16 do not.
        new Bind(str_repeat('a', 15), 'p')->encode();
        $this->expectException(MalformedPdu::class);
        new Bind(str_repeat('a', 16), 'p')->encode();
    }

    #[Test]
    public function aBodyWithoutItsNulOrRunningShortIsRefused(): void
    {
        foreach (["abc", "abc\0pw\0", "abc\0pw\0\0\x34\x01"] as $body) {
            try {
                Bind::decode($body);
                self::fail('read a truncated bind: ' . bin2hex($body));
            } catch (MalformedPdu) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function aTlvRunningPastTheBodyIsRefused(): void
    {
        $body = new ShortMessage('a', 'b')->encode() . hex2bin('04240005414243');
        $this->expectException(MalformedPdu::class);
        ShortMessage::decode($body);
    }

    #[Test]
    public function aBindRespMayBeEmptyOnErrorAndNamesItsInterfaceVersion(): void
    {
        self::assertSame('', BindResp::decode('')->systemId);
        $resp = BindResp::decode(new BindResp('SMSC', [Tlv::uint8(Tag::ScInterfaceVersion, 0x34)])->encode());
        self::assertSame(['SMSC', 0x34], [$resp->systemId, $resp->interfaceVersion()]);
    }

    #[Test]
    public function aShortMessageAbove254OctetsIsRefused(): void
    {
        $this->expectException(MalformedPdu::class);
        new ShortMessage('a', 'b', str_repeat('x', 255));
    }

    #[Test]
    public function statusesAreNamedAndTheTransientOnesKnown(): void
    {
        self::assertSame('ESME_RTHROTTLED (0x00000058)', CommandStatus::describe(0x58));
        self::assertSame('0x00000401', CommandStatus::describe(0x401));
        self::assertTrue(CommandStatus::isTransient(0x58));
        self::assertTrue(CommandStatus::isTransient(0x14));
        self::assertFalse(CommandStatus::isTransient(0x0B));
        self::assertFalse(CommandStatus::isTransient(0x401));
    }

    /** Hex from a readable layout: whitespace dropped, lower-cased. */
    private static function hex(string $layout): string
    {
        return strtolower(str_replace([' ', "\n", "\r", "\t"], '', $layout));
    }
}
