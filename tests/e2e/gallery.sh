#!/usr/bin/env bash
# tests/fixtures/gallery under Xvfb: arrays inside collection rows (a @foreach inside a @foreach):
# each post's tags (Badges) and pictures (Images). "Add a picture" appends to the first post's
# arrays: the new (blue) picture shows up in its row. Needs DISPLAY (e.g. Xvfb :99), ImageMagick.
#
#   DISPLAY=:99 tests/e2e/gallery.sh             # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/gallery"
fail() { echo "!! gallery: $* (files in $tmp)"; echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null; exit 1; }

unset WAYLAND_DISPLAY
cp -r "$root/tests/fixtures/gallery" "$app"
rm -rf "$app/build"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

(cd "$app/build/slint" && timeout 20 ./gallery >"$tmp/app.log" 2>&1) &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '"gallery"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 1
count() { convert "$1" -fx "$2" -format '%[fx:int(mean*w*h)]' info: | tail -n 1; } # (IM 7 may print a stray line first)
import -window "$window" "$tmp/before.png"
python3 -I "$root/tests/e2e/xclick.py" 60,30   # Add a picture
sleep 0.8
import -window "$window" "$tmp/after.png"
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid" || fail "the app's exit code was $?"
[ "$(count "$tmp/before.png" '(r>0.9)*(g<0.1)*(b<0.1)')" -gt 2000 ] || fail "the first post's red picture isn't shown"
[ "$(count "$tmp/before.png" '(b>0.9)*(r<0.1)*(g<0.1)')" -lt 100 ] || fail "a blue picture shows before it was added"
[ "$(count "$tmp/after.png" '(b>0.9)*(r<0.1)*(g<0.1)')" -gt 2000 ] || fail "the added (blue) picture didn't show in the row"
[ "$(cat "$tmp/app.log")" = "pictures 2" ] || fail "unexpected output"
rm -rf "$tmp"
echo "gallery: ok"
