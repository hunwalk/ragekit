#!/usr/bin/env bash
# demo/titlebar under Xvfb: drags the bar (the app moves the window on X11), resizes from a corner,
# clicks a button inside the bar (must click, not drag), closes with the window's own close button.
# Needs DISPLAY (e.g. Xvfb :99 at 1280x1024, no window manager), xwininfo, ImageMagick (import,
# compare), python3.
#
#   DISPLAY=:99 tests/e2e/titlebar.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
demo="$root/demo/titlebar"
tmp=$(mktemp -d)
fail() { echo "!! titlebar: $*"; kill "$pid" 2>/dev/null; exit 1; }

(cd "$demo" && "$root/bin/ragekit" build "$@") || { echo "!! titlebar: build failed"; exit 1; }
(cd "$demo/build/slint" && env -u WAYLAND_DISPLAY ./titlebar >"$tmp/app.log" 2>&1; echo $? >"$tmp/exit") &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("titlebar"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.5
position() { xwininfo -id "$window" | awk '/Absolute upper-left X/ {x=$4} /Absolute upper-left Y/ {y=$4} END {print x "," y}'; }
[ "$(position)" = "0,0" ] || fail "expected the window at 0,0, got $(position)"

# The bar's empty part (the title label) drags the window by 100,100.
python3 -I "$root/tests/e2e/xdrag.py" 200,15 300,115
[ "$(position)" = "100,100" ] || fail "dragging the bar moved the window to $(position), expected 100,100"

# The bottom-right grip resizes the window by 100,50 (window at 100,100, 640x400).
size() { xwininfo -id "$window" | awk '/Width:/ {w=$2} /Height:/ {h=$2} END {print w "x" h}'; }
python3 -I "$root/tests/e2e/xdrag.py" 736,496 836,546
[ "$(size)" = "740x450" ] || fail "dragging the bottom-right corner resized the window to $(size), expected 740x450"
[ "$(position)" = "100,100" ] || fail "resizing from the bottom-right corner moved the window to $(position)"

# The Search button sits inside the drag area: it must run its handler (the summary changes).
import -window root -crop 300x30+110+175 "$tmp/before.png"
python3 -I "$root/tests/e2e/xclick.py" 653,117 # right-aligned: 100 px further after the resize
sleep 0.5
import -window root -crop 300x30+110+175 "$tmp/after.png"
changed=$(compare -metric AE "$tmp/before.png" "$tmp/after.png" null: 2>&1)
[ "${changed%% *}" != "0" ] || fail "clicking Search in the bar changed nothing"
position=$(position)
[ "$position" = "100,100" ] || fail "clicking Search moved the window to $position"

# The window's own close button ends the app.
python3 -I "$root/tests/e2e/xclick.py" 817,116
for _ in $(seq 1 50); do
    [ -f "$tmp/exit" ] && break
    sleep 0.1
done
[ -f "$tmp/exit" ] || fail "the close button didn't end the app"
[ "$(cat "$tmp/exit")" = "0" ] || { cat "$tmp/app.log"; fail "exit code $(cat "$tmp/exit")"; }
echo "ok titlebar: drag, resize, a button in the bar, close"
