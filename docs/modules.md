# Native modules

A module gives your app access to something native that PHP alone can't do, such as playing
sound. RageKit ships `audio`, `http`, `crypto`, `keyring`, `notifications`, `tray` and `dialogs`. You can write
your own in C++.

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
| `speed(float $speed)` | Playback speed, `1` is normal (e.g. `1.5` for a voice note). The pitch changes with it. |
| `position(): float` / `duration(): float` | Current position and length, in seconds. |
| `isPlaying(): bool` | Whether it is playing right now. |
| `onFinished(Closure $fn)` | Runs `$fn()` once when the sound plays to its end. |
| `onProgress(Closure $fn)` | Runs `$fn(float $seconds)` about four times a second while playing. |
| `unload()` | Frees the sound. The object can't be used afterwards. |

Supported files: WAV, FLAC and MP3 (miniaudio's built-in decoders), and Opus in MP4 (the voice notes
browsers record, as dev-null.sh's server stores them): on Linux decoded with the system's libopus
(`libopus0`, which desktops ship; loaded at runtime), on Windows with Media Foundation, which also
reads AAC/M4A. Those are decoded whole into memory when loaded, so they suit short sounds. Ogg Vorbis
and Opus in WebM/Ogg are not included. When a file can't be loaded, `Sound::load()`'s exception says why.

Set `RAGEKIT_AUDIO_NULL=1` to use miniaudio's null device: sounds "play" in real time without
output. Use it for tests and on machines without speakers; progress and "finished" still work.

`demo/player` is a complete example: Play / Pause / Stop, a progress bar, a volume slider, and a
"Finished" status. It shows "Audio unavailable" when the library isn't there.

### Recording

`RageKit\Audio\Recorder` records the default microphone as a voice note: 16 kHz, mono, 16-bit WAV,
up to 61 seconds (what the dev-null.sh server takes), one recording at a time.

```php
use RageKit\Audio\Recorder;

$this->recorder = Recorder::start(
    onLevel: fn(int $level) => $this->level = $level,   // 0-100, about 10 times a second
    onFull: fn() => $this->finish(),                    // 61 seconds reached
);
// …
$note = $this->recorder->stop($this->app->cacheDir() . '/note.wav');
$note->path;       // the WAV file
$note->seconds;    // 2.4
$note->waveform;   // 48 loudness bars, 0-100 each, for drawing it
```

`start()` throws `RuntimeException` when no microphone can be opened; `stop()` when the file can't be
written. `cancel()` stops without keeping anything. With `RAGEKIT_AUDIO_NULL=1` it records silence
from no device (tests). `App::cacheDir()` is a good place for recordings on their way to a server.

Not in this version: choosing a device, streaming, other formats (e.g. Opus) and effects.

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
| `->multipart(Multipart $form)` | Sends a form with files as `multipart/form-data` (sets the body and its `Content-Type`). |
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

A form with files, as an HTML form uploads them:

```php
use RageKit\Http\Multipart;

$form = (new Multipart())
    ->field('caption', 'Holiday')
    ->file('images[]', 'beach.jpg', file_get_contents($path), 'image/jpeg');
Http::post('https://example.com/upload', '')->multipart($form)->send(…);
```

`field($name, $value)` and `file($name, $filename, $content, $type = 'application/octet-stream')`
take any bytes; quotes and line breaks in names are percent-encoded, as browsers do. `body()` and
`contentType()` give the encoded form, e.g. to sign or hash it first.

Redirects are followed (up to 10); `headers` are those of the final answer. Compressed answers
are decompressed. Requests send `User-Agent: RageKit` unless you set your own.

What does the work:

- **Linux:** libcurl, which desktop distributions ship (`libcurl4`). Building needs its headers:
  `sudo apt install libcurl4-openssl-dev` (Debian/Ubuntu).
- **Windows:** WinHTTP, part of Windows; nothing to install or ship. Written, but tested less.
- **macOS:** not yet.

`demo/fetch` looks up a GitHub repository and downloads its README, with a progress bar and
Cancel.

Not in this version: WebSockets, streaming a body as it arrives or an upload from a file without
reading it into memory, cookies, proxy settings and TLS options.

## The crypto module

Ed25519 signatures, SHA-256 and SHA-512, random bytes from the operating system, and OpenSSH's
formats for keys and signatures. Enable it with `ragekit: { modules: [crypto] }`. It works the same
in Nano builds, whose PHP has no `hash()` for every algorithm and no `sodium`.

```php
use RageKit\Crypto\Ed25519Key;
use RageKit\Crypto\Hash;
use RageKit\Crypto\Random;
use RageKit\Crypto\Ssh;

$key = Ed25519Key::generate();                 // or Ed25519Key::fromSeed($savedSeed)
$seed = $key->seed();                          // 32 bytes: keep it safe (see the keyring module)

$signature = $key->sign($message);             // 64 bytes
Ed25519Key::verify($key->publicKey(), $message, $signature);   // true

Ssh::publicKey($key, 'my laptop');             // "ssh-ed25519 AAAA… my laptop"
Ssh::fingerprint($key);                        // "SHA256:…", as ssh-keygen -l prints it
Ssh::sign($key, $message, 'my-namespace');     // what ssh-keygen -Y sign -n my-namespace makes

Hash::sha256($data);                           // hex, like hash('sha256', $data)
Hash::sha512($data, binary: true);             // 64 raw bytes
Random::bytes(16);                             // 16 bytes from getrandom / BCryptGenRandom
```

| Class | Methods |
|---|---|
| `Ed25519Key` | `generate()`, `fromSeed(string $seed)` (32 bytes, else `InvalidArgumentException`), `seed()`, `publicKey()` (32 bytes), `sign(string $message)` (64 bytes), static `verify(string $publicKey, string $message, string $signature): bool` (false for wrong sizes too) |
| `Ssh` | `publicKey(Ed25519Key, string $comment = '')`, `fingerprint(Ed25519Key)`, `sign(Ed25519Key, string $message, string $namespace)`, `publicKeyBlob(Ed25519Key)` (the key in SSH's wire format) |
| `Hash` | `sha256(string $data, bool $binary = false)`, `sha512(...)` |
| `Random` | `bytes(int $count)` (1 to 65536) |

All of them take and return raw bytes, except the hex `Hash` results and the text SSH formats.

`Ssh::sign()` returns the signature's base64 on one line, as an HTTP header carries it. It is the
body of the armored file `ssh-keygen -Y sign` writes, and byte for byte the same signature
(Ed25519 is deterministic). Wrap it to check it with OpenSSH:

```sh
printf -- '-----BEGIN SSH SIGNATURE-----\n%s\n-----END SSH SIGNATURE-----\n' "$sig" | fold -w 70 > msg.sig
ssh-keygen -Y verify -f allowed_signers -I me -n my-namespace -s msg.sig < msg
```

What does the work: [Monocypher](https://monocypher.org) 4.0.2 (BSD-2-Clause or CC0, fetched by
the build) for Ed25519 and SHA-512, a SHA-256 of RageKit's own, and the OS's random generator.
Ed25519 is RFC 8032's, so keys and signatures are interchangeable with sodium, OpenSSH and
others. Windows uses `BCryptGenRandom`. macOS: not yet.

Not in this version: encryption, other key types (RSA, ECDSA), reading OpenSSH private key files.

## The keyring module

Secrets in the operating system's credential store, where they are encrypted and tied to the
user's login: a device key, a token, a password. Enable it with `ragekit: { modules: [keyring] }`.

```php
use RageKit\Crypto\Ed25519Key;
use RageKit\Keyring\Keyring;
use RageKit\Keyring\KeyringException;

if (!Keyring::available()) {
    $this->status = Keyring::why();            // e.g. "no Secret Service: …"
    return;
}
try {
    $seed = Keyring::get('com.example.app', 'device-key');      // null when none is stored
    if ($seed === null) {
        $key = Ed25519Key::generate();
        Keyring::set('com.example.app', 'device-key', $key->seed());
    }
} catch (KeyringException $e) {
    $this->status = $e->getMessage();          // locked and not unlocked, the service went away, …
}
Keyring::delete('com.example.app', 'device-key');                // signing out
```

| Method | What it does |
|---|---|
| `available(): bool`, `why(): string` | Whether the store can be used, and if not why (`''` when it can). |
| `set($service, $account, $secret)` | Stores the secret (any bytes), replacing one already there. |
| `get($service, $account): ?string` | The secret, or `null` when none is stored. |
| `delete($service, $account)` | Removes it; nothing happens when there is none. |

One secret per service and account. Use your app's reverse-DNS name as the service. `set`, `get`
and `delete` throw `KeyringException` when the store fails (also for names that aren't UTF-8), and
`InvalidArgumentException` for an empty service or account, a NUL byte in either, or a `:` in the
service.

`get` tells "nothing stored" (`null`) apart from "stored, but the keyring is locked" (a
`KeyringException`, e.g. when the user dismissed the unlock prompt), so an app doesn't mistake a
locked keyring for a missing key and replace it.

The calls wait for the store. That is quick, except when the keyring is locked: the OS then shows
its own unlock prompt and the call returns once the user answered.

What does the work:

- **Linux:** the Secret Service over D-Bus, which GNOME Keyring, KWallet and KeePassXC provide,
  through libsecret (`libsecret-1-0`, installed on desktop distributions). The library is loaded
  at runtime, so building needs nothing extra, and without it the module reports itself
  unavailable. Entries use the schema `org.ragekit.Secret` and show up in Seahorse as
  "<service> (<account>)". The secret is stored as hex text.
- **Windows:** Credential Manager, generic credentials named `<service>:<account>`, at most 2560
  bytes. It needs a desktop logon: an app started over SSH with a key gets "the specified logon
  session does not exist".
- **macOS:** not yet (`why()` says so).

There is no fallback to a plain file: when no store is available, your app decides what to do.

## The notifications module

Desktop notifications the user can click. Enable it with `ragekit: { modules: [notifications] }`.

```php
use RageKit\Notifications\Notification;

if (Notification::available()) {
    $n = Notification::show('Bob', 'Are you coming?',
        onClick: fn() => $this->openConversation('bob'),
        onClose: fn() => $this->notification = null);
}
$n->close();                                   // take it away, e.g. once the message was read
```

| Method | What it does |
|---|---|
| `available(): bool`, `why(): string` | Whether notifications can be shown, and if not why. |
| `show($title, $body = '', ?Closure $onClick = null, ?Closure $onClose = null)` | Shows one; the body is plain text. Throws `RuntimeException` (with the reason) when it can't. |
| `->id`, `->open()`, `->close()` | Its id, whether it's still shown, and taking it away. |
| `show(..., onReply: fn(string $text) => …, replyHint: 'Reply to Bob')` | A reply field in the notification, where the desktop has one (Windows, KDE); `onReply` gets what was sent. Elsewhere (GNOME) the notification shows without it. |
| `Notification::badge(int $count): bool`, `badgeWhy($count)` | The number on the app's taskbar button or dock icon; 0 takes it away. |

`onClick` runs when the user clicks the notification, `onClose` when it goes away (clicked,
dismissed, expired or closed by the app), on the UI thread like a click in a window.

What does the work:

- **Linux:** the desktop's notification service (`org.freedesktop.Notifications` on D-Bus: GNOME,
  KDE, …) through GLib's GDBus, loaded at runtime (GLib is part of every desktop). A small thread
  listens for clicks. The app name shown is the executable's name.
- **Windows:** toast notifications (Windows.UI.Notifications through C++/WinRT, which comes with the
  Windows SDK). Windows only shows toasts for apps it knows, so the first `available()` / `show()`
  registers the app under `HKCU\Software\Classes\AppUserModelId\RageKit.<exe name>` (its name, and
  `icon.png` next to the executable when there is one); no installer or Start menu shortcut is
  needed. A notification that times out moves to the notification center and can still be clicked
  there while the app runs, so `onClose` only runs when it's clicked, dismissed or closed by the app.
  With Do Not Disturb on, Windows puts them straight into the notification center.
- **macOS:** not yet (`why()` says so).

**Replies:** Windows shows a text box and a Send button in the toast; KDE Plasma shows its inline
reply (the `x-kde-reply-placeholder-text` hint, answered with `NotificationReplied`); GNOME has no
reply field, so there the notification is an ordinary one (a click still runs `onClick`).

**Badge:** Windows draws the count in a red disc over the taskbar button (`ITaskbarList3`
overlay; it needs the window to be shown). On Linux it's the Unity launcher API
(`com.canonical.Unity.LauncherEntry`), which KDE's task manager, Ubuntu's dock and Dash to Dock
show; the dock finds the app by its .desktop file (`ragekit package` installs `<name>.desktop`;
app links register `ragekit-<name>.desktop`). Plain GNOME shows no badges.

Not in this version: buttons, icons and images, and sounds.

## The tray module

An icon in the system tray (the panel's status area) with a tooltip, a click and a menu. Enable it
with `ragekit: { modules: [tray] }`. One icon per app.

```php
use RageKit\Tray\Tray;

final class MyApp extends App
{
    protected function start(): void
    {
        if (!Tray::available()) {
            return;                               // Tray::why() says why
        }
        Tray::show('assets/tray.png', 'My app', onClick: fn() => $this->main()->show())
            ->item('Open', fn() => $this->main()->show())
            ->separator()
            ->item('Quit', fn() => $this->quit());
    }

    /** Keep running in the tray: closing the window only hides it. */
    protected function mainWindowClosed(): void
    {
        $this->main()->hide();
    }
}
```

| Method | What it does |
|---|---|
| `available(): bool`, `why(): string` | Whether the desktop has a tray, and if not why. |
| `show($icon, $tooltip = '', ?Closure $onClick = null)` | Shows the icon (a png or svg file, on Windows also ico; relative paths are the app's folder, so ship it with `ragekit: { assets: [...] }`), or updates it. `onClick` runs when the icon is clicked; the menu opens with the other mouse button. Throws `RuntimeException` when it can't. |
| `item($label, Closure $fn)`, `separator()`, `clearMenu()` | The menu, in order. |
| `tooltip($text)` | Changes the tooltip. |
| `remove()` | Takes the icon away. |

`Window::hide()` takes a window off the screen without closing it (its screens keep their state)
and `Window::show()` brings it back to the front; any app can use them. `visible()` is false only
for a hidden window; `minimized()` says whether it's minimised to the taskbar or dock. A chat app
that skips notifications for the conversation on screen checks both: a minimised window isn't being read.

What does the work:

- **Linux:** the StatusNotifierItem protocol and com.canonical.dbusmenu over D-Bus, through GLib's
  GDBus loaded at runtime. KDE, XFCE and most other desktops show these icons; **GNOME needs the
  AppIndicator extension** ("AppIndicator and KStatusNotifierItem Support", preinstalled on Ubuntu).
  Without a tray host, `available()` is false and `why()` says so. The icon is passed as a file
  (its folder and name), so the desktop draws it at its own size. On GNOME (checked on GNOME 48
  with the AppIndicator extension) both mouse buttons open the menu and `onClick` never runs, so
  give the menu an item that does what the click does (e.g. "Open").
- **Windows:** the taskbar's notification area (`Shell_NotifyIcon`). A left click (or Enter on the
  focused icon) runs `onClick`, the right button opens the menu. The icon may be a png, ico, bmp or
  svg (drawn with Direct2D), scaled to the taskbar's size. Windows 11 puts a new app's icon in the
  hidden part of the tray (the ^ button) until the user drags it out. If Explorer restarts, the
  icon comes back by itself.
- **macOS:** not yet (`why()` says so).

Not in this version: submenus, checkable items, icons in the menu, an attention or overlay icon, and
scroll events.

## The dialogs module

The OS's own file dialogs: open one or more files, save, choose a folder. Enable it with
`ragekit: { modules: [dialogs] }`.

```php
use RageKit\Dialogs\FileDialog;

FileDialog::open()
    ->title('Send a picture')
    ->filter('Images', ['png', 'jpg', 'jpeg', 'gif', 'webp'])
    ->filter('All files', ['*'])
    ->multiple()
    ->show(function (array $paths): void {
        foreach ($paths as $path) {            // [] when the user cancelled
            $this->attach($path);
        }
    });

FileDialog::save('voice note.wav')->filter('Sound', ['wav'])->show(fn(array $paths) => ...);
FileDialog::folder()->in($musicFolder)->show(fn(array $paths) => ...);
```

| Method | What it does |
|---|---|
| `open()`, `save($name = '')`, `folder()` | The kind of dialog; `save()` suggests a file name. |
| `->title($title)` | The dialog's title. |
| `->filter($name, $extensions)` | A choice in its file type list (extensions without the dot, any case; `'*'` = every file). The first is selected. |
| `->multiple()` | `open()` only: several files at once. |
| `->in($folder)` | The folder it starts in (otherwise the OS remembers the last one). |
| `->show(Closure $then, ?Closure $failed = null)` | Shows it and returns at once. `$then(list<string> $paths)` runs on the UI thread when the user is done. If it can't be shown, `$failed($why)` runs; without `$failed`, `show()` throws `RuntimeException`. |
| `available(): bool`, `why(): string` | Whether dialogs can be shown, and if not why. |

The app keeps running while a dialog is open (timers, events and other windows go on).

What does the work:

- **Linux:** the desktop's own file chooser through **xdg-desktop-portal** (`org.freedesktop.portal.FileChooser`
  on D-Bus: GNOME, KDE and the other desktops ship a portal), through GLib's GDBus, loaded at runtime.
  It also works for sandboxed apps (Flatpak). Without a portal (a bare window manager), `why()` says so.
  Only local files are returned.
- **Windows:** the common item dialogs (`IFileOpenDialog`, `IFileSaveDialog`), owned by the app's active
  window, which Windows disables while the dialog is open. `save()` adds the first filter's extension
  when the user types a name without one.
- **macOS:** not yet.

On X11 (and XWayland) the dialog opens over the app's active window and stays in front of it (the
portal gets it as the parent). Apps running as native Wayland windows get a dialog the desktop
places on its own.

Not in this version: message boxes (`alert`/`confirm`; use a `<Dialog>` in the template).

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

Timers (`RageKit\Timer`, see [state.md](state.md#timers-polling-and-delays)) use the same path:
their ticks are events of the module `@timer`. The `@` keeps that name apart from real modules.

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
  written for (`"ragekit/ragekit": "^0.3"`): `module.yml` and the C functions a library implements
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
