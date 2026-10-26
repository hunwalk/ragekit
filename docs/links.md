# App links: `myapp://…`

An app can be opened by a link with its own scheme, `myapp://…`: from a browser (a web sign-in
that sends the user back to the app is the usual case), a chat message, or another app. RageKit
handles the parts that are the same in every app:

- **One instance.** An app with links runs once. Launching it again, by a link or plainly, hands
  over to the running instance and ends; the running app comes to the front and gets the link.
- **Registering the scheme** with the operating system, when the app asks for it.
- **Delivering the link** to your PHP on the UI thread, like a click.

## Declaring and registering

In `project.yml`:

```yaml
ragekit:
  links: [myapp]
```

A scheme is a letter, then letters, digits, `+`, `-` or `.` (`http`, `https`, `file`, `mailto` and
the like are refused). Declaring it makes the app single-instance; it doesn't touch the system yet.
Your app registers itself when it wants, for example on the sign-in screen just before opening the
browser:

```php
use RageKit\Links;

Links::register('myapp');   // throws if project.yml doesn't declare it or the OS settings can't be written
```

What it writes:

- **Linux:** `~/.local/share/applications/ragekit-<app>.desktop` (`$XDG_DATA_HOME`) that runs this
  executable with the link (with the app's `icon:` when it has one), and `x-scheme-handler/myapp=ragekit-<app>.desktop` under
  `[Default Applications]` in `~/.config/mimeapps.list` (`$XDG_CONFIG_HOME`). Browsers and
  `xdg-open`/`gio open` read these. Calling it again just refreshes them (e.g. after the app moved).
- **Windows:** `HKEY_CURRENT_USER\Software\Classes\myapp` (a `URL Protocol` key whose open command
  runs this executable with the link). Windows' shell adds a `/` after a link's host when there is no
  path: `myapp://hello?x=1` arrives as `myapp://hello/?x=1` (`Links::query()` reads both the same).
- **macOS:** not yet.

`Links::openInBrowser($url)` opens an `http(s)://` page in the user's browser (any app can, links or
not), e.g. the web page that will send the user back with a link. `Links::schemes()` lists the
declared schemes. `Links::query($url)` gives a link's query as
name => value, decoded (`parse_str()` isn't available in Nano builds).

## Receiving links

Override `linkOpened()` in your App class:

```php
final class MyApp extends App
{
    protected function linkOpened(string $url): void
    {
        // myapp://auth?code=…&state=…
        $query = Links::query($url);   // ['code' => …, 'state' => …]
        AuthFlow::finish($query['code'] ?? '', $query['state'] ?? '');
    }
}
```

It runs on the UI thread after `start()`, and the windows show the new values afterwards. The main
window is brought to the front first. A link the app was started with arrives the same way.

## How it works

- **Linux:** the running instance listens on a Unix socket in `$XDG_RUNTIME_DIR`, or in
  `/tmp/ragekit-<uid>` (created for the user alone, mode 0700) when that isn't set; readable only by
  the user. If neither folder is private to the user, the app runs without the handover (each
  launch is on its own) rather than risk another user posing as it and getting its links. A new launch connects, sends
  its link (or "come to the front") and exits. Claiming the socket happens under a lock file, so
  two launches at the same moment can't both become the running one; a socket left behind by a
  crash is replaced.
- **Windows:** a named pipe per app (`\\.\pipe\ragekit-<app>`, local clients only); the first
  launch creates it, later ones send their link to it. A crashed instance takes its pipe with it.
- **Coming to the front** (also `Window::raise()`): on X11 RageKit asks the window manager to
  activate the window (`_NET_ACTIVE_WINDOW`, dated by the launch's startup id when it has one).
  On Wayland a window may come forward only with an activation token from whoever started the
  launch (`XDG_ACTIVATION_TOKEN`), which the launch hands over with its link; browsers don't pass
  one yet (Firefox 153), and without it GNOME only shows "… is ready". So an app that declares
  links runs on XWayland when both are there, where GNOME brings it forward (checked on GNOME 48);
  `RAGEKIT_WAYLAND=1` keeps native Wayland. Some desktops only mark the window as wanting attention. On
  Windows: `SetForegroundWindow`. Windows lets a background app come forward only when the user's
  last input went to it or a launch the user started allows it: a new launch handing over its link
  does (so links bring the window forward), while `raise()` from a timer only flashes the taskbar
  button.

## Not in this version

- `ragekit dev`: links work in built apps (`ragekit run`). In dev mode the app runs as if it
  declared none, and `Links::register()` throws.
- Unregistering, links on macOS, and "universal links" (https links that open the app).
