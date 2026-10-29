# phaenon/runtime

The runtime half of [Phaenon](https://phaenon.dev), a typed dialect of PHP that compiles to
PHP. Compiled code depends on this package only where it uses `is` / `as` on a **structural
type**, which PHP cannot test on its own: `$x is list<int>` compiles to
`\Phaenon\Type::list(\Phaenon\Type::int())->check($x)`, and `$x as map<string, int>` to the
matching `->assert($x)`.

Everything else compiles to plain PHP with no dependency on this package. Class types need no
descriptor: `$x is User` is `instanceof`, and a generic that needs its type parameter at runtime
holds a `class<T>` value — a plain class-name string — the way PHP code does today.

## `\Phaenon\Type`

An immutable, interned type descriptor:

```php
use Phaenon\Type;

$t = Type::list(Type::int());        // 'list<int>'
$t->check([1, 2]);                   // true — deep: every element is tested
$t->assert($value);                  // returns $value, or throws TypeError("Expected list<int>, got string")
$t->orNull($value);                  // the `as?` form

$u = Type::of(User::class);          // a class type, as an element of a structural type
Type::list($u)->check($users);       // every element is a User
$u->className();                     // User::class
```

Constructors: `of`, `int`, `float`, `string`, `bool`, `null`, `mixed`, `object`, `array`,
`iterable`, `callable`, `resource`, `list`, `map`, `nullable`, `union`, `intersection`,
`literal`, `classString`. Queries: `check`, `assert`, `orNull`, `className`, `name`, `equals`,
`isSubtypeOf`.

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
