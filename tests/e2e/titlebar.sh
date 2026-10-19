#!/usr/bin/env bash
# demo/titlebar under Xvfb (no window manager, so the bar moves the window by hand): drags the bar,
# clicks a button inside it (must click, not drag), closes with the window's own close button.
# Needs DISPLAY (e.g. Xvfb :99 at 1280x1024), xwininfo, ImageMagick (import, compare), python3.
# FAKE_WM=1: the root advertises _NET_WM_MOVERESIZE but no window manager takes the drag (like a
# WM that can't grab the pointer): the app must still move the window itself.
#
#   DISPLAY=:99 tests/e2e/titlebar.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
demo="$root/demo/titlebar"
tmp=$(mktemp -d)
fail() { echo "!! titlebar: $*"; kill "$pid" 2>/dev/null; exit 1; }

(cd "$demo" && "$root/bin/ragekit" build "$@") || { echo "!! titlebar: build failed"; exit 1; }
if [ "${FAKE_WM:-}" = "1" ]; then
    python3 -I "$root/tests/e2e/xfakewm.py" &
    fakewm=$!
    trap 'kill $fakewm 2>/dev/null' EXIT
    sleep 0.3
fi
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

# The Search button sits inside the drag area: it must run its handler (the summary changes).
import -window root -crop 300x30+110+175 "$tmp/before.png"
python3 -I "$root/tests/e2e/xclick.py" 553,117
sleep 0.5
import -window root -crop 300x30+110+175 "$tmp/after.png"
changed=$(compare -metric AE "$tmp/before.png" "$tmp/after.png" null: 2>&1)
[ "${changed%% *}" != "0" ] || fail "clicking Search in the bar changed nothing"
position=$(position)
[ "$position" = "100,100" ] || fail "clicking Search moved the window to $position"

# The window's own close button ends the app.
python3 -I "$root/tests/e2e/xclick.py" 717,116
for _ in $(seq 1 50); do
    [ -f "$tmp/exit" ] && break
    sleep 0.1
done
[ -f "$tmp/exit" ] || fail "the close button didn't end the app"
[ "$(cat "$tmp/exit")" = "0" ] || { cat "$tmp/app.log"; fail "exit code $(cat "$tmp/exit")"; }
echo "ok titlebar: drag, a button in the bar, close"
