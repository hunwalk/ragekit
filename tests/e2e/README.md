# End-to-end checks (Linux, no desktop needed)

`tests/e2e/demos.sh` builds every demo, opens it and closes it (CI runs it with `--nano`):

    DISPLAY=:99 tests/e2e/demos.sh            # extra arguments go to ragekit build, e.g. --nano

`tests/e2e/kitchen-sink.sh` opens every section and tab of `demo/kitchen-sink` and checks the window keeps its
size (an element that caps a column's width shrinks the window); `--kit=material` for the other kit.

`tests/e2e/navigator.sh` drives `demo/navigator`: signs in (a store event the app class turns into
navigation), pushes screens and goes back with the title bar's ← and with Alt+Left, checking the
window stays the same X window and size and the first screen keeps its state; a dialog on a pushed
screen, a toast that survives a reset of the stack, Sign out in the title bar.

    DISPLAY=:99 tests/e2e/navigator.sh        # also with --kit=material, --nano

`tests/e2e/titlebar.sh` drives `demo/titlebar` (a `Window` subclass): drags the bar, resizes from a corner
(on X11 the app moves and resizes its window itself), clicks a button inside the bar, closes with
the window's own close button:

    DISPLAY=:99 tests/e2e/titlebar.sh         # also with --nano

`tests/e2e/timer.sh` builds `tests/fixtures/timer` in a temporary folder: a one-shot started before
the window opens, one stopped before it fires, one that throws (reported, the app goes on) and a
repeating timer that ticks five times, redrawing the window, then stops itself. Nano builds buffer
stdout until exit, so the script reads the output after closing the window:

    DISPLAY=:99 tests/e2e/timer.sh            # also with --nano

`tests/e2e/crypto.sh` builds `tests/fixtures/crypto` (the crypto module) and compares what it
prints for fixed keys and messages with `tests/fixtures/crypto/expected.txt`, which comes from
sodium, PHP's `hash()` and `ssh-keygen`:

    DISPLAY=:99 tests/e2e/crypto.sh           # also with --nano

`tests/e2e/keyring.sh` builds `tests/fixtures/devicekey` (crypto + keyring) and runs it twice in a
throwaway D-Bus session and gnome-keyring-daemon with a temporary HOME: the first run stores a new
device key, the second loads the same one. Then once with no Secret Service at all:

    DISPLAY=:99 tests/e2e/keyring.sh          # also with --nano

`tests/e2e/scroll.sh` builds `tests/fixtures/chat` (a `<ScrollView>` used as a chat) and measures,
from screenshots, how far the messages move: one row for a new message at the bottom, nothing for
one while scrolled up, and no jump when older messages load above:

    DISPLAY=:99 tests/e2e/scroll.sh           # also with --nano
    VIRTUAL=1 DISPLAY=:99 tests/e2e/scroll.sh # the same checks on <ScrollView virtual="true">

`tests/e2e/pressable.sh` builds `tests/fixtures/pressable`: Ctrl+F before any click focuses the search
box (`ref` + `focus()`), then hovers, presses and right-clicks `<View>` rows (onHoverIn/onHoverOut,
onPress, `<ContextMenu>`) and uses `<Shortcut>`s, comparing the events the app prints:

    DISPLAY=:99 tests/e2e/pressable.sh        # also with --kit=material, --nano

`tests/e2e/clipboard.sh` builds `tests/fixtures/clipboard`: a GTK program (`xclipboard.py`) owns the X11
clipboard, the app pastes its text and copies its own, which the program reads back:

    DISPLAY=:99 tests/e2e/clipboard.sh        # also with --nano

`tests/e2e/upload.sh` builds `tests/fixtures/upload`, which sends a `Multipart` form through the
http module to a local `php -S`; the answer is what PHP's own parser made of it:

    DISPLAY=:99 tests/e2e/upload.sh           # also with --nano

`tests/e2e/notifications.sh` builds `tests/fixtures/notify` and runs it in a throwaway D-Bus session
with a fake notification service (`tests/fixtures/notifications/fake_server.py`, Python with gi): the
notification is shown, clicked through the service's test hook, and the app's handler changes the
window:

    DISPLAY=:99 tests/e2e/notifications.sh    # also with --nano

`tests/e2e/rich-text.sh` builds `tests/fixtures/richtext`: markdown bubbles from `@foreach` and a row
of `<Emoji>`; it checks that the heart and the thumbs up are drawn in colour, and clicks a markdown link
and a bare URL, which reach onLink:

    DISPLAY=:99 tests/e2e/rich-text.sh        # also with --nano

`tests/e2e/paste-image.sh` builds `tests/fixtures/pasteimage` (`<PasteImage>` next to a Textarea) and,
with the text box focused, presses Ctrl+V three times: with an image on the clipboard (saved as png),
a copied image file (saved with its own bytes), and text (pasted into the text box as usual):

    DISPLAY=:99 tests/e2e/paste-image.sh      # also with --nano

`tests/e2e/dialogs.sh` builds `tests/fixtures/filepick` and runs it in a throwaway D-Bus session with
a fake file chooser portal (`tests/fixtures/portal/fake_portal.py`) that answers each dialog at once:
two files, a cancel, a save and a folder; the test checks what the app got and what the portal was asked:

    DISPLAY=:99 tests/e2e/dialogs.sh          # also with --nano

`tests/e2e/links.sh` builds `tests/fixtures/links` (links: [rktest]) and, with temporary XDG folders,
checks the registration (`xdg-mime`), a `gio open rktest://…` handed to the running app, a plain
second launch, rktest://quit, and a start after an instance was killed (stale socket):

    DISPLAY=:99 tests/e2e/links.sh            # also with --nano

`tests/e2e/tray.sh` builds `tests/fixtures/trayapp` (it lives in the tray) and runs it in a throwaway
D-Bus session with a fake StatusNotifierWatcher (`tests/fixtures/tray/fake_watcher.py`): closing the
window hides it, a click on the icon shows it again, the menu's Quit ends the app:

    DISPLAY=:99 tests/e2e/tray.sh             # also with --nano

By hand:

    Xvfb :99 -screen 0 1280x1024x24 -nolisten tcp &
    export DISPLAY=:99
    env -u WAYLAND_DISPLAY ./build/slint/<app> &       # opens at 0,0 (no window manager); without
                                                       # unsetting it, a desktop session's Wayland wins
    xwininfo -root -tree | grep '("<app>"'             # window id
    import -window <id> shot.png                       # screenshot → find button centres
    python3 -I tests/e2e/xclick.py 48,268 121,268      # click x,y pairs (window coords = screen)
    python3 -I tests/e2e/xdrag.py 20,269 320,269    # press, move in steps, release (sliders)
    python3 -I tests/e2e/xtype.py Ann <id>             # focus the window (no WM does it), type into the focused field
    python3 -I tests/e2e/xkey.py Escape <id>           # press one key by X keysym name
    python3 -I tests/e2e/xclose.py <id>                # WM_DELETE_WINDOW, like the close button

## Dev mode (`ragekit dev`)

    cd demo/kitchen-sink && DISPLAY=:99 env -u WAYLAND_DISPLAY ../../bin/ragekit dev </dev/null
    # (start it in the background; the CLI drives the dev shell through its own pipe)
    xwininfo -root -tree | grep ragekit-devshell       # dev windows belong to "ragekit-devshell"

- Edit a template: the window's UI is rebuilt — a new X window id at the same position/size.
- Edit PHP: same X window; public screen properties come back from build/dev/state.json.
- `--on-top`: Xvfb has no window manager to set _NET_WM_STATE, so check the request itself:
  run a root listener for _NET_WM_STATE client messages (`_NET_WM_STATE_ABOVE` is added).
- Stopping: kill the `ragekit` PHP process itself (not a wrapper shell); the shell sees its
  stdin close and takes PHP down with it.

## Audio (`modules: [audio]`, demo/player)

    RAGEKIT_AUDIO_NULL=1 DISPLAY=:99 env -u WAYLAND_DISPLAY ./build/slint/player   # miniaudio's null device

- The null device plays in real time without sound: positions, progress events and "finished"
  behave as with speakers. Volume can't be heard; only the label shows it.
- Module unavailable: move `build/slint/librk_module_audio.so` away — the app must still start.
