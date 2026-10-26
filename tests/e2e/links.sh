#!/usr/bin/env bash
# tests/fixtures/links under Xvfb, with temporary XDG folders (never the user's own settings): the
# app registers itself for rktest: links (.desktop file + mimeapps.list, which xdg-mime then
# reports); `gio open rktest://hello` starts a second launch that hands the link to the running app
# (whose window shows it); a plain second launch hands over and ends; rktest://quit ends the app.
# Then an instance killed with SIGKILL leaves its socket behind, and the next launch still starts
# (and opens the link it was started with). The app's icon (ragekit: icon:) is checked on the way.
# Needs DISPLAY (e.g. Xvfb :99), gio, xdg-mime and xprop.
#
#   DISPLAY=:99 tests/e2e/links.sh             # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/links"
fail() { echo "!! links: $* (files in $tmp)"; for f in "$tmp"/run*.log; do echo "--- $f:"; cat "$f"; done; pkill -x links; exit 1; }
xwininfo -root -tree | grep -q '("links"' && { echo "!! links: a links window is already open"; exit 1; }

cp -r "$root/tests/fixtures/links" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
exe="$app/build/slint/links"

mkdir -p "$tmp/data" "$tmp/config" "$tmp/runtime"
chmod 700 "$tmp/runtime"
export XDG_DATA_HOME="$tmp/data" XDG_CONFIG_HOME="$tmp/config" XDG_RUNTIME_DIR="$tmp/runtime" XDG_DATA_DIRS="$tmp/data"
unset WAYLAND_DISPLAY
window=
started() {
    for _ in $(seq 1 100); do
        window=$(xwininfo -root -tree | grep -m1 '("links"' | awk '{print $1}')
        [ -n "$window" ] && return 0
        sleep 0.1
    done
    return 1
}

"$exe" >"$tmp/run1.log" 2>&1 &
first=$!
started || fail "no window appeared"
sleep 0.5
grep -qx 'MimeType=x-scheme-handler/rktest;' "$tmp/data/applications/ragekit-links.desktop" || fail "no .desktop file"
grep -q "^Exec=\"$exe\" %u$" "$tmp/data/applications/ragekit-links.desktop" || fail "the .desktop file doesn't run the app"
# The app's icon (ragekit: icon:): copied next to the executable, named by the .desktop file, shown by
# the window (_NET_WM_ICON, which xprop draws: "Icon (64 x 64):" and the pixels).
grep -qx "Icon=$app/build/slint/icon.png" "$tmp/data/applications/ragekit-links.desktop" || fail "the .desktop file doesn't name the icon"
cmp -s "$app/build/slint/icon.png" "$root/tests/fixtures/links/icon.png" || fail "the icon isn't next to the executable"
# Into a file first: under pipefail, `xprop | head -1` fails whenever head closes the pipe early.
xprop -id "$window" _NET_WM_ICON >"$tmp/icon.txt" 2>&1
head -1 "$tmp/icon.txt" | grep -q 'Icon (64 x 64)' || fail "the window has no 64x64 icon"
[ "$(xdg-mime query default x-scheme-handler/rktest)" = "ragekit-links.desktop" ] || fail "xdg-mime doesn't name the app as the handler"
[ -S "$tmp/runtime/ragekit-links.sock" ] || fail "the running instance doesn't listen"
timeout 5 import -window "$window" "$tmp/before.png"

gio open 'rktest://hello?code=a%20b&state=x+y' || fail "gio open failed"
sleep 1
timeout 5 import -window "$window" "$tmp/after.png"
[ "$(compare -metric AE "$tmp/before.png" "$tmp/after.png" null: 2>&1 | cut -d' ' -f1)" != "0" ] || fail "the window didn't show the link"
[ "$(xwininfo -root -tree | grep -c '("links"')" = "1" ] || fail "a second window opened"

timeout 5 "$exe" >"$tmp/run2.log" 2>&1 || fail "a plain second launch didn't end by itself"
timeout 5 "$exe" 'rktest://quit' >"$tmp/run3.log" 2>&1 || fail "the quit launch didn't end by itself"
for _ in $(seq 1 50); do kill -0 "$first" 2>/dev/null || break; sleep 0.1; done
kill -0 "$first" 2>/dev/null && fail "rktest://quit didn't end the app"
wait "$first" || fail "the app's exit code was $?"
[ "$(cat "$tmp/run1.log")" = "$(printf '%s\n' started 'link rktest://hello?code=a%20b&state=x+y' '  code=a b' '  state=x y' 'link rktest://quit')" ] || fail "unexpected output"
[ -s "$tmp/run2.log" ] && fail "the plain second launch printed something"
[ -e "$tmp/runtime/ragekit-links.sock" ] && fail "the socket is still there after a clean exit"

# A crash leaves the socket: the next launch must still start, with the link it was given.
"$exe" >"$tmp/run4.log" 2>&1 &
crashed=$!
started || fail "no window the second time"
kill -9 "$crashed"; wait "$crashed" 2>/dev/null
[ -S "$tmp/runtime/ragekit-links.sock" ] || fail "expected a stale socket after SIGKILL"
"$exe" 'rktest://again' >"$tmp/run5.log" 2>&1 &
again=$!
sleep 0.5
started || fail "no window after a crash"
sleep 0.5
timeout 5 "$exe" 'rktest://quit' >/dev/null 2>&1
wait "$again" || fail "the instance after the crash exited with $?"
[ "$(cat "$tmp/run5.log")" = "$(printf '%s\n' started 'link rktest://again' 'link rktest://quit')" ] || fail "after a crash: unexpected output"
rm -rf "$tmp"
echo "links: ok"
