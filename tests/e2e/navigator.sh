#!/usr/bin/env bash
# demo/navigator under Xvfb: screens change inside ONE window (same X window id and size
# throughout): albums → an album's photos (push) → back with the title bar's ← → the albums kept
# their state; push twice and come back with Alt+Left twice; a dialog on a pushed screen; "All
# albums" resets the stack. Needs DISPLAY (Xvfb :99, no window manager), xwininfo, ImageMagick
# (import, compare), python3. Extra arguments go to ragekit build (e.g. --kit=material, --nano).
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
demo="$root/demo/navigator"
tmp=$(mktemp -d)
fail() { echo "!! navigator: $*"; kill "$pid" 2>/dev/null; exit 1; }

(cd "$demo" && "$root/bin/ragekit" build "$@") || { echo "!! navigator: build failed"; exit 1; }
(cd "$demo/build/slint" && env -u WAYLAND_DISPLAY ./navigator >"$tmp/app.log" 2>&1) &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("navigator"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.5
size() { xwininfo -id "$window" | awk '/Width:/ {w=$2} /Height:/ {h=$2} END {print w "x" h}'; }
expected=$(size)
click() { python3 -I "$root/tests/e2e/xclick.py" "$1"; sleep 0.5; }
key() { python3 -I "$root/tests/e2e/xkey.py" "$1" "$window"; sleep 0.5; }
shot() { import -window "$window" "$tmp/$1.png"; }
same() { [ "$(compare -metric AE "$tmp/$1.png" "$tmp/$2.png" null: 2>&1 | cut -d' ' -f1)" = "0" ]; }
still() {
    [ "$(xwininfo -root -tree | grep -c '("navigator"')" = "1" ] || fail "$1: another window opened"
    [ "$(size)" = "$expected" ] || fail "$1: the window is now $(size) (was $expected)"
}

shot albums-start
click 260,147                       # "Family": push the album's photos
still "push"
shot photos
same photos albums-start && fail "push didn't change the window's content"
click 21,18                         # the title bar's ←
still "back"
shot albums-back
same albums-back albums-start && fail "the albums lost their state (Last opened)"

click 260,147                       # Family again
click 260,183                       # "Family 3": push one photo (three screens deep)
key Alt_L+Left
key Alt_L+Left                      # back twice with the keyboard
still "Alt+Left"
shot albums-keys
same albums-keys albums-back || fail "Alt+Left twice didn't return to the albums as they were"

click 260,147
click 260,183
click 67,107                        # "Rename…": a dialog on the third screen
shot dialog
case " $* " in *" --kit=material "*) save=276,268 ;; *) save=225,250 ;; esac
click "$save"                       # Save: closes it and shows a toast
shot saved
same saved dialog && fail "the dialog didn't close"
click 180,107                       # "All albums": reset the stack
still "reset"
click 400,300                       # an empty spot: no hover highlight left
shot albums-reset-toast
same albums-reset-toast albums-start && fail "the toast didn't survive the reset (it belongs to the window)"
sleep 3.2                           # the toast expires
shot albums-reset
same albums-reset albums-start || fail "reset didn't show a fresh albums screen"

python3 -I "$root/tests/e2e/xclose.py" "$window" 2>/dev/null || kill "$pid"
wait "$pid" 2>/dev/null
echo "ok navigator: push, back (← and Alt+Left), state kept, dialog, reset — one window, $expected"
