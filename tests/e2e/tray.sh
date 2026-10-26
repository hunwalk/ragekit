#!/usr/bin/env bash
# tests/fixtures/trayapp under Xvfb, in a throwaway D-Bus session with a fake StatusNotifierWatcher
# (tests/fixtures/tray/fake_watcher.py) playing the desktop's tray: closing the window hides it and
# the app keeps running; clicking the tray icon shows it again; the tray menu's Quit ends the app.
# Needs DISPLAY (e.g. Xvfb :99), dbus-run-session, gdbus, python3 with gi, xwininfo.
#
#   DISPLAY=:99 tests/e2e/tray.sh              # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/trayapp"
fail() { echo "!! tray: $* (files in $tmp)"; for f in "$tmp"/*.log; do echo "--- $f:"; cat "$f"; done; exit 1; }

cp -r "$root/tests/fixtures/trayapp" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

cat >"$tmp/session.sh" <<SCRIPT
python3 "$root/tests/fixtures/tray/fake_watcher.py" "$tmp/watcher.log" >/dev/null 2>&1 &
watcher=\$!
for i in \$(seq 50); do grep -q ready "$tmp/watcher.log" 2>/dev/null && break; sleep 0.1; done
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY timeout 30 ./trayapp >"$tmp/app.log" 2>&1; echo \$? >"$tmp/exit") &
app=\$!
for i in \$(seq 100); do grep -q '^register ' "$tmp/watcher.log" && break; sleep 0.1; done
# The tray can register before the window is mapped; an empty id would make xwininfo wait for a click.
for i in \$(seq 100); do
    window=\$(xwininfo -root -tree | grep -m1 '("trayapp"' | awk '{print \$1}')
    [ -n "\$window" ] && break
    sleep 0.1
done
sleep 0.3
state() { xwininfo -id "\$window" | awk '/Map State/ {print \$3}'; }
service=\$(awk '/^register /{print \$2}' "$tmp/watcher.log")
echo "start \$(state)" >"$tmp/states.log"
python3 -I "$root/tests/e2e/xclose.py" "\$window"; sleep 0.5
echo "closed \$(state)" >>"$tmp/states.log"
gdbus call --session --dest "\$service" --object-path /StatusNotifierItem --method org.kde.StatusNotifierItem.Activate 0 0 >/dev/null
sleep 0.5
echo "clicked \$(state)" >>"$tmp/states.log"
python3 -I "$root/tests/e2e/xclose.py" "\$window"; sleep 0.5
gdbus call --session --dest "\$service" --object-path /MenuBar --method com.canonical.dbusmenu.Event -- 3 clicked '<0>' 0 >/dev/null
wait \$app
kill \$watcher
SCRIPT
env -i PATH=/usr/bin:/bin DISPLAY="$DISPLAY" HOME="$tmp" timeout 60 dbus-run-session -- sh "$tmp/session.sh" >/dev/null 2>&1

[ "$(cat "$tmp/states.log" 2>/dev/null)" = "$(printf '%s\n' 'start IsViewable' 'closed IsUnMapped' 'clicked IsViewable')" ] \
    || fail "the window wasn't hidden and shown as expected"
[ "$(cat "$tmp/exit" 2>/dev/null)" = "0" ] || fail "the tray's Quit didn't end the app cleanly"
[ "$(cat "$tmp/app.log")" = "$(printf '%s\n' 'tray yes' hidden clicked hidden quit)" ] || fail "unexpected output"
rm -rf "$tmp"
echo "tray: ok"
