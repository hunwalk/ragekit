# Contributing

Thanks for looking. RageKit is experimental, so open an issue before a large change: the design
may already be moving.

Working with an AI coding agent? Point it at [AGENTS.md](AGENTS.md).

## Set up

```sh
./setup.sh        # Linux; setup.ps1 on Windows
```

That installs the CLI's Composer dependencies, the pinned toolchain (TypePHP and Slint, shared
per user, or into `.ragekit/` if the repository root has one) and runs `ragekit doctor`. See
[docs/toolchain.md](docs/toolchain.md).

## Tests

```sh
vendor/bin/phpunit
```

The suite needs the toolchain from `ragekit setup` (some tests build C++ and run the dev shell
headless) and PHP's `mbstring` extension. Window-level checks of the demos run under Xvfb; see
[tests/e2e/README.md](tests/e2e/README.md).

CI (`.github/workflows/ci.yml`) runs on every push and pull request, on Ubuntu 24.04:
`ragekit setup`, `ragekit doctor`, the test suite, then a Nano and a normal build of every demo
(the normal ones link Ubuntu's `libphp8.4-embed`), each started and closed under Xvfb
(`tests/e2e/demos.sh`). Windows and macOS aren't covered.

Every change comes with a test that fails without it. Bug fixes start with a test that
reproduces the bug.

## Code rules

- `src/Cli/` is the `ragekit` command. It runs on the developer's PHP and must work on PHP 8.2.
- PHP that ends up in apps (`src/*.php`, `src/Slint/*.php`, modules' `src/`) is compiled by
  TypePHP, which accepts a subset of PHP. In particular: every `switch` case ends with
  `break`/`return`/`continue`/`throw`; functions from `.stub.php` files are called fully
  qualified (`\slint_open()`); no `abstract static` methods; no class named `Box`.
- C++ in `native/` and modules: the host is C++17 (Nano builds require it), the bridge and dev
  shell are C++20 (Slint requires it). Only the bridge calls Slint or the OS.
- Keep claims precise: RageKit draws its own widgets and its executables contain PHP's runtime
  (a stripped-down one in Nano builds). Don't describe it as "native widgets" or "no PHP
  runtime".

## Commits and pull requests

Small, focused commits with a message that says why. A pull request describes what changed, how
you tested it, and on which OS.

By contributing you agree that your contribution is licensed under the GPLv3, like the rest of
RageKit. You also allow the project's maintainer to license your contribution under other open
source licenses in future versions (for example to let apps built with RageKit use any license).
Versions already released keep their license.
