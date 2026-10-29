# phaenon/runtime — agent guide

The Composer package `phaenon/runtime`: `\Phaenon\Type`, the immutable, interned type descriptor
that code compiled from Phaenon uses for `is` / `as` on structural types (design:
`../design/generics.md` §5.5, §11 and §11.2 — `reified` is gone, the descriptor never appears in a
signature). The map of every Phaenon repo, the working
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
- Commit messages say what changed and why, with no attribution lines and no `Co-Authored-By`: the
  repository will be public.
