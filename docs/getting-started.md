# Getting started

This guide takes you from an empty folder to a running RageKit app. It covers what to install,
how to add RageKit to an app, a minimal app, and the commands you use every day.

RageKit is experimental. Expect rough edges and breaking changes.

## Requirements

| | Linux (x86_64) | Windows (x86_64) | macOS |
|---|---|---|---|
| Status | tested | less tested | not supported yet |
| PHP | 8.4 or 8.5 | 8.4 or 8.5 | |
| Composer, git, tar | yes | yes | |
| C++ compiler | g++ (`build-essential`) | Visual Studio 2022 Build Tools, "Desktop development with C++" (`install-build-tools.ps1` installs them) | |
| Other | Slint's system libraries (below) | 7-Zip on `PATH` (unpacks Slint) | |

On Windows, RageKit's `install-build-tools.ps1` installs the Build Tools with only what's needed
(Windows asks for administrator rights; a few GB, 5-20 minutes). In an app it's at
`vendor\ragekit\ragekit\install-build-tools.ps1`:

```powershell
powershell -ExecutionPolicy Bypass -File vendor\ragekit\ragekit\install-build-tools.ps1
```

It does nothing when they're already installed, and adds the C++ workload to an existing Build
Tools install that lacks it. You rarely need to run it yourself: `composer create-project
ragekit/starter` and `ragekit setup` ask whether to install the Build Tools when they're missing.

The `ragekit` command itself runs on PHP 8.2+, but TypePHP (the compiler) needs PHP 8.4 or 8.5, so
in practice you need 8.4+.

On Debian or Ubuntu:

```sh
sudo apt install php8.4-cli composer git build-essential \
    libfontconfig1 libfreetype6 libxkbcommon0 libinput10 libgbm1 libudev1 \
    php8.4-dev libphp8.4-embed cmake libgmp-dev libmpfr-dev
```

The second line is what Slint's library links against. A desktop install already has them; a
minimal or server install may not, and then linking fails with "undefined reference to
`libinput_…`". Apps you ship need them on the user's machine too.

The third line is optional: with PHP's embed library, normal builds link your PHP and take seconds.
Without it, the first normal build compiles a private PHP (~10 minutes, once). Nano builds need
neither; see [toolchain.md](toolchain.md#linux-libphp-or-a-private-php).

RageKit downloads everything else (TypePHP and Slint) itself with `ragekit setup`; see
[toolchain.md](toolchain.md).

## Start from the starter app

The quickest way: create an app from `ragekit/starter`, which also runs `ragekit setup`:

```sh
composer create-project ragekit/starter myapp
cd myapp
composer start      # build and run; also: composer build, composer dev
```

The rest of this guide does the same by hand: add RageKit to a project, install the toolchain and
write a minimal app.

## Add RageKit to an app

RageKit is a Composer package, `ragekit/ragekit`:

```sh
composer require ragekit/ragekit:^0.2
```

Your classes need an autoloader entry in `composer.json`, for example:

```json
{
    "autoload": {
        "psr-4": { "Demo\\": "src/" }
    }
}
```

To work on RageKit itself and use your checkout in an app, add it as a path repository
(`"repositories": [{ "type": "path", "url": "../ragekit" }]`) and require `"ragekit/ragekit": "*@dev"`.

Always run the command as `vendor/bin/ragekit` from the app's folder (the folder with
`project.yml`). Composer's wrapper passes the app's autoloader, which the build needs to read your
classes.

## Install the toolchain

```sh
vendor/bin/ragekit setup
vendor/bin/ragekit doctor
```

`setup` installs the TypePHP and Slint versions this RageKit version was tested with. They go to
a folder shared by all your apps (`~/.local/share/ragekit/` on Linux), so the second app doesn't
download them again. `doctor` checks everything a build needs and tells you how to
fix what's missing. When you update RageKit and its pinned versions change, builds stop and ask
you to run `setup` again. Details: [toolchain.md](toolchain.md).

## A minimal app

```
myapp/
  project.yml
  main.php
  src/CounterScreen.php
  templates/CounterScreen.rk
```

`project.yml` holds TypePHP's settings plus a `ragekit:` section:

```yaml
name: myapp
sources:
  - main.php
  - src

ragekit:
  backend: slint
  renderer: software
```

`main.php` defines `main()`, the program's entry point. It creates the app, opens the first
window and runs the event loop until the last window closes:

```php
<?php

use Demo\CounterScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new CounterScreen(), title: 'Counter', width: 360, height: 200);
    $app->run();
}
```

A screen is a class with public properties (the state) and public methods (the handlers).
`template()` says where its `.rk` template is, relative to the project folder:

```php
<?php

namespace Demo;

use RageKit\Screen;

final class CounterScreen extends Screen
{
    public int $count = 0;

    public static function template(): string
    {
        return 'templates/CounterScreen.rk';
    }

    public function increment(): void
    {
        $this->count++;
    }
}
```

