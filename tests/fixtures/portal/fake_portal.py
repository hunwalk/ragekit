"""A fake xdg-desktop-portal FileChooser for the dialogs module's tests: run it inside a throwaway
D-Bus session (dbus-run-session). It logs every OpenFile / SaveFile call as a line in the log file
(argv[1]) and answers each one at once, the way a user picking files would, from a script of
answers (argv[2:], one per call): "a|b" picks those paths, "-" cancels.
"""
import sys
from urllib.parse import quote
from gi.repository import Gio, GLib

LOG = sys.argv[1]
ANSWERS = sys.argv[2:]
XML = """
<node>
  <interface name="org.freedesktop.portal.FileChooser">
    <method name="OpenFile">
      <arg type="s" direction="in"/><arg type="s" direction="in"/><arg type="a{sv}" direction="in"/>
      <arg type="o" direction="out"/>
    </method>
    <method name="SaveFile">
      <arg type="s" direction="in"/><arg type="s" direction="in"/><arg type="a{sv}" direction="in"/>
      <arg type="o" direction="out"/>
    </method>
    <property name="version" type="u" access="read"/>
  </interface>
</node>
"""
state = {"calls": 0}


def log(line):
    with open(LOG, "a") as f:
        f.write(line + "\n")


def describe(options):
    parts = []
    for key in sorted(options):
        if key == "handle_token":
            continue
        value = options[key]
        if key == "filters":
            value = ";".join("%s=%s" % (name, ",".join(p for _, p in pats)) for name, pats in value)
        elif key == "current_folder":
            value = bytes(value).rstrip(b"\0").decode()
        parts.append("%s=%s" % (key, value))
    return " ".join(parts)


def call(conn, sender, path, iface, method, params, invocation):
    parent, title, options = params.unpack()
    token = options.get("handle_token", "t")
    handle = "/org/freedesktop/portal/desktop/request/%s/%s" % (sender[1:].replace(".", "_"), token)
    log("%s title=%s %s" % (method, title, describe(options)))
    invocation.return_value(GLib.Variant("(o)", (handle,)))
    answer = ANSWERS[state["calls"]] if state["calls"] < len(ANSWERS) else "-"
    state["calls"] += 1
    if answer == "-":
        response = GLib.Variant("(ua{sv})", (1, {}))
    else:
        uris = ["file://" + quote(p) for p in answer.split("|")]
        response = GLib.Variant("(ua{sv})", (0, {"uris": GLib.Variant("as", uris)}))
    conn.emit_signal(sender, handle, "org.freedesktop.portal.Request", "Response", response)


def get_property(conn, sender, path, iface, name):
    return GLib.Variant("u", 4)


def acquired(conn, name):
    node = Gio.DBusNodeInfo.new_for_xml(XML)
    conn.register_object("/org/freedesktop/portal/desktop", node.interfaces[0], call, get_property, None)
    log("ready")


Gio.bus_own_name(Gio.BusType.SESSION, "org.freedesktop.portal.Desktop", Gio.BusNameOwnerFlags.NONE, acquired, None, None)
GLib.MainLoop().run()
