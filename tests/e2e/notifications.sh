#!/usr/bin/env bash
# tests/fixtures/notify under Xvfb, in a throwaway D-Bus session with a fake notification service
# (tests/fixtures/notifications/fake_server.py): the app shows a notification; clicking it (the fake
# service's test hook) runs the app's handler, which changes the window; it closes. A second one is
# answered from its reply field (as KDE does), the app sets a badge and takes it away, and quits.
# Needs DISPLAY (e.g. Xvfb :99), dbus-run-session, gdbus, python3 with gi (python3-gi), ImageMagick.
#
#   DISPLAY=:99 tests/e2e/notifications.sh     # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/notify"
fail() { echo "!! notifications: $* (files in $tmp)"; echo "--- app:"; cat "$tmp/app.log" 2>/dev/null; echo "--- service:"; cat "$tmp/server.log" 2>/dev/null; exit 1; }

cp -r "$root/tests/fixtures/notify" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

cat >"$tmp/session.sh" <<SCRIPT
python3 "$root/tests/fixtures/notifications/fake_server.py" "$tmp/server.log" >/dev/null 2>&1 &
server=\$!
for i in \$(seq 50); do grep -q ready "$tmp/server.log" 2>/dev/null && break; sleep 0.1; done
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY timeout 20 ./notify >"$tmp/app.log" 2>&1; echo \$? >"$tmp/exit") &
app=\$!
for i in \$(seq 100); do grep -q '^notify 1 ' "$tmp/server.log" && break; sleep 0.1; done
sleep 0.5
window=\$(xwininfo -root -tree | grep -m1 '("notify"' | awk '{print \$1}')
timeout 5 import -window "\$window" "$tmp/before.png"
gdbus call --session --dest org.freedesktop.Notifications --object-path /org/freedesktop/Notifications \
    --method org.ragekit.Test.Click 1 >/dev/null
sleep 0.3
timeout 5 import -window "\$window" "$tmp/after.png" 2>/dev/null
for i in \$(seq 100); do grep -q '^notify 2 ' "$tmp/server.log" && break; sleep 0.1; done
sleep 0.3
gdbus call --session --dest org.freedesktop.Notifications --object-path /org/freedesktop/Notifications \
    --method org.ragekit.Test.Reply 2 "On my way" >/dev/null
wait \$app
kill \$server
SCRIPT
env -i PATH=/usr/bin:/bin DISPLAY="$DISPLAY" HOME="$tmp" timeout 60 dbus-run-session -- sh "$tmp/session.sh" >/dev/null 2>&1

grep -q '^notify 1 app=notify summary=Bob body=Are you &lt;there&gt;? actions=default,Open$' "$tmp/server.log" || fail "the notification wasn't shown as expected"
[ "$(cat "$tmp/exit" 2>/dev/null)" = "0" ] || fail "the app didn't quit by itself after the notification closed"
grep -q '^notify 2 app=notify summary=Bob body=Lunch? actions=default,Open,inline-reply,Reply reply=Answer Bob$' "$tmp/server.log" || fail "the second notification had no reply field"
grep -q '^badge application://notify.desktop count=2 visible=True$' "$tmp/server.log" || fail "the badge wasn't sent"
grep -q '^badge application://notify.desktop count=0 visible=False$' "$tmp/server.log" || fail "the badge wasn't taken away"
[ "$(cat "$tmp/app.log")" = "$(printf '%s\n' "available yes" "shown 1" "clicked" "closed" "shown 2" "badge yes" "replied On my way" "closed")" ] || fail "unexpected output"
[ -f "$tmp/after.png" ] && [ "$(compare -metric AE "$tmp/before.png" "$tmp/after.png" null: 2>&1 | cut -d' ' -f1)" != "0" ] \
    || fail "the window didn't change when the notification was clicked"
rm -rf "$tmp"
echo "notifications: ok"
