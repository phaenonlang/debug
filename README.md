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

A third part is opt-in and used only on error paths: **`Phaenon\Debug`** reads the line map at the
end of every compiled file and points logged exceptions and Sentry events at `.phn` source
positions ([below](#phaenondebug--source-positions-in-production)).

## Requirements

PHP 8.3 or later (`Str::padStart` / `padEnd` use `mb_str_pad`, new in 8.3; nothing in the
package needs 8.4) with `ext-mbstring`, which `Str` and `Bytes::text` rely on throughout.
`ext-intl` is optional: `Str::graphemeLength()` and `Str::truncate()` count grapheme clusters
with the `grapheme_*` functions and need it; every other method works without it.
`monolog/monolog` 3 and `sentry/sentry` 4 are suggested, not required: each integration in
`Phaenon\Debug` loads only when the application registers it, which it does only with its
library installed.

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

## `Phaenon\Debug` — source positions in production

PHP has no source maps: a stack trace, a warning, a log line report the line of the code that ran,
and that is `.src/Foo.php`, not `src/Foo.phn`. So every file `phn build` compiles ends with a
comment, its **line map**, that says which `.phn` line each line of the output came from
(design: `line-map.md` §3):

```php
// phaenon-map v1 ../src/Domain/Foo.phn 1-6=1 7:1 15-17=9 18:9
```

`7:1` is a run (output line 7 is source line 1, and the lines after it follow one for one),
`15-17=9` a hold (output lines 15 to 17 — a docblock the compiler wrote — all stand for source
line 9). The path is relative to the compiled file and resolved without touching the file system,
so it holds in a container whose root is not the build machine's, and the `.phn` need not be
deployed for the positions to be right.

Nothing here runs on a request that throws nothing: the map of a file is read when a position in
it is first asked for, once per file and process, and kept.

### `LineMap`

```php
use Phaenon\Debug\LineMap;

$map = LineMap::forFile('/app/.src/Domain/Foo.php');  // null for hand-written PHP: no map
$map->sourceFile();       // '/app/src/Domain/Foo.phn'
$map->sourceLine(16);     // 9: forward, a compiled line → its source line
$map->compiledLine(9);    // 18: reverse, a source line → the first compiled line of a run, never a hold
LineMap::locate('/app/.src/Domain/Foo.php', 16);   // ['/app/src/Domain/Foo.phn', 9], or null
```

The reader finds the map on the last line, on a last line `<?php // phaenon-map …` (a file that
ends in inline HTML), or on the line before `__halt_compiler()`. A map of a version it does not
know, or a malformed one, counts as none: positions in that file stay as they are.

### `ThrowableRewriter`

`ThrowableRewriter::rewrite($e)` points a throwable at its source **in place**: the file and line
it was thrown at, the file and line of every frame of its trace, the lines in closure names
(`{closure:App\Foo::bar():12}`), and the same for every `getPrevious()`. PHP reports these only
through `Throwable`'s final getters, so the rewrite sets the properties behind them by reflection,
as Symfony's `ErrorHandler` sets the trace of the errors it turns into exceptions. Everything that
reads the throwable afterwards sees `.phn` positions: every Monolog formatter, Sentry, Symfony's
error page, PHP's own output when the exception is rethrown and never caught. A throwable is
rewritten once; `rewrite()` on it again does nothing, and `compiledPosition($e)` still tells where
it was thrown in the compiled file. The Monolog processor below is built on it; another error
reporter can call it the same way.

### Monolog: `Monolog\LineMapProcessor`

A Monolog 3 processor: the record's `context.exception` is rewritten as above, so the line, JSON,
Logstash and console formatters, and every handler (Sentry's Monolog handler too), write source
positions. A record message that quotes the exception's compiled position — Symfony's
`Uncaught PHP Exception …: "…" at Foo.php line 57`, Monolog's own error handler — quotes the source
position instead. A record without an exception costs one array lookup; a failure to translate
leaves the record as it was, never drops it.

```php
$logger->pushProcessor(new \Phaenon\Debug\Monolog\LineMapProcessor());
```

### Sentry: `Sentry\LineMapIntegration`

A Sentry SDK 4 integration. The SDK builds an event's frames before any event processor runs, so
the integration's event processor replaces each frame in a compiled file with one in the `.phn`:
the path (absolute, and stripped of the `prefixes` option as the SDK strips paths, which is what
Sentry's code mappings match), the line, closure names. `in_app`, the function name and the frame's
variables stay as the SDK computed them. The context lines Sentry shows come from the `.phn` when it
is deployed beside the compiled tree — the same number of lines around the source line as the SDK
read from the compiled file; without it they stay the compiled excerpt, the code that ran. A
throwable the Monolog processor already rewrote reaches Sentry with source positions and is left
alone. The integration never throws (the SDK drops an event whose processor throws).

```php
\Sentry\init([
    'dsn' => '…',
    'integrations' => [new \Phaenon\Debug\Sentry\LineMapIntegration()],
]);
```

A list in `integrations` comes after the SDK's default integrations, the order the context lines
rely on.

### Registering both in Symfony

```yaml
# config/services.yaml
services:
    Phaenon\Debug\Monolog\LineMapProcessor:
        tags: [monolog.processor]

    Phaenon\Debug\Sentry\LineMapIntegration: ~

# config/packages/sentry.yaml (sentry/sentry-symfony)
sentry:
    options:
        integrations:
            - Phaenon\Debug\Sentry\LineMapIntegration
```

The tag without a `channel` or `handler` adds the processor to every logger. Symfony logs an
uncaught exception before it renders the error page, so in `dev` the page shows `.phn` positions
and, from the `.phn`, the code around them.

## Generated code

`src/Seq.php`, `src/Map.php`, `src/Str.php`, `src/Bytes.php` and everything under `src/Debug/` are
**compiled by the Phaenon compiler** from its own stdlib sources, `stdlib/runtime/**/*.phn` in the
compiler repository, with `phn stdlib --out src`. Those `.phn` files are the one source: the
compiler reads them as the declaration of this API, and the compiled PHP is what this package
ships. Do not edit these files; change the `.phn` source and regenerate. The compiler checks the
`Debug` integrations against its own declaration of the Monolog and Sentry API they use; PHPStan
here checks the compiled code against the real libraries. The compiler's test suite checks that the
copies here are fresh, and `phn stdlib --out src --check` does the same from the command line.

`src/Type.php` is written by hand; the compiler holds a copy of its declaration.

## Development

```sh
composer install
composer test
composer stan
```

License: MIT.
