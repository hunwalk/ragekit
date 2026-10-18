#!/usr/bin/env bash
# Builds every demo, starts it, waits for its window, closes it and expects exit code 0.
# Needs an X display (e.g. Xvfb) in DISPLAY, plus xwininfo (x11-utils) and python3.
#
#   DISPLAY=:99 tests/e2e/demos.sh            # normal builds
#   DISPLAY=:99 tests/e2e/demos.sh --nano     # Nano builds (extra arguments go to ragekit build)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
logs=$(mktemp -d)
export RAGEKIT_AUDIO_NULL=1 # the player's audio on miniaudio's null device (no speakers needed)
failed=0

for dir in "$root"/demo/*/; do
    demo=$(basename "$dir")
    echo "== $demo: ragekit build $*"
    if ! (cd "$dir" && "$root/bin/ragekit" build "$@"); then
        echo "!! $demo: build failed"
        failed=1
        continue
    fi
    exe=$(find "$dir/build" -maxdepth 2 -type f -name "$demo" -perm -u+x | head -n 1)
    if [ -z "$exe" ]; then
        echo "!! $demo: no executable named $demo under build/"
        failed=1
        continue
    fi

    (cd "$(dirname "$exe")" && env -u WAYLAND_DISPLAY "./$demo" >"$logs/$demo.log" 2>&1) &
    pid=$!
    window=
    for _ in $(seq 1 100); do # up to 10 s
        window=$(xwininfo -root -tree | grep -m1 "(\"$demo\"" | awk '{print $1}')
        [ -n "$window" ] && break
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.1
    done
    if [ -z "$window" ]; then
        echo "!! $demo: no window appeared"
        kill "$pid" 2>/dev/null
        wait "$pid" 2>/dev/null
        cat "$logs/$demo.log"
        failed=1
        continue
    fi

    sleep 1 # let it draw and settle before closing
    python3 -I "$root/tests/e2e/xclose.py" "$window"
    for _ in $(seq 1 100); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.1
    done
    if kill -0 "$pid" 2>/dev/null; then
        echo "!! $demo: still running 10 s after its window was closed"
        kill "$pid"
        failed=1
    fi
    wait "$pid"
    code=$?
    if [ "$code" -ne 0 ]; then
        echo "!! $demo: exit code $code"
        cat "$logs/$demo.log"
        failed=1
    else
        echo "ok $demo: window $window opened and closed, exit 0"
    fi
done

rm -rf "$logs"
exit $failed
