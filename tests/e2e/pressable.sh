#!/usr/bin/env bash
# tests/fixtures/pressable under Xvfb: <View onPress/onHoverIn/onHoverOut>, its <ContextMenu> (a
# right-click opens it at the pointer, an item runs its handler) and <Shortcut>s (Ctrl+F and Escape
# while typing in an Entry; Ctrl+Shift+F must not match Ctrl+F). The app prints each event.
# Needs DISPLAY (e.g. Xvfb :99).
#
#   DISPLAY=:99 tests/e2e/pressable.sh            # extra arguments go to ragekit build (--nano, --kit=material)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/pressable"
pid=
window=
fail() {
    echo "!! pressable: $* (files in $tmp)"
    [ -n "$window" ] && python3 -I "$root/tests/e2e/xclose.py" "$window"
    [ -n "$pid" ] && kill "$pid" 2>/dev/null
    echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null
    exit 1
}
xwininfo -root -tree | grep -q '("pressable"' && { echo "!! pressable: a pressable window is already open"; exit 1; }

cp -r "$root/tests/fixtures/pressable" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY ./pressable >"$tmp/app.log" 2>&1) &
pid=$!
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("pressable"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.5
# Without a window manager the window sits at 0,0 (420x360); rows: ann at y≈111, bob at y≈171,
# each with a Wave button on the right. Positions differ a little by kit, so the clicks aim at
# the middle of things.
if grep -q -- '--kit=material' <<<"$*"; then
    row1=135; row2=199; entry=70; second=80     # Material: taller rows and menu items (48 px)
else
    row1=111; row2=171; entry=58; second=52
fi
x="python3 -I $root/tests/e2e"
$x/xmove.py "100,$row1" "100,$row2" 300,320               # hover ann, then bob, then away
$x/xclick.py "100,$row1"                                   # press ann's row
$x/xclick.py "370,$row2"                                   # bob's Wave: the button, not the row
$x/xmove.py 300,320
$x/xclick.py "3@150,$row1"                                 # right-click ann: the menu at the pointer
sleep 0.4
timeout 5 import -window "$window" "$tmp/menu.png"
$x/xclick.py "190,$((row1 + second))"                          # its second item, Delete
$x/xclick.py "200,$entry"                                       # the Entry
$x/xtype.py hi "$window"
$x/xkey.py Control_L+f "$window"; sleep 0.3
$x/xkey.py Escape "$window"; sleep 0.3
$x/xkey.py Control_L+Shift_L+f "$window"; sleep 0.3
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid" || fail "the app's exit code was $?"
pid=
window=

expected=$(printf '%s\n' "hover in ann" "hover out ann" "hover in bob" "hover out bob" "hover in ann" "open ann" "hover out ann" \
    "hover in bob" "wave bob" "hover out bob" "hover in ann" "hover out ann" "remove ann" "find" "escape")
[ "$(cat "$tmp/app.log")" = "$expected" ] || { diff <(echo "$expected") "$tmp/app.log"; fail "unexpected events"; }
rm -rf "$tmp"
echo "pressable: ok"
