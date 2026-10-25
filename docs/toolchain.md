# The toolchain

How a RageKit app becomes an executable: the tools involved, how `ragekit setup` installs them,
what each build step does, normal versus Nano builds, and how to fix common errors.

## The pieces

- **TypePHP** compiles your PHP ahead of time: PHP → C++ → machine code. It compiles a subset of
  PHP (no `eval`, static types per variable, …); see TypePHP's own documentation for the details.
- **A C++ compiler** (g++ on Linux, MSVC on Windows) compiles what TypePHP generates and RageKit's
  native code.
- **Slint** draws the UI. RageKit turns your `.rk` templates into Slint code at build time, and
  Slint's compiler turns that into C++. Slint draws its own widgets (on the CPU or the GPU), so
  RageKit apps don't use the operating system's widgets and don't contain a browser engine.

RageKit's native code comes in two parts, because the two sides need different C++ versions:

- **The bridge** (`libragekit_slint`, C++20): everything that touches Slint or the operating
  system: windows, events, loading module libraries. Slint requires C++20. RageKit builds it
  with the C++ compiler and places it next to the executable.
- **The host** (C++17): the `\slint_*` functions your PHP calls, compiled into the executable by
  TypePHP. Nano builds require C++17, so the host stays C++17 and talks to the bridge through
  plain C functions.

## Pinned versions

RageKit is tested with exact versions of TypePHP and Slint, recorded in
`toolchain/toolchain.yml` inside RageKit:

- **TypePHP**: a git commit of its source repository, plus a `composer.lock` for its
  dependencies (`toolchain/typephp/composer.lock`) and patch files RageKit applies on top
  (`toolchain/typephp/patches/`). RageKit uses TypePHP's source, not a release, because the
  patches change its source.
- **Slint**: release downloads (the C++ package and its compiler), each checked against a
  sha256 checksum.

`platforms:` adds per-platform entries, keyed `<os>-<arch>` (`linux-x86_64`,
`windows-x86_64`) or just `<os>` (`linux`, `windows`, `macos`), the same keys as in `module.yml` and
`backend.yml`. The `<os>` entry is applied first, then the `<os>-<arch>` one. Lists in a platform
entry are appended to the general ones. There are no
entries for macOS yet.

### Patches RageKit carries

- `php-nano-core-dependency.patch`: TypePHP's minimal runtime (php-nano) refused to start any Nano
  app that uses a core PHP function such as `strlen` ("Unable to start PHP Nano extensions"),
  because it didn't count the Core extension as started. The patch skips that check for Core.

Patches are meant to shrink: each one goes away once the fix is in TypePHP itself.

## ragekit setup

```sh
vendor/bin/ragekit setup        # bin/ragekit setup in a RageKit checkout
```

It installs two parts into a toolchain folder:

- `typephp/`: TypePHP checked out at the pinned commit, `composer.lock` copied in,
  `composer install`, then each patch applied with `git apply` (a patch that doesn't fit stops
  setup with an error).
- `slint/`: the Slint downloads, checked and unpacked (on Windows, the Slint installer is unpacked
  with 7-Zip, not run).
- On Windows, also `typephp/release/`: TypePHP's Windows release, which provides PHP's import
  libraries and DLLs for normal builds.

Which toolchain folder, first match wins (builds look for it the same way, starting from the app's
folder):

1. `RAGEKIT_TOOLCHAIN`, if the environment variable is set.
2. The nearest `.ragekit/` folder from the current folder upward. A RageKit checkout can keep one
   in its root for its demos, and any app can have its own: create an empty `.ragekit/` folder and
   run setup. Add it to `.gitignore`.
