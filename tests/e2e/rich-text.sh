#!/usr/bin/env bash
# tests/fixtures/richtext under Xvfb: <Text markdown="true"> bubbles from @foreach (bold, italic,
# code, links, a code block) and <Emoji> (colour images from the system's emoji font, Noto Color
# Emoji). Clicking a link runs onLink with its URL, a right-click on the text opens the bubble's
# <ContextMenu>; the heart and the thumbs up are drawn in colour.
# Needs DISPLAY (e.g. Xvfb :99), ImageMagick, fonts-noto-color-emoji and HarfBuzz.
#
#   DISPLAY=:99 tests/e2e/rich-text.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/richtext"
fail() { echo "!! rich-text: $* (files in $tmp)"; echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null; exit 1; }

unset WAYLAND_DISPLAY
cp -r "$root/tests/fixtures/richtext" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

(cd "$app/build/slint" && timeout 20 ./richtext >"$tmp/app.log" 2>&1) &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '"richtext"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 1
import -window "$window" "$tmp/shot.png"
count() { convert "$tmp/shot.png" -fx "$1" -format '%[fx:int(mean*w*h)]' info: | tail -n 1; } # (IM 7 may print a stray line first)
red=$(count '(r>0.78)*(g<0.45)*(b<0.45)')      # the heart (nothing else in the window is red)
yellow=$(count '(r>0.8)*(g>0.6)*(b<0.35)')     # the thumbs up, the laughing face
[ "$red" -gt 100 ] || fail "only $red red pixels: the heart isn't drawn in colour"
[ "$yellow" -gt 200 ] || fail "only $yellow yellow pixels: the emoji aren't drawn in colour"
python3 -I "$root/tests/e2e/xclick.py" 3@80,28  # right-click on the first bubble's text: its menu
sleep 0.5
python3 -I "$root/tests/e2e/xclick.py" 110,45   # Reply
sleep 0.5
python3 -I "$root/tests/e2e/xclick.py" 100,67   # "the docs"
sleep 0.5
python3 -I "$root/tests/e2e/xclick.py" 205,67   # the bare URL
sleep 0.5
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid" || fail "the app's exit code was $?"
[ "$(cat "$tmp/app.log")" = "$(printf '%s\n' 'reply Hello **bold** and _italic_ with `code` :tada:' 'link https://example.com/docs' 'link https://example.org/x')" ] \
    || fail "the menu or the links didn't reach their handlers"
rm -rf "$tmp"
echo "rich-text: ok"
