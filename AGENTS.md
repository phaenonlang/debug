# phaenon/runtime — agent guide

The Composer package `phaenon/runtime`, what code compiled from Phaenon needs at runtime:

- `\Phaenon\Type`, the immutable, interned type descriptor behind `is` / `as` on structural
  types (design: `../design/generics.md` §5.5, §11 and §11.2 — `reified` is gone, the
  descriptor never appears in a signature).
- `\Phaenon\Seq`, `Map`, `Str` and `Bytes`, the stdlib behind method syntax on `list`, `map`,
  `string` and `bytes` values (design: `../design/collections.md` §3 and §7,
  `../design/strings.md` §2–§4 and §6). `$xs->map($f)` compiles to `Seq::map($xs, $f)`; the
  receiver comes first, callbacks last; a `bytes` value is a PHP string at runtime.

The map of every Phaenon repo, the working method and the commit rules are in the compiler
repo: read `../phaenon/AGENTS.md` first.

```sh
composer install
composer test        # PHPUnit 12
composer stan        # PHPStan at level max over src/ and tests/
```

## Two directions of flow

- **`src/Type.php` is hand-kept here.** The compiler's checker reads a copy of its declaration,
  `../phaenon/stdlib/runtime/Type.php`: change both together.
- **`src/Seq.php`, `Map.php`, `Str.php` and `Bytes.php` are generated: never edit them.** The
  compiler compiles them from its own `../phaenon/stdlib/runtime/*.phn` with
  `phn stdlib --out src` (run in this repository), and reads those `.phn` files as its
  declaration of the API. A wrong behaviour is fixed in the `.phn` source, then regenerated;
  the compiler's test suite (`TestRuntimePackageFresh` in `internal/emit`) fails while the
  copies here are stale, and `phn stdlib --out src --check` reports the same. A bug found from
  this side is recorded as a skipped test naming it until the source is fixed.

## Rules

- The compiler emits calls to this API (`../phaenon/internal/emit/`), so renaming or removing a
  public method breaks compiled code.
- `composer.json` requires PHP 8.3 (`mb_str_pad`) and `ext-mbstring`; `ext-intl` is suggested
  for the grapheme functions. Raise the floor only when generated code needs it, and say so in
  the README.
- Tests: one final test class per unit in `tests/`, PHPUnit attributes, `self::assert…`; edge
  cases (empty input, null returns, copies left unchanged, the exceptions the docblocks
  promise) are part of the contract. PHPStan runs on the tests too; the identifiers relaxed
  under `tests/*` in `phpstan.neon` each carry their reason.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `build:` …
  with a body that says what changed and why), with no attribution lines and no
  `Co-Authored-By`: the repository will be public.
