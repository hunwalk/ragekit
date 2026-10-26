#!/usr/bin/env bash
# tests/fixtures/upload under Xvfb: the http module sends a Multipart form (a field, a JSON part, a
# file with every byte value) to a local php -S running tests/fixtures/http/multipart.php, which
# answers with what PHP parsed; the app prints it. Needs DISPLAY (e.g. Xvfb :99) and php.
#
#   DISPLAY=:99 tests/e2e/upload.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/upload"
server=
fail() { echo "!! upload: $* (files in $tmp)"; [ -n "$server" ] && kill "$server" 2>/dev/null; echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null; exit 1; }

cp -r "$root/tests/fixtures/upload" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
port=$(python3 -I -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')
php -S "127.0.0.1:$port" "$root/tests/fixtures/http/multipart.php" >/dev/null 2>&1 &
server=$!
for _ in $(seq 1 50); do (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && break; sleep 0.1; done
echo "http://127.0.0.1:$port/upload" >"$app/build/slint/upload-url.txt"
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY timeout 20 ./upload >"$tmp/app.log" 2>&1) || fail "the app failed"
kill "$server"; server=

expected=$(php -r '$b = ""; for ($i = 0; $i < 256; $i++) { $b .= chr($i); }
echo json_encode(["contentType" => "", "fields" => ["caption" => "Hello \"there\""], "files" => [
    ["field" => "payload", "name" => "payload.json", "type" => "application/json", "error" => 0, "sha256" => hash("sha256", "{\"text\":\"hi\"}")],
    ["field" => "images", "name" => "all-bytes.bin", "type" => "application/octet-stream", "error" => 0, "sha256" => hash("sha256", $b)]]]);')
# The boundary is random: compare without the content type, then check that separately.
got=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); if (!is_array($a)) { exit(1); } echo str_starts_with($a["contentType"], "multipart/form-data; boundary=ragekit-") ? "ok" : "bad"; $a["contentType"] = ""; echo json_encode($a);' "$tmp/app.log") \
    || fail "the answer isn't JSON"
[ "$got" = "ok$expected" ] || fail "PHP parsed something else: $got"
rm -rf "$tmp"
echo "upload: ok"
