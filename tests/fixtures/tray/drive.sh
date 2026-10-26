#!/bin/sh
# Inside a throwaway D-Bus session (dbus-run-session): the fake watcher, the probe, then what a tray
# host does with the item. Arguments: <probe> <library> <icon file> <output folder>
set -u
probe="$1"; lib="$2"; icon="$3"; out="$4"
python3 "$(dirname "$0")/fake_watcher.py" "$out/watcher.log" >/dev/null 2>&1 &
watcher=$!
for i in $(seq 50); do grep -q ready "$out/watcher.log" 2>/dev/null && break; sleep 0.1; done
"$probe" "$lib" "$icon" >"$out/probe.log" 2>&1 &
for i in $(seq 50); do grep -q '^ready ' "$out/probe.log" 2>/dev/null && break; sleep 0.1; done
for i in $(seq 50); do grep -q '^register ' "$out/watcher.log" && break; sleep 0.1; done
service="org.kde.StatusNotifierItem-$(awk '/^ready /{print $2}' "$out/probe.log")-1"
get() { gdbus call --session --dest "$service" --object-path /StatusNotifierItem --method org.freedesktop.DBus.Properties.Get org.kde.StatusNotifierItem "$1"; }
{
    echo "IconName $(get IconName)"
    echo "IconThemePath $(get IconThemePath)"
    echo "ToolTip $(get ToolTip)"
    echo "Menu $(get Menu)"
    echo "Layout $(gdbus call --session --dest "$service" --object-path /MenuBar --method com.canonical.dbusmenu.GetLayout -- 0 -1 '[]')"
} >"$out/host.log" 2>&1
gdbus call --session --dest "$service" --object-path /StatusNotifierItem --method org.kde.StatusNotifierItem.Activate 10 10 >/dev/null
gdbus call --session --dest "$service" --object-path /MenuBar --method com.canonical.dbusmenu.Event 3 clicked '<0>' 0 >/dev/null
wait %2 2>/dev/null || sleep 1
for i in $(seq 50); do grep -q '^done' "$out/probe.log" && break; sleep 0.1; done
kill $watcher