3. Otherwise a folder shared by all your apps, one per set of pinned versions:
   `~/.local/share/ragekit/toolchains/<id>/` on Linux (`$XDG_DATA_HOME` is honoured),
   `~/Library/Application Support/ragekit/toolchains/<id>/` on macOS and
   `%LOCALAPPDATA%\ragekit\toolchains\<id>\` on Windows. The `<id>` is a hash of the pins, so apps
   on the same RageKit version share one install (about 440 MB), and an app on another version
   gets its own instead of replacing it.

Old versions in the shared folder aren't removed automatically. `ragekit setup --prune` deletes
every shared toolchain except the one for the RageKit it runs from, printing each one's size and
when a build last used it. An app still on one of those versions needs `ragekit setup` again
before it builds. `.ragekit/` folders and `RAGEKIT_TOOLCHAIN` are never touched.

After installing a part, setup writes a marker (`.ragekit-setup`) holding a fingerprint of what it
installed: the commit, the downloads and the content of the patches and the lock file. The marker
is written last, so an interrupted setup never counts as done; running setup again starts that
part over. Running setup when everything matches does nothing.

Builds compare the marker with RageKit's pins. When you update RageKit and its pins changed, the
build stops with "doesn't match this RageKit version: run `ragekit setup` again". Builds never
download or modify the toolchain themselves.

TypePHP also keeps a build cache of its own in `~/.typephp` (shared by all projects; see
[libphp](#linux-libphp-or-a-private-php)).

### Using your own TypePHP or Slint

Environment variables replace the installed versions. They're used as they are, without
checking them against the pins:

| Variable | Points to |
|---|---|
| `TYPEPHP_HOME` | a TypePHP source checkout (with `composer install` done) or a release (with `tpc` / `tpc.exe`) |
| `SLINT_HOME` | Slint's C++ package (`include/`, `lib/`, `bin/slint-compiler`) |
| `PHP_HOME` | Windows: PHP's import libraries and DLLs for normal builds with a TypePHP checkout. Linux: a PHP installation (`bin/php-config`, `lib/libphp.so`) for tpc to use as it is |
| `RAGEKIT_LIBPHP` | Linux: the libphp normal builds link, or `none` for a private PHP (see [libphp](#linux-libphp-or-a-private-php)) |

## ragekit doctor

```sh
vendor/bin/ragekit doctor
```

```
  ✓ PHP           8.4.26 (/usr/bin/php8.4)
  ✓ TypePHP       5ba93766 + 1 patch (~/.local/share/ragekit/toolchains/270ccbcac5ec/typephp)
  ✓ Slint         1.18.1 (~/.local/share/ragekit/toolchains/270ccbcac5ec/slint)
  ✓ C++ compiler  g++ (Debian 14.2.0-19) 14.2.0
  ✓ git           /usr/bin/git
  ✓ Composer      /usr/local/bin/composer
  ✓ tar           /usr/bin/tar
  ✓ libphp        /usr/lib/libphp8.4.so: normal builds link it
  ✓ Project       …/myapp/project.yml
