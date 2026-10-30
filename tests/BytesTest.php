<?php

declare(strict_types=1);

namespace Phaenon\Tests;

use OutOfRangeException;
use Phaenon\Bytes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

final class BytesTest extends TestCase
{
    /** Two bytes whose base64 uses both characters that differ between the alphabets. */
    private const string PLUS_SLASH = "\xFB\xFF";

    #[Test]
    public function fromHexDecodesEitherCase(): void
    {
        self::assertSame('Hello', Bytes::fromHex('48656c6c6f'));
        self::assertSame("\x00\xFF", Bytes::fromHex('00ff'));
        self::assertSame("\x00\xFF", Bytes::fromHex('00FF'));
        self::assertSame('', Bytes::fromHex(''));
    }

    #[Test]
    public function fromHexRejectsAnOddNumberOfDigits(): void
    {
        $this->expectException(ValueError::class);
        Bytes::fromHex('abc');
    }

    #[Test]
    public function fromHexRejectsNonHexCharacters(): void
    {
        $this->expectException(ValueError::class);
        Bytes::fromHex('zz');
    }

    #[Test]
    public function fromBase64DecodesBothAlphabets(): void
    {
        self::assertSame('Hello', Bytes::fromBase64('SGVsbG8='));
        self::assertSame('Hello', Bytes::fromBase64('SGVsbG8'));
        self::assertSame(self::PLUS_SLASH, Bytes::fromBase64('+/8='));
        self::assertSame(self::PLUS_SLASH, Bytes::fromBase64('-_8='));
        self::assertSame(self::PLUS_SLASH, Bytes::fromBase64('-_8'));
        self::assertSame('', Bytes::fromBase64(''));
    }

    #[Test]
    public function fromBase64RejectsCharactersOutsideTheAlphabet(): void
    {
        $this->expectException(ValueError::class);
        Bytes::fromBase64('SGVs!G8=');
    }

    #[Test]
    public function fromBase64RejectsBrokenPadding(): void
    {
        $this->expectException(ValueError::class);
        Bytes::fromBase64('SGVsbG8==');
    }

    #[Test]
    public function lengthAndIsEmptyCountBytesNotCharacters(): void
    {
        self::assertSame(0, Bytes::length(''));
        self::assertSame(3, Bytes::length("\x00\x01\x02"));
        self::assertSame(2, Bytes::length("\xC3\xA9"));
        self::assertTrue(Bytes::isEmpty(''));
        self::assertFalse(Bytes::isEmpty("\x00"));
    }

    #[Test]
    public function sliceFollowsSubstr(): void
    {
        $b = "\x00\x01\x02\x03\x04";
        self::assertSame("\x02\x03\x04", Bytes::slice($b, 2));
        self::assertSame("\x01\x02", Bytes::slice($b, 1, 2));
        self::assertSame("\x03\x04", Bytes::slice($b, -2));
        self::assertSame("\x01\x02\x03", Bytes::slice($b, 1, -1));
        self::assertSame('', Bytes::slice($b, 5));
        self::assertSame('', Bytes::slice('', 0, 2));
    }

    #[Test]
    public function concatJoinsInOrder(): void
    {
        self::assertSame("\x01\x02\x03", Bytes::concat("\x01", "\x02\x03"));
        self::assertSame("\x01", Bytes::concat('', "\x01", ''));
        self::assertSame('', Bytes::concat());
        self::assertSame('', Bytes::concat('', ''));
    }

    #[Test]
    public function hexIsLowercaseAndRoundTrips(): void
    {
        self::assertSame('48656c6c6f', Bytes::hex('Hello'));
        self::assertSame('00ff', Bytes::hex("\x00\xFF"));
        self::assertSame('', Bytes::hex(''));
        self::assertSame("\xDE\xAD\xBE\xEF", Bytes::fromHex(Bytes::hex("\xDE\xAD\xBE\xEF")));
    }

