# How RageKit is laid out

RageKit turns a PHP app with `.rk` templates into a native executable: TypePHP compiles the PHP
to machine code, Slint draws the UI. This page explains where everything lives and how the pieces
fit together.

## The two kinds of code

RageKit contains code that ends up **inside your app** and code that only runs **on your
machine** while you build or develop. Keeping them apart is the main rule of the layout.

| Ends up in your app | Runs only on your machine |
|---|---|
| core PHP (`src/*.php`) | the `ragekit` command (`src/Cli/`) |
| the Slint backend's PHP (`src/Slint/*.php`) | the dev runtime (`src/Slint/Dev/`) |
| the Slint bridge + host (`native/slint/bridge`, `native/slint/host`) | the dev shell (`native/slint/devshell`) |
| kits' Slint code and enabled modules | tests, demos |

TypePHP only compiles the files a backend lists one by one in its `backend.yml` (`sources:`), so
nothing from the right-hand column can end up in an app by accident.

## Top level

```
src/        PHP only
native/     RageKit's own C and C++ only
kits/       UI kits: what core elements look like (Slint)
modules/    native modules: device and OS features (audio, http, …)
demo/       example apps
tests/      PHPUnit tests (+ small C++ test programs in tests/fixtures)
toolchain/  the TypePHP and Slint versions RageKit builds with (toolchain.yml) + TypePHP patches
docs/       user guides (getting started, toolchain, templates, kits, modules, dev mode)
bin/        the ragekit command's entry point
vendor/     Composer dependencies (not in git)
```

## src/ — PHP

```
src/
  App.php, Backend.php, Component.php, Screen.php     the app model every backend implements
  Events.php, Modules.php, ModuleUnavailable.php       module events and availability
  Slint/
    backend.yml          what the Slint backend adds to an app's build (sources, libraries,
                         options, dev shell)
    backend.php          ragekit_backend(): which backend App uses
    SlintBackend.php     windows, event dispatch            ┐ compiled into apps
    SlintWindow.php      one window: values in, actions out ┘
    Dev/                 dev runtime: \slint_* over a pipe to the dev shell, bindings read from
                         screens.json, state snapshots (plain PHP, never compiled)
  Cli/                   the ragekit command (never compiled)
```

`src/Cli/` is grouped by job:

```
src/Cli/
  Application.php        boots symfony/console; Command/ holds build, run, dev
  Project/               project.yml, backend.yml, merging them into TypePHP's config, paths
  Toolchain/             TypePHP, the C++ compiler (Gcc / Msvc), running processes
  Build/                 the build pipeline: one class per step in Build/Steps, NativeBuild for
                         compiling C++ only when its inputs changed
  Templates/             .rk parser, kits, reading the app's classes (introspect.php)
  Slint/                 turning templates into Slint + the C++/PHP glue that connects them
  Modules/               module.yml and the glue generated for modules
  Fetch/                 downloading what kits and modules declare under fetch:
  Setup/                 ragekit setup (installing the pinned toolchain) and ragekit doctor
  Dev/                   ragekit dev: watching files, the dev shell, reloads
```

## native/ — C and C++

```
native/
  include/
    ragekit_module.h     what a module's library includes: export macro, event callback type
  slint/
    bridge/              libragekit_slint (C++20): everything that touches Slint or the OS —
                         windows, events, loading module libraries at runtime. Built by
                         ragekit with g++/cl next to the executable.
    host/                the PHPX host (C++17) + slint.stub.php: the \slint_* functions PHP
                         calls. Compiled into the app by TypePHP (also in Nano builds).
    devshell/            ragekit-devshell (C++20): runs Slint's interpreter and the app's PHP
                         for `ragekit dev`.
    window/              what Slint's C++ API lacks for frameless windows (layouts): moving and
                         resizing (Windows: the OS's loop; X11: the app follows the pointer) and
                         the floating look. window.h + one file per platform
                         (window_x11.cc, window_win32.cc), compiled into both bridge and dev shell.
```

Platform-specific code follows one pattern: a shared header plus one file per platform, each
wrapped whole in `#if` (the build compiles whole folders), like `modules/http/native/transfer_*.cc`.

The split between bridge (C++20) and host (C++17) exists because TypePHP's Nano builds require
C++17 while Slint requires C++20; the two talk through the plain C functions in
`bridge/ragekit_slint.h`.

