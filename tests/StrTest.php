<?php

declare(strict_types=1);

namespace Phaenon\Tests;

use Phaenon\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

/** A value object: every Str function accepts one where it takes a string. */
final class Id implements \Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class StrTest extends TestCase
{
    /** Five code points, one grapheme cluster: man, ZWJ, woman, ZWJ, girl. */
    private const string FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

    #[Test]
    public function lengthCountsCodePoints(): void
    {
        self::assertSame(5, Str::length('héllo'));
        self::assertSame(5, Str::length('hello'));
        self::assertSame(0, Str::length(''));
        self::assertSame(5, Str::length(self::FAMILY));
        self::assertSame(2, Str::length("e\u{0301}"));
        self::assertSame(3, Str::length(new Id('héé')));
    }

    #[Test]
    public function isEmptyIsTrueOnlyForTheEmptyString(): void
    {
        self::assertTrue(Str::isEmpty(''));
        self::assertTrue(Str::isEmpty(new Id('')));
        self::assertFalse(Str::isEmpty(' '));
        self::assertFalse(Str::isEmpty('0'));
    }

    #[Test]
    public function caseMappingIsUnicodeAware(): void
    {
        self::assertSame('HÉLLO', Str::upper('héllo'));
        self::assertSame('STRASSE', Str::upper('straße'));
        self::assertSame('héllo', Str::lower('HÉLLO'));
        self::assertSame('ÉLAN', Str::upper(new Id('élan')));
        self::assertSame('', Str::upper(''));
        self::assertSame('', Str::lower(''));
    }

    #[Test]
    public function upperFirstTouchesOnlyTheFirstCodePoint(): void
    {
        self::assertSame('Élan', Str::upperFirst('élan'));
        self::assertSame('Hello World', Str::upperFirst('hello World'));
        self::assertSame('É', Str::upperFirst('é'));
        self::assertSame('', Str::upperFirst(''));
        self::assertSame('Abc', Str::upperFirst(new Id('abc')));
    }

    #[Test]
    public function trimStripsWhitespaceOrTheGivenCharacters(): void
    {
        self::assertSame('x', Str::trim(" \t\n\r\0\x0Bx \n"));
        self::assertSame('x  ', Str::trimStart('  x  '));
        self::assertSame('  x', Str::trimEnd('  x  '));
        self::assertSame('b', Str::trim('--b--', '-'));
        self::assertSame('b--', Str::trimStart('--b--', '-'));
        self::assertSame('--b', Str::trimEnd('--b--', '-'));
        self::assertSame('', Str::trim('   '));
        self::assertSame('x', Str::trim(new Id('  x  ')));
    }

    #[Test]
    public function startsWithEndsWithContains(): void
    {
        self::assertTrue(Str::startsWith('héllo', 'hé'));
        self::assertFalse(Str::startsWith('héllo', 'é'));
        self::assertTrue(Str::startsWith('héllo', ''));
        self::assertTrue(Str::endsWith('héllo', 'llo'));
        self::assertFalse(Str::endsWith('héllo', 'll'));
        self::assertTrue(Str::contains('héllo', 'éll'));
        self::assertFalse(Str::contains('héllo', 'xyz'));
        self::assertTrue(Str::contains('héllo', ''));
        self::assertTrue(Str::startsWith(new Id('u-42'), 'u-'));
        self::assertTrue(Str::endsWith(new Id('u-42'), '42'));
        self::assertTrue(Str::contains(new Id('u-42'), '-'));
    }

    #[Test]
    public function indexOfCountsCodePointsAndReturnsNullNotFalse(): void
    {
        self::assertSame(2, Str::indexOf('héllo', 'l'));
        self::assertSame(1, Str::indexOf('héllo', 'é'));
        self::assertSame(3, Str::indexOf('héllo', 'l', 3));
        self::assertSame(3, Str::indexOf('héllo', 'l', -2));
        self::assertSame(0, Str::indexOf('héllo', ''));
        self::assertNull(Str::indexOf('héllo', 'x'));
        self::assertNull(Str::indexOf('héllo', 'h', 1));
        self::assertNull(Str::indexOf('', 'a'));
        self::assertSame(1, Str::indexOf(new Id('u-42'), '-'));
    }

    #[Test]
    public function indexOfAnswersNullForAnOffsetOutsideTheString(): void
    {
        self::assertNull(Str::indexOf('héllo', 'l', 5));
        self::assertNull(Str::indexOf('héllo', 'l', 6));
        self::assertNull(Str::indexOf('héllo', 'l', -6));
        self::assertSame(2, Str::indexOf('héllo', 'l', -5));
        self::assertNull(Str::indexOf('', 'a', 1));
    }

