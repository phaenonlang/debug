<?php

declare(strict_types=1);

namespace Phaenon\Tests;

use LogicException;
use Phaenon\Type;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypeError;

enum Colour: string
{
    case Red = 'red';
    case Blue = 'blue';
}

class Animal
{
    public function __construct(public readonly string $name = 'generic')
    {
    }
}

final class Dog extends Animal
{
}

final class TypeTest extends TestCase
{
    #[Test]
    public function descriptorsAreInterned(): void
    {
        self::assertSame(Type::int(), Type::int());
        self::assertSame(Type::list(Type::int()), Type::list(Type::int()));
        self::assertSame(Type::of(Animal::class), Type::of('\\' . Animal::class));
        self::assertSame(Type::union(Type::int(), Type::string()), Type::union(Type::int(), Type::union(Type::string(), Type::int())));
        self::assertSame(Type::int(), Type::union(Type::int(), Type::int()));
        self::assertSame(Type::nullable(Type::int()), Type::nullable(Type::nullable(Type::int())));
    }

    #[Test]
    public function namesReadLikePhpstan(): void
    {
        self::assertSame('list<int>', Type::list(Type::int())->name());
        self::assertSame('array<string, ?float>', (string) Type::map(Type::string(), Type::nullable(Type::float())));
        self::assertSame(Animal::class . '<int>', Type::of(Animal::class, Type::int())->name());
        self::assertSame("'a'|1|true", Type::union(Type::literal('a'), Type::literal(1), Type::literal(true))->name());
        self::assertSame('\\' . Colour::class . '::Red', Type::literal(Colour::Red)->name());
        self::assertSame('class-string<' . Animal::class . '>', Type::classString(Type::of(Animal::class))->name());
    }

    #[Test]
    public function scalarChecks(): void
    {
        self::assertTrue(Type::int()->check(1));
        self::assertFalse(Type::int()->check('1'));
        self::assertTrue(Type::float()->check(1.5));
        self::assertFalse(Type::float()->check(1));
        self::assertTrue(Type::string()->check(''));
        self::assertTrue(Type::bool()->check(false));
        self::assertTrue(Type::null()->check(null));
        self::assertTrue(Type::mixed()->check(null));
        self::assertTrue(Type::object()->check(new Dog()));
        self::assertTrue(Type::callable()->check('strlen'));
        self::assertTrue(Type::iterable()->check(new \ArrayIterator([])));
    }

    #[Test]
    public function classChecksUseInstanceof(): void
    {
        self::assertTrue(Type::of(Animal::class)->check(new Dog()));
        self::assertFalse(Type::of(Dog::class)->check(new Animal()));
        self::assertTrue(Type::of(\Stringable::class)->check(Type::int()));
    }

    #[Test]
    public function containerChecksAreDeep(): void
    {
        $ints = Type::list(Type::int());
        self::assertTrue($ints->check([1, 2, 3]));
        self::assertTrue($ints->check([]));
        self::assertFalse($ints->check([1, '2']));
        self::assertFalse($ints->check([1 => 1]));
        $map = Type::map(Type::string(), Type::of(Animal::class));
        self::assertTrue($map->check(['a' => new Dog()]));
        self::assertFalse($map->check([0 => new Dog()]));
        self::assertFalse($map->check(['a' => 1]));
    }

    #[Test]
    public function compoundChecks(): void
    {
        self::assertTrue(Type::nullable(Type::int())->check(null));
        self::assertTrue(Type::nullable(Type::int())->check(1));
        self::assertFalse(Type::nullable(Type::int())->check('x'));
        $u = Type::union(Type::int(), Type::string());
        self::assertTrue($u->check('x'));
        self::assertFalse($u->check(1.0));
        $i = Type::intersection(Type::of(\Countable::class), Type::of(\Traversable::class));
        self::assertTrue($i->check(new \ArrayIterator([])));
        self::assertFalse($i->check(new \stdClass()));
        self::assertTrue(Type::literal(Colour::Red)->check(Colour::Red));
        self::assertFalse(Type::literal(Colour::Red)->check(Colour::Blue));
        self::assertTrue(Type::literal(1)->check(1));
        self::assertFalse(Type::literal(1)->check('1'));
    }

    #[Test]
    public function classStringChecks(): void
    {
        $t = Type::classString(Type::of(Animal::class));
        self::assertTrue($t->check(Dog::class));
        self::assertFalse($t->check(\stdClass::class));
        self::assertFalse($t->check('NoSuchClass'));
        self::assertTrue(Type::classString(Type::object())->check(\stdClass::class));
    }

    #[Test]
    public function assertReturnsTheValueOrThrows(): void
    {
        self::assertSame([1], Type::list(Type::int())->assert([1]));
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Expected list<int>, got string');
        Type::list(Type::int())->assert('no');
    }

    #[Test]
    public function orNullIsTheSoftCast(): void
    {
        self::assertSame(1, Type::int()->orNull(1));
        self::assertNull(Type::int()->orNull('1'));
    }

    #[Test]
    public function classTypesKnowTheirName(): void
    {
        $t = Type::of(Dog::class);
        self::assertSame(Dog::class, $t->className());
        self::assertSame(Dog::class, Type::of(Dog::class, Type::int())->className());
        $this->expectException(LogicException::class);
        Type::int()->className();
    }

    #[Test]
    public function subtyping(): void
    {
        self::assertTrue(Type::of(Dog::class)->isSubtypeOf(Type::of(Animal::class)));
        self::assertFalse(Type::of(Animal::class)->isSubtypeOf(Type::of(Dog::class)));
        self::assertTrue(Type::of(Dog::class)->isSubtypeOf(Type::object()));
        self::assertTrue(Type::int()->isSubtypeOf(Type::mixed()));
        self::assertTrue(Type::int()->isSubtypeOf(Type::nullable(Type::int())));
        self::assertTrue(Type::null()->isSubtypeOf(Type::nullable(Type::int())));
        self::assertTrue(Type::list(Type::of(Dog::class))->isSubtypeOf(Type::list(Type::of(Animal::class))));
        self::assertTrue(Type::list(Type::int())->isSubtypeOf(Type::map(Type::int(), Type::int())));
        self::assertTrue(Type::list(Type::int())->isSubtypeOf(Type::array()));
        self::assertTrue(Type::union(Type::int(), Type::string())->isSubtypeOf(Type::union(Type::string(), Type::int(), Type::null())));
        self::assertFalse(Type::union(Type::int(), Type::string())->isSubtypeOf(Type::int()));
        self::assertTrue(Type::literal(Colour::Red)->isSubtypeOf(Type::of(Colour::class)));
        self::assertTrue(Type::literal(3)->isSubtypeOf(Type::float()));
        self::assertTrue(Type::classString(Type::of(Dog::class))->isSubtypeOf(Type::string()));
    }

    #[Test]
    public function literalRejectsObjects(): void
    {
        $this->expectException(LogicException::class);
        Type::literal(new \stdClass()); // @phpstan-ignore argument.type, argument.templateType
    }
}
