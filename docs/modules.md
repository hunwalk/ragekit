# Native modules

A module gives your app access to something native that PHP alone can't do, such as playing
sound. RageKit ships one module today, `audio`. You can write your own in C++.

A module has three parts:

- a **PHP API** your app calls (for audio: `RageKit\Audio\Sound`);
- a **native library** written in C++17 (`librk_module_audio.so` on Linux, `rk_module_audio.dll` on
  Windows);
- a **`module.yml`** that lists the native functions and their types. RageKit generates all the
  glue between PHP and the library from it.

The library is **loaded at runtime, never linked** into your executable. It sits next to the
executable and is opened the first time the app uses the module. If it is missing, the app still
starts and runs; only the module reports itself as unavailable.

## Using a module

Enable it in your app's `project.yml`:

```yaml
ragekit:
  backend: slint
  modules: [audio]
  assets: [assets/]      # files the app reads at runtime, e.g. sounds
```

`ragekit build` then:

- adds the module's PHP API to your app;
- builds the module's library next to the executable (for example `build/slint/librk_module_audio.so`);
- copies everything listed under `assets:` next to the executable, under the same relative paths.
  Assets are copied, never embedded. The paths must be inside the project folder. Files you remove
  from the list (or delete) are removed from the build folder on the next build.

Modules need the Slint backend.

### Checking whether a module is there

```php
use RageKit\Modules;
use RageKit\ModuleUnavailable;

if (Modules::available('audio')) {
    // show the player
}

try {
    Modules::require('audio');   // throws ModuleUnavailable, with the reason
} catch (ModuleUnavailable $e) {
    $this->status = $e->getMessage();
}
```

A module counts as unavailable when its library isn't next to the executable, can't be loaded, or
lacks one of the functions your app was built against (for example a library built from an older
`module.yml`).

A module's own PHP API normally checks this for you: `Sound::load()` throws `ModuleUnavailable`
when the audio library is missing, and an HTTP request reports it as the request's error. If you call a module's generated functions directly, check
`Modules::available()` first. In a release build a missing function returns its type's default
(`0`, `0.0`, `false`, `''`) instead of failing.

The library is looked for in the executable's folder. Set `RAGEKIT_MODULE_DIR` to load module
libraries from another folder instead.

### Files and paths

`App::resolve($path)` turns a path relative to the app's folder into an absolute one. In a release
build the app's folder is the executable's folder (where `assets:` are copied). In `ragekit dev` it
is the project folder, so assets are read in place. Absolute paths are returned unchanged. Module
APIs that take a path, such as `Sound::load()`, resolve it this way.

## The audio module

