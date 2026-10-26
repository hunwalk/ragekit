#!/usr/bin/env bash
# tests/fixtures/devicekey under Xvfb, in a throwaway D-Bus session and gnome-keyring-daemon with a
# temporary HOME (never the user's keyring): the first run creates an Ed25519 device key and stores
# it in the keyring, the second loads the same key (same fingerprint). Then, with no Secret
# Service at all, the app says the keyring is unavailable and keeps running.
# Needs DISPLAY (e.g. Xvfb :99), dbus-run-session, gnome-keyring-daemon, and network once (Monocypher).
#
#   DISPLAY=:99 tests/e2e/keyring.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/devicekey"
fail() { echo "!! keyring: $* (files in $tmp)"; for f in "$tmp"/run*.log; do echo "--- $f:"; cat "$f"; done; exit 1; }

cp -r "$root/tests/fixtures/devicekey" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"

mkdir -p "$tmp/home"
run() {
    env -i PATH=/usr/bin:/bin DISPLAY="$DISPLAY" HOME="$tmp/home" XDG_RUNTIME_DIR="$tmp" XDG_DATA_HOME="$tmp/home/.local/share" \
        dbus-run-session -- sh -c "printf pw | gnome-keyring-daemon --unlock --components=secrets >/dev/null; cd '$app/build/slint' && timeout 20 ./devicekey"
}
run >"$tmp/run1.log" 2>&1 || fail "first run failed"
run >"$tmp/run2.log" 2>&1 || fail "second run failed"
grep -qx 'keyring available' "$tmp/run1.log" || fail "the keyring wasn't available"
grep -qx 'created' "$tmp/run1.log" || fail "the first run didn't create the key"
grep -qx 'loaded' "$tmp/run2.log" || fail "the second run didn't find the stored key"
[ "$(grep '^fingerprint' "$tmp/run1.log")" = "$(grep '^fingerprint' "$tmp/run2.log")" ] || fail "the fingerprints differ"
grep -qx 'signs yes' "$tmp/run2.log" || fail "the loaded key doesn't sign"

# No session bus: unavailable, and get() throws instead of hanging; the app goes on and exits 0.
(cd "$app/build/slint" && env -i PATH=/usr/bin:/bin DISPLAY="$DISPLAY" HOME="$tmp/home" DBUS_SESSION_BUS_ADDRESS="unix:path=$tmp/no-bus" \
    timeout 20 ./devicekey >"$tmp/run3.log" 2>&1) || fail "the run without a keyring failed"
grep -q '^keyring unavailable: no Secret Service' "$tmp/run3.log" || fail "no reason given without a keyring"
grep -q '^error ' "$tmp/run3.log" || fail "get() didn't fail without a keyring"
rm -rf "$tmp"
echo "keyring: ok"
