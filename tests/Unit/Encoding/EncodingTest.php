<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Tests\Unit\Encoding;

use CoolMS\Smpp\Encoding\DataCoding;
use CoolMS\Smpp\Encoding\Gsm7;
use CoolMS\Smpp\Encoding\Latin1;
use CoolMS\Smpp\Encoding\Ucs2;
use CoolMS\Smpp\Exception\MalformedPdu;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function hex2bin;

/** GSM 03.38 (3GPP TS 23.038 section 6.2.1 and its extension table 6.2.1.1), ISO-8859-1 and UCS-2. */
final class EncodingTest extends TestCase
{
    #[Test]
    public function gsm7MapsTheBasicTableOneSeptetPerCharacter(): void
    {
        // '@' 0x00, '£' 0x01, '¡' 0x40, 'A' 0x41, 'Δ' 0x10, 'ß' 0x1E, 'à' 0x7F, '\n' 0x0A.
        self::assertSame('00014041101e7f0a', bin2hex(Gsm7::encode("@£¡AΔßà\n")));
    }

    #[Test]
    public function gsm7WritesExtensionCharactersAsEscapeAndCode(): void
    {
        // '{' ESC 0x28, '€' ESC 0x65, '^' ESC 0x14, '|' ESC 0x40, '[' ESC 0x3C, '\' ESC 0x2F.
        self::assertSame('1b281b651b141b401b3c1b2f', bin2hex(Gsm7::encode('{€^|[\\')));
        self::assertSame('{€^|[\\', Gsm7::decode(Gsm7::encode('{€^|[\\')));
    }

    #[Test]
    public function gsm7CountsAnExtensionCharacterAsTwoSeptets(): void
    {
        self::assertSame(5, Gsm7::septets('Hello'));
        self::assertSame(7, Gsm7::septets('€ & {'));
    }

    #[Test]
    public function gsm7KnowsWhatItCannotEncode(): void
    {
        self::assertTrue(Gsm7::canEncode('Hello, world! {1} 50€ @ä'));
        self::assertFalse(Gsm7::canEncode('Привет'));
        self::assertFalse(Gsm7::canEncode('é and ê'), 'ê is not in GSM 03.38; é is');
        self::assertFalse(Gsm7::canEncode('emoji 🙂'));
        $this->expectException(MalformedPdu::class);
        Gsm7::encode('ê');
    }

    #[Test]
    public function gsm7PacksSeptetsIntoOctets(): void
    {
        // The classic 3GPP example: "hellohello" in 9 octets.
        self::assertSame('e8329bfd4697d9ec37', bin2hex(Gsm7::pack(Gsm7::encode('hellohello'))));
        self::assertSame('hellohello', Gsm7::decode(Gsm7::unpack((string) hex2bin('e8329bfd4697d9ec37'), 10)));
    }

    #[Test]
    public function gsm7PacksAfterAUdhWithFillBits(): void
    {
        // After a 6-octet UDH (48 bits) the text starts on a septet boundary: 49 bits, so one fill bit leads.
        $packed = Gsm7::pack(Gsm7::encode('hi'), 1);
        self::assertSame('hi', Gsm7::decode(Gsm7::unpack($packed, 2, 1)));
        self::assertSame('d069', bin2hex($packed));
    }

    #[Test]
    public function gsm7DecodesAnUnknownEscapeAsASpaceLikeHandsetsDo(): void
    {
        // 23.038: an undefined extension code is shown as the basic table's character, or a space; never dropped.
        self::assertSame('a b', Gsm7::decode("\x61\x1B\x7F\x62"));
    }

    #[Test]
    public function latin1RoundTripsAndRefusesWhatItCannotHold(): void
    {
        self::assertSame('e9e46e', bin2hex(Latin1::encode('éän')));
        self::assertSame('éän', Latin1::decode((string) hex2bin('e9e46e')));
        self::assertTrue(Latin1::canEncode('Grüße, señor'));
        self::assertFalse(Latin1::canEncode('€'));
        $this->expectException(MalformedPdu::class);
        Latin1::encode('€');
    }

    #[Test]
    public function ucs2IsUtf16BigEndianWithSurrogatePairs(): void
    {
        self::assertSame('041f04400438', bin2hex(Ucs2::encode('При')));
        self::assertSame('d83dde42', bin2hex(Ucs2::encode('🙂')));
        self::assertSame('При🙂', Ucs2::decode((string) hex2bin('041f04400438d83dde42')));
        self::assertSame(2, Ucs2::units('🙂'));
    }

    #[Test]
    public function ucs2RefusesAnOddLength(): void
    {
        $this->expectException(MalformedPdu::class);
        Ucs2::decode("\x04\x1F\x04");
    }

    #[Test]
    public function dataCodingChoosesGsmWhenItFitsElseUcs2(): void
    {
        self::assertSame(DataCoding::Default, DataCoding::bestFor('Your code is 123456'));
        self::assertSame(DataCoding::Ucs2, DataCoding::bestFor('Ваш код 123456'));
        self::assertSame(DataCoding::Latin1, DataCoding::bestFor('Café ê', allowLatin1: true));
        self::assertSame(DataCoding::Ucs2, DataCoding::bestFor('Café ê'));
    }

    #[Test]
    public function dataCodingDecodesWhatADeliverSmCarries(): void
    {
        self::assertSame('Hi €', DataCoding::Default->decode(Gsm7::encode('Hi €')));
        self::assertSame('Привет', DataCoding::Ucs2->decode(Ucs2::encode('Привет')));
        self::assertSame('Grüße', DataCoding::Latin1->decode(Latin1::encode('Grüße')));
        self::assertSame('plain', DataCoding::Ascii->decode('plain'));
        self::assertSame(DataCoding::Ucs2, DataCoding::fromByte(0x08));
        self::assertSame(DataCoding::Octet, DataCoding::fromByte(0xF5), 'a 0xF0-0xFF value with 8-bit set is octet data');
    }
}
