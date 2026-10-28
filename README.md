# phaenon/runtime

The runtime half of [Phaenon](https://phaenon.dev), a typed dialect of PHP that compiles to
PHP. Compiled code depends on this package only when it uses what PHP cannot express alone:

- a **reified** type parameter — `class Repository<reified T of Model>` compiles to a class that
  takes a `string|\Phaenon\Type $T` and keeps it in a property, so `new T()`, `T::class` and
  `$x instanceof T` work at runtime;
- `is` / `as` on **structural types** — `$x is list<int>` compiles to
  `\Phaenon\Type::list(\Phaenon\Type::int())->check($x)`.

Code that never does either compiles to plain PHP with no dependency on this package.

## `\Phaenon\Type`

An immutable, interned type descriptor:

```php
use Phaenon\Type;

$t = Type::list(Type::int());        // 'list<int>'
$t->check([1, 2]);                   // true — deep: every element is tested
$t->assert($value);                  // returns $value, or throws TypeError("Expected list<int>, got string")
$t->orNull($value);                  // the `as?` form

$u = Type::of(User::class);          // a class type
$u->className();                     // User::class
$u->instantiate($id);                // new User($id)
Type::from(User::class) === $u;      // what a PHP caller passes for a reified parameter
```

Constructors: `of`, `from`, `int`, `float`, `string`, `bool`, `null`, `mixed`, `object`,
`array`, `iterable`, `callable`, `resource`, `list`, `map`, `nullable`, `union`,
`intersection`, `literal`, `classString`. Queries: `check`, `assert`, `orNull`,
`instantiate`, `className`, `name`, `equals`, `isSubtypeOf`.

Descriptors are interned (`Type::int() === Type::int()`), so building one in a hot path
costs a lookup, not an allocation. Container checks are O(n) by design: the cost of a deep
check is visible at the call site that asked for it.

## Development

```sh
composer install
composer test
composer stan
```

License: MIT.
