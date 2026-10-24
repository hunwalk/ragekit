#!/usr/bin/env bash
# tests/e2e/images under Xvfb: a registered asset (red), an asset switched by state (red → blue
# after Toggle), and a web image served by php -S (green), downloaded into a fresh cache. Then:
# the cached image shows with the server stopped, and with neither server nor cache the image shows
# its fallback (blue) and the app prints one error line. Needs DISPLAY (Xvfb, no window manager),
# xwininfo, ImageMagick (convert, import), python3, php. Extra arguments go to ragekit build.
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
app="$root/tests/e2e/images"
tmp=$(mktemp -d)
port=18766
server=
pid=
fail() { echo "!! images: $*"; kill "$pid" "$server" 2>/dev/null; exit 1; }

mkdir -p "$app/assets" "$tmp/web"
convert -size 64x64 xc:red "$app/assets/red.png"
convert -size 64x64 xc:blue "$app/assets/blue.png"
convert -size 64x64 xc:lime "$tmp/web/green.png"
(cd "$app" && "$root/bin/ragekit" build "$@") || { echo "!! images: build failed"; exit 1; }
exe=$(find "$app/build" -maxdepth 2 -type f -name images -perm -u+x | head -n 1)

serve() { php -S "127.0.0.1:$port" -t "$tmp/web" >"$tmp/server.log" 2>&1 & server=$!; sleep 0.5; }
start() { # $1 = cache folder
    (cd "$(dirname "$exe")" && XDG_CACHE_HOME="$1" env -u WAYLAND_DISPLAY ./images >"$tmp/app.log" 2>&1) &
    pid=$!
    window=
    for _ in $(seq 1 100); do
        window=$(xwininfo -root -tree | grep -m1 '("images"' | awk '{print $1}')
        [ -n "$window" ] && break
        sleep 0.1
    done
    [ -n "$window" ] || fail "no window appeared"
}
pixel() { import -window "$window" "$tmp/shot.png"; convert "$tmp/shot.png" -depth 8 -format "%[hex:p{$1}]" info: | cut -c1-6; }
expect() { # $1 = x,y  $2 = RRGGBB  $3 = what; waits up to 5 s
    for _ in $(seq 1 50); do
        [ "$(pixel "$1")" = "$2" ] && return 0
        sleep 0.1
    done
    fail "$3: $1 is $(pixel "$1"), expected $2"
}
stop() {
    python3 -I "$root/tests/e2e/xclose.py" "$window"
    for _ in $(seq 1 100); do kill -0 "$pid" 2>/dev/null || break; sleep 0.1; done
    wait "$pid"
    code=$?
    [ "$code" -eq 0 ] || fail "$1: exit code $code"
}

serve
start "$tmp/cache"
expect 48,48 FF0000 "registered asset"
expect 120,48 FF0000 "asset from state"
expect 192,48 00FF00 "web image"
python3 -I "$root/tests/e2e/xclick.py" 160,105
expect 120,48 0000FF "asset after Toggle"
stop "first run"
echo "ok images: asset, state-switched asset, web image"

kill "$server"
start "$tmp/cache"
expect 192,48 00FF00 "web image from the cache (server stopped)"
stop "cached run"
echo "ok images: the cache works without the server"

start "$tmp/empty-cache"
expect 192,48 0000FF "fallback while the download fails"
stop "fallback run"
grep -q "ragekit: image http://127.0.0.1:$port/green.png: " "$tmp/app.log" || fail "no error line: $(cat "$tmp/app.log")"
[ "$(grep -c 'ragekit: image' "$tmp/app.log")" = "1" ] || fail "more than one error line: $(cat "$tmp/app.log")"
echo "ok images: fallback and one error line without server or cache"
rm -rf "$tmp"