The template describes the window. `{count}` shows the property, `onClick="increment"` calls the
method, and after every event the window shows the new values:

```xml
<VStack>
    <Label text="Count: {count}" />
    <Button text="+" onClick="increment" />
</VStack>
```

More on templates, elements and child components: [templates.md](templates.md). For a custom title
bar (a frameless window framed by your own layout), see [Layouts](templates.md#layouts-a-window-frame-and-a-custom-title-bar).

## Build, run, develop

```sh
vendor/bin/ragekit build        # compile into build/slint/
vendor/bin/ragekit run          # build, then start the app
vendor/bin/ragekit dev          # hot reload while you work
```

- `build` is the default command, so plain `vendor/bin/ragekit` builds too. `build --run` is the
  same as `run`.
- `--kit=<name>` builds with another kit than the one in `project.yml`; `--backend=<name>`
  overrides the backend (only `slint` exists).
- `-v` shows the compilers' output live. Without it, output only appears when a step fails.
- Arguments after `--` go to TypePHP's compiler unchanged, e.g. `vendor/bin/ragekit build -- -f`
  (`-f` forces a full rebuild).
- `dev` runs your PHP on plain `php` inside a prebuilt shell: saving a template reloads the UI,
  saving PHP restarts only PHP and keeps the state. Options: `--kit=<name>`, `--on-top`. See
  [dev-mode.md](dev-mode.md). `dev` runs full PHP, so run `build` from time to time to check that
  TypePHP still compiles your code.

TypePHP compiles a subset of PHP. Code that works in `dev` can fail to compile in `build`; the
build error says what to change.

## What a build produces

Everything ends up in `build/slint/` (`build/<backend>/` in general):

| File (Linux / Windows) | What it is |
|---|---|
| `myapp` / `myapp.exe` | the app |
| `libragekit_slint.so` / `ragekit_slint.dll` | RageKit's bridge to Slint (built from your templates) |
| `libslint_cpp.so` / `slint_cpp.dll` | Slint's library |
| `librk_module_<name>.so` / `rk_module_<name>.dll` | one per enabled module (loaded at runtime) |
| your `assets:` | copied with their folder structure |
| `tpc.yml`, `generated/`, `cache/` | build files, not needed to run |

To ship the app, copy the executable together with the libraries and assets next to it. On
Windows, a normal (non-Nano) build also needs PHP's DLLs from the TypePHP release
(`php8ts.dll`, `phpx.dll`, …); ragekit doesn't copy those yet, so `ragekit run` finds them on
`PATH` but a copied app won't. Nano builds don't need them.

## project.yml options

The `ragekit:` section:

| Key | Values | What it does |
|---|---|---|
| `backend` | `slint` (default) | the UI toolkit |
| `kit` | `material` (default), `shadcn`, or your own | how elements look; see [kits.md](kits.md) |
| `renderer` | `software`, `femtovg`, `skia` | how Slint draws (see below) |
| `nano` | `true` / `false` (default) | build with TypePHP's Nano runtime; see [toolchain.md](toolchain.md#normal-and-nano-builds) |
| `modules` | list, e.g. `[audio]` | native modules the app uses; see [modules.md](modules.md) |
| `assets` | list of files or folders, e.g. `[assets/]` | copied next to the executable; read them with `App::resolve('assets/…')` |
| `dev` | `{ on-top: true }` | `ragekit dev` keeps the windows above others (same as `--on-top`) |

Renderers:

- `software` draws on the CPU. It uses the least memory (around 20–30 MB for a small app) and
  starts fastest, but doesn't draw drop shadows.
- `femtovg` and `skia` draw on the GPU and draw shadows. They use much more memory (often
  100–200 MB, mostly the graphics driver). `skia` is Slint's default when `renderer` isn't set.

The environment variable `SLINT_BACKEND` (e.g. `winit-skia`) overrides `renderer` at run time.

Unknown keys print "ignored" during the build. Everything outside `ragekit:` is passed to TypePHP
(`name`, `sources`, `cxx-flags`, `defines`, …).

## Run the demos in this repository

From a checkout of RageKit:

```sh
./setup.sh                      # Linux; setup.ps1 on Windows
cd demo/counter-slint && ../../bin/ragekit run
```

| Demo | Shows |
|---|---|
| `demo/calculator` | logic in plain PHP, a keypad from a constant with `each` |
| `demo/counter-slint` | state, handlers, a child component, a second window |
| `demo/kitchen-sink` | every core element, with the shadcn kit (`--kit=material` to compare) |
| `demo/kit-demo` | an app's own kit (`kits/icons`) with SVG icons embedded at build time |
| `demo/player` | the audio module and assets; `RAGEKIT_AUDIO_NULL=1` plays silently without speakers |

The demos use the toolchain `setup.sh` installed: the shared one, or a `.ragekit/` folder in the
repository root if you created one (builds look for `.ragekit/` in the app's folder and each
parent folder first).
