#!/usr/bin/env bash
# tests/fixtures/pasteimage under Xvfb (<PasteImage>, RageKit\Clipboard::image()), with the text box
# focused: Ctrl+V with an image on the clipboard (GTK offers it as png, jpeg, …) runs the handler,
# which saves it as a png; with a copied image file (text/uri-list) the file's own bytes are saved;
# with text on the clipboard, Ctrl+V pastes into the text box as usual. Needs DISPLAY (e.g. Xvfb :99),
# python3-gi with GTK 3, ImageMagick.
#
#   DISPLAY=:99 tests/e2e/paste-image.sh          # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/pasteimage"
fail() { echo "!! paste-image: $* (files in $tmp)"; echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null; exit 1; }

cp -r "$root/tests/fixtures/pasteimage" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
# Xvfb's clipboard only: never the desktop's. Set after the build, which finds the toolchain
# through the real HOME when the checkout has no .ragekit of its own.
unset WAYLAND_DISPLAY
export GDK_BACKEND=x11 HOME="$tmp" XDG_CACHE_HOME="$tmp/cache"
convert -size 40x30 xc:red "$tmp/red.png"
convert -size 20x10 xc:blue "$tmp/copied file.jpg"

own() { # what the clipboard holds: set <text> | image <file> | file <path>
    [ -n "${owner:-}" ] && kill "$owner" 2>/dev/null && wait "$owner" 2>/dev/null
    python3 -I "$root/tests/e2e/xclipboard.py" "$1" "$2" 30 &
    owner=$!
    # Until it owns the clipboard (GTK can take over a second to start on a busy machine).
    for _ in $(seq 1 50); do
        [ -n "$(python3 -I "$root/tests/e2e/xclipboard.py" targets 2>/dev/null)" ] && break
        sleep 0.1
    done
}
key() { python3 -I "$root/tests/e2e/xkey.py" "$1"; sleep 0.5; }

own image "$tmp/red.png"
(cd "$app/build/slint" && timeout 30 ./pasteimage >"$tmp/app.log" 2>&1) &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("pasteimage"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.5
python3 -I "$root/tests/e2e/xkey.py" Escape "$window" # gives it the input focus (no window manager)
python3 -I "$root/tests/e2e/xclick.py" 100,50         # into the text box
sleep 0.3
python3 -I "$root/tests/e2e/xtype.py" "hi "
key Control_L+v
own file "$tmp/copied file.jpg"
key Control_L+v
own set "some text"
key Control_L+v
key Return
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid" || fail "the app's exit code was $?"
kill "$owner" 2>/dev/null

png=$(sed -n 1p "$tmp/app.log" | sed 's/^image //')
jpg=$(sed -n 2p "$tmp/app.log" | sed 's/^image //')
case "$png" in "$tmp"/cache/pasteimage/pasted/pasted-*.png) ;; *) fail "the image wasn't saved as a png in the cache folder: $png" ;; esac
[ "$(identify -format '%m %wx%h' "$png" 2>/dev/null)" = "PNG 40x30" ] || fail "the pasted png isn't the 40x30 image"
case "$jpg" in *.jpg) ;; *) fail "the copied file wasn't saved as a jpg: $jpg" ;; esac
cmp -s "$jpg" "$tmp/copied file.jpg" || fail "the copied file's bytes changed"
[ "$(sed -n 3p "$tmp/app.log")" = "draft hi some text" ] || fail "text paste into the text box broke"
[ "$(wc -l <"$tmp/app.log")" = 3 ] || fail "unexpected output"
rm -rf "$tmp"
echo "paste-image: ok"