    #[Test]
    public function base64StandardKeepsPaddingAndUrlSafeDropsIt(): void
    {
        self::assertSame('SGVsbG8=', Bytes::base64('Hello'));
        self::assertSame('SGVsbG8', Bytes::base64('Hello', urlSafe: true));
        self::assertSame('+/8=', Bytes::base64(self::PLUS_SLASH));
        self::assertSame('-_8', Bytes::base64(self::PLUS_SLASH, urlSafe: true));
        self::assertSame('YWJj', Bytes::base64('abc'));
        self::assertSame('YWJj', Bytes::base64('abc', urlSafe: true));
        self::assertSame('', Bytes::base64(''));
        self::assertSame('', Bytes::base64('', urlSafe: true));
    }

    #[Test]
    public function base64RoundTripsThroughFromBase64(): void
    {
        $raw = "\x00\xFB\xFF\x10\x7F";
        self::assertSame($raw, Bytes::fromBase64(Bytes::base64($raw)));
        self::assertSame($raw, Bytes::fromBase64(Bytes::base64($raw, urlSafe: true)));
    }

    #[Test]
    public function textReturnsValidUtf8Unchanged(): void
    {
        self::assertSame('héllo', Bytes::text("h\xC3\xA9llo"));
        self::assertSame('', Bytes::text(''));
        self::assertSame("\u{1F468}", Bytes::text("\xF0\x9F\x91\xA8"));
    }

    #[Test]
    public function textRejectsInvalidUtf8(): void
    {
        $this->expectException(ValueError::class);
        Bytes::text("abc\xFF");
    }

    #[Test]
    public function textRejectsATruncatedSequence(): void
    {
        $this->expectException(ValueError::class);
        Bytes::text("h\xC3");
    }

    #[Test]
    public function textLossySubstitutesUFffdForEveryInvalidSequence(): void
    {
        self::assertSame("a\u{FFFD}b", Bytes::textLossy("a\xFFb"));
        self::assertSame("h\u{FFFD}", Bytes::textLossy("h\xC3"));
        self::assertSame("\u{FFFD}\u{FFFD}", Bytes::textLossy("\xFF\xFE"));
        self::assertSame('héllo', Bytes::textLossy("h\xC3\xA9llo"));
        self::assertSame('', Bytes::textLossy(''));
    }

    #[Test]
    public function textLossyRestoresTheProcessWideSubstituteCharacter(): void
    {
        $before = mb_substitute_character();
        Bytes::textLossy("\xFF");
        self::assertSame($before, mb_substitute_character());
    }

    #[Test]
    public function equalsComparesWholeValues(): void
    {
        self::assertTrue(Bytes::equals("\x00\x01", "\x00\x01"));
        self::assertTrue(Bytes::equals('', ''));
        self::assertFalse(Bytes::equals("\x00\x01", "\x00\x02"));
        self::assertFalse(Bytes::equals("\x00\x01", "\x00\x01\x02"));
        self::assertFalse(Bytes::equals('', "\x00"));
    }

    #[Test]
    public function atReturnsTheByteValueWithNegativeIndexesFromTheEnd(): void
    {
        $b = "a\x00\xFF";
        self::assertSame(97, Bytes::at($b, 0));
        self::assertSame(0, Bytes::at($b, 1));
        self::assertSame(255, Bytes::at($b, 2));
        self::assertSame(255, Bytes::at($b, -1));
        self::assertSame(97, Bytes::at($b, -3));
    }

    #[Test]
    public function atRejectsAnIndexPastTheEnd(): void
    {
        $this->expectException(OutOfRangeException::class);
        Bytes::at('abc', 3);
    }

    #[Test]
    public function atRejectsANegativeIndexBeforeTheStart(): void
    {
        $this->expectException(OutOfRangeException::class);
        Bytes::at('abc', -4);
    }

    #[Test]
    public function atRejectsAnyIndexIntoEmptyBytes(): void
    {
        $this->expectException(OutOfRangeException::class);
        Bytes::at('', 0);
    }

    #[Test]
    public function randomBytesAreBytes(): void
    {
        $raw = random_bytes(16);
        self::assertSame(16, Bytes::length($raw));
        self::assertSame(32, strlen(Bytes::hex($raw)));
        self::assertSame($raw, Bytes::fromHex(Bytes::hex($raw)));
        self::assertSame($raw, Bytes::fromBase64(Bytes::base64($raw, urlSafe: true)));
    }
}
