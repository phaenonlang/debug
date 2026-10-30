<?php

declare(strict_types=1);

namespace Phaenon\Tests;

use Phaenon\Seq;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

final class Label implements \Stringable
{
    public function __construct(private readonly string $text)
    {
    }

    public function __toString(): string
    {
        return $this->text;
    }
}

final class SeqTest extends TestCase
{
    /** An int PHPStan cannot fold, so a filter that keeps nothing is not "always false". */
    private static function threshold(): int
    {
        return 10;
    }

    #[Test]
    public function mapAppliesTheFunctionInOrder(): void
    {
        self::assertSame([2, 4, 6], Seq::map([1, 2, 3], fn (int $x): int => $x * 2));
        self::assertSame(['1', '2'], Seq::map([1, 2], fn (int $x): string => (string) $x));
        self::assertSame([], Seq::map([], fn (int $x): int => $x));
    }

    #[Test]
    public function filterKeepsMatchesAndReindexes(): void
    {
        $evens = Seq::filter([1, 2, 3, 4], fn (int $x): bool => 0 === $x % 2);
        self::assertSame([2, 4], $evens);
        self::assertSame([0, 1], array_keys($evens));
        self::assertSame([], Seq::filter([1, 3], fn (int $x): bool => $x > self::threshold()));
        self::assertSame([], Seq::filter([], fn (int $x): bool => true));
    }

    #[Test]
    public function reduceFoldsFromTheLeft(): void
    {
        self::assertSame(10, Seq::reduce([1, 2, 3, 4], 0, fn (int $acc, int $x): int => $acc + $x));
        self::assertSame('abc', Seq::reduce(['a', 'b', 'c'], '', fn (string $acc, string $x): string => $acc . $x));
        self::assertSame(42, Seq::reduce([], 42, fn (int $acc, int $x): int => $acc + $x));
    }

    #[Test]
    public function eachVisitsEveryElementInOrder(): void
    {
        $seen = [];
        Seq::each(['a', 'b', 'c'], function (string $x) use (&$seen): void {
            $seen[] = $x;
        });
        self::assertSame(['a', 'b', 'c'], $seen);

        Seq::each([], function (string $x): void {
            self::fail('an empty list has nothing to visit');
        });
    }

    #[Test]
    public function firstAndLastReturnNullOnAnEmptyList(): void
    {
        self::assertSame(1, Seq::first([1, 2, 3]));
        self::assertSame(3, Seq::last([1, 2, 3]));
        self::assertSame('only', Seq::first(['only']));
        self::assertSame('only', Seq::last(['only']));
        self::assertNull(Seq::first([]));
        self::assertNull(Seq::last([]));
    }

    #[Test]
    public function containsIsStrict(): void
    {
        self::assertTrue(Seq::contains([1, 2, 3], 2));
        self::assertFalse(Seq::contains([1, 2, 3], 4));
        self::assertFalse(Seq::contains([1, 2, 3], '2'));
        self::assertFalse(Seq::contains([0], null));
        self::assertFalse(Seq::contains([], 1));
    }

    #[Test]
    public function indexOfReturnsNullNotFalse(): void
    {
        self::assertSame(0, Seq::indexOf(['a', 'b', 'a'], 'a'));
        self::assertSame(1, Seq::indexOf(['a', 'b', 'a'], 'b'));
        self::assertNull(Seq::indexOf(['a', 'b'], 'c'));
        self::assertNull(Seq::indexOf([1, 2], '1'));
        self::assertNull(Seq::indexOf([], 1));
    }

    #[Test]
    public function sliceFollowsArraySliceAndReindexes(): void
    {
        $xs = [1, 2, 3, 4, 5];
        self::assertSame([3, 4, 5], Seq::slice($xs, 2));
        self::assertSame([2, 3], Seq::slice($xs, 1, 2));
        self::assertSame([4, 5], Seq::slice($xs, -2));
        self::assertSame([1, 2, 3], Seq::slice($xs, 0, -2));
        self::assertSame([], Seq::slice($xs, 5));
        self::assertSame([], Seq::slice([], 0, 3));
        self::assertSame([0, 1], array_keys(Seq::slice($xs, 3)));
    }

    #[Test]
    public function concatJoinsListsInOrder(): void
    {
        self::assertSame([1, 2, 3, 4], Seq::concat([1, 2], [3], [4]));
        self::assertSame([1, 2], Seq::concat([], [1, 2], []));
        self::assertSame([1], Seq::concat([1]));
        self::assertSame([], Seq::concat()); // @phpstan-ignore argument.templateType
        self::assertSame([], Seq::concat([], []));
    }

    #[Test]
    public function reverseReturnsAReindexedCopy(): void
    {
        $xs = [1, 2, 3];
        self::assertSame([3, 2, 1], Seq::reverse($xs));
        self::assertSame([1, 2, 3], $xs);
        self::assertSame([], Seq::reverse([]));
    }

    #[Test]
    public function sortReturnsASortedCopyAndLeavesTheInputAlone(): void
    {
        $xs = [3, 1, 2];
        self::assertSame([1, 2, 3], Seq::sort($xs));
        self::assertSame([3, 1, 2], $xs);

        $words = ['pear', 'fig', 'banana'];
        $byLength = Seq::sort($words, fn (string $a, string $b): int => strlen($a) <=> strlen($b));
        self::assertSame(['fig', 'pear', 'banana'], $byLength);
        self::assertSame(['pear', 'fig', 'banana'], $words);

        self::assertSame([3, 2, 1], Seq::sort([1, 2, 3], fn (int $a, int $b): int => $b <=> $a));
        self::assertSame([], Seq::sort([]));
    }