Sound playback, built on [miniaudio](https://miniaud.io) (public domain / MIT-0).

```php
use RageKit\Audio\Sound;

$sound = Sound::load('assets/tone.wav');   // relative to the app's folder
$sound->onFinished(function (): void {
    $this->status = 'Finished';
});
$sound->setVolume(0.8);
$sound->play();
```

| Method | What it does |
|---|---|
| `Sound::load(string $path): Sound` | Loads a file. Throws `ModuleUnavailable` without the library, `RuntimeException` if the file can't be loaded. |
| `play()` / `pause()` | Starts or pauses playback. |
| `stop()` | Stops and rewinds to the start. |
| `seek(float $seconds)` | Jumps to a position. NaN and infinity are ignored. |
| `setVolume(float $volume)` | `0` is silent, `1` is as recorded. Negative means `0`; NaN and infinity are ignored. |
| `position(): float` / `duration(): float` | Current position and length, in seconds. |
| `isPlaying(): bool` | Whether it is playing right now. |
| `onFinished(Closure $fn)` | Runs `$fn()` once when the sound plays to its end. |
| `onProgress(Closure $fn)` | Runs `$fn(float $seconds)` about four times a second while playing. |
| `unload()` | Frees the sound. The object can't be used afterwards. |

Supported files: WAV, FLAC and MP3 (miniaudio's built-in decoders). Ogg Vorbis is not included.

Set `RAGEKIT_AUDIO_NULL=1` to use miniaudio's null device: sounds "play" in real time without
output. Use it for tests and on machines without speakers; progress and "finished" still work.

`demo/player` is a complete example: Play / Pause / Stop, a progress bar, a volume slider, and a
"Finished" status. It shows "Audio unavailable" when the library isn't there.

Not in this version: recording, choosing an output device, streaming, and effects.

## The http module

HTTP and HTTPS requests that never block the window: each request runs on a worker thread, and
its callback runs on the UI thread when the answer is in, like a click handler. It works the same
in Nano builds, whose PHP can't use the network itself.

```php
use RageKit\Http\Http;
use RageKit\Http\Response;

Http::get('https://api.github.com/repos/hunwalk/ragekit')
    ->header('Accept', 'application/vnd.github+json')
    ->timeout(10)
    ->send(function (Response $r): void {
        if (!$r->ok()) {
            $this->status = $r->error !== '' ? $r->error : "GitHub answered $r->status";
            return;
        }
        $repo = json_decode($r->body, true);
        $this->stars = $repo['stargazers_count'] ?? 0;
    });

$download = Http::get('https://example.com/big.zip')
    ->saveTo('downloads/big.zip')          // relative to the app's folder
    ->onProgress(function (int $received, int $total): void {
        $this->progress = $total > 0 ? $received / $total : 0.0;
    })
    ->send(function (Response $r): void {
        $this->status = $r->ok() ? 'Saved' : 'Failed';
    });
$download->cancel();                       // e.g. from a Cancel button
```

| Method | What it does |
|---|---|
| `Http::get($url)`, `Http::post($url, $body)`, `Http::request($method, $url)` | Make a request (not sent yet). |
| `->header($name, $value)` | Adds a request header. A name or value with a line break throws `InvalidArgumentException`. |
| `->body($body)` | The request body (any bytes). |
| `->timeout($seconds)` | Gives up after this long **without progress**: while connecting, or when no data arrives. Not a limit on the whole transfer, so big downloads work. Default 30. |
| `->saveTo($path)` | Writes the body to a file instead of `Response::$body`. Missing folders are created. The file appears only for a 2xx answer; it is written as `<path>.part` first. |
| `->onProgress(Closure $fn)` | `$fn(int $received, int $total)` about ten times a second while data arrives; `$total` is `-1` when unknown. |
| `->send(Closure $then)` | Starts it. `$then(Response $r)` runs exactly once. Returns the request. |
| `->cancel()` | Stops it; its callbacks won't run. Harmless after it finished. |

`Response` has `status` (`0` when there was no answer), `headers` (lowercase names, repeated
headers joined with `, `), `body` (any bytes), `error` (`''` when the server answered, otherwise
why not: no network, unknown host, TLS, timeout, a file that can't be written, or the module
missing), `ok()` (no error and a 2xx status) and `header($name)` (case-insensitive, `''` when
missing). A 404 or 500 is a normal response with `ok()` false, not an error.

Redirects are followed (up to 10); `headers` are those of the final answer. Compressed answers
are decompressed. Requests send `User-Agent: RageKit` unless you set your own.

What does the work:

- **Linux:** libcurl, which desktop distributions ship (`libcurl4`). Building needs its headers:
  `sudo apt install libcurl4-openssl-dev` (Debian/Ubuntu).
- **Windows:** WinHTTP, part of Windows; nothing to install or ship. Written, but tested less.
- **macOS:** not yet.

`demo/fetch` looks up a GitHub repository and downloads its README, with a progress bar and
Cancel.

Not in this version: WebSockets, streaming a body as it arrives, cookies, proxy settings and TLS
options.

## Events: from native threads to PHP

Native code often finishes work on its own threads (the audio module's "finished" comes from a
background thread). PHP must never run on those threads. Modules therefore send **events**, and
RageKit delivers them to PHP on the UI thread, the same way it delivers clicks:

1. The library calls the `post` callback it got at start-up, from any thread.
2. The bridge copies the event and queues it on the UI thread's event loop.
3. PHP's `RageKit\Events::deliver()` runs the handlers registered for that event.
4. Every open window then re-reads its values, exactly as after a click handler. Your handler can
   simply change a screen property; the UI updates.

Events posted before the first window exists wait until it does.

Handlers are registered per module, handle and kind. A module's PHP API usually wraps this (as
`Sound::onFinished()` does), but you can use it directly:

```php
use RageKit\Events;

Events::on('audio', $handle, 'progress', function (string $data): void {
    $this->position = (float) $data;
});
Events::off('audio', $handle);   // removes all handlers for that handle
```

`$data` is free-form text chosen by the module.

## Writing a module

A module lives in a folder with a `module.yml`:

```
modules/<name>/
  module.yml        native functions and their types
  src/              the PHP API apps use (compiled into apps)
  native/           the library's C++17 sources
  native/third_party/   third-party code it needs (fetched, git-ignore it)
```

RageKit looks for modules in three places:

- RageKit's own `modules/*/module.yml`;
- your app's `modules/*/module.yml`;
- Composer packages that declare one in `composer.json`:

  ```json
  { "extra": { "ragekit": { "module": "module.yml" } } }
  ```

  The path is relative to the package's folder. Require the RageKit minor version the module was
  written for (`"ragekit/ragekit": "^0.1"`): `module.yml` and the C functions a library implements
  may change between 0.x minor versions (see the CHANGELOG).

Two different folders declaring the same module name is a build error.

### module.yml

This is the audio module's:

```yaml
name: audio
php: [src]
fetch:
  - url: https://raw.githubusercontent.com/mackron/miniaudio/0.11.22/miniaudio.h
    sha256: 9019743287e443c55e5737a7297f38e5e358561701d6db2d905afb114390c410
    to: native/third_party/miniaudio/miniaudio.h
library:
  sources: [native]
  include-paths: [native, native/third_party/miniaudio]
  platforms: { linux: { link-libs: [dl, pthread, m] } }
functions:
  audio_load:     { params: { path: string }, returns: int }
  audio_play:     { params: { sound: int } }
  audio_volume:   { params: { sound: int, volume: float } }
  audio_position: { params: { sound: int }, returns: float }
  audio_playing:  { params: { sound: int }, returns: bool }
  # ...
```

| Key | Meaning |
|---|---|
| `name` | Lowercase letters, digits and `_`. It becomes part of C and PHP names. |
| `php` | Files or folders with the module's PHP API, added to every app that enables the module. |
| `fetch` | Third-party code to download before building (see below). |
| `library.sources` | C++ files or folders (every `.cc`, `.cpp` or `.cxx` in a folder) for the library. |
| `library.include-paths`, `link-paths`, `link-libs` | As for any C++ build. |
| `library.platforms` | Additions per OS: `linux`, `windows` or `macos`, or for one CPU too (`linux-x86_64`, `linux-arm64`). Lists are appended. |
| `functions` | The native functions, see below. |

Paths are relative to the module's folder. `${MODULE}` (the module's folder) and `${RAGEKIT}`
(RageKit's root) can be used in them.

**Functions.** Each name must start with `<name>_`. `params` lists the parameters in order;
`returns` is optional (no `returns` means no result). The types are:

| module.yml | PHP | C (what your library implements) |
|---|---|---|
| `int` | `int` | `int64_t` |
| `float` | `float` | `double` |
| `bool` | `bool` | `int` (0 or 1) |
| `string` | `string` | parameter: `const char *name, size_t name_len`; result: `const char *` |

Parameter names can't be C++ keywords, `fn`, `module`, `name` or `this`, and can't end in `_len`.

**Handles.** Native objects (a loaded sound) cross into PHP as `int` handles. Keep a map from
handle to object in your library, and use `0` for "failed".

### What the library implements

From `module.yml` RageKit generates a header, `rk_module_<name>.h`, which your sources include.
For the audio module it declares:

```c
RKM_API int64_t rkm_audio_load(const char *path, size_t path_len);
RKM_API void rkm_audio_play(int64_t sound);
RKM_API void rkm_audio_volume(int64_t sound, double volume);
RKM_API double rkm_audio_position(int64_t sound);
RKM_API int rkm_audio_playing(int64_t sound);
/* ... one rkm_<function> per entry in functions: ... */

RKM_API void rkm_audio_init(rk_post_fn post);
RKM_API void rkm_audio_reset(void);
```

Implement, with C linkage (`extern "C"`) and the `RKM_API` export macro:

- **`rkm_<function>`** for every function in `module.yml`.
- **`rkm_<name>_init(rk_post_fn post)`**: called once before any other function. Keep `post`;
  it is how you send events.
- **`rkm_<name>_reset(void)`**: free everything (stop threads, release objects). All handles
  become invalid. It is called when the app's event loop ends and, in `ragekit dev`, before PHP
  restarts. The library must work again after it (the next call starts fresh).

A string result must be a valid, NUL-terminated string that stays valid until your library's next
call (a buffer you own, e.g. a `thread_local std::string`). Don't return `NULL`; if you do, PHP
gets `''`.

RageKit also generates and compiles `rkm_<name>_call` into your library (calling functions by name,
used by `ragekit dev`); don't implement it yourself.

`native/include/ragekit_module.h` (included by the generated header) defines `RKM_API` and the
event callback:

```c
typedef void (*rk_post_fn)(const char *module, int64_t handle, const char *kind,
                           const char *data, size_t data_len);
```

Call it from any thread, any number of times. The strings are copied before it returns. For
example, from a background thread:

```cpp
post("audio", id, "finished", "", 0);

std::string seconds = std::to_string(position);
post("audio", id, "progress", seconds.data(), seconds.size());
```

On the PHP side, `Events::on('<name>', $handle, '<kind>', $fn)` receives them.

Threads are your library's business. If you start any, stop them in `rkm_<name>_reset()`: a thread
still running when the process exits can crash it.

### The PHP API

Put a small class in `src/` that wraps the generated functions, which are plain global PHP
functions named like the `module.yml` entries:

```php
namespace Acme\Vibration;

use RageKit\Modules;

final class Vibration
{
    public static function pulse(int $milliseconds): void
    {
        Modules::require('vibration');
        \vibration_pulse($milliseconds);
    }
}
```

Call the generated functions fully qualified (`\vibration_pulse`), as above: TypePHP needs that in
namespaced code. The API is compiled by TypePHP along with the app, so it must stay within the PHP
subset TypePHP supports. `ragekit build` is the check.

### Third-party code: fetch:

Don't commit third-party code into the module. Declare it under `fetch:`; the build downloads it
into the module's folder the first time:

```yaml
fetch:
  # a single file, checked by its sha256
  - url: https://example.com/lib/1.2/lib.h
    sha256: 0123…
    to: native/third_party/lib/lib.h
  # a folder of a git repository, at a tag that must point to the given commit
  - git: https://github.com/example/lib.git
    ref: v1.2.0
    commit: 89ab…
    path: src
    to: native/third_party/lib
```

`to` must stay inside the module's folder. Add those folders to `.gitignore`.

## In dev mode

`ragekit dev` builds each enabled module's library into `build/dev/modules/` and the dev shell loads
it. Editing a file in the module's `library.sources` rebuilds only that library, loads the new one,
and restarts PHP with your screen state restored. PHP must restart because the old library's
handles (loaded sounds and so on) are gone. If the build fails, the app keeps the old library and
the error is printed.

In dev mode, a call to a module that isn't available throws `ModuleUnavailable`, where a release
build returns the type's default. This is on purpose: dev mode points at the missing
`Modules::available()` check while you work, and a shipped app keeps running instead of stopping on
a user's machine. Code that checks works the same in both.

## Limits

- Linux is the tested platform. The Windows code paths (loading `rk_module_<name>.dll`, building
  with MSVC) are written but less tested.
- macOS has no module loader yet: it looks for `.so`, not `.dylib`.
- A library that failed to load is remembered as unavailable until the app restarts.
- Values aren't range-checked on the way in: a module should check its own inputs.

## See also

- [getting-started.md](getting-started.md): a first app
- [toolchain.md](toolchain.md): what `ragekit setup` installs
- [templates.md](templates.md) and [kits.md](kits.md): the UI side
- [dev-mode.md](dev-mode.md): `ragekit dev`
