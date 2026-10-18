# Dev mode: `ragekit dev`

`ragekit dev` runs your app with hot reload. Edit a template and the window updates in place. Edit
PHP and only PHP restarts, with your screen's state kept. You don't wait for a full native build
on every change.

```sh
cd myapp
vendor/bin/ragekit dev
```

Stop it with Ctrl+C, or by closing the app's last window.

## How it works

A release build (`ragekit build`) compiles your PHP to machine code with TypePHP and your
templates to C++ with Slint. That takes a while. Dev mode skips both:

- A prebuilt **dev shell** (`ragekit-devshell`) owns the windows. It draws your templates with
  Slint's interpreter, so a changed template needs no C++ build.
- Your app's PHP runs on **plain `php`**, as a child process of the shell. Restarting it takes
  milliseconds.

The first `ragekit dev` builds the dev shell into `build/dev/` (about 15 seconds). After that it
is reused until RageKit's dev shell sources change.

## What happens when you save

| You edit | What happens |
|---|---|
| A `.rk` template, a kit file, or an image a template uses | The UI is regenerated and every window is rebuilt at the same position and size. PHP keeps running, so all state is kept. |
| PHP (your screens, components, any app PHP file) | PHP restarts and reopens its windows. Public screen properties are restored (see below). If the change also affects the generated UI (for example a property's type), the UI reloads too. |
| A screen is added or removed | The whole app restarts, state restored. |
| A module's C++ (see [modules.md](modules.md)) | Only that module's library is rebuilt and reloaded, then PHP restarts with state restored. |
| The dev shell's own C++ (only when working on RageKit itself) | The shell is rebuilt and the app restarts with state restored. |

Each change prints one line with the files, what happened and how long it took.

Changes are detected by file content, polled every 250 ms. A file counts as changed once it has
stayed the same for 150 ms, so an editor's save (several writes, or a rename) is one change.

When a template changes, the rebuilt window is a new native window. Most desktops show it in the
same place, but it may come to the front.

## What state is kept across a PHP restart

After every event, dev mode writes a snapshot to `build/dev/state.json`: each open window's
screen class, title, size, and its screen's **public properties**. A restarted PHP reopens the
windows and assigns those properties back. Windows a handler opened (an About window, say) are
reopened too.

Kept:

- public, non-static, non-readonly properties of each screen;
- values that are `null`, scalars (`int`, `float`, `string`, `bool`), or arrays of those.

Not kept:

- private and protected properties;
- properties holding objects (for example a loaded `Sound`);
- static properties and anything outside the screens.

If a saved value no longer fits (the property was removed, renamed, made private, or its type
changed), it is skipped and dev mode prints why:

```
ragekit dev: not restoring $count of Demo\CounterScreen (no longer declared)
```

Native module state doesn't survive a PHP restart either: modules are reset first, so a sound that
was playing stops. Load such things lazily (on first use) and they come back when needed.

## Errors

- **Template error:** the message points at the file and line. The running UI stays as it was,
  and the next save that fixes it reloads the UI.
- **PHP error:** PHP's message is printed, then
  `ragekit dev: PHP stopped (exit N) — fix the error and save to restart`. The windows stay
  open (if any were open yet, e.g. not for an error in `main()`). The next save restarts PHP from
  the last snapshot.
- **Module build error:** the compiler output is printed and the app keeps the old library.

## Options

```sh
ragekit dev --kit=material    # use another kit than project.yml says
ragekit dev --on-top          # keep the app's windows above other windows
ragekit dev --headless        # no windows (Slint's testing backend), for automated tests
```

`--on-top` can also be set in `project.yml`:

```yaml
ragekit:
  dev:
    on-top: true
```

Wayland has no "always on top" request, so with `--on-top` the shell runs under XWayland. The
request is sent, but GNOME currently ignores it; other window managers may honour it.

The renderer is the one in `project.yml` (`ragekit: renderer:`), `software` by default.

## Dev mode runs full PHP

Dev mode runs your code on ordinary PHP, which accepts everything PHP accepts. A release build
compiles it with TypePHP, which supports a subset of PHP. Code that works in dev mode can therefore
still fail to build. `ragekit dev` reminds you of this when it starts.

Run `ragekit build` regularly, and before you ship, to check that TypePHP compiles your app.

Files your app reads at runtime are read from the project folder in dev mode: `App::resolve()`
uses the project folder there, and the executable's folder in a release build.

## Known limits

- **Linux only for now.** The dev shell's process and library handling is written for POSIX
  systems; on Windows use `ragekit build` / `ragekit run`.
- A **new PHP file** is picked up with the next change after it is created. Save it (or any other
  watched file) once more if a new class isn't found.
- PHP files that declare functions are loaded when PHP starts; class files are loaded on first
  use.
- While PHP is stopped after an error, a template reload shows the template's default values until
  PHP is back.

## See also

- [getting-started.md](getting-started.md): a first app
- [templates.md](templates.md): `.rk` templates
- [kits.md](kits.md): kits
- [modules.md](modules.md): native modules
- [toolchain.md](toolchain.md): `ragekit setup` and what a release build needs
