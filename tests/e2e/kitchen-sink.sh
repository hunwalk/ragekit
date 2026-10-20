#!/usr/bin/env bash
# demo/kitchen-sink under Xvfb: opens every tab and checks the window keeps its size (an element
# that caps a column's width shrinks the whole window). Extra arguments go to ragekit build, e.g.
# --kit=material. Needs DISPLAY (Xvfb :99, no window manager), xwininfo, python3.
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
demo="$root/demo/kitchen-sink"
fail() { echo "!! kitchen-sink: $*"; kill "$pid" 2>/dev/null; exit 1; }

(cd "$demo" && "$root/bin/ragekit" build "$@") || { echo "!! kitchen-sink: build failed"; exit 1; }
(cd "$demo/build/slint" && env -u WAYLAND_DISPLAY ./kitchen-sink >/dev/null 2>&1) &
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
# Tab centres along the tab bar (y 92 hits it with both kits), left to right: Inputs, More inputs,
# Display, Feedback, Navigation, Layout, Overlays.
for x in 55 148 246 336 440 532 616 55; do
    python3 -I "$root/tests/e2e/xclick.py" "$x,92"
    sleep 0.4
    [ "$(size)" = "$expected" ] || fail "the tab at x=$x resized the window to $(size) (was $expected)"
done
# Overlays tab (points hit the same buttons with both kits): Delete… opens the dialog, Esc closes it;
# again, and a click outside closes it; Save fires a toast; More ⋯ opens the menu, a click elsewhere
# closes it. The app must survive all of it at the same size.
python3 -I "$root/tests/e2e/xclick.py" "616,92"
for step in "click 60,325" "key Escape" "click 60,325" "click 650,560" "click 160,325" "click 250,325" "click 600,450"; do
    set -- $step
    if [ "$1" = click ]; then python3 -I "$root/tests/e2e/xclick.py" "$2"; else python3 -I "$root/tests/e2e/xkey.py" "$2" "$window"; fi
    sleep 0.4
    kill -0 "$pid" 2>/dev/null || fail "the app exited during '$step' on the Overlays tab"
    [ "$(size)" = "$expected" ] || fail "'$step' on the Overlays tab resized the window to $(size)"
done
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid"
echo "ok kitchen-sink: every tab, window stays $expected"
