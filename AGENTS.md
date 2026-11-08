# phaenon/runtime — agent guide

The Composer package `phaenon/runtime`, what code written in Phaenon calls by name at runtime.
The compiler introduces no call into it: `is` and `as` compile to PHP's own tests, and
`\Phaenon\Type`, the descriptor they once compiled to, is gone (design: `../design/generics.md`
§11.4, `../design/roadmap.md` D23 and D24). So is `\Phaenon\Bytes`: `$b as string` is the
checked conversion of `bytes` to text, lowered to `mb_check_encoding()`, and PHP's own functions
are the rest (`../design/strings.md` §6). `Seq`, `Map` and `Str` were removed with the method
syntax (`../design/roadmap.md` D20).

- `\Phaenon\Debug`, used on error paths only (design: `../design/line-map.md` §3 and §4.3):
  `LineMap` reads the line map at the end of a compiled file; `ThrowableRewriter` points a
  throwable at its `.phn` positions in place; `Monolog\LineMapProcessor` and
  `Sentry\LineMapIntegration` apply it to log records and Sentry events. Monolog 3 and the Sentry
  SDK 4 are `suggest`ed and in `require-dev`, never required.

The map of every Phaenon repo, the working method and the commit rules are in the compiler
repo: read `../phaenon/AGENTS.md` first.

```sh
composer install
composer test        # PHPUnit 12
composer stan        # PHPStan at level max over src/ and tests/
```

## Where the files come from

- **`src/` is generated: never edit it.** The compiler compiles it from its own
  `../phaenon/stdlib/runtime/**/*.phn` with `phn stdlib --out src` (run in this repository),
  and reads those `.phn` files as its declaration of the API. A wrong behaviour is fixed in the
  `.phn` source, then regenerated; the compiler's test suite (`TestRuntimePackageFresh` in
  `internal/emit`) fails while the copies here are stale, and `phn stdlib --out src --check`
  reports the same. A bug found from this side is recorded as a skipped test naming it until the
  source is fixed.
- **`tests/Debug/vectors.json` is a verbatim copy** of the compiler's
  `internal/linemap/testdata/vectors.json`, the reference every line-map reader is tested
  against; the compiler's freshness test compares the copies. Refresh it by copying, never edit
  it here. `tests/Debug/Fixture/build/*.php` are compiled files written by hand to the D19 layout,
  with `src/*.phn` as their sources.

## Rules

- Code written in Phaenon calls this API by name, so renaming or removing a public method breaks
  it.
- `composer.json` requires PHP 8.3 and no extension. Raise the floor only when generated code
  needs it, and say so in the README.
- Tests: one final test class per unit in `tests/`, PHPUnit attributes, `self::assert…`; edge
  cases (empty input, null returns, copies left unchanged, the exceptions the docblocks
  promise) are part of the contract. PHPStan runs on the tests too; the identifiers relaxed
  under `tests/*` in `phpstan.neon` each carry their reason.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `build:` …
  with a body that says what changed and why), with no attribution lines and no
  `Co-Authored-By`: the repository will be public.
