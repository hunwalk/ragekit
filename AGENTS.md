# Notes for AI coding agents

RageKit is largely written with AI coding assistants (see the README). This file collects what an
agent needs to work on it without breaking things. Read [CONTRIBUTING.md](CONTRIBUTING.md) (setup,
tests, code rules) and [ARCHITECTURE.md](ARCHITECTURE.md) (where everything lives) first; this page
adds the details that are easy to get wrong.

## Commands

```sh
./setup.sh                         # Composer deps, the pinned TypePHP + Slint, ragekit doctor
vendor/bin/phpunit                 # needs the toolchain and PHP's mbstring extension
cd demo/counter-slint && ../../bin/ragekit run      # build + start a demo (--nano for Nano)
DISPLAY=:99 tests/e2e/demos.sh [--nano]             # build, open and close every demo under Xvfb
```

GUI checks need no desktop: run Xvfb and drive the windows with the helpers in `tests/e2e/`
(see its README). Unset `WAYLAND_DISPLAY` so Slint uses X11. On Xvfb there's no window manager:
windows open at 0,0, so window coordinates are screen coordinates.

## Two kinds of PHP

- **`src/Cli/`** is the `ragekit` command. It runs on the developer's PHP (8.2 or later) and is
  never compiled into apps. Errors are `RageKitException`, printed as one `ragekit: …` line.
- **Everything compiled into apps** (`src/*.php`, `src/Slint/*.php`, modules' `src/`, the demos)
  goes through TypePHP, which compiles a subset of PHP. Rules that bite:
  - every `switch` case, including `default:`, ends with `break`/`return`/`continue`/`throw`;
  - functions declared in `.stub.php` files are called fully qualified (`\slint_open()`), even
    inside a namespace, or the C++ declaration isn't found;
  - a variable keeps one static type; don't reassign it to another class;
  - no `abstract static` methods (they compile but fail at start), no class named `Box`;
  - Nano builds also have no `eval`/`include`, sockets, processes or anonymous classes, and some
    functions (e.g. `ctype_*`) stop the app at start.
  `ragekit dev` runs full PHP, so it won't catch these: build to check.
- `src/Slint/Dev/` is the dev runtime: plain PHP, never compiled. Backends list their compiled files
  one by one in `backend.yml`; never add a whole folder there.

## Native code

- `native/slint/host`: C++17 (Nano requires it), compiled into the executable by TypePHP.
- `native/slint/bridge`: C++20 (Slint requires it). The only code that calls Slint or the OS; the
  host reaches it through plain C functions. Keep OS calls out of the host, or Nano's import check
  fails.
- `native/slint/devshell`: C++20, the `ragekit dev` window process (Slint's interpreter).
- Module libraries are loaded at run time with `dlopen`, never linked. An app must still start
  without them.
- Slint's `int` is 32-bit and its `float` 32-bit too; generated glue narrows PHP's values.

## Templates and kits

`.rk` templates are compiled to Slint at build time (`src/Cli/Templates`, `src/Cli/Rageblade`, `src/Cli/Kits`, `src/Cli/Slint`). Every
kit accepts the same core element attributes (see docs/templates.md and docs/kits.md); a change to
that contract changes every kit and the docs. Template errors name the file and line and say what's
wrong; keep new errors that way.

## Rules for changes

- Every change comes with a test that fails without it; GUI-visible changes are also checked under
  Xvfb (normal and Nano builds).
- Keep claims precise in code comments and docs: RageKit draws its own widgets (not the OS's), and
  its executables contain PHP's runtime (a stripped-down one in Nano builds). Never write "native
  widgets" or "no PHP runtime".
- Windows paths exist but are tested less; macOS isn't supported. Say so instead of guessing.
- Third-party code is fetched by checksum (`fetch:` in kit.yml / module.yml, pins in
  `toolchain/toolchain.yml`), never committed.
