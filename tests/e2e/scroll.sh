#!/usr/bin/env bash
# tests/fixtures/chat under Xvfb: <ScrollView stickToBottom anchor onTop> as a chat, with a
# <Textarea onSubmit maxRows> composer. It opens at the bottom; dragging the scrollbar up and back
# down returns there; a new message (received, or typed and sent with Return) while at the bottom
# moves the content up by exactly one row (25px); Shift+Return grows the box and the messages move
# up with it; the wheel click that loads ten older messages near the top moves it exactly as far
# as a plain click (no jump: anchor="bottom"); a new message while scrolled up moves nothing;
# scrolling on loads everything; a resize at the bottom stays there; a short chat fills itself up
# once. Shifts are measured with xshift.py.
# Nano builds buffer stdout until exit, so while it runs a load shows as the header's count
# changing ("30 messages" → "40 messages"), and the log is checked after the window closed.
# Needs DISPLAY (e.g. Xvfb :99, no window manager), xwininfo, ImageMagick, python3.
#
#   DISPLAY=:99 tests/e2e/scroll.sh            # extra arguments go to ragekit build (e.g. --nano)
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
app="$tmp/chat"
window=
fail() {
    echo "!! scroll: $* (files in $tmp)"
    [ -n "$window" ] && python3 -I "$root/tests/e2e/xclose.py" "$window"
    echo "--- app output:"; cat "$tmp/app.log" 2>/dev/null
    exit 1
}
xwininfo -root -tree | grep -q '("chat"' && { echo "!! scroll: a chat window is already open"; exit 1; }

