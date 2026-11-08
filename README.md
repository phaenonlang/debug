# phaenon/runtime

Runtime support for [Phaenon](https://phaenon.dev), a typed dialect of PHP that compiles to PHP.
The compiler introduces no call into this package: `is` and `as` compile to PHP's own tests —
`$x is int` to `\is_int($x)`, `$x is list` to `\is_array($x) && \array_is_list($x)` — and a
generic that needs its type parameter at runtime holds a `class<T>` value, a plain class-name
string, the way PHP code does today.

What it holds is called by name: **`Phaenon\Debug`**, opt-in and used only on error paths. It
reads the line map at the end of every compiled file and points logged exceptions and Sentry
events at `.phn` source positions ([below](#phaenondebug--source-positions-in-production)).

Install: `composer require phaenon/runtime` (the compiler's package, `phaenon/phn`, requires it
already).

## Requirements

PHP 8.3 or later. `monolog/monolog` 3 and `sentry/sentry` 4 are suggested, not required: each
integration in `Phaenon\Debug` loads only when the application registers it, which it does only
with its library installed.

The package once held `\Phaenon\Type` as well, the descriptor `is` and `as` compiled to on a
type PHP cannot test itself (`$x is list<int>`). Phaenon tests only what PHP tests in O(1) now,
and such a test is an error (design: [`generics.md`](https://github.com/phaenonlang/design/blob/main/generics.md)
§11.4).

It held `\Phaenon\Bytes` too, the conversions between Phaenon's `bytes` and text. A `bytes` value
is a PHP string at runtime, and the conversions are PHP's own: `$b as string` compiles to
`\mb_check_encoding($b, 'UTF-8') ? $b : throw new \ValueError('not valid UTF-8')`, and
`hex2bin()`, `bin2hex()`, `base64_decode()`, `base64_encode()` and `mb_scrub()` do the rest
(design: [`strings.md`](https://github.com/phaenonlang/design/blob/main/strings.md) §6).

The package once held `Seq`, `Map` and `Str` as well, behind a method syntax on lists, maps and
strings that Phaenon no longer has (design: [`roadmap.md`](https://github.com/phaenonlang/design/blob/main/roadmap.md)
D20): PHP's array functions, `mb_*`, `grapheme_*` and symfony/string do that work, chained with
pipes.

## `Phaenon\Debug` — source positions in production

PHP has no source maps: a stack trace, a warning, a log line report the line of the code that ran,
and that is `.src/Foo.php`, not `src/Foo.phn`. So every file `phn build` compiles ends with a
comment, its **line map**, that says which `.phn` line each line of the output came from
(design: [`line-map.md`](https://github.com/phaenonlang/design/blob/main/line-map.md) §3):

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

Everything in `src/` is **compiled by the Phaenon compiler** from its own stdlib sources,
`stdlib/runtime/**/*.phn` in the compiler repository, with `phn stdlib --out src`. Those `.phn`
files are the one source: the compiler reads them as the declaration of this API, and the
compiled PHP is what this package ships. Do not edit these files; change the `.phn` source and
regenerate. The compiler checks the `Debug` integrations against its own declaration of the
Monolog and Sentry API they use; PHPStan here checks the compiled code against the real
libraries. The compiler's test suite checks that the copies here are fresh, and
`phn stdlib --out src --check` does the same from the command line.

## Development

```sh
composer install
composer test
composer stan
```

License: MIT.
