"""A fake org.freedesktop.Notifications server for the notifications module's tests: run it inside a
throwaway D-Bus session (dbus-run-session). It records every Notify / CloseNotification call as a
line in the log file (argv[1]) and, through the extra interface org.ragekit.Test, lets a test click a
notification (ActionInvoked) the way a user would:
    gdbus call --session --dest org.freedesktop.Notifications --object-path /org/freedesktop/Notifications \
        --method org.ragekit.Test.Click 1
    gdbus call … --method org.ragekit.Test.Reply 2 "On my way"
"""
import sys
from gi.repository import Gio, GLib

LOG = sys.argv[1]
XML = """
<node>
  <interface name="org.freedesktop.Notifications">
    <method name="Notify">
      <arg type="s" direction="in"/><arg type="u" direction="in"/><arg type="s" direction="in"/>
      <arg type="s" direction="in"/><arg type="s" direction="in"/><arg type="as" direction="in"/>
      <arg type="a{sv}" direction="in"/><arg type="i" direction="in"/><arg type="u" direction="out"/>
    </method>
    <method name="CloseNotification"><arg type="u" direction="in"/></method>
    <method name="GetCapabilities"><arg type="as" direction="out"/></method>
    <method name="GetServerInformation">
      <arg type="s" direction="out"/><arg type="s" direction="out"/><arg type="s" direction="out"/><arg type="s" direction="out"/>
    </method>
    <signal name="ActionInvoked"><arg type="u"/><arg type="s"/></signal>
    <signal name="NotificationClosed"><arg type="u"/><arg type="u"/></signal>
    <signal name="NotificationReplied"><arg type="u"/><arg type="s"/></signal>
  </interface>
  <interface name="org.ragekit.Test">
    <method name="Click"><arg type="u" direction="in"/></method>
    <method name="Reply"><arg type="u" direction="in"/><arg type="s" direction="in"/></method>
  </interface>
</node>
"""
state = {"next": 1}


def log(line):
    with open(LOG, "a") as f:
        f.write(line + "\n")


def emit(conn, name, params):
    conn.emit_signal(None, "/org/freedesktop/Notifications", "org.freedesktop.Notifications", name, params)


def call(conn, sender, path, iface, method, params, invocation):
    if method == "Notify":
        app, replaces, icon, summary, body, actions, hints, timeout = params.unpack()
        nid = replaces or state["next"]
        state["next"] = max(state["next"], nid + 1)
        reply = hints.get("x-kde-reply-placeholder-text")
        log("notify %d app=%s summary=%s body=%s actions=%s%s" % (nid, app, summary, body, ",".join(actions), " reply=" + reply if reply else ""))
        invocation.return_value(GLib.Variant("(u)", (nid,)))
    elif method == "CloseNotification":
        (nid,) = params.unpack()
        log("close %d" % nid)
        invocation.return_value(None)
        emit(conn, "NotificationClosed", GLib.Variant("(uu)", (nid, 3)))
    elif method == "GetCapabilities":
        invocation.return_value(GLib.Variant("(as)", (["actions", "body", "body-markup"],)))
    elif method == "GetServerInformation":
        invocation.return_value(GLib.Variant("(ssss)", ("fake", "ragekit", "1", "1.2")))
    elif method == "Click":
        (nid,) = params.unpack()
        log("click %d" % nid)
        invocation.return_value(None)
        emit(conn, "ActionInvoked", GLib.Variant("(us)", (nid, "default")))
        emit(conn, "NotificationClosed", GLib.Variant("(uu)", (nid, 2)))
    elif method == "Reply":
        nid, text = params.unpack()
        log("reply %d %s" % (nid, text))
        invocation.return_value(None)
        emit(conn, "ActionInvoked", GLib.Variant("(us)", (nid, "inline-reply")))
        emit(conn, "NotificationReplied", GLib.Variant("(us)", (nid, text)))
        emit(conn, "NotificationClosed", GLib.Variant("(uu)", (nid, 2)))


def badge(conn, sender, path, iface, signal, params):
    uri, props = params.unpack()
    log("badge %s count=%s visible=%s" % (uri, props.get("count"), props.get("count-visible")))


def acquired(conn, name):
    conn.signal_subscribe(None, "com.canonical.Unity.LauncherEntry", "Update", None, None, Gio.DBusSignalFlags.NONE, badge)
    node = Gio.DBusNodeInfo.new_for_xml(XML)
    for iface in node.interfaces:
        conn.register_object("/org/freedesktop/Notifications", iface, call, None, None)
    log("ready")


Gio.bus_own_name(Gio.BusType.SESSION, "org.freedesktop.Notifications", Gio.BusNameOwnerFlags.NONE, acquired, None, None)
GLib.MainLoop().run()
