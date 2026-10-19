# Changelog

All notable changes to RageKit. Versions follow [semantic versioning](https://semver.org); while
RageKit is on 0.x, a minor version (0.1 → 0.2) may break things, and the entry says what and how
to update. Patch versions (0.1.0 → 0.1.1) only fix things.

"Breaking" covers more than the PHP API: the `.rk` template syntax, the core element contract
(what every kit accepts), the `kit.yml` and `module.yml` formats, the C functions a module library
implements, and `project.yml` options. Kits and modules distributed as Composer packages should
require the RageKit minor version they were written for, e.g. `"ragekit/ragekit": "^0.1"`.

## [Unreleased]

### Added

- The `http` module: HTTP and HTTPS requests on a worker thread, so the window never waits
  (`RageKit\Http\Http::get($url)->send(fn (Response $r) => …)`), with headers, bodies, timeouts,
  downloads to a file with progress, and cancelling. Works in Nano builds. Linux uses libcurl
  (building needs `libcurl4-openssl-dev`), Windows WinHTTP. Example: `demo/fetch`.
- Layouts: `RageKit\Layout` frames screens that name it in `layout()`, with its own template
  (`<Content />` marks the screen), state and handlers, and a context interface the screens
  implement (`{context.title}`). Such windows are frameless: `<WindowDrag>` moves the window
  (double-click maximises), `<WindowControls />` adds the kit's minimise, maximise and close
  buttons, and the edges resize. On Linux the app moves and resizes the window itself (no window
  manager snapping) and these apps run on XWayland in a Wayland session (`RAGEKIT_WAYLAND=1` keeps
  Wayland). On Windows the OS moves and resizes the window (with snapping). Example:
  `demo/titlebar`.

## [0.1.2] - 2026-10-19

### Added

- Windows: `install-build-tools.ps1` installs Visual Studio 2022 Build Tools with the C++
  workload (or adds the workload to an existing install, or finishes an interrupted one).
  `ragekit setup` offers to run it when they're missing, and the "Build Tools not found" error
  points to it. Apps can ask during `composer create-project` with the Composer hook
  `RageKit\Cli\Composer\Hooks::offerBuildTools` (the starter does).

### Fixed

- Windows: `setup.ps1` in a git clone of RageKit failed with "php-nano-core-dependency.patch
  doesn't fit": Git for Windows had checked the patch out with CRLF line endings. Patches are now
  applied with LF line endings, setup's TypePHP checkout ignores the user's line-ending conversion,
  and `.gitattributes` keeps patch files LF.

## [0.1.1] - 2026-10-18

### Fixed

- Windows: `ragekit setup` failed with "could not start composer". Tools installed as `.bat` or
  `.cmd` files (like Composer) are now found on PATH.
- Windows: normal (non-Nano) builds failed with "phpx library not found". They now link PHPX from
  the TypePHP release `ragekit setup` installs.

## [0.1.0] - 2026-10-18

The first release. Experimental: Linux is where it's built and tested, Windows works but
is tested less, macOS isn't supported yet.

### Apps

- Screens and components as PHP classes (public properties are state, public methods are
  handlers), each with a `.rk` template; `App::open()` for multiple windows.
- Templates: `VStack`/`HStack`, bindings (`{count}`, computed methods), two-way inputs, `when` and
  `each`, child components with props, `<Tabs>`.
- Core elements every kit accepts: Label, Button, Entry, Checkbox, Slider, Select, Image,
  Progress, Card, Separator.
- Kits: `material` (Slint's Material 3 library) and `shadcn`; kits in the app (`kits/`) and in
  Composer packages; `image` props embedded at build time.
- Native modules (runtime-loaded C++ libraries with a generated PHP API), starting with `audio`
  (WAV, FLAC, MP3 via miniaudio); events from native threads; `assets:` copied next to the
  executable.

### Building

- `ragekit build` / `run`: templates compiled ahead of time to Slint and C++, PHP compiled by
  TypePHP; normal builds or Nano builds (`nano: true` or `--nano`, a stripped-down PHP runtime
  compiled into the executable); renderer choice (`software`, `femtovg`, `skia`).
- `ragekit dev`: hot reload of templates, kits, images and PHP with screen state kept (Linux).
- `ragekit setup` installs the pinned TypePHP and Slint (shared per user, one folder per set of
  pins; `--prune` deletes the ones of other versions); `ragekit doctor` checks everything a build
  needs.
- Starter app: `composer create-project ragekit/starter`.

### Known limits

- TypePHP compiles a subset of PHP; code that runs in `ragekit dev` can still fail to build.
- Windows: normal builds don't copy PHP's DLLs next to the executable; `ragekit dev` is Linux-only,
  and native modules are less tested there.
- Normal builds on Linux compile a private PHP once (~10 minutes) unless PHP's embed library is
  installed (`libphp8.4-embed`, plus `php8.4-dev`, `cmake`, `libgmp-dev` and `libmpfr-dev`).
- No networking in Nano builds; PHP runs on the UI thread.
- Apps built with RageKit are GPLv3 when distributed (see THIRD_PARTY_NOTICES.md).
