#!/usr/bin/env bash
# demo/kitchen-sink under Xvfb: opens every section of the sidebar and every tab, and uses a few
# things on the way (a right-click menu, 200 chat messages, a new key, the tray and notification
# buttons without a desktop), checking the app keeps running and the window keeps its size (an
# element that caps a column's width shrinks the whole window). Extra arguments go to ragekit
# build, e.g. --kit=material. Needs DISPLAY (Xvfb :99, no window manager), xwininfo, python3.
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
demo="$root/demo/kitchen-sink"
fail() { echo "!! kitchen-sink: $*"; kill "$pid" 2>/dev/null; exit 1; }

(cd "$demo" && "$root/bin/ragekit" build "$@") || { echo "!! kitchen-sink: build failed"; exit 1; }
# No D-Bus session (no real notifications from a test) and no microphone.
(cd "$demo/build/slint" && env -u WAYLAND_DISPLAY -u DBUS_SESSION_BUS_ADDRESS RAGEKIT_AUDIO_NULL=1 ./kitchen-sink >/dev/null 2>&1) &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("kitchen-sink"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.5
size() { xwininfo -id "$window" | awk '/Width:/ {w=$2} /Height:/ {h=$2} END {print w "x" h}'; }
expected=$(size)
step() { # $1: what; then xclick.py positions
    local what=$1
    shift
    python3 -I "$root/tests/e2e/xclick.py" "$@"
    sleep 0.5
    kill -0 "$pid" 2>/dev/null || fail "the app exited during '$what'"
    [ "$(size)" = "$expected" ] || fail "'$what' resized the window to $(size) (was $expected)"
}
# The sidebar's sections (fixed-height rows, the same with both kits) and their tabs.
step "Inputs: both tabs" 80,59 717,106 367,106
step "Display: three tabs" 80,97 512,106 768,106 277,106
step "Navigation" 80,135
step "Layout: select a row" 80,173 400,263
step "Overlays: right-click the card" 80,211 3@533,200
python3 -I "$root/tests/e2e/xkey.py" Escape "$window"
step "Chat: 200 more messages" 80,249 811,67
step "Desktop: new key, tray, notification" 80,287 343,468 343,318 675,168
step "back to Inputs" 80,59
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid"
echo "ok kitchen-sink: every section and tab, window stays $expected"
