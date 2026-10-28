<?php

declare(strict_types=1);

namespace Phaenon;

use LogicException;
use TypeError;
use UnitEnum;

/**
 * A runtime type descriptor.
 *
 * Phaenon compiles a reified type parameter to one of these, and `is` / `as`
 * on structural types (`$x is list<int>`) to its check() and assert().
 * Descriptors are immutable and interned: two spellings of the same type are
 * the same object, so a hot path pays a property read, not an allocation.
 *
 * @template-covariant T
 */
final class Type implements \Stringable
{
    /** @var array<string, self<mixed>> */
    private static array $interned = [];

    /**
     * @param list<self<mixed>> $args
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $name,
        private readonly array $args = [],
        private readonly mixed $value = null,
    ) {
    }

    /**
     * @param list<self<mixed>> $args
     * @return self<mixed>
     */
    private static function intern(string $kind, string $name, array $args = [], mixed $value = null): self
    {
        return self::$interned[$name] ??= new self($kind, $name, $args, $value);
    }

    // ---------------------------------------------------------------- constructors

    /**
     * A class or interface type, with type arguments for the message only.
     *
     * @template U of object
     * @param class-string<U> $class
     * @param self<mixed> ...$args
     * @return self<U>
     */
    public static function of(string $class, self ...$args): self
    {
        $class = ltrim($class, '\\');
        $name = $class;
        if ($args !== []) {
            $name .= '<' . implode(', ', array_map(static fn (self $a): string => $a->name, $args)) . '>';
        }

        return self::intern('class', $name, array_values($args));
    }

    /**
     * Accepts what a PHP caller passes for a reified parameter: a class
     * name or a descriptor.
     *
     * @template U of object
     * @param class-string<U>|self<U> $type
     * @return self<U>
     */
    public static function from(string|self $type): self
    {
        return $type instanceof self ? $type : self::of($type);
    }

    /** @return self<int> */
    public static function int(): self
    {
        return self::intern('int', 'int');
    }

    /** @return self<float> */
    public static function float(): self
    {
        return self::intern('float', 'float');
    }

    /** @return self<string> */
    public static function string(): self
    {
        return self::intern('string', 'string');
    }

    /** @return self<bool> */
    public static function bool(): self
    {
        return self::intern('bool', 'bool');
    }

    /** @return self<null> */
    public static function null(): self
    {
        return self::intern('null', 'null');
    }

    /** @return self<mixed> */
    public static function mixed(): self
    {
        return self::intern('mixed', 'mixed');
    }

    /** @return self<object> */
    public static function object(): self
    {
        return self::intern('object', 'object');
    }

    /** @return self<array<mixed>> */
    public static function array(): self
    {
        return self::intern('array', 'array');
    }

    /** @return self<iterable<mixed>> */
    public static function iterable(): self
    {
        return self::intern('iterable', 'iterable');
    }

    /** @return self<callable> */
    public static function callable(): self
    {
        return self::intern('callable', 'callable');
    }

    /** @return self<resource> */
    public static function resource(): self
    {
        return self::intern('resource', 'resource');
    }

    /**
     * @template V
     * @param self<V> $element
     * @return self<list<V>>
     */
    public static function list(self $element): self
    {
        return self::intern('list', 'list<' . $element->name . '>', [$element]);
    }

    /**
     * @template K of array-key
     * @template V
     * @param self<K> $key
     * @param self<V> $value
     * @return self<array<K, V>>
     */
    public static function map(self $key, self $value): self
    {
        return self::intern('map', 'array<' . $key->name . ', ' . $value->name . '>', [$key, $value]);
    }

    /**
     * @template V
     * @param self<V> $type
     * @return self<V|null>
     */
    public static function nullable(self $type): self
    {
        if ($type->kind === 'nullable' || $type->kind === 'mixed' || $type->kind === 'null') {
            return $type;
        }

        return self::intern('nullable', '?' . $type->name, [$type]);
    }

