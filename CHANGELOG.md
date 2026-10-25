# Changelog

All notable changes to RageKit. Versions follow [semantic versioning](https://semver.org); while
RageKit is on 0.x, a minor version (0.1 → 0.2) may break things, and the entry says what and how
to update. Patch versions (0.1.0 → 0.1.1) only fix things.

"Breaking" covers more than the PHP API: the `.rk` template syntax, the core element contract
(what every kit accepts), the `kit.yml` and `module.yml` formats, the C functions a module library
implements, and `project.yml` options. Kits and modules distributed as Composer packages should
require the RageKit minor version they were written for, e.g. `"ragekit/ragekit": "^0.3"`.

## [Unreleased]

### Added

- **Lists of objects in templates.** A `RageKit\Collection` subclass names its item class in
  `const OF`; `@foreach ($devices as $device)` then reads the items' typed members:
  `{{ $device->name }}`, `{{ $device->label() }}`, in text, attributes, `@if` and handler
  arguments, checked at build time. Collections work as screen, window and store members;
  `count($devices)` counts them. Change them with `add()`, `[] =`, `set()`, `remove()`, `clear()`.
  Only rows that changed are redrawn. `ragekit dev` keeps collections across PHP restarts.
- **Images inside `@foreach`.** `<Image source="{{ $device->image }}">` and
  `@foreach ($photos as $photo) <Image source="{{ $photo }}" />` show each row's own image,
  resolved like single bound images (registered assets, files, web images downloaded once and
  cached), with `fallback` per row.

### Changed

- **TypePHP 0.9.5.** `ragekit setup` now installs TypePHP v0.9.5 (was v0.9.4 plus 8 commits); run
  `ragekit setup` again after updating. Among its fixes: writes through a property to an
  `ArrayAccess` object (`$this->items[] = $x`) now call `offsetSet()` instead of replacing the
  object with an array, and tpc creates the output folder itself.

## [0.5.1] - 2026-10-24

The dev shell on Windows: `ragekit dev` (hot reload) now works there too. Nothing changes for
0.5 apps.

### Added

- **`ragekit dev` on Windows.** The dev shell now builds with MSVC and runs there: hot reload,
  PHP restarts with state restored, and native modules (loaded with `LoadLibrary`).

### Changed

- The dev shell talks to the app's PHP and to `ragekit dev` over 127.0.0.1 (a one-time token
  passed in the environment) instead of inherited fds 3 and 4, on every OS. Nothing changes for
  apps; a custom dev runtime opens the link with `RageKit\Slint\Dev\Channel::connect()`.

### Fixed

- Windows: detecting MSVC a second time in one process (vcvars printing `PATH` where the
  environment had `Path`) left the old `Path` in place, so `cl.exe` wasn't found.

## [0.5.0] - 2026-10-24

Images from PHP: registered assets, files and web images behind the same `source`. Nothing changes
for 0.4 apps; kits with an `image` prop and custom bridges see the additions under Changed.

### Added

- **Images from PHP** (docs/templates.md, "Image"): `source="{{ $cover }}"` shows whatever a string
  property, method or `$store` value holds while the app runs: a registered image, a file, or an
  `http(s)://` URL, downloaded in the background (http module) and cached in the user's cache
  folder. `fallback="…"` is shown while a bound image is empty, loading or failed.
- **Registered images:** `App::assets()` returns classes of path constants (`['images' =>
  Images::class]`); templates show them as `$assets->images->logo`, the build checks and embeds
  them, and `Images::LOGO` in PHP switches a bound image to the embedded copy.
  `ragekit make:assets Images` creates such a class.
- demo/fetch shows the repository owner's avatar.

### Changed

- **Kits:** `image` props can be bound (`in property <image>` set from PHP), and
  `source="{name}"` (the old spelling) now says to write `{{ $name }}`.
- **Bridge C API:** `rk_set_image_asset`, `rk_set_image_file`, `rk_cache_dir`; generated window
  instances implement `set_image()` (and `asset()` when the app registers images).

## [0.4.0] - 2026-10-24

Generators for screens, windows, components, stores, services and flows, templates that find
themselves, and two crash fixes. Nothing changes for 0.3 apps; a custom backend implements one new
method (`printError()`, see Changed).

### Added

- **Generators:** `ragekit make:screen`, `make:window`, `make:component`, `make:store`,
  `make:service` and `make:flow` create a class (and, for screens, windows and components, its
  `.rk` template) in the recommended folders (`src/Screens`, `src/Windows`, `src/Stores`, …).
  `make:store` also adds the store to the app's root store. `ragekit stub:publish` copies the
  stubs into the app's `stubs/` so it can change what `make:` writes. Docs: docs/make.md.
