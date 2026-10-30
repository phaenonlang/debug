# phaenon/runtime

The runtime half of [Phaenon](https://phaenon.dev), a typed dialect of PHP that compiles to
PHP. Compiled code depends on this package in two places:

- **`is` / `as` on a structural type**, which PHP cannot test on its own: `$x is list<int>`
  compiles to `\Phaenon\Type::list(\Phaenon\Type::int())->check($x)`, and `$x as map<string,
  int>` to the matching `->assert($x)`.
- **Method syntax on lists, maps, strings and bytes**: `$xs->map($f)` compiles to
  `\Phaenon\Seq::map($xs, $f)`, and likewise `Map`, `Str` and `Bytes` for the other three types.

Everything else compiles to plain PHP with no dependency on this package. Class types need no
descriptor: `$x is User` is `instanceof`, and a generic that needs its type parameter at runtime
holds a `class<T>` value — a plain class-name string — the way PHP code does today.

## Requirements

PHP 8.3 or later (`Str::padStart` / `padEnd` use `mb_str_pad`, new in 8.3; nothing in the
package needs 8.4) with `ext-mbstring`, which `Str` and `Bytes::text` rely on throughout.
`ext-intl` is optional: `Str::graphemeLength()` and `Str::truncate()` count grapheme clusters
with the `grapheme_*` functions and need it; every other method works without it.

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

## The stdlib: `Seq`, `Map`, `Str` and `Bytes`

Four final classes of static functions, one per Phaenon type that has methods. They are the
functions a PHP developer would have written over `array_*`, `mb_*` and friends, with the
value semantics PHP already has: every function takes **the receiver first, then the operands,
callbacks last**, and returns a new value. Nothing is mutated, sorting included.

In Phaenon source they are the methods of the value, and the compiler lowers each call to the
static form, so the PHP it emits reads as an ordinary static call:

```php
$emails = $users->filter(fn (User $u) => $u->active)->map(fn (User $u) => $u->email);
// → Seq::map(Seq::filter($users, fn (User $u) => $u->active), fn (User $u) => $u->email)
```

The classes are plain PHP, so PHP code can call them too.

### `Seq` — `list<T>`

A `list<T>` is a PHP array with keys `0..n-1`. Results are reindexed lists; `sort` and `sortBy`
return a sorted copy and leave the input alone.

```php
use Phaenon\Seq;

Seq::map([1, 2, 3], fn (int $x): int => $x * 2);              // [2, 4, 6]
Seq::filter([1, 2, 3, 4], fn (int $x): bool => $x % 2 === 0); // [2, 4]
Seq::first([]);                                               // null, never a warning
Seq::sort([3, 1, 2]);                                         // [1, 2, 3]; the argument is unchanged
Seq::zip([1, 2, 3], ['a', 'b']);                              // [[1, 'a'], [2, 'b']]
Seq::groupBy(['apple', 'banana', 'avocado'], fn (string $s): string => $s[0]);
                                                              // ['a' => ['apple', 'avocado'], 'b' => ['banana']]
Seq::chunk([1, 2, 3], 0);                                     // throws \ValueError
```

`map`, `filter`, `reduce`, `each`, `first`, `last`, `contains`, `indexOf` (`?int`, never
`false`), `slice`, `concat`, `reverse`, `sort`, `sortBy`, `unique`, `flatten`, `chunk`, `zip`,
`keyBy`, `groupBy`, `join`, `isEmpty`, `count`.

### `Map` — `map<K, V>`

A `map<K, V>` is a PHP array keyed by `int|string`. Callbacks receive the value first, then
the key. `merge` lets the last map win key by key, as `array_replace` does; integer keys keep
their value (unlike `array_merge`).

```php
use Phaenon\Map;

Map::get(['a' => 1], 'b');                        // null
Map::merge(['a' => 1, 'b' => 2], ['b' => 20]);    // ['a' => 1, 'b' => 20]
Map::with(['a' => 1], 'b', 2);                    // ['a' => 1, 'b' => 2]; the argument is unchanged
Map::without(['a' => 1, 'b' => 2], 'a');          // ['b' => 2]
Map::toList(['a' => 1]);                          // [['a', 1]]
Map::fromList([['a', 1], ['b', 2]]);              // ['a' => 1, 'b' => 2]
```

`get`, `has`, `keys`, `values`, `map`, `filter`, `each`, `merge`, `with`, `without`, `toList`,
`fromList`, `isEmpty`, `count`.

### `Str` — `string`

A `string` is UTF-8 text and **the unit is the code point**: `length`, `slice`, `indexOf` and
`chars` count code points and never split a character. Grapheme clusters are explicit:
`graphemes`, `graphemeLength` and `truncate` are for text shown to a person. Every function
accepts a `\Stringable` in place of the string, so a value object has the `Str` methods too.

```php
use Phaenon\Str;

Str::length('héllo');                 // 5
Str::slice('héllo', 1, 3);            // 'éll'
Str::indexOf('héllo', 'x');           // null, never false
Str::chars("👨‍👩‍👧");                 // 5 code points …
Str::graphemeLength("👨‍👩‍👧");        // … one grapheme cluster
Str::truncate('hello world', 5);      // 'hello…'
Str::split('a,b', '');                // throws \ValueError: chars() splits into code points
Str::startsWith($userId, 'u-');       // $userId may be a \Stringable
```

`length`, `isEmpty`, `upper`, `lower`, `upperFirst`, `trim`, `trimStart`, `trimEnd`,
`startsWith`, `endsWith`, `contains`, `indexOf`, `lastIndexOf`, `slice`, `split`, `lines`,
`replace`, `replaceAll`, `repeat`, `padStart`, `padEnd`, `chars`, `graphemes`,
`graphemeLength`, `truncate`, `matches`, `bytes`.

### `Bytes` — `bytes`

A `bytes` value **is a PHP string at runtime**: the type exists in Phaenon, where binary data
and text are distinct, and erases to `string` in the output. `Str::bytes()` and the `(bytes)`
cast cost nothing; `Bytes::text()` is the one conversion that scans, because it validates
UTF-8.

```php
use Phaenon\Bytes;

Bytes::fromHex('48656c6c6f');                       // 'Hello'
Bytes::base64(random_bytes(16), urlSafe: true);     // '-' and '_', no padding
Bytes::fromBase64('SGVsbG8');                       // either alphabet, padding optional
Bytes::text("h\xC3\xA9llo");                        // 'héllo'
Bytes::text("\xFF");                                // throws \ValueError: not valid UTF-8
Bytes::textLossy("a\xFFb");                         // "a\u{FFFD}b"
Bytes::at("\x00\xFF", -1);                          // 255; out of range throws \OutOfRangeException
Bytes::equals($signature, $expected);               // constant time
```

`fromHex`, `fromBase64`, `length`, `isEmpty`, `slice`, `concat`, `hex`, `base64`, `text`,
`textLossy`, `equals`, `at`.

## Generated code

`src/Seq.php`, `src/Map.php`, `src/Str.php` and `src/Bytes.php` are **compiled by the Phaenon
compiler** from its own stdlib sources, `stdlib/runtime/*.phn` in the compiler repository, with
`phn stdlib --out src`. Those `.phn` files are the one source: the compiler reads them as the
declaration of this API, and the compiled PHP is what this package ships. Do not edit the four
files; change the `.phn` source and regenerate. The compiler's test suite checks that the
copies here are fresh, and `phn stdlib --out src --check` does the same from the command line.

`src/Type.php` is written by hand; the compiler holds a copy of its declaration.

## Development

```sh
composer install
composer test
composer stan
```

License: MIT.