## kits/ — how elements look

A kit maps RageKit's core elements (`Button`, `Slider`, `Tabs`, …) to Slint components and can add
its own elements. Each kit is self-contained:

```
kits/<name>/
  kit.yml                elements and their attributes (the core element contract is the same
                         for every kit, so a template builds with any of them)
  <name>.slint           the Slint components
  library/               third-party Slint code it needs (fetched, not in git)
```

RageKit ships `material` (Slint's Material 3 library) and `shadcn`. Third-party code a kit or module
needs is declared under `fetch:` in its `kit.yml` / `module.yml`, either a file (URL + sha256) or a
folder from a pinned git commit, and `ragekit build` / `ragekit dev` fetch it into the package the
first time. Apps can have their own kits
in `<project>/kits/`, and Composer packages can provide kits (`extra.ragekit.kit`).

## modules/ — native features

A module gives PHP access to something native (sound, later network, files, …). It is loaded at
runtime, never linked: an app runs without a module's library, and `RageKit\Modules::available()`
tells whether it's there.

```
modules/<name>/
  module.yml             the native functions and their types (int, float, bool, string)
  src/                   the PHP API apps use (e.g. RageKit\Audio\Sound), compiled into apps
  native/                the library's C++ (C++17)
  native/third_party/    third-party code it needs (fetched, not in git)
```

From `module.yml` RageKit generates all glue: the C header the library implements, an entry for
calling functions by name (used by the dev shell), the PHP functions and their host for release
builds. Apps enable modules with `ragekit: { modules: [audio] }`; their own modules can live in
`<project>/modules/`, and Composer packages can provide modules (`extra.ragekit.module`).

Events (e.g. "a sound finished") travel from the module's thread through the bridge to PHP on the
UI thread (`RageKit\Events`), and the windows refresh afterwards, like after a click.

## An app

```
myapp/
  project.yml            TypePHP settings + ragekit: { backend, kit, modules, assets, nano, … }
  main.php               main(): creates App, opens the first Screen, runs
  src/                   Screens and components (PHP)
  templates/             their .rk templates (each component says where in template())
  assets/                files read at runtime (copied next to the executable with `assets:`)
  kits/, modules/        the app's own kits and modules (optional)
  build/                 build output (not in git): build/slint for releases, build/dev for dev mode
```

## The toolchain (`ragekit setup`)

A build needs TypePHP (compiles PHP to C++ and machine code) and Slint's C++ package. RageKit pins
exact versions in `toolchain/toolchain.yml`: TypePHP as a git commit plus its `composer.lock`
and our patch files (`toolchain/typephp/patches/`, fixes not yet upstream), Slint as release
downloads with checksums. `ragekit setup` installs them into a toolchain folder
(`Setup\ToolchainDir`: `RAGEKIT_TOOLCHAIN`, else the nearest `.ragekit/` from the app's folder
upward, else a per-user folder named after the pins' hash, e.g.
`~/.local/share/ragekit/toolchains/<id>/`, shared by all apps on that RageKit version) and writes
a marker of what it installed. Builds find the folder the same way and stop with "run `ragekit setup` again" when the marker doesn't match RageKit's pins,
so updating RageKit can never build with a stale or unpatched TypePHP. `TYPEPHP_HOME` and
`SLINT_HOME` override the installed versions (used as is). `ragekit doctor` checks all of it,
plus PHP, the C++ compiler and the tools setup needs.

## How a build runs (`ragekit build`)

1. Read `project.yml` and the backend's `backend.yml`, merge them into a TypePHP config.
2. Fetch what kits and modules declare under `fetch:` (only the first time).
3. Compile the `.rk` templates to Slint and generate the code that connects them to PHP.
4. Generate the glue for enabled modules.
5. Build the native libraries: the Slint bridge (linked) and module libraries (not linked).
6. Linux, normal builds: prepare the host PHP's libphp for TypePHP (`Toolchain\HostPhp`).
7. Copy runtime files and assets next to the executable.
8. Run TypePHP: the app's PHP, the core and the host become one executable.

`ragekit dev` skips steps 5–8 for the app: it runs the PHP on plain `php` inside the prebuilt dev
shell, which reloads templates in place and restarts only PHP when PHP changes.