- **Templates find themselves:** a screen, window or component without `template()` uses
  `templates/<its path under src/>.rk`, e.g. `templates/Screens/LoginScreen.rk` for
  `src/Screens/LoginScreen.php`. Overriding `template()` still works.
- **`App::uncaughtException()`:** where exceptions nobody caught in a handler or a module event
  end up. By default they're printed to stderr; override it to show or log them.

### Fixed

- An exception a click handler or a module event's callback didn't catch ended the app ("Fatal
  error" in normal builds, a segfault in Nano builds). It's now printed and the app keeps running.
- An app could crash on exit while a sound was playing: the audio module freed its sounds while
  miniaudio's audio thread was still mixing them. It now shuts the engine down first.

### Changed

- **Backends:** `RageKit\Backend` gains `printError(string $line)` (the Slint backend writes to
  stderr through the bridge's new `rk_print_error`, since Nano PHP has no `STDERR`).

## [0.3.0] - 2026-10-24

The app gets a shape: an app class you extend, a `Window` object per OS window, and app-wide state
in stores. This release breaks 0.2 apps; "Upgrading from 0.2" below lists every change.

### Added

- **The app class.** Apps extend `RageKit\App`: `firstScreen()` names the main window's first
  screen, `title()`, `width()` and `height()` its title and size, `createStore()` the app's state
  and `start()` runs once the main window is open. `main()` becomes `(new MyApp())->run()`.
  `$this->main()` is the main window.
- **Stores** (docs/state.md): `RageKit\RootStore` holds the app's stores (`RageKit\Store`), each a
  typed public property. PHP reads them typed as `AppStore::get()->session`; templates of screens
  and windows read `{{ $store->session->userName }}` and `{{ $store->session->signedIn() }}`, call
  `onClick="$store->session->signOut()"` (with arguments too) and bind inputs two-way to
  `{{ $store->settings->darkMode }}`. Every window showing a store value follows it. Stores tell
  the app what happened with events (`$this->emit('signedOut')`, `->on('signedOut', …)`), so the
  app class turns them into navigation and stores never navigate. All of it is checked at build
  time and compiles in Nano builds. `ragekit dev` keeps the stores across PHP restarts.
- `demo/navigator` signs in: a sign-in screen, the user's name and Sign out in the title bar, and
  the app class resetting the window on `signedIn` / `signedOut`.

### Changed

- **`Window` replaces `Layout`.** Every OS window is a `RageKit\Window`: the plain `Window` keeps
  the OS's title bar, a subclass with a template draws its own (what a layout did). It owns the
  window's navigator (`$window->navigator()`), its toasts, `close()`, `minimize()`,
  `toggleMaximize()`, `$maximized`, and `back()` / `canGoBack()` for a back button.
- **Closing the main window ends the app** and closes the other windows; closing another window
  only closes it. (Before, the app ended when the last window closed.) Apps that should keep
  running override `App::mainWindowClosed()`.
- `App::open()` returns the new `Window` instead of an id.
- The navigator is only the stack (`push`, `back`, `replace`, `reset`, `canGoBack`, `top`, `depth`,
  `stack`); toasts moved to the window.
- `ragekit dev` loads every file that declares a function (main.php) up front, also when it
  declares the app's class too.

### Upgrading from 0.2

- **The app class.** Move what `main()` did into a class: `final class MyApp extends App` with
  `firstScreen()` returning the screen you opened, and `title()`, `width()`, `height()` returning
  what you passed to `open()`. `main()` becomes `(new MyApp())->run();`. `new App()` no longer works
  (`App` is abstract).
- **Layout → Window.** `extends Layout` → `extends Window`, `use RageKit\Layout` →
  `use RageKit\Window`, and in screens `public static function layout(): string` →
  `public static function window(): string`. A screen that returned `''` from `layout()` drops the
  method (the default is `Window::class`). Templates stay as they are.
- In a screen, `$this->layout` is gone: use `$this->currentWindow()`.
- `App::open()` returns a `Window`; `App::close($id)` is gone: `$window->close()`.
- A window that should outlive the main window can't anymore unless the app overrides
  `mainWindowClosed()`; e.g. counter-slint's About closes with the main window now.
- `$store` is reserved in templates: rename a screen property or a loop variable called `store`.
- Kits, modules, `.rk` syntax and `project.yml` are unchanged.

## [0.2.0] - 2026-10-22

### Added

- The `http` module: HTTP and HTTPS requests on a worker thread, so the window never waits
  (`RageKit\Http\Http::get($url)->send(fn (Response $r) => …)`), with headers, bodies, timeouts,
  downloads to a file with progress, and cancelling. Works in Nano builds. Linux uses libcurl
  (building needs `libcurl4-openssl-dev`), Windows WinHTTP. Example: `demo/fetch`.
- **Rageblade** templates: a Blade-flavoured syntax in `.rk` files, compiled ahead of time like
  the rest: `{{ expressions }}` (a strict, typed PHP subset: properties, methods, constants,
  arithmetic, comparisons, logic, ternaries, `count()`), `@foreach ($list as $i => $item)`,
  `@if`/`@elseif`/`@else`, and handlers with expression arguments (`onClick="move($i, 1)"`).
  The older syntax (`{name}`, `each=`, `when=`) still builds; `each=`/`when=` print a hint.
- **Styles, React Native's way:** a `STYLES` constant with React Native's property names,
  `style="card row"`, conditional styles with `@style(['selected' => $cond])`, inline style
  attributes that take expressions (`width="{{ $p * 200 }}"`), and `transition` to animate style
  changes. Kit elements take the outer-box styles (size, margin, flex, position, opacity; Label
  also text styles).
- **`<View>`:** React Native's box: background, border, corners, clip and shadow around a flexbox
  layout (direction, wrap, justify/align, gap, padding), with `position: 'absolute'` children for
  corners and overlays. A root `<View>` fills the window. The demos use Rageblade now, and
  kitchen-sink's Layout tab shows View, wrapping, a corner badge and a styled selectable list.
- `ragekit eject <kit> [name]` copies a kit (shadcn, material, or a Composer kit) into the app's
  `kits/` folder with its components renamed, and switches `project.yml` to it, so the app can
  change how elements are drawn. `ragekit eject` lists the kits.
- `<Dialog open="{{ $confirmDelete }}" title="…">`: a dialog over the whole window, shown while a
  bool property is true; a click outside it or Esc closes it.
- `$this->toast('Saved')` in a screen or layout shows a message in the window's bottom-right
  corner for about 3 seconds.
- `<DropdownMenu text="⋯">` with `<MenuItem text="…" onClick="…" />`s: a button that opens a menu;
  each item calls its own handler.
- `<Text variant="h1">Settings</Text>`: React Native's Text with shadcn's typography variants (`h1`
  to `h4`, `p`, `lead`, `large`, `small`, `muted`, `code`, `blockquote`); the text is the element's
  content, `{{ expressions }}` included. `Label` is unchanged.
- **Navigator:** a window holds a stack of screens. `$this->navigator()->push(new PhotosScreen($album))`
  shows the next screen in the same window, `back()`, `replace()` and `reset()` move around, and a
  covered screen keeps its state. Layouts get `back()` / `canGoBack()` for a back button; Alt+Left
  goes back too. `shown()` / `hidden()` hooks on Screen. Screens sharing a layout compile into one
  Slint window component (a page each); `ragekit dev` keeps the whole stack across PHP restarts.
  Toasts now belong to the window, so they stay up across navigation. Example: `demo/navigator`,
  docs: docs/navigation.md.
- Navigation core elements `Sidebar`, `NavList` (two-way `selected`), `Breadcrumb` (`onSelect`
  calls the handler with the clicked item), `Pagination` (two-way `page`) and `<Accordion>` with
  `<Section>`s (one open at a time), in shadcn and Material; kitchen-sink's new Navigation tab
  shows them. Kit events can now pass a value: `onSelect: { callback: selected, value: string }`.
- Core elements `Switch`, `Textarea`, `RadioGroup`, `Toggle`, `Badge`, `Avatar`, `Alert`,
  `Skeleton` and `Spinner`, in the shadcn and Material kits. `demo/kitchen-sink` shows them in two
  new tabs and has its own title bar.
- Layouts: `RageKit\Layout` frames screens that name it in `layout()`, with its own template
  (`<Content />` marks the screen), state and handlers, and a context interface the screens
  implement (`{context.title}`). Such windows are frameless: `<WindowDrag>` moves the window
  (double-click maximises), `<WindowControls />` adds the kit's minimise, maximise and close
  buttons, and the edges resize. On Linux the app moves and resizes the window itself (no window
  manager snapping) and these apps run on XWayland in a Wayland session (`RAGEKIT_WAYLAND=1` keeps
  Wayland). On Windows the OS moves and resizes the window (with snapping). Example:
  `demo/titlebar`.

### Upgrading from 0.1

- Templates written for 0.1 still build: `{name}`, `each=` and `when=` work and print a hint
  pointing to `{{ }}`, `@foreach` and `@if`.
- New methods on the base classes: `Screen` has `shown()`, `hidden()` and `navigator()`, `Layout`
  has `back()` and `canGoBack()`. A screen or layout of yours with a method of one of those names
  (and another signature) must rename it.
- Kits written for 0.1 keep working: core elements they don't provide (Text, Dialog, DropdownMenu,
  the navigation elements, toasts) fall back to Material's. A kit that wants to offer them follows
  the contract in docs/kits.md.

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