    /**
     * @param self<mixed> ...$members
     * @return self<mixed>
     */
    public static function union(self ...$members): self
    {
        $flat = [];
        foreach ($members as $m) {
            foreach ($m->kind === 'union' ? $m->args : [$m] as $part) {
                $flat[$part->name] = $part;
            }
        }
        if (count($flat) === 1) {
            return reset($flat);
        }
        $flat = array_values($flat);

        return self::intern('union', implode('|', array_map(static fn (self $a): string => $a->name, $flat)), $flat);
    }

    /**
     * @param self<mixed> ...$members
     * @return self<mixed>
     */
    public static function intersection(self ...$members): self
    {
        $flat = [];
        foreach ($members as $m) {
            foreach ($m->kind === 'intersection' ? $m->args : [$m] as $part) {
                $flat[$part->name] = $part;
            }
        }
        if (count($flat) === 1) {
            return reset($flat);
        }
        $flat = array_values($flat);

        return self::intern('intersection', implode('&', array_map(static fn (self $a): string => $a->name, $flat)), $flat);
    }

    /**
     * A single value: a scalar or an enum case.
     *
     * @template V of scalar|UnitEnum
     * @param V $value
     * @return self<V>
     */
    public static function literal(mixed $value): self
    {
        if (!is_scalar($value) && !$value instanceof UnitEnum) {
            throw new LogicException('Only scalars and enum cases can be literal types, ' . get_debug_type($value) . ' given');
        }
        $name = match (true) {
            $value instanceof UnitEnum => '\\' . $value::class . '::' . $value->name,
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };

        return self::intern('literal', $name, [], $value);
    }

    /**
     * A class name string: class-string<T>.
     *
     * @template U of object
     * @param self<U> $of
     * @return self<class-string<U>>
     */
    public static function classString(self $of): self
    {
        return self::intern('class-string', 'class-string<' . $of->name . '>', [$of]);
    }

    // ---------------------------------------------------------------- checks

    /**
     * Whether $value is of this type. Containers are checked element by
     * element, so a list<int> check is O(n); class types are O(1).
     *
     * @phpstan-assert-if-true T $value
     */
    public function check(mixed $value): bool
    {
        switch ($this->kind) {
            case 'class':
                $class = $this->className();

                return $value instanceof $class;
            case 'int':
                return is_int($value);
            case 'float':
                return is_float($value);
            case 'string':
                return is_string($value);
            case 'bool':
                return is_bool($value);
            case 'null':
                return $value === null;
            case 'mixed':
                return true;
            case 'object':
                return is_object($value);
            case 'array':
                return is_array($value);
            case 'iterable':
                return is_iterable($value);
            case 'callable':
                return is_callable($value);
            case 'resource':
                return is_resource($value);
            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    return false;
                }
                foreach ($value as $v) {
                    if (!$this->args[0]->check($v)) {
                        return false;
                    }
                }

                return true;
            case 'map':
                if (!is_array($value)) {
                    return false;
                }
                foreach ($value as $k => $v) {
                    if (!$this->args[0]->check($k) || !$this->args[1]->check($v)) {
                        return false;
                    }
                }

                return true;
            case 'nullable':
                return $value === null || $this->args[0]->check($value);
            case 'union':
                foreach ($this->args as $m) {
                    if ($m->check($value)) {
                        return true;
                    }
                }

                return false;
            case 'intersection':
                foreach ($this->args as $m) {
                    if (!$m->check($value)) {
                        return false;
                    }
                }

                return true;
            case 'literal':
                return $value === $this->value;
            case 'class-string':
                if (!is_string($value) || (!class_exists($value) && !interface_exists($value) && !enum_exists($value))) {
                    return false;
                }
                $of = $this->args[0];

                return $of->kind !== 'class' || is_a($value, $of->className(), true);
        }