ragekit: ready to build
```

`✓` is fine, `!` is worth knowing, `✗` blocks a build and says how to fix it. The exit code is 1
when anything shows `✗`. git, Composer, tar (and 7-Zip on Windows) are only needed by `setup`.

## What a build does

`ragekit build` prints one line per step:

1. **Merge config**: `project.yml` plus the backend's settings (`src/Slint/backend.yml`)
   become one TypePHP configuration. On Linux it targets the PHP version running ragekit.
2. **Apply options**: the `ragekit:` options (`renderer`, `nano`, `kit`, …).
3. **tpc workarounds**: small fixes for TypePHP quirks (e.g. it doesn't create its output folder).
4. **Fetch dependencies**: third-party code kits and modules declare under `fetch:` (e.g. the
   Material library, miniaudio), downloaded the first time and checked.
5. **Generate views**: your `.rk` templates become Slint code, Slint's compiler turns it into
   C++, and RageKit generates the glue between your PHP classes and the UI.
6. **Prepare modules**: glue code for the modules in `ragekit: modules:`.
7. **Build libraries**: the C++ compiler builds the bridge (linked to the app) and module
   libraries (loaded at runtime, not linked). Libraries are rebuilt only when their inputs or
   flags change.
8. **Prepare PHP**: which PHP a normal build links (see [libphp](#linux-libphp-or-a-private-php));
   for your own, libphp and libphpx go next to the executable.
9. **Copy runtime files**: Slint's library next to the executable.
10. **Copy assets**: the files in `ragekit: assets:`.
11. **Write tpc.yml**: the final TypePHP configuration (`build/slint/tpc.yml`).
12. **Compile with tpc**: TypePHP compiles your PHP, RageKit's PHP and the host into the
    executable.

`ragekit run` adds a last step that starts the app.

## Normal and Nano builds

Both compile your PHP to machine code. They differ in which PHP runtime comes with it. Neither is
"PHP without a runtime": compiled PHP still needs PHP's engine for strings, arrays, objects and
the standard library.

**Normal builds** (the default) contain PHP's full runtime: linked statically into the
executable on Linux, as separate DLLs (`php8ts.dll`, `phpx.dll`, …) on Windows. Everything
TypePHP supports works.

**Nano builds** (`ragekit: { nano: true }` in `project.yml`, or `ragekit build --nano` for one
build) compile php-nano, a stripped-down PHP runtime, into
the executable itself. No PHP library or DLL ships with the app, the executable is much smaller
(a few MB instead of ~50 MB on Linux) and the app uses somewhat less memory. In exchange:

- no `eval`, `include` or `require` at run time;
- no sockets or network streams;
- no running other processes;
- no anonymous classes;
- some functions and extensions aren't available. TypePHP rejects them at compile time, but not
  every missing piece is caught there: an app can also stop at start with "Unable to start PHP
  Nano extensions".

Nano builds take longer (the runtime is compiled each time). Both kinds need the bridge, Slint's
library and any module libraries next to the executable.

### Linux: libphp or a private PHP

A normal build on Linux links PHP's embed library (libphp) for the PHP running `ragekit`. There
are two ways to get one:

- **Your PHP's** (recommended): on Debian or Ubuntu, `sudo apt install libphp8.4-embed php8.4-dev
  cmake libgmp-dev libmpfr-dev`. The build finds `libphp8.4.so` (or a `libphp.so` / `libphp.a` from a PHP you compiled
  yourself) and, the first time, builds TypePHP's runtime library `libphpx.so` against it with
  cmake (~10 seconds, kept in the toolchain folder until your PHP changes). It shows as
  `Prepare PHP .... libphp8.4.so → libphp.so, libphpx.so` in the build. Both libraries are copied
  next to the executable; the machines you ship the app to also need the libraries libphp itself
  uses (libxml2, libssl, libsodium, libargon2, …).
- **A private PHP**: without a usable libphp (or with `RAGEKIT_LIBPHP=none`), TypePHP builds a
  PHP once, into `~/.typephp/php-builder/`, and links it statically. This takes about ten minutes
  the first time and is reused afterwards.

`ragekit doctor` tells you which one applies and, for the private PHP, which packages are missing. `RAGEKIT_LIBPHP=/path/to/libphp8.4.so` picks a
library by hand. Nano builds need neither.

## Troubleshooting

**`TypePHP isn't set up: run ragekit setup`**: the toolchain folder for this app (see
[ragekit setup](#ragekit-setup)) has no TypePHP. Run `vendor/bin/ragekit setup` in the app's
folder.

**`TypePHP in … doesn't match this RageKit version: run ragekit setup again`**: RageKit was
updated and pins a different TypePHP or different patches, or a previous setup didn't finish.
Run setup again; it replaces the old install.

**`slint-compiler not found at … (run ragekit setup, or set SLINT_HOME)`**: Slint isn't
installed where the build looks. Run setup, or point `SLINT_HOME` at a Slint C++ package.

**`no C++ compiler on PATH (install build-essential)`**: install g++ (Linux). On Windows, install
Visual Studio 2022 Build Tools with "Desktop development with C++" (RageKit's
`install-build-tools.ps1` does it); ragekit finds them itself, so you don't need a developer
command prompt.

**A patch "doesn't fit" during setup**: the pinned commit and the patch disagree, which means
RageKit's pins are inconsistent. Please report it. (Up to 0.1.1 this also happened in a git clone
of RageKit on Windows, whose patch files git had converted to CRLF line endings.)

**TypePHP rejects your code**: TypePHP compiles a subset of PHP. The error names the file and
line; change the code there. `ragekit dev` runs full PHP and won't catch these errors, so build
regularly.

**A step fails**: the failing tool's output is printed under the step. Run with `-v` to see all
output live.