    #[Test]
    public function lastIndexOfCountsCodePointsAndReturnsNullNotFalse(): void
    {
        self::assertSame(3, Str::lastIndexOf('héllo', 'l'));
        self::assertSame(7, Str::lastIndexOf('héllo héllo', 'é'));
        self::assertNull(Str::lastIndexOf('héllo', 'x'));
        self::assertNull(Str::lastIndexOf('', 'a'));
        self::assertSame(3, Str::lastIndexOf(new Id('a-b-c'), '-'));
    }

    #[Test]
    public function sliceCountsCodePointsAndFollowsSubstr(): void
    {
        self::assertSame('éll', Str::slice('héllo', 1, 3));
        self::assertSame('llo', Str::slice('héllo', 2));
        self::assertSame('llo', Str::slice('héllo', -3));
        self::assertSame('éll', Str::slice('héllo', 1, -1));
        self::assertSame('', Str::slice('héllo', 5));
        self::assertSame('', Str::slice('héllo', 10, 2));
        self::assertSame('héllo', Str::slice('héllo', 0));
        self::assertSame('', Str::slice('', 0, 3));
        self::assertSame('-4', Str::slice(new Id('u-42'), 1, 2));
    }

    #[Test]
    public function splitByASeparator(): void
    {
        self::assertSame(['a', 'b', 'c'], Str::split('a,b,c', ','));
        self::assertSame(['a', 'b,c'], Str::split('a,b,c', ',', 2));
        self::assertSame(['a', 'b'], Str::split('a,b,c', ',', -1));
        self::assertSame(['abc'], Str::split('abc', ','));
        self::assertSame([''], Str::split('', ','));
        self::assertSame(['', ''], Str::split(',', ','));
        self::assertSame(['h', 'llo'], Str::split('héllo', 'é'));
        self::assertSame(['u', '42'], Str::split(new Id('u-42'), '-'));
    }

    #[Test]
    public function splitRejectsAnEmptySeparator(): void
    {
        $this->expectException(ValueError::class);
        Str::split('abc', '');
    }

    #[Test]
    public function linesSplitOnEveryLineEnding(): void
    {
        self::assertSame(['a', 'b', 'c', 'd'], Str::lines("a\r\nb\nc\rd"));
        self::assertSame(['a', ''], Str::lines("a\n"));
        self::assertSame(['', 'a'], Str::lines("\na"));
        self::assertSame([''], Str::lines(''));
        self::assertSame(['one line'], Str::lines('one line'));
        self::assertSame(['x', 'y'], Str::lines(new Id("x\ny")));
    }

    #[Test]
    public function replaceReplacesEveryOccurrence(): void
    {
        self::assertSame('hallo wald', Str::replace('hello weld', 'e', 'a'));
        self::assertSame('héllo', Str::replace('héllo', 'x', 'y'));
        self::assertSame('hllo', Str::replace('héllo', 'é', ''));
        self::assertSame('', Str::replace('', 'a', 'b'));
        self::assertSame('u_42', Str::replace(new Id('u-42'), '-', '_'));
    }

    #[Test]
    public function replaceAllRejectsAnEmptySearchString(): void
    {
        $this->expectException(ValueError::class);
        Str::replaceAll('ab', ['' => 'x', 'a' => 'b']);
    }

    #[Test]
    public function replaceAllReplacesInOnePassLongestKeysFirst(): void
    {
        self::assertSame('bc', Str::replaceAll('ab', ['a' => 'b', 'b' => 'c']));
        self::assertSame('X', Str::replaceAll('ab', ['a' => 'A', 'ab' => 'X']));
        self::assertSame('Hi, World', Str::replaceAll('{greet}, {name}', ['{greet}' => 'Hi', '{name}' => 'World']));
        self::assertSame('héllo', Str::replaceAll('héllo', []));
        self::assertSame('ok', Str::replaceAll(new Id('ko'), ['ko' => 'ok']));
    }

    #[Test]
    public function repeat(): void
    {
        self::assertSame('ababab', Str::repeat('ab', 3));
        self::assertSame('ééé', Str::repeat('é', 3));
        self::assertSame('', Str::repeat('ab', 0));
        self::assertSame('xx', Str::repeat(new Id('x'), 2));
    }

