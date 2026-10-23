# End-to-end checks (Linux, no desktop needed)

`tests/e2e/demos.sh` builds every demo, opens it and closes it (CI runs it with `--nano`):

    DISPLAY=:99 tests/e2e/demos.sh            # extra arguments go to ragekit build, e.g. --nano

`tests/e2e/kitchen-sink.sh` opens every tab of `demo/kitchen-sink` and checks the window keeps its
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
