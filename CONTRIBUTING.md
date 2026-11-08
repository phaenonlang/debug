# Contributing to phaenon/debug

Issues about this package go to [its tracker](https://github.com/phaenonlang/debug/issues);
issues about the compiler or the language, to
[phaenonlang/phaenon](https://github.com/phaenonlang/phaenon/issues). A vulnerability is
reported privately: see [SECURITY.md](SECURITY.md).

## Where a change goes

All of `src/` is generated: it is compiled by the Phaenon compiler from its own sources,
[`stdlib/runtime/`](https://github.com/phaenonlang/phaenon/tree/main/stdlib/runtime) in the
compiler repository, with `phn stdlib --out src`, and the compiler reads those `.phn` files as
its declaration of this API. A change to these files, a fix included, is therefore a pull
request to the compiler that changes the `.phn` source; the files here are regenerated from it.
An edit to a generated file here would be undone by the next regeneration, and the compiler's
test suite fails while the two disagree.

- `tests/` is edited here. A bug found from this side can come as a test here, skipped with a
  note that names the bug, until the source is fixed.
- `tests/Debug/vectors.json` is a copy of the compiler's
  `internal/linemap/testdata/vectors.json`, the reference every line-map reader is tested
  against: it is refreshed by copying, never edited here.

Applications call this API by name, so renaming or removing a public method breaks them: it is
a breaking change.

## Running the tests

PHP 8.3 or later and Composer:

```sh
composer install
composer test        # PHPUnit
composer stan        # PHPStan at level max over src/ and tests/
```

CI runs both on PHP 8.5 for every pull request.

## Pull requests

A pull request is squash-merged: its title becomes the commit on `main`, and its commits'
messages that commit's body. So a pull request needs:

- **A Conventional Commit title, and Conventional Commits**
  ([conventionalcommits.org](https://www.conventionalcommits.org)): `type(scope): subject`, the
  type one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`,
  `chore`, `revert`, with `!` after it (or a `BREAKING CHANGE:` footer) for a breaking change.
  Merge commits are exempt; `fixup!` and `squash!` commits are squashed before the merge.
- **A title as strong as its strongest commit, and no stronger**: a breaking change above
  `feat`, `feat` above `fix` and `revert`, those above the rest. A `fix:` title over a `feat:`
  commit understates the change; a `feat:` title over `docs:` commits promises one that is not
  there.
- **Green checks**: `composer validate --strict`, `composer test` and `composer stan`. A change
  in behaviour comes with the tests that show it.

The PR semantics check enforces the first two points, CI the third.
