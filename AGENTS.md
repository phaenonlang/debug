# phaenon/runtime — agent guide

The Composer package `phaenon/runtime`: `\Phaenon\Type`, the immutable, interned type descriptor
that code compiled from Phaenon uses for reified type parameters and for `is` / `as` on structural
types (design: `../design/generics.md` §5.5 and §11). The map of every Phaenon repo, the working
method and the commit rules are in the compiler repo: read `../phaenon/AGENTS.md` first.

```sh
composer install
composer test        # PHPUnit 12
composer stan        # PHPStan at level max over src/ and tests/
```

- The compiler emits calls to this API (`../phaenon/internal/emit/generics.go`), so renaming or
  removing a public method breaks compiled code.
- The compiler's checker reads a hand-kept declaration of this API,
  `../phaenon/stdlib/runtime/Type.php`. Change both together.
- Commit messages read as human-written prose, with no attribution lines and no `Co-Authored-By`:
  the repository will be public.