    #[Test]
    public function sortByIsStableAndLeavesTheInputAlone(): void
    {
        $words = ['ccc', 'a', 'bb'];
        self::assertSame(['a', 'bb', 'ccc'], Seq::sortBy($words, fn (string $s): int => strlen($s)));
        self::assertSame(['ccc', 'a', 'bb'], $words);

        // Equal keys keep their input order (usort is stable since PHP 8.0).
        $tagged = ['b1', 'a1', 'b2', 'a2'];
        self::assertSame(['a1', 'a2', 'b1', 'b2'], Seq::sortBy($tagged, fn (string $s): string => $s[0]));
        self::assertSame([], Seq::sortBy([], fn (string $s): string => $s));
    }

    #[Test]
    public function uniqueKeepsTheFirstOccurrenceAndReindexes(): void
    {
        $unique = Seq::unique([3, 1, 3, 2, 1]);
        self::assertSame([3, 1, 2], $unique);
        self::assertSame([0, 1, 2], array_keys($unique));
        self::assertSame(['a', 'b'], Seq::unique(['a', 'b', 'a']));
        self::assertSame([], Seq::unique([]));
    }

    #[Test]
    public function flattenRemovesOneLevel(): void
    {
        self::assertSame([1, 2, 3, 4], Seq::flatten([[1, 2], [], [3], [4]]));
        self::assertSame([[1], [2]], Seq::flatten([[[1]], [[2]]]));
        self::assertSame([], Seq::flatten([])); // @phpstan-ignore argument.templateType
        self::assertSame([], Seq::flatten([[], []]));
    }

    #[Test]
    public function chunkSplitsIntoListsOfAtMostSizeElements(): void
    {
        self::assertSame([[1, 2], [3, 4], [5]], Seq::chunk([1, 2, 3, 4, 5], 2));
        self::assertSame([[1], [2], [3]], Seq::chunk([1, 2, 3], 1));
        self::assertSame([[1, 2, 3]], Seq::chunk([1, 2, 3], 10));
        self::assertSame([], Seq::chunk([], 3));
    }

    #[Test]
    public function chunkRejectsAZeroSize(): void
    {
        $this->expectException(ValueError::class);
        Seq::chunk([1, 2, 3], 0);
    }

    #[Test]
    public function chunkRejectsANegativeSize(): void
    {
        $this->expectException(ValueError::class);
        Seq::chunk([1, 2, 3], -1);
    }

    #[Test]
    public function zipStopsAtTheShorterList(): void
    {
        self::assertSame([[1, 'a'], [2, 'b']], Seq::zip([1, 2, 3], ['a', 'b']));
        self::assertSame([[1, 'a'], [2, 'b']], Seq::zip([1, 2], ['a', 'b', 'c']));
        self::assertSame([[1, true]], Seq::zip([1], [true]));
        self::assertSame([], Seq::zip([], ['a']));
        self::assertSame([], Seq::zip([1, 2], []));
    }

    #[Test]
    public function keyByIndexesByTheKeyAndTheLastDuplicateWins(): void
    {
        $keyed = Seq::keyBy(['apple', 'avocado', 'banana'], fn (string $s): string => $s[0]);
        self::assertSame(['a' => 'avocado', 'b' => 'banana'], $keyed);
        self::assertSame([3 => 'fig', 4 => 'pear'], Seq::keyBy(['fig', 'pear'], fn (string $s): int => strlen($s)));
        self::assertSame([], Seq::keyBy([], fn (string $s): string => $s));
    }

    #[Test]
    public function groupByKeepsTheInputOrderWithinEachGroup(): void
    {
        $groups = Seq::groupBy(['apple', 'banana', 'avocado', 'blueberry'], fn (string $s): string => $s[0]);
        self::assertSame(['a' => ['apple', 'avocado'], 'b' => ['banana', 'blueberry']], $groups);
        self::assertSame(['a', 'b'], array_keys($groups));
        self::assertSame([1 => [1, 3], 0 => [2]], Seq::groupBy([1, 2, 3], fn (int $x): int => $x % 2));
        self::assertSame([], Seq::groupBy([], fn (int $x): int => $x));
    }

    #[Test]
    public function joinAcceptsStringsAndStringables(): void
    {
        self::assertSame('a, b, c', Seq::join(['a', 'b', 'c'], ', '));
        self::assertSame('x-y', Seq::join([new Label('x'), 'y'], '-'));
        self::assertSame('abc', Seq::join(['a', 'b', 'c'], ''));
        self::assertSame('solo', Seq::join(['solo'], ', '));
        self::assertSame('', Seq::join([], ', '));
    }

    #[Test]
    public function isEmptyAndCount(): void
    {
        self::assertTrue(Seq::isEmpty([]));
        self::assertFalse(Seq::isEmpty([null]));
        self::assertFalse(Seq::isEmpty([0]));
        self::assertSame(0, Seq::count([]));
        self::assertSame(3, Seq::count([1, 2, 3]));
    }

    #[Test]
    public function methodsChainAsTheMethodSyntaxCompilesThem(): void
    {
        // $words->filter(…)->map(…)->sort() in Phaenon source.
        $result = Seq::sort(
            Seq::map(
                Seq::filter(['pear', 'fig', 'banana', 'kiwi'], fn (string $s): bool => strlen($s) > 3),
                fn (string $s): string => strtoupper($s),
            ),
        );
        self::assertSame(['BANANA', 'KIWI', 'PEAR'], $result);
    }
}