cp -r "$root/tests/fixtures/chat" "$app"
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
(cd "$app" && "$root/bin/ragekit" build "$@") || fail "build failed"
(cd "$app/build/slint" && env -u WAYLAND_DISPLAY ./chat >"$tmp/app.log" 2>&1; echo $? >"$tmp/exit") &
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("chat"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "no window appeared"
sleep 0.5

n=0
shot() { n=$((n + 1)); import -window "$window" "$tmp/$n.png"; }
# The message area (between the header and the message box), left of the scrollbar.
moved() { python3 -I "$root/tests/e2e/xshift.py" "$tmp/$((n - 1)).png" "$tmp/$n.png" 0,60,440,280; }
receive() { python3 -I "$root/tests/e2e/xclick.py" 59,33; sleep 0.4; }
wheel() { python3 -I "$root/tests/e2e/xscroll.py" 200,200 "$1" "$2"; sleep 0.4; }
# Whether the message count (right of the button, whose hover comes and goes) changed between the last two shots.
header() { [ "$(compare -metric AE "$tmp/$((n - 1)).png[360x52+110+0]" "$tmp/$n.png[360x52+110+0]" null: 2>&1 | cut -d' ' -f1)" != "0" ]; }

shot
# The scrollbar: dragging its thumb up and back down by the same distance returns to the same place.
python3 -I "$root/tests/e2e/xdrag.py" 459,330 459,290; sleep 0.3; shot
[ "$(moved)" != "none" ] && [ "$(moved)" -lt 0 ] || fail "dragging the scrollbar up didn't scroll up ($(moved))"
python3 -I "$root/tests/e2e/xdrag.py" 459,290 459,330; sleep 0.3; shot
[ "$(compare -metric AE "$tmp/1.png[440x280+0+60]" "$tmp/$n.png[440x280+0+60]" null: 2>&1 | cut -d' ' -f1)" = "0" ] \
    || fail "dragging the scrollbar back down didn't return to the bottom"
receive; shot
[ "$(moved)" = "25" ] || fail "a new message at the bottom moved the content by $(moved), not one row (25)"
# The message box (a Textarea, rows 1, maxRows 4): Return sends (onSubmit), the message shows at
# the bottom; Shift+Return adds a line, the box grows and the messages move up with it.
python3 -I "$root/tests/e2e/xclick.py" 240,368
python3 -I "$root/tests/e2e/xtype.py" hello "$window"
python3 -I "$root/tests/e2e/xkey.py" Return "$window"; sleep 0.4; shot
[ "$(moved)" = "25" ] || fail "sending with Return didn't add a row at the bottom ($(moved))"
python3 -I "$root/tests/e2e/xtype.py" line1 "$window"
python3 -I "$root/tests/e2e/xkey.py" Shift_L+Return "$window"
python3 -I "$root/tests/e2e/xtype.py" line2 "$window"; sleep 0.4; shot
grew=$(moved)
[ "$grew" != "none" ] && [ "$grew" -gt 0 ] || fail "a second line didn't grow the message box ($grew)"
python3 -I "$root/tests/e2e/xkey.py" Return "$window"; sleep 0.4; shot
# One wheel click at a time until older messages load (the count changes): that click must move
# the content exactly as far as the plain click before it.
wheel up 1; shot
click=$(moved)
[ "$click" != "none" ] && [ "$click" -lt 0 ] || fail "the wheel didn't scroll up ($click)"
header && fail "older messages loaded too early"
for _ in $(seq 1 6); do
    wheel up 1; shot
    header && break
    [ "$(moved)" = "$click" ] || fail "plain wheel clicks moved differently ($(moved), $click)"
done
header || fail "coming near the top didn't load older messages"
[ "$(moved)" = "$click" ] || fail "loading older messages jumped: moved $(moved), a plain click moves $click"
receive; shot
[ "$(moved)" = "0" ] || fail "a new message while scrolled up moved the content ($(moved))"
for _ in $(seq 1 12); do wheel up 2; done
wheel down 80; shot
python3 -I "$root/tests/e2e/xresize.py" "$window" 480 260; shot
# 140px less height: the last rows (just above the message box) are where they were, 140px higher.
convert "$tmp/$((n - 1)).png" -crop 440x140+0+206 +repage "$tmp/before.png"
convert "$tmp/$n.png" -crop 440x140+0+66 +repage "$tmp/after.png"
[ "$(compare -metric AE "$tmp/before.png" "$tmp/after.png" null: 2>&1 | cut -d' ' -f1)" = "0" ] \
    || fail "shrinking the window at the bottom didn't keep the bottom in view"

python3 -I "$root/tests/e2e/xclose.py" "$window"
window=
wait
[ "$(cat "$tmp/exit")" = "0" ] || fail "exit code $(cat "$tmp/exit")"
expected=$(printf '%s\n' "received 101" "sent hello" "sent line1" "line2" "loaded 61" "received 104" "loaded 51" "loaded 41" "loaded 31" "loaded 21" "loaded 11" "loaded 1" "no older")
[ "$(uniq "$tmp/app.log")" = "$expected" ] || fail "unexpected events"

# A short chat (3 messages): it asks for older ones until the view is full (13, then 23 rows), and
# new messages at the bottom don't ask.
short="$tmp/short"
cp -r "$root/tests/fixtures/chat" "$short"
sed -i 's/private int \$oldest = 71;/private int $oldest = 98;/' "$short/src/ChatScreen.php"
(cd "$short" && "$root/bin/ragekit" build "$@" >/dev/null) || fail "building the short chat failed"
(cd "$short/build/slint" && env -u WAYLAND_DISPLAY ./chat >"$tmp/short.log" 2>&1) &
for _ in $(seq 1 100); do
    window=$(xwininfo -root -tree | grep -m1 '("chat"' | awk '{print $1}')
    [ -n "$window" ] && break
    sleep 0.1
done
[ -n "$window" ] || fail "the short chat's window didn't appear"
sleep 0.8
receive; receive
python3 -I "$root/tests/e2e/xclose.py" "$window"
window=
wait
[ "$(cat "$tmp/short.log")" = "$(printf '%s\n' "loaded 88" "loaded 78" "received 101" "received 102")" ] \
    || { cp "$tmp/short.log" "$tmp/app.log"; fail "the short chat didn't fill up once (or asked again on new messages)"; }
rm -rf "$tmp"
echo "scroll: ok"
