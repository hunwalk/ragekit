#!/usr/bin/env bash
# tests/fixtures/crypto under Xvfb: an app built with the crypto module (its library loaded at
# runtime from next to the executable) prints keys, signatures, hashes and SSH formats for fixed
# inputs, which must match tests/fixtures/crypto/expected.txt (from sodium, hash() and ssh-keygen).
# Needs DISPLAY (e.g. Xvfb :99) and network once (Monocypher is fetched by the build).
#
#   DISPLAY=:99 tests/e2e/crypto.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/cryptoapp"
fail() { echo "!! crypto: $* (files in $tmp)"; echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null; exit 1; }

cp -r "$root/tests/fixtures/crypto" "$app"
# Built in a temporary copy, which wouldn't find this checkout's .ragekit/ toolchain by itself.
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
[ -f "$app/build/slint/librk_module_crypto.so" ] || fail "the module's library isn't next to the executable"
# TZ: the app also prints RageKit\LocalTime for the unix epoch (UTC+5:30, no daylight saving there).
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY TZ=Asia/Kolkata timeout 20 ./cryptoapp >"$tmp/app.log" 2>&1) || fail "the app failed (exit $?)"
diff -u "$app/expected.txt" "$tmp/app.log" || fail "unexpected output"
rm -rf "$tmp"
echo "crypto: ok"