    #[Test]
    public function paddingCountsCodePoints(): void
    {
        self::assertSame('**héllo', Str::padStart('héllo', 7, '*'));
        self::assertSame('héllo**', Str::padEnd('héllo', 7, '*'));
        self::assertSame('  héllo', Str::padStart('héllo', 7));
        self::assertSame('ééhéllo', Str::padStart('héllo', 7, 'é'));
        self::assertSame('abahéllo', Str::padStart('héllo', 8, 'ab'));
        self::assertSame('héllo', Str::padStart('héllo', 3, '*'));
        self::assertSame('héllo', Str::padEnd('héllo', 5, '*'));
        self::assertSame('0042', Str::padStart(new Id('42'), 4, '0'));
    }

    #[Test]
    public function charsSplitsIntoCodePoints(): void
    {
        self::assertSame(['h', 'é', 'l', 'l', 'o'], Str::chars('héllo'));
        self::assertSame([], Str::chars(''));
        self::assertCount(5, Str::chars(self::FAMILY));
        self::assertSame(['e', "\u{0301}"], Str::chars("e\u{0301}"));
        self::assertSame(['a', 'b'], Str::chars(new Id('ab')));
    }

    #[Test]
    public function graphemesSplitIntoGraphemeClusters(): void
    {
        self::assertSame([self::FAMILY], Str::graphemes(self::FAMILY));
        self::assertSame(['h', 'é', 'l', 'l', 'o'], Str::graphemes('héllo'));
        self::assertSame(["e\u{0301}", 'a'], Str::graphemes("e\u{0301}a"));
        self::assertSame([], Str::graphemes(''));
        self::assertSame([self::FAMILY, '!'], Str::graphemes(new Id(self::FAMILY . '!')));
    }

    #[Test]
    public function graphemeLengthCountsClustersNotCodePoints(): void
    {
        self::assertSame(1, Str::graphemeLength(self::FAMILY));
        self::assertSame(5, Str::length(self::FAMILY));
        self::assertSame(5, Str::graphemeLength('héllo'));
        self::assertSame(1, Str::graphemeLength("e\u{0301}"));
        self::assertSame(0, Str::graphemeLength(''));
        self::assertSame(2, Str::graphemeLength(new Id(self::FAMILY . 'x')));
    }

    #[Test]
    public function truncateCutsAtGraphemeClustersAndAppendsTheEllipsis(): void
    {
        self::assertSame('hello…', Str::truncate('hello world', 5));
        self::assertSame('hello', Str::truncate('hello', 5));
        self::assertSame('hi', Str::truncate('hi', 5));
        self::assertSame('hel...', Str::truncate('hello', 3, '...'));
        self::assertSame('hel', Str::truncate('hello', 3, ''));
        self::assertSame('…', Str::truncate('hello', 0));
        self::assertSame('', Str::truncate('', 3));
        self::assertSame(self::FAMILY . '…', Str::truncate(self::FAMILY . self::FAMILY, 1));
        self::assertSame(self::FAMILY . self::FAMILY, Str::truncate(self::FAMILY . self::FAMILY, 2));
        self::assertSame("e\u{0301}…", Str::truncate("e\u{0301}ab", 1));
        self::assertSame('ab…', Str::truncate(new Id('abcd'), 2));
    }

    #[Test]
    public function matchesIsPregMatch(): void
    {
        self::assertTrue(Str::matches('2024-01-31', '/^\d{4}-\d{2}-\d{2}$/'));
        self::assertFalse(Str::matches('yesterday', '/^\d{4}-\d{2}-\d{2}$/'));
        self::assertTrue(Str::matches('héllo', '/^\w+$/u'));
        self::assertFalse(Str::matches('', '/.+/'));
        self::assertTrue(Str::matches(new Id('u-42'), '/^u-\d+$/'));
    }

    #[Test]
    public function bytesIsTheUtf8EncodingUnchanged(): void
    {
        self::assertSame('héllo', Str::bytes('héllo'));
        self::assertSame(6, strlen(Str::bytes('héllo')));
        self::assertSame("\xC3\xA9", Str::bytes('é'));
        self::assertSame('', Str::bytes(''));
        self::assertSame('u-42', Str::bytes(new Id('u-42')));
    }

    #[Test]
    public function methodsChainAsTheMethodSyntaxCompilesThem(): void
    {
        // $name->trim()->upperFirst()->truncate(4) in Phaenon source.
        self::assertSame('Élod…', Str::truncate(Str::upperFirst(Str::trim('  élodie  ')), 4));
    }
}
