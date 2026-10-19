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
# Display, Feedback, Layout.
for x in 74 208 346 475 603 74; do
    python3 -I "$root/tests/e2e/xclick.py" "$x,92"
    sleep 0.4
    [ "$(size)" = "$expected" ] || fail "the tab at x=$x resized the window to $(size) (was $expected)"
done
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid"
echo "ok kitchen-sink: every tab, window stays $expected"
