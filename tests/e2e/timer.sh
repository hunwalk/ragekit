#!/usr/bin/env bash
# tests/fixtures/timer under Xvfb: a one-shot started before the window opens fires, one stopped
# before it fires never does, one that throws is reported and the app goes on, and a repeating
# timer ticks five times, redrawing the window each time, then stops itself. Needs DISPLAY
# (e.g. Xvfb :99, no window manager), xwininfo, ImageMagick (import, compare), python3.
#
#   DISPLAY=:99 tests/e2e/timer.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/timer"
pid=
window=
fail() {
    echo "!! timer: $* (files in $tmp)"
    [ -n "$window" ] && python3 -I "$root/tests/e2e/xclose.py" "$window"
    [ -n "$pid" ] && kill "$pid" 2>/dev/null
    echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null
    exit 1
}
xwininfo -root -tree | grep -q '("timer"' && { echo "!! timer: a timer window is already open"; exit 1; }

cp -r "$root/tests/fixtures/timer" "$app"
# Built in a temporary copy, which wouldn't find this checkout's .ragekit/ toolchain by itself.
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY ./timer >"$tmp/app.log" 2>&1; echo $? >"$tmp/exit") &
pid=$!
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("timer"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.3
import -window "$window" "$tmp/waiting.png"     # ticking starts a second after start()
sleep 3                                          # 5 ticks 0.2 s apart, then nothing more
import -window "$window" "$tmp/stopped.png"      # (Nano buffers stdout until exit: no polling the log)
[ "$(compare -metric AE "$tmp/waiting.png" "$tmp/stopped.png" null: 2>&1 | cut -d' ' -f1)" != "0" ] \
    || fail "the window didn't redraw after the ticks"
python3 -I "$root/tests/e2e/xclose.py" "$window"
wait "$pid"
pid=

# The exception goes to stderr, unbuffered, so its place among the stdout lines varies.
grep -qx 'ragekit: uncaught RuntimeException: boom' "$tmp/app.log" || fail "the exception wasn't reported"
expected=$(printf '%s\n' "before the window" "tick 1" "tick 2" "tick 3" "tick 4" "tick 5" "stopped")
[ "$(grep -v '^ragekit: uncaught' "$tmp/app.log")" = "$expected" ] || fail "unexpected output"
[ "$(cat "$tmp/exit")" = "0" ] || fail "exit code $(cat "$tmp/exit")"
rm -rf "$tmp"
echo "timer: ok"
