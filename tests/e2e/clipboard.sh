#!/usr/bin/env bash
# tests/fixtures/clipboard under Xvfb: another program (GTK, xclipboard.py) owns the clipboard with
# some text; the app pastes it (RageKit\Clipboard::paste), then copies its own two lines, which the
# other program then reads. Needs DISPLAY (e.g. Xvfb :99) and python3-gi with GTK 3.
#
#   DISPLAY=:99 tests/e2e/clipboard.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/clipboard"
fail() { echo "!! clipboard: $* (files in $tmp)"; echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null; exit 1; }

# Xvfb's clipboard only: never the desktop's (a Wayland session's would take GTK's side).
unset WAYLAND_DISPLAY
export GDK_BACKEND=x11
cp -r "$root/tests/fixtures/clipboard" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

python3 -I "$root/tests/e2e/xclipboard.py" set "from outside ✓" 6 &
owner=$!
sleep 0.5
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY timeout 20 ./clipboardapp >"$tmp/app.log" 2>&1) &
pid=$!
window=
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("clipboardapp"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 2 # the app pastes and copies a second after start
got=$(python3 -I "$root/tests/e2e/xclipboard.py" get)
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid" || fail "the app's exit code was $?"
kill "$owner" 2>/dev/null
[ "$got" = "$(printf 'from RageKit ✓\nsecond line')" ] || fail "the clipboard holds '$got'"
[ "$(cat "$tmp/app.log")" = "$(printf '%s\n' 'pasted: from outside ✓' copied)" ] || fail "unexpected output"
rm -rf "$tmp"
echo "clipboard: ok"
