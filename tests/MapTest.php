<?php

declare(strict_types=1);

namespace Phaenon\Tests;

use Phaenon\Map;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MapTest extends TestCase
{
    #[Test]
    public function getReturnsTheValueOrNull(): void
    {
        $m = ['a' => 1, 'b' => 2, 7 => 'seven'];
        self::assertSame(1, Map::get($m, 'a'));
        self::assertSame('seven', Map::get($m, 7));
        self::assertNull(Map::get($m, 'z'));
        self::assertNull(Map::get([], 'a'));
        self::assertNull(Map::get(['n' => null], 'n'));
    }

    #[Test]
    public function hasTellsPresenceApartFromANullValue(): void
    {
        $m = ['a' => 1, 'n' => null, 0 => 'zero'];
        self::assertTrue(Map::has($m, 'a'));
        self::assertTrue(Map::has($m, 'n'));
        self::assertTrue(Map::has($m, 0));
        self::assertFalse(Map::has($m, 'z'));
        self::assertFalse(Map::has([], 'a'));
    }

    #[Test]
    public function keysAndValuesAreListsInInsertionOrder(): void
    {
        $m = ['b' => 2, 'a' => 1, 10 => 0];
        self::assertSame(['b', 'a', 10], Map::keys($m));
        self::assertSame([2, 1, 0], Map::values($m));
        self::assertSame([0, 1, 2], array_keys(Map::values($m)));
        self::assertSame([], Map::keys([]));
        self::assertSame([], Map::values([]));
    }

    #[Test]
    public function mapReceivesValueThenKeyAndKeepsTheKeys(): void
    {
        $m = ['a' => 1, 'b' => 2];
        self::assertSame(['a' => 'a=1', 'b' => 'b=2'], Map::map($m, fn (int $v, string $k): string => "$k=$v"));
        self::assertSame(['a' => 2, 'b' => 4], Map::map($m, fn (int $v, string $k): int => $v * 2));
        self::assertSame([], Map::map([], fn (int $v, string $k): int => $v));
    }

    #[Test]
    public function filterReceivesValueThenKeyAndKeepsTheKeys(): void
    {
        $m = ['a' => 1, 'b' => 2, 'c' => 3];
        self::assertSame(['a' => 1, 'c' => 3], Map::filter($m, fn (int $v, string $k): bool => 1 === $v % 2));
        self::assertSame(['b' => 2], Map::filter($m, fn (int $v, string $k): bool => 'b' === $k));
        self::assertSame([], Map::filter($m, fn (int $v, string $k): bool => false));
        self::assertSame([], Map::filter([], fn (int $v, string $k): bool => true));
    }

    #[Test]
    public function eachVisitsEveryPairInOrder(): void
    {
        $seen = [];
        Map::each(['x' => 1, 'y' => 2], function (int $v, string $k) use (&$seen): void {
            $seen[] = "$k:$v";
        });
        self::assertSame(['x:1', 'y:2'], $seen);

        Map::each([], function (int $v, string $k): void {
            self::fail('an empty map has nothing to visit');
        });
    }

    #[Test]
    public function mergeLetsTheLastMapWin(): void
    {
        $merged = Map::merge(['a' => 1, 'b' => 2], ['b' => 20, 'c' => 30], ['c' => 300]);
        self::assertSame(['a' => 1, 'b' => 20, 'c' => 300], $merged);
        self::assertSame(['a', 'b', 'c'], array_keys($merged));
    }

    #[Test]
    public function mergeKeepsIntegerKeysUnlikeArrayMerge(): void
    {
        self::assertSame([1 => 'y', 2 => 'z'], Map::merge([1 => 'x'], [1 => 'y', 2 => 'z']));
        self::assertSame([5 => 'a', 6 => 'b'], Map::merge([5 => 'a'], [6 => 'b']));
    }

    #[Test]
    public function mergeOfNothingOrEmptyMapsIsEmpty(): void
    {
        self::assertSame([], Map::merge()); // @phpstan-ignore argument.templateType, argument.templateType
        self::assertSame([], Map::merge([], []));
        self::assertSame(['a' => 1], Map::merge([], ['a' => 1], []));
    }

    #[Test]
    public function withReturnsACopyWithTheEntrySet(): void
    {
        $m = ['a' => 1];
        self::assertSame(['a' => 1, 'b' => 2], Map::with($m, 'b', 2));
        self::assertSame(['a' => 10], Map::with($m, 'a', 10));
        self::assertSame(['a' => 1], $m);
        self::assertSame([3 => 'x'], Map::with([], 3, 'x'));
    }

    #[Test]
    public function withoutReturnsACopyWithoutTheKeys(): void
    {
        $m = ['a' => 1, 'b' => 2, 'c' => 3];
        self::assertSame(['a' => 1, 'c' => 3], Map::without($m, 'b'));
        self::assertSame(['c' => 3], Map::without($m, 'a', 'b'));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], Map::without($m, 'z'));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], Map::without($m));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $m);
        self::assertSame([], Map::without([], 'a'));
    }

    #[Test]
    public function toListAndFromListRoundTrip(): void
    {
        $m = ['a' => 1, 7 => 'seven', 'z' => null];
        $pairs = Map::toList($m);
        self::assertSame([['a', 1], [7, 'seven'], ['z', null]], $pairs);
        self::assertSame($m, Map::fromList($pairs));
        self::assertSame([], Map::toList([]));
        self::assertSame([], Map::fromList([])); // @phpstan-ignore argument.templateType, argument.templateType
    }

    #[Test]
    public function fromListLetsTheLastPairWin(): void
    {
        self::assertSame(['a' => 2, 'b' => 3], Map::fromList([['a', 1], ['b', 3], ['a', 2]]));
    }

    #[Test]
    public function isEmptyAndCount(): void
    {
        self::assertTrue(Map::isEmpty([]));
        self::assertFalse(Map::isEmpty(['a' => null]));
        self::assertSame(0, Map::count([]));
        self::assertSame(2, Map::count(['a' => 1, 'b' => 2]));
    }
}
