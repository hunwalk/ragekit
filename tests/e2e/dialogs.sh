#!/usr/bin/env bash
# tests/fixtures/filepick under Xvfb, in a throwaway D-Bus session with a fake file chooser portal
# (tests/fixtures/portal/fake_portal.py) that answers each dialog at once: two files picked (one
# with a space in its name), a cancel, a save, a folder. The app prints what it got and quits.
# Needs DISPLAY (e.g. Xvfb :99), dbus-run-session, python3 with gi (python3-gi).
#
#   DISPLAY=:99 tests/e2e/dialogs.sh     # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/filepick"
fail() { echo "!! dialogs: $* (files in $tmp)"; echo "--- app:"; cat "$tmp/app.log" 2>/dev/null; echo "--- portal:"; cat "$tmp/portal.log" 2>/dev/null; exit 1; }

cp -r "$root/tests/fixtures/filepick" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

cat >"$tmp/session.sh" <<SCRIPT
python3 "$root/tests/fixtures/portal/fake_portal.py" "$tmp/portal.log" "/home/a/cat.png|/home/a/b c.jpg" - "/tmp/voice note.wav" /home/a >/dev/null 2>&1 &
portal=\$!
for i in \$(seq 50); do grep -q ready "$tmp/portal.log" 2>/dev/null && break; sleep 0.1; done
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY timeout 20 ./filepick >"$tmp/app.log" 2>&1; echo \$? >"$tmp/exit")
kill \$portal
SCRIPT
env -i PATH=/usr/bin:/bin DISPLAY="$DISPLAY" HOME="$tmp" timeout 60 dbus-run-session -- sh "$tmp/session.sh" >/dev/null 2>&1

[ "$(cat "$tmp/exit" 2>/dev/null)" = "0" ] || fail "the app didn't quit by itself"
[ "$(cat "$tmp/app.log")" = "$(printf '%s\n' "available yes" "opened /home/a/cat.png | /home/a/b c.jpg" "cancelled 0" \
    "saved /tmp/voice note.wav" "folder /home/a")" ] || fail "unexpected output"
expected="ready
OpenFile title=Send a picture filters=Images=*.png,*.PNG,*.jpg,*.JPG;All files=* multiple=True
OpenFile title=Open 
SaveFile title=Save current_folder=/tmp current_name=voice note.wav filters=Sound=*.wav,*.WAV
OpenFile title=Choose a folder directory=True"
[ "$(cat "$tmp/portal.log")" = "$expected" ] || fail "the portal wasn't asked as expected"
rm -rf "$tmp"
echo "dialogs: ok"