        throw new LogicException('Unknown type kind ' . $this->kind);
    }

    /**
     * Returns $value when it is of this type, throws a TypeError otherwise.
     *
     * @return T
     */
    public function assert(mixed $value): mixed
    {
        if (!$this->check($value)) {
            throw new TypeError(sprintf('Expected %s, got %s', $this->name, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * Returns $value when it is of this type, null otherwise (`as?`).
     *
     * @return T|null
     */
    public function orNull(mixed $value): mixed
    {
        return $this->check($value) ? $value : null;
    }

    /**
     * Constructs an instance of a class type (`new T(...)`).
     *
     * @return T
     */
    public function instantiate(mixed ...$args): mixed
    {
        $class = $this->className();

        return new $class(...$args);
    }

    /**
     * The class name of a class type (`T::class`).
     *
     * @return class-string<T>
     */
    public function className(): string
    {
        if ($this->kind !== 'class') {
            throw new LogicException(sprintf('%s is not a class type', $this->name));
        }
        $name = $this->name;
        if (($lt = strpos($name, '<')) !== false) {
            $name = substr($name, 0, $lt);
        }

        /** @var class-string<T> $name */
        return $name;
    }

    /** The type as written: `list<int>`, `App\User`, `?string`. */
    public function name(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /** @param self<mixed> $other */
    public function equals(self $other): bool
    {
        return $this === $other || $this->name === $other->name;
    }

    /**
     * Whether every value of this type is a value of $other.
     *
     * @param self<mixed> $other
     */
    public function isSubtypeOf(self $other): bool
    {
        if ($this->equals($other) || $other->kind === 'mixed') {
            return true;
        }
        if ($other->kind === 'union') {
            foreach ($other->args as $m) {
                if ($this->isSubtypeOf($m)) {
                    return true;
                }
            }
            if ($this->kind !== 'union') {
                return false;
            }
        }
        if ($other->kind === 'nullable') {
            return $this->kind === 'null' || $this->isSubtypeOf($other->args[0])
                || ($this->kind === 'nullable' && $this->args[0]->isSubtypeOf($other->args[0]));
        }
        if ($other->kind === 'intersection') {
            foreach ($other->args as $m) {
                if (!$this->isSubtypeOf($m)) {
                    return false;
                }
            }

            return true;
        }
        switch ($this->kind) {
            case 'union':
                foreach ($this->args as $m) {
                    if (!$m->isSubtypeOf($other)) {
                        return false;
                    }
                }

                return true;
            case 'intersection':
                foreach ($this->args as $m) {
                    if ($m->isSubtypeOf($other)) {
                        return true;
                    }
                }

                return false;
            case 'class':
                return ($other->kind === 'class' && is_a($this->className(), $other->className(), true))
                    || $other->kind === 'object';
            case 'literal':
                return match (true) {
                    $this->value instanceof UnitEnum => $other->kind === 'class' && is_a($this->value, $other->className()),
                    is_int($this->value) => $other->kind === 'int' || $other->kind === 'float',
                    is_float($this->value) => $other->kind === 'float',
                    is_string($this->value) => $other->kind === 'string',
                    default => $other->kind === 'bool',
                };
            case 'list':
                return ($other->kind === 'list' && $this->args[0]->isSubtypeOf($other->args[0]))
                    || ($other->kind === 'map' && self::int()->isSubtypeOf($other->args[0]) && $this->args[0]->isSubtypeOf($other->args[1]))
                    || $other->kind === 'array' || $other->kind === 'iterable';
            case 'map':
                return ($other->kind === 'map' && $this->args[0]->isSubtypeOf($other->args[0]) && $this->args[1]->isSubtypeOf($other->args[1]))
                    || $other->kind === 'array' || $other->kind === 'iterable';
            case 'array':
                return $other->kind === 'iterable';
            case 'int':
                return $other->kind === 'float';
            case 'class-string':
                return $other->kind === 'string';
            case 'null':
                return $other->kind === 'nullable';
        }

        return false;
    }
}
