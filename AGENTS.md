# phaenon/debug — agent guide

The Composer package `phaenon/debug`: the reader of the line map every compiled `.phn` file ends
with, and the Monolog and Sentry integrations built on it that point production errors at `.phn`
positions. A project opts into it; compiled code never calls it, because the output depends on
nothing but PHP (design: `../design/roadmap.md` D24, `../design/line-map.md` L6). Until 0.3 it
was `phaenon/runtime` (`../design/tooling.md` T16), which held `\Phaenon\Type`,
`\Phaenon\Bytes`, `Seq`, `Map` and `Str` as well; a type test is PHP's own now, `bytes` is
converted by PHP's own functions, and the method syntax is gone (`../design/roadmap.md` D26 and
D20, `../design/strings.md` §6).

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
  `.phn` source, then regenerated; the compiler's test suite (`TestDebugPackageFresh` in
  `internal/emit`) fails while the copies here are stale, and `phn stdlib --out src --check`
  reports the same. A bug found from this side is recorded as a skipped test naming it until the
  source is fixed.
- **`tests/Debug/vectors.json` is a verbatim copy** of the compiler's
  `internal/linemap/testdata/vectors.json`, the reference every line-map reader is tested
  against; the compiler's freshness test compares the copies. Refresh it by copying, never edit
  it here. `tests/Debug/Fixture/build/*.php` are compiled files written by hand to the D19 layout,
  with `src/*.phn` as their sources.

## Rules

- Applications call this API by name (their configuration registers the processor and the
  integration; another error reporter calls `ThrowableRewriter`), so renaming or removing a
  public method breaks them.
- `composer.json` requires PHP 8.3 and no extension. Raise the floor only when the compiled
  sources need it, and say so in the README.
- Tests: one final test class per unit in `tests/`, PHPUnit attributes, `self::assert…`; edge
  cases (empty input, null returns, copies left unchanged, the exceptions the docblocks
  promise) are part of the contract. PHPStan runs on the tests too; the identifiers relaxed
  under `tests/*` in `phpstan.neon` each carry their reason.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `build:` …
  with a body that says what changed and why), with no attribution lines and no
  `Co-Authored-By`: the repository will be public.
- Every pull request targets `main`, never the branch of another open pull request: CI and the
  PR semantics check start only on pull requests into `main`, and GitHub closes a stacked pull
  request for good when the branch under it is deleted. Work that depends on an unmerged change
  goes into the same pull request, or waits for the merge and branches from `main`.
